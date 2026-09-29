<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

use TypeDock\Core\PluginContext;

/**
 * Correos del portal, a prueba de fallos: si el SMTP se cae, la acción del usuario igual se guarda
 * (nunca se rompe una request por un mail).
 *
 * - Todos usan CorreoPlantilla (HTML con versión en texto).
 * - Los avisos al CLIENTE sólo salen de lunes a viernes en el horario hábil de su país
 *   (por defecto 07:00–19:00, hora local). Fuera de eso quedan en cola (portal_correos_cola)
 *   y salen solos en la próxima apertura: al vaciar la cola en cada visita al portal/admin
 *   y, opcionalmente, con una tarea programada (cron) del hosting.
 * - Los avisos al EQUIPO salen al tiro (son para ti, no dependen del huso del cliente).
 * - El código de acceso también sale al tiro: es una acción que la persona está esperando.
 *
 * Hostinger limita los envíos por hora: sólo se manda correo cuando pasa algo que requiere
 * acción de la otra parte, no por cada archivo.
 */
class Notifier
{
    public const COLA = 'portal_correos_cola';
    private const MAX_POR_VACIADO = 6;
    private const MAX_INTENTOS = 3;

    /** Reloj inyectable para pruebas. */
    public static ?\DateTimeImmutable $ahora = null;

    public function __construct(private readonly PluginContext $ctx, private readonly \PDO $pdo, private readonly ?string $baseDir = null) {}

    private function ajustes(): AjustesService
    {
        return new AjustesService($this->pdo);
    }

    private function marca(): MarcaService
    {
        return new MarcaService($this->pdo, $this->baseDir);
    }

    public static function baseUrl(): string
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }

    private function ahoraUtc(): \DateTimeImmutable
    {
        return (self::$ahora ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC'));
    }

    private function absoluta(string $ruta): string
    {
        return str_starts_with($ruta, 'http') ? $ruta : self::baseUrl() . $ruta;
    }

    // ---- Armado ----------------------------------------------------------------

    /**
     * Convierte un texto simple (párrafos separados por línea en blanco; líneas «• …» = lista) en bloques.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function bloquesDesdeTexto(string $texto): array
    {
        $bloques = [];
        foreach (preg_split('/\R{2,}/u', trim($texto)) ?: [] as $trozo) {
            $trozo = trim($trozo);
            if ($trozo === '') {
                continue;
            }
            $lineas = preg_split('/\R/u', $trozo) ?: [];
            $lista = [];
            $resto = [];
            foreach ($lineas as $l) {
                if (preg_match('/^\s*[•\-\*]\s+(.*)$/u', $l, $m) === 1) {
                    $lista[] = $m[1];
                } else {
                    $resto[] = $l;
                }
            }
            if ($resto !== []) {
                $bloques[] = ['p' => implode("\n", $resto)];
            }
            if ($lista !== []) {
                $bloques[] = ['lista' => $lista];
            }
        }
        return $bloques;
    }

    /** Datos de marca de un cliente (color, logos) para la plantilla. @return array<string, mixed> */
    public function datosMarca(?string $clienteId): array
    {
        $m = $this->marca();
        $base = self::baseUrl();
        return [
            'agencia' => ['nombre' => $m->nombreEquipo(), 'logo' => $m->urlLogoAgencia($base)],
            'color'   => $clienteId !== null && $clienteId !== '' ? $m->colorCliente($clienteId) : AjustesService::colorValido(''),
        ];
    }

    /**
     * @param array<string, mixed> $op etiqueta, titulo, resaltado, boton, bloques (extra), preheader, compacto, cliente_id, proyecto_id
     * @return array{0: string, 1: string} [html, texto]
     */
    public function componer(string $asunto, string $cuerpo, array $op, ?string $saludo, string $url, ?string $clienteId, array $pie, ?array $contexto = null): array
    {
        $d = $this->datosMarca($clienteId) + [
            'etiqueta'  => (string) ($op['etiqueta'] ?? ''),
            'titulo'    => (string) ($op['titulo'] ?? $asunto),
            'resaltado' => (string) ($op['resaltado'] ?? ''),
            'preheader' => (string) ($op['preheader'] ?? mb_substr(trim(preg_replace('/\s+/u', ' ', $cuerpo) ?? ''), 0, 110)),
            'saludo'    => $saludo,
            'bloques'   => array_merge(self::bloquesDesdeTexto($cuerpo), (array) ($op['bloques'] ?? [])),
            'boton'     => $url !== '' ? ['texto' => (string) ($op['boton'] ?? 'Abrir el portal'), 'url' => $url] : null,
            'contexto'  => $contexto,
            'pie'       => $pie,
            'compacto'  => (bool) ($op['compacto'] ?? mb_strlen($cuerpo) < 220 && empty($op['bloques'])),
        ];
        return CorreoPlantilla::render($d);
    }

    // ---- Envíos --------------------------------------------------------------------

    /**
     * Aviso al equipo (correo configurado en Portal · Ajustes), al tiro. Con 'proyecto_id' o 'cliente_id'
     * en $op el correo trae arriba el logo, el nombre del cliente y el proyecto.
     *
     * @param array<string, mixed> $op
     */
    public function alEquipo(string $asunto, string $cuerpo, ?string $rutaAdmin = null, array $op = []): void
    {
        $to = trim($this->ajustes()->get('global', 'portal', 'email_avisos'));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $to = '';
        }
        $clienteId = (string) ($op['cliente_id'] ?? '');
        $proyecto = '';
        if (!empty($op['proyecto_id'])) {
            $st = $this->pdo->prepare('SELECT nombre, cliente_id FROM portal_proyectos WHERE id = ?');
            $st->execute([(string) $op['proyecto_id']]);
            $p = $st->fetch();
            if ($p !== false) {
                $proyecto = (string) $p['nombre'];
                $clienteId = $clienteId !== '' ? $clienteId : (string) $p['cliente_id'];
            }
        }
        $contexto = null;
        if ($clienteId !== '') {
            $st = $this->pdo->prepare('SELECT nombre, empresa FROM portal_clientes WHERE id = ?');
            $st->execute([$clienteId]);
            $c = $st->fetch();
            if ($c !== false) {
                $contexto = [
                    'cliente'  => trim((string) ($c['empresa'] ?: $c['nombre'])),
                    'logo'     => $this->marca()->urlLogoCliente($clienteId, self::baseUrl()),
                    'proyecto' => (string) ($op['proyecto'] ?? $proyecto),
                ];
            }
        }
        $cid = $clienteId !== '' ? $clienteId : null;
        $pie = ['Aviso interno: el cliente no ve este correo.'];

        // Correo de avisos de Ajustes: enlace al admin de TypeDock.
        if ($to !== '') {
            $url = $rutaAdmin !== null ? $this->absoluta($this->ctx->adminUrl($rutaAdmin)) : '';
            [$html, $texto] = $this->componer($asunto, $cuerpo, $op + ['boton' => 'Abrir en el admin'], null, $url, $cid, $pie, $contexto);
            $this->enviarCorreo($to, $asunto, $html, $texto);
        }

        // Personas de la agencia asignadas a ese proyecto/cliente: enlace al panel de equipo.
        $enviados = [strtolower($to)];
        $url = $rutaAdmin !== null ? $this->absoluta('/equipo/' . ltrim($rutaAdmin, '/')) : $this->absoluta('/equipo');
        $pieEq = array_merge($pie, ['Te llega porque tienes asignado este cliente. Puedes desactivar estos avisos en «Mis ajustes» del panel.']);
        foreach ((new EquipoService($this->pdo))->destinatariosAvisos((string) ($op['proyecto_id'] ?? ''), $cid) as $u) {
            $mail = strtolower((string) $u['email']);
            if (in_array($mail, $enviados, true)) {
                continue;
            }
            $enviados[] = $mail;
            [$html, $texto] = $this->componer($asunto, $cuerpo, $op + ['boton' => 'Abrir en el panel'], null, $url, $cid, $pieEq, $contexto);
            $this->enviarCorreo($mail, $asunto, $html, $texto);
        }
    }

    /**
     * Aviso a los contactos de un cliente (o a uno solo) que no hayan desactivado los avisos.
     * Respeta el horario hábil del país del cliente: fuera de él, queda en cola.
     *
     * @param array<string, mixed> $op
     */
    public function alCliente(string $clienteId, ?string $contactoId, string $asunto, string $cuerpo, string $ruta = '/portal', array $op = []): void
    {
        $sql    = 'SELECT id, nombre, email FROM portal_contactos WHERE cliente_id = ?';
        $params = [$clienteId];
        if ($contactoId !== null && $contactoId !== '') {
            $sql .= ' AND id = ?';
            $params[] = $contactoId;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $contactos = $stmt->fetchAll();
        if ($contactos === []) {
            return;
        }

        $cuando = $this->cuandoEnviar($clienteId, !empty($op['inmediato']));
        $ahora  = $this->ahoraUtc();
        foreach ($contactos as $c) {
            if ($this->ajustes()->get('contacto', (string) $c['id'], 'avisos_email', '1') === '0') {
                continue;
            }
            [$html, $texto] = $this->componer(
                $asunto, $cuerpo, $op, 'Hola ' . $this->primerNombre((string) $c['nombre']) . ',', $this->absoluta($ruta), $clienteId,
                ['Puedes desactivar estos avisos en Ajustes dentro del portal.']
            );
            if ($cuando <= $ahora) {
                $this->enviarCorreo((string) $c['email'], $asunto, $html, $texto);
            } else {
                $this->encolar($clienteId, (string) $c['id'], (string) $c['email'], $asunto, $texto, $html, $cuando);
            }
        }
    }

    /** Código de acceso (siempre al tiro), con la marca del cliente del contacto. */
    public function codigoAcceso(array $contacto, string $codigo): void
    {
        $clienteId = (string) ($contacto['cliente_id'] ?? '');
        [$html, $texto] = $this->componer(
            'Tu código de acceso: ' . $codigo,
            '',
            ['etiqueta' => 'Acceso', 'titulo' => 'Tu código de acceso', 'resaltado' => 'código', 'compacto' => true,
             'preheader' => 'Tu código es ' . $codigo . '. Vence en 10 minutos.',
             'bloques' => [['p' => 'Usa este código para entrar al portal. Vence en 10 minutos.'], ['codigo' => $codigo]]],
            'Hola ' . $this->primerNombre((string) $contacto['nombre']) . ',', '', $clienteId !== '' ? $clienteId : null,
            ['Si no pediste este código, puedes ignorar este correo.']
        );
        $this->enviarCorreo((string) $contacto['email'], 'Tu código de acceso: ' . $codigo, $html, $texto, true);
    }

    /** Invitación a un usuario de agencia: cómo entrar al panel de equipo. */
    public function invitacionEquipo(array $usuario): bool
    {
        $url = $this->absoluta('/equipo/entrar');
        [$html, $texto] = $this->componer(
            'Te dimos acceso al panel de equipo',
            'Desde el panel verás los clientes y proyectos que tienes asignados: tareas, contenidos para revisión y reuniones.' . "\n\n"
                . 'Para entrar no necesitas contraseña: escribe tu correo y te enviaremos un código.',
            ['etiqueta' => 'Equipo', 'titulo' => 'Bienvenido al panel de equipo', 'resaltado' => 'equipo', 'boton' => 'Entrar al panel'],
            'Hola ' . $this->primerNombre((string) $usuario['nombre']) . ',', $url, null,
            ['Entras siempre con este correo: ' . $usuario['email']]
        );
        return $this->enviarCorreo((string) $usuario['email'], 'Te dimos acceso al panel de equipo', $html, $texto, false);
    }

    /** Código de acceso de un usuario de agencia (front /equipo). Sale al tiro, con la marca de la agencia. */
    public function codigoAccesoEquipo(array $usuario, string $codigo): void
    {
        [$html, $texto] = $this->componer(
            'Tu código para el panel de equipo: ' . $codigo,
            '',
            ['etiqueta' => 'Equipo', 'titulo' => 'Tu código de acceso', 'resaltado' => 'código', 'compacto' => true,
             'preheader' => 'Tu código es ' . $codigo . '. Vence en 10 minutos.',
             'bloques' => [['p' => 'Usa este código para entrar al panel de equipo. Vence en 10 minutos.'], ['codigo' => $codigo]]],
            'Hola ' . $this->primerNombre((string) $usuario['nombre']) . ',', '', null,
            ['Si no pediste este código, puedes ignorar este correo.']
        );
        $this->enviarCorreo((string) $usuario['email'], 'Tu código para el panel de equipo: ' . $codigo, $html, $texto, true);
    }

    private function primerNombre(string $n): string
    {
        $p = preg_split('/\s+/u', trim($n)) ?: [];
        return $p[0] ?? $n;
    }

    // ---- Horario hábil ---------------------------------------------------------------

    /** @return array{0: bool, 1: int, 2: int} respetar, inicio, fin */
    public function horario(): array
    {
        $a = $this->ajustes();
        $respetar = $a->get('global', 'portal', 'horario_respetar', '1') !== '0';
        [$i, $f] = HorarioHabil::rango((int) $a->get('global', 'portal', 'horario_ini', (string) HorarioHabil::INICIO), (int) $a->get('global', 'portal', 'horario_fin', (string) HorarioHabil::FIN));
        return [$respetar, $i, $f];
    }

    public function paisDe(string $clienteId): string
    {
        $st = $this->pdo->prepare('SELECT pais FROM portal_clientes WHERE id = ?');
        try {
            $st->execute([$clienteId]);
            $p = $st->fetchColumn();
        } catch (\Throwable) {
            $p = false;
        }
        return HorarioHabil::paisValido(is_string($p) ? $p : '');
    }

    /** Instante (UTC) desde el que se puede enviar a este cliente. */
    public function cuandoEnviar(string $clienteId, bool $inmediato = false): \DateTimeImmutable
    {
        $ahora = $this->ahoraUtc();
        [$respetar, $ini, $fin] = $this->horario();
        if ($inmediato || !$respetar) {
            return $ahora;
        }
        return HorarioHabil::proximoEnvio($ahora, $this->paisDe($clienteId), $ini, $fin)->setTimezone(new \DateTimeZone('UTC'));
    }

    // ---- Cola ----------------------------------------------------------------------------

    private function encolar(string $clienteId, string $contactoId, string $to, string $asunto, string $texto, string $html, \DateTimeImmutable $cuando): void
    {
        Schema::asegurar($this->pdo);
        $this->pdo->prepare(
            'INSERT INTO ' . self::COLA . ' (id, cliente_id, contacto_id, destino, asunto, texto, html, enviar_desde, estado, intentos, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)'
        )->execute([typedock_uuid7(), $clienteId, $contactoId, $to, mb_substr($asunto, 0, 500), $texto, $html, $cuando->format('Y-m-d H:i:s'), 'pendiente', $this->ahoraUtc()->format('Y-m-d H:i:s')]);
    }

    /**
     * Envía lo que ya llegó a su hora. Seguro de llamar en cualquier request: no hace nada si no hay pendientes.
     *
     * @return int cantidad enviada
     */
    public function vaciarCola(int $max = self::MAX_POR_VACIADO): int
    {
        try {
            Schema::asegurar($this->pdo);
            $ahora = $this->ahoraUtc()->format('Y-m-d H:i:s');
            // Reclamos que quedaron colgados (request caída a mitad de envío).
            $this->pdo->prepare('UPDATE ' . self::COLA . " SET estado = 'pendiente' WHERE estado = 'enviando' AND enviado_en < ?")
                ->execute([$this->ahoraUtc()->modify('-10 minutes')->format('Y-m-d H:i:s')]);
            $st = $this->pdo->prepare('SELECT * FROM ' . self::COLA . " WHERE estado = 'pendiente' AND enviar_desde <= ? ORDER BY enviar_desde, created_at LIMIT " . max(1, $max));
            $st->execute([$ahora]);
            $filas = $st->fetchAll();
        } catch (\Throwable) {
            return 0;
        }
        $n = 0;
        foreach ($filas as $f) {
            if ($this->enviarDeCola($f)) {
                $n++;
            }
        }
        return $n;
    }

    /** @param array<string, mixed> $f */
    private function enviarDeCola(array $f): bool
    {
        $ahora = $this->ahoraUtc();
        $claim = $this->pdo->prepare('UPDATE ' . self::COLA . " SET estado = 'enviando', enviado_en = ?, intentos = intentos + 1 WHERE id = ? AND estado = 'pendiente'");
        $claim->execute([$ahora->format('Y-m-d H:i:s'), $f['id']]);
        if ($claim->rowCount() !== 1) {
            return false;   // otra request se lo llevó
        }
        $ok = $this->enviarCorreo((string) $f['destino'], (string) $f['asunto'], (string) $f['html'], (string) $f['texto'], true);
        if ($ok) {
            $this->pdo->prepare('UPDATE ' . self::COLA . " SET estado = 'enviado', enviado_en = ?, error = NULL WHERE id = ?")->execute([$ahora->format('Y-m-d H:i:s'), $f['id']]);
            return true;
        }
        $intentos = (int) $f['intentos'] + 1;
        if ($intentos >= self::MAX_INTENTOS) {
            $this->pdo->prepare('UPDATE ' . self::COLA . " SET estado = 'error', error = ? WHERE id = ?")->execute(['El servidor de correo no aceptó el envío ' . $intentos . ' veces.', $f['id']]);
        } else {
            $this->pdo->prepare('UPDATE ' . self::COLA . " SET estado = 'pendiente', enviar_desde = ? WHERE id = ?")
                ->execute([$ahora->modify('+15 minutes')->format('Y-m-d H:i:s'), $f['id']]);
        }
        return false;
    }

    /** @return array<int, array<string, mixed>> pendientes y con error, para el panel de Ajustes */
    public function cola(int $max = 50): array
    {
        try {
            Schema::asegurar($this->pdo);
            $st = $this->pdo->query('SELECT c.id, c.destino, c.asunto, c.enviar_desde, c.estado, c.intentos, c.error, c.cliente_id, cl.nombre AS cliente_nombre
                FROM ' . self::COLA . " c LEFT JOIN portal_clientes cl ON cl.id = c.cliente_id
                WHERE c.estado IN ('pendiente', 'enviando', 'error') ORDER BY c.enviar_desde LIMIT " . max(1, $max));
            return $st ? $st->fetchAll() : [];
        } catch (\Throwable) {
            return [];
        }
    }

    public function enviarAhora(string $id): bool
    {
        $st = $this->pdo->prepare('SELECT * FROM ' . self::COLA . " WHERE id = ? AND estado IN ('pendiente', 'error')");
        $st->execute([$id]);
        $f = $st->fetch();
        if ($f === false) {
            return false;
        }
        $this->pdo->prepare('UPDATE ' . self::COLA . " SET estado = 'pendiente', intentos = 0 WHERE id = ?")->execute([$id]);
        $f['intentos'] = 0;
        return $this->enviarDeCola($f);
    }

    public function cancelar(string $id): void
    {
        $this->pdo->prepare('DELETE FROM ' . self::COLA . " WHERE id = ? AND estado IN ('pendiente', 'error')")->execute([$id]);
    }

    // ---- Transporte -----------------------------------------------------------------------------

    /** 'auto' | 'smtp' | 'html' | 'texto' */
    public function modo(): string
    {
        $m = $this->ajustes()->get('global', 'portal', 'correo_modo', 'auto');
        return in_array($m, ['auto', 'smtp', 'html', 'texto'], true) ? $m : 'auto';
    }

    // ---- SMTP propio ---------------------------------------------------------------------

    /** @return array{host: string, puerto: int, seg: string, usuario: string, clave: string, desde: string}|null null si falta algo */
    public function smtpConfig(): ?array
    {
        $a = $this->ajustes();
        $host = trim($a->get('global', 'portal', 'smtp_host'));
        $user = trim($a->get('global', 'portal', 'smtp_user'));
        $clave = (new Cifrado($this->pdo, $this->baseDir))->descifrar($a->get('global', 'portal', 'smtp_clave'));
        if ($host === '' || $user === '' || $clave === '') {
            return null;
        }
        $seg = $a->get('global', 'portal', 'smtp_seg', 'ssl');
        $desde = trim($a->get('global', 'portal', 'smtp_desde'));
        return [
            'host' => $host, 'puerto' => max(1, min(65535, (int) $a->get('global', 'portal', 'smtp_puerto', '465'))),
            'seg' => in_array($seg, ['ssl', 'tls', 'ninguna'], true) ? $seg : 'ssl', 'usuario' => $user, 'clave' => $clave,
            'desde' => filter_var($desde, FILTER_VALIDATE_EMAIL) ? $desde : (filter_var($user, FILTER_VALIDATE_EMAIL) ? $user : ''),
        ];
    }

    /** @return array<string, mixed> datos para mostrar en Ajustes (sin la contraseña) */
    public function smtpVista(): array
    {
        $a = $this->ajustes();
        return [
            'host' => $a->get('global', 'portal', 'smtp_host', 'smtp.hostinger.com'), 'puerto' => $a->get('global', 'portal', 'smtp_puerto', '465'),
            'seg' => $a->get('global', 'portal', 'smtp_seg', 'ssl'), 'usuario' => $a->get('global', 'portal', 'smtp_user'),
            'desde' => $a->get('global', 'portal', 'smtp_desde'), 'tieneClave' => $a->get('global', 'portal', 'smtp_clave') !== '',
            'lista' => $this->smtpConfig() !== null, 'ultimoError' => $a->get('global', 'portal', 'smtp_ultimo_error'),
        ];
    }

    /**
     * Guarda la conexión SMTP. La contraseña sólo cambia si se escribe una nueva.
     *
     * @param array<string, mixed> $d host, puerto, seg, usuario, desde, clave, quitar_clave
     * @return string|null mensaje de error o null
     */
    public function smtpGuardar(array $d): ?string
    {
        $a = $this->ajustes();
        $host = trim((string) ($d['host'] ?? ''));
        if ($host !== '' && preg_match('/^[A-Za-z0-9.\-]{3,253}$/', $host) !== 1) {
            return 'El servidor SMTP no parece válido (ej.: smtp.hostinger.com).';
        }
        $desde = trim((string) ($d['desde'] ?? ''));
        if ($desde !== '' && !filter_var($desde, FILTER_VALIDATE_EMAIL)) {
            return 'El correo «Enviar desde» no es válido.';
        }
        $seg = (string) ($d['seg'] ?? 'ssl');
        $a->set('global', 'portal', 'smtp_host', $host);
        $a->set('global', 'portal', 'smtp_puerto', (string) max(1, min(65535, (int) ($d['puerto'] ?? 465))));
        $a->set('global', 'portal', 'smtp_seg', in_array($seg, ['ssl', 'tls', 'ninguna'], true) ? $seg : 'ssl');
        $a->set('global', 'portal', 'smtp_user', trim((string) ($d['usuario'] ?? '')));
        $a->set('global', 'portal', 'smtp_desde', $desde);
        if (!empty($d['quitar_clave'])) {
            $a->set('global', 'portal', 'smtp_clave', '');
        } elseif (trim((string) ($d['clave'] ?? '')) !== '') {
            $a->set('global', 'portal', 'smtp_clave', (new Cifrado($this->pdo, $this->baseDir))->cifrar((string) $d['clave']));
        }
        $a->set('global', 'portal', 'smtp_ultimo_error', '');
        return null;
    }

    /** @return string|null mensaje de error o null si la conexión y las credenciales funcionan */
    public function smtpProbar(): ?string
    {
        $c = $this->smtpConfig();
        if ($c === null) {
            return 'Falta completar servidor, usuario y contraseña del correo.';
        }
        try {
            $this->clienteSmtp($c)->probar();
            $this->ajustes()->set('global', 'portal', 'smtp_ultimo_error', '');
            return null;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    /** @param array<string, mixed> $c */
    private function clienteSmtp(array $c): SmtpCliente
    {
        $host = (string) (parse_url(self::baseUrl(), PHP_URL_HOST) ?: 'localhost');
        return new SmtpCliente($c['host'], $c['puerto'], $c['seg'], $c['usuario'], $c['clave'], 20, $host);
    }

    /** ¿El correo del núcleo tiene un método propio para HTML? */
    private function metodoHtml(): ?string
    {
        $mail = $this->ctx->mail();
        foreach (['sendHtml', 'sendHTML', 'sendMultipart'] as $m) {
            if (method_exists($mail, $m)) {
                return $m;
            }
        }
        return null;
    }

    /**
     * Envía el correo. Nunca lanza excepciones. Orden:
     *  1. SMTP propio del portal (HTML + texto, el diseño llega siempre) si está configurado y el modo es auto/smtp.
     *     Si falla, se guarda el motivo (se ve en Ajustes) y se cae al correo del núcleo en texto plano.
     *  2. Modo html: HTML por el envío estándar del núcleo.  Modo auto: método HTML del núcleo si existe.
     *  3. Texto plano por el correo del núcleo.
     */
    public function enviarCorreo(string $to, string $asunto, string $html, string $texto, bool $silencioso = true): bool
    {
        $modo = $this->modo();
        if (in_array($modo, ['auto', 'smtp'], true) && ($cfg = $this->smtpConfig()) !== null && $cfg['desde'] !== '') {
            try {
                $this->clienteSmtp($cfg)->enviar($cfg['desde'], $this->marca()->nombreEquipo(), $to, $asunto, $html, $texto, trim($this->ajustes()->get('global', 'portal', 'email_avisos')) ?: null);
                return true;
            } catch (\Throwable $e) {
                $this->ajustes()->set('global', 'portal', 'smtp_ultimo_error', mb_substr($e->getMessage(), 0, 250) . ' (' . date('d/m H:i') . ')');
                // cae al correo del núcleo, en texto
                $html = '';
            }
        }
        try {
            $mail = $this->ctx->mail();
            $especial = ($modo === 'texto' || $html === '') ? null : $this->metodoHtml();
            if ($especial !== null) {
                $mail->{$especial}($to, $asunto, $html, $texto);
                return true;
            }
            $r = $mail->send($to, $asunto, ($modo === 'html' && $html !== '') ? $html : $texto);
            return $r !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Para el panel: cómo es el correo del núcleo (clase y métodos públicos). @return array{clase: string, metodos: array<string>} */
    public function diagnostico(): array
    {
        try {
            $mail = $this->ctx->mail();
            $rc = new \ReflectionObject($mail);
            $ms = [];
            foreach ($rc->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
                if (!str_starts_with($m->getName(), '__')) {
                    $ps = array_map(fn($p) => '$' . $p->getName(), $m->getParameters());
                    $ms[] = $m->getName() . '(' . implode(', ', $ps) . ')';
                }
            }
            return ['clase' => $rc->getName(), 'metodos' => $ms];
        } catch (\Throwable) {
            return ['clase' => '(desconocida)', 'metodos' => []];
        }
    }
}

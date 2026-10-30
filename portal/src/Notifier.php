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
 * - Los avisos al EQUIPO van a cada persona según sus preferencias: al tiro o agrupados (ver Avisos).
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

    public function absoluta(string $ruta): string
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
            'color'   => $clienteId !== null && $clienteId !== '' ? $m->colorCliente($clienteId)
                : AjustesService::colorValido($this->ajustes()->get('global', 'portal', 'color_agencia')),   // correos internos: color de la agencia
        ];
    }

    /**
     * @param array<string, mixed> $op etiqueta, titulo, resaltado, boton, bloques (extra), preheader, compacto, cliente_id, proyecto_id
     * @return array{0: string, 1: string} [html, texto]
     */
    public function componer(string|\Stringable $asunto, string|\Stringable $cuerpo, array $op, string|\Stringable|null $saludo, string $url, ?string $clienteId, array $pie, ?array $contexto = null): array
    {
        // Las frases (Idioma::f) se escriben aquí, en el idioma que dejó puesto quien llama (el de quien recibe el correo).
        [$asunto, $cuerpo, $op, $saludo, $pie] = [Idioma::resolver($asunto), Idioma::resolver($cuerpo), Idioma::resolver($op), Idioma::resolver($saludo), Idioma::resolver($pie)];
        $d = $this->datosMarca($clienteId) + [
            'etiqueta'  => (string) ($op['etiqueta'] ?? ''),
            'titulo'    => (string) ($op['titulo'] ?? $asunto),
            'resaltado' => (string) ($op['resaltado'] ?? ''),
            'preheader' => (string) ($op['preheader'] ?? mb_substr(trim(preg_replace('/\s+/u', ' ', $cuerpo) ?? ''), 0, 110)),
            'saludo'    => $saludo,
            'bloques'   => array_merge(self::bloquesDesdeTexto($cuerpo), (array) ($op['bloques'] ?? [])),
            'boton'     => $url !== '' ? ['texto' => (string) ($op['boton'] ?? Idioma::t('Abrir el portal')), 'url' => $url] : null,
            'contexto'  => $contexto,
            'pie'       => $pie,
            'compacto'  => (bool) ($op['compacto'] ?? mb_strlen($cuerpo) < 220 && empty($op['bloques'])),
        ];
        return CorreoPlantilla::render($d);
    }

    // ---- Envíos --------------------------------------------------------------------

    /**
     * Aviso al equipo por algo que pasó en un cliente o proyecto.
     *
     *  - El correo de copia de Ajustes (opcional) recibe todo, también agrupado, con enlace al admin.
     *  - Cada persona del equipo recibe lo que le toca según sus preferencias (Avisos::leToca), siempre
     *    agrupado (Avisos::BUZON): a horas fijas o cuando se juntan 10. Una urgencia adelanta el envío
     *    a la próxima pasada del cron, junto con lo demás que esté pendiente.
     *
     * $op además de lo de componer():
     *   proyecto_id / cliente_id   de qué cliente es (arma el encabezado y define a quién le toca)
     *   responsable                id del equipo de quien es el asunto (p. ej. responsable de la tarea)
     *   solo_responsable           true: sólo a esa persona (p. ej. «te asignaron una tarea»)
     *   actor                      id del equipo que lo hizo (no se le avisa a sí mismo)
     *   urgente                    true: no espera la próxima hora fija (sale en la siguiente pasada del cron)
     *   clave                      para agrupar varias novedades de lo mismo (por defecto, la ruta)
     *   detalle                    una línea para el correo agrupado
     *   vigencia                   marca de Vigencia: si mientras espera deja de aplicar, no sale
     *
     * @param array<string, mixed> $op
     */
    public function alEquipo(string|\Stringable $asunto, string|\Stringable $cuerpo, ?string $rutaAdmin = null, array $op = []): void
    {
        [$cid, $pid, $contexto] = $this->contextoAviso($op);
        // El texto de cada aviso se escribe en el idioma de quien lo recibe (Avisos::guardar).
        $detalle = $op['detalle'] ?? Idioma::perezoso(fn() => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $cuerpo) ?? ''), 0, 160));

        // Copia de todos los avisos (Ajustes): también agrupada, con enlace al admin de TypeDock.
        $to = trim($this->ajustes()->get('global', 'portal', 'email_avisos'));
        $avisos = new Avisos($this->ctx, $this->pdo, $this);
        if (filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $avisos->guardar(Avisos::DESTINO_COPIA, $cid, $pid, (string) ($op['clave'] ?? $rutaAdmin ?? (string) $asunto), $asunto,
                $detalle, $rutaAdmin,
                isset($op['vigencia']) ? (string) $op['vigencia'] : null, !empty($op['urgente']), 'copia');
        } else {
            $to = '';
        }

        // Personas del equipo: a cada una lo suyo, al buzón (sale agrupado).
        $enviados = [strtolower($to)];
        foreach ($avisos->destinatarios($pid, $cid, $op) as $u) {
            $mail = strtolower((string) $u['email']);
            if (in_array($mail, $enviados, true)) {
                continue;
            }
            $enviados[] = $mail;
            $avisos->guardar((string) $u['id'], $cid, $pid, (string) ($op['clave'] ?? $rutaAdmin ?? (string) $asunto), $asunto,
                $detalle, $rutaAdmin,
                isset($op['vigencia']) ? (string) $op['vigencia'] : null, !empty($op['urgente']));
        }
    }

    /**
     * Cliente, proyecto y encabezado (logo + nombres) de un aviso interno.
     *
     * @param array<string, mixed> $op
     * @return array{0: ?string, 1: ?string, 2: ?array<string, string>} [clienteId, proyectoId, contexto]
     */
    public function contextoAviso(array $op): array
    {
        $clienteId = (string) ($op['cliente_id'] ?? '');
        $proyectoId = (string) ($op['proyecto_id'] ?? '');
        $proyecto = '';
        if ($proyectoId !== '') {
            $st = $this->pdo->prepare('SELECT nombre, cliente_id FROM portal_proyectos WHERE id = ?');
            $st->execute([$proyectoId]);
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
        return [$clienteId !== '' ? $clienteId : null, $proyectoId !== '' ? $proyectoId : null, $contexto];
    }

    /**
     * Aviso a los contactos de un cliente (o a uno solo) que no hayan desactivado los avisos.
     * Va al buzón y sale agrupado, a horas fijas en el horario hábil de la persona (Avisos::barrer).
     * Con $op['inmediato'] sale ya (respetando el horario hábil): sólo para lo que la persona está esperando.
     *
     * $op además de lo de componer(): proyecto_id, clave (agrupa novedades de lo mismo; por defecto la ruta),
     * detalle (una línea para el correo agrupado), vigencia (si deja de aplicar, no sale), contactos, inmediato.
     *
     * @param array<string, mixed> $op
     */
    public function alCliente(string $clienteId, ?string $contactoId, string|\Stringable $asunto, string|\Stringable $cuerpo, string $ruta = '/portal', array $op = []): void
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

        // Sólo algunos contactos (p. ej. los convocados a una reunión).
        if (isset($op['contactos']) && is_array($op['contactos'])) {
            $contactos = array_values(array_filter($contactos, fn($c) => in_array((string) $c['id'], $op['contactos'], true)));
        }
        foreach ($contactos as $c) {
            if ($this->ajustes()->get('contacto', (string) $c['id'], 'avisos_email', '1') === '0') {
                continue;
            }
            if (empty($op['inmediato'])) {
                $detalle = $op['detalle'] ?? Idioma::perezoso(fn() => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $cuerpo) ?: (string) ($op['preheader'] ?? '')), 0, 160));
                $this->alBuzonCliente($c, $clienteId, $asunto, $detalle, $ruta, (string) ($op['clave'] ?? $ruta), isset($op['proyecto_id']) ? (string) $op['proyecto_id'] : null,
                    isset($op['vigencia']) ? (string) $op['vigencia'] : null);
                continue;
            }
            Idioma::con(Idioma::de($this->ajustes(), 'contacto', (string) $c['id'], $clienteId), function () use ($c, $asunto, $cuerpo, $op, $ruta, $clienteId): void {
                [$html, $texto] = $this->componer(
                    $asunto, $cuerpo, $op, Idioma::t('Hola %s,', $this->primerNombre((string) $c['nombre'])), $this->absoluta($ruta), $clienteId,
                    [Idioma::t('Puedes desactivar estos avisos en Ajustes dentro del portal.')]
                );
                $this->aContacto($c, $clienteId, (string) $asunto, $html, $texto, true, null, isset($op['vigencia']) ? (string) $op['vigencia'] : null);
            });
        }
    }

    /**
     * Deja un aviso para un contacto del cliente en el buzón: sale agrupado, a horas fijas (Avisos::barrer).
     *
     * @param array<string, mixed> $contacto id
     * @param bool $reemplaza el aviso nuevo reemplaza al que seguía esperando con la misma clave
     */
    public function alBuzonCliente(array $contacto, string $clienteId, string|\Stringable $asunto, string|\Stringable $detalle, string $ruta, string $clave, ?string $proyectoId = null, ?string $vigencia = null, bool $reemplaza = false): void
    {
        (new Avisos($this->ctx, $this->pdo, $this))->guardar((string) $contacto['id'], $clienteId, $proyectoId, $clave, $asunto, $detalle, $ruta, $vigencia, false, 'cliente', $reemplaza);
    }

    /** Código de acceso (siempre al tiro), con la marca del cliente del contacto. */
    public function codigoAcceso(array $contacto, string $codigo): void
    {
        $clienteId = (string) ($contacto['cliente_id'] ?? '');
        Idioma::con($this->idiomaContacto($contacto), function () use ($contacto, $codigo, $clienteId): void {
            $asunto = Idioma::t('Tu código de acceso: %s', $codigo);
            [$html, $texto] = $this->componer(
                $asunto,
                '',
                ['etiqueta' => Idioma::t('Acceso'), 'titulo' => Idioma::t('Tu código de acceso'), 'resaltado' => Idioma::t('código'), 'compacto' => true,
                 'preheader' => Idioma::t('Tu código es %s. Vence en 10 minutos.', $codigo),
                 'bloques' => [['p' => Idioma::t('Usa este código para entrar al portal. Vence en 10 minutos.')], ['codigo' => $codigo]]],
                Idioma::t('Hola %s,', $this->primerNombre((string) $contacto['nombre'])), '', $clienteId !== '' ? $clienteId : null,
                [Idioma::t('Si no pediste este código, puedes ignorar este correo.')]
            );
            $this->enviarCorreo((string) $contacto['email'], $asunto, $html, $texto, true);
        });
    }

    /**
     * Invitación de un contacto del cliente a su portal: qué es, cómo entrar (sin contraseña),
     * qué va a encontrar y, si ya hay algo esperándolo, su primera tarea. Sale al tiro.
     *
     * @param array<string, mixed> $contacto
     */
    public function invitacionCliente(array $contacto, string $mensaje = '', string $firma = ''): bool
    {
        // La invitación se arma en el idioma del contacto (el suyo, si no el de su cliente, si no el de la agencia).
        [$asunto, $html, $texto] = Idioma::con($this->idiomaContacto($contacto), fn(): array => $this->armarInvitacionCliente($contacto, $mensaje, $firma));
        return $this->enviarCorreo((string) $contacto['email'], $asunto, $html, $texto, false);
    }

    /**
     * La misma invitación, pero programada: sale en $horas horas (respetando el horario hábil del contacto), salvo que
     * antes se envíe a mano o se cancele. Devuelve cuándo sale (UTC, «Y-m-d H:i:s»).
     *
     * @param array<string, mixed> $contacto
     */
    public function programarInvitacionCliente(array $contacto, int $horas): string
    {
        [$asunto, $html, $texto] = Idioma::con($this->idiomaContacto($contacto), fn(): array => $this->armarInvitacionCliente($contacto, '', ''));
        $clienteId = (string) $contacto['cliente_id'];
        $cuando = $this->cuandoEnviar($clienteId, false, (string) $contacto['id'], $this->ahoraUtc()->modify('+' . max(0, $horas) . ' hours'));
        $this->encolar($clienteId, (string) $contacto['id'], (string) $contacto['email'], $asunto, $texto, $html, $cuando, null, Vigencia::invitacion((string) $contacto['id']));
        return $cuando->format('Y-m-d H:i:s');
    }

    /** La invitación programada de un contacto que todavía no sale: [cuando (UTC), id en la cola] o null. @return array{cuando: string, id: string}|null */
    public function invitacionProgramada(string $contactoId): ?array
    {
        try {
            Schema::asegurar($this->pdo);
            $st = $this->pdo->prepare('SELECT id, enviar_desde FROM ' . self::COLA . " WHERE contacto_id = ? AND vigencia = ? AND estado IN ('pendiente', 'enviando') ORDER BY enviar_desde LIMIT 1");
            $st->execute([$contactoId, Vigencia::invitacion($contactoId)]);
            $f = $st->fetch();
            return $f !== false ? ['cuando' => (string) $f['enviar_desde'], 'id' => (string) $f['id']] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function cancelarInvitacionProgramada(string $contactoId): void
    {
        try {
            $this->pdo->prepare('DELETE FROM ' . self::COLA . " WHERE contacto_id = ? AND vigencia = ? AND estado = 'pendiente'")
                ->execute([$contactoId, Vigencia::invitacion($contactoId)]);
        } catch (\Throwable) {
        }
    }

    /** @param array<string, mixed> $contacto @return array{0: string, 1: string, 2: string} asunto, html, texto */
    private function armarInvitacionCliente(array $contacto, string $mensaje, string $firma): array
    {
        $clienteId = (string) $contacto['cliente_id'];
        $agencia = $this->marca()->nombreEquipo();
        $email = (string) $contacto['email'];

        // La primera tarea que le toca (si hay): la más próxima a vencer.
        $st = $this->pdo->prepare(
            "SELECT t.titulo, t.fecha_vencimiento, t.tipo FROM portal_tareas t JOIN portal_proyectos p ON p.id = t.proyecto_id
             WHERE p.cliente_id = ? AND t.responsable_tipo = 'cliente' AND t.estado IN ('pendiente', 'en_progreso')
               AND (t.responsable_contacto_id IS NULL OR t.responsable_contacto_id = ?)
             ORDER BY (t.fecha_vencimiento IS NULL), t.fecha_vencimiento, t.created_at LIMIT 1"
        );
        $st->execute([$clienteId, (string) $contacto['id']]);
        $tarea = $st->fetch() ?: null;

        $bloques = [];
        if (trim($mensaje) !== '') {
            $bloques[] = ['cita' => mb_substr(trim($mensaje), 0, 1500) . ($firma !== '' ? "\n— " . $firma : '')];
        }
        $bloques[] = ['p' => Idioma::t('Te abrimos un portal para trabajar juntos: ahí ves en qué va tu proyecto, lo que necesitamos de ti y lo que tenemos listo para que revises. Todo en un solo lugar, sin perseguir correos.')];
        if ($tarea) {
            $fmt = new Fmt();
            $bloques[] = ['p' => Idioma::t('Ya tienes algo esperándote:')];
            $bloques[] = ['tarjetas' => [['titulo' => (string) $tarea['titulo'], 'detalle' => $fmt->tipo((string) $tarea['tipo']) . ($tarea['fecha_vencimiento'] ? ' · ' . Idioma::t('para el %s', $fmt->fecha((string) $tarea['fecha_vencimiento'])) : '')]]];
        }
        $bloques[] = ['p' => Idioma::t('Lo que vas a encontrar:')];
        $bloques[] = ['lista' => [
            Idioma::t('Tareas: lo que te toca a ti (enviar un archivo, revisar algo) y lo que está haciendo el equipo.'),
            Idioma::t('Revisiones: piezas y contenidos para aprobar o pedir cambios con un clic.'),
            Idioma::t('Reuniones: la próxima, con su enlace, y el resumen de las anteriores.'),
            Idioma::t('Solicitudes: para pedirnos algo nuevo, un presupuesto, una reunión o avisarnos de un problema.'),
        ]];
        $bloques[] = ['p' => Idioma::t('Para entrar no necesitas contraseña: escribe tu correo y te llega un código de 6 dígitos. Dentro del portal, en «¿Cómo funciona?», está todo explicado en dos minutos.')];

        $asunto = Idioma::t('%s te invita a tu portal de proyecto', $agencia);
        [$html, $texto] = $this->componer(
            $asunto, '',
            ['etiqueta' => Idioma::t('Invitación'), 'titulo' => Idioma::t('Tu portal con %s', $agencia), 'resaltado' => Idioma::t('portal'), 'boton' => Idioma::t('Entrar a mi portal'),
                'bloques' => $bloques, 'preheader' => Idioma::t('Aquí vas a ver tu proyecto, lo que te toca y lo que está listo para revisar.')],
            Idioma::t('Hola %s,', $this->primerNombre((string) $contacto['nombre'])),
            $this->absoluta('/login?email=' . rawurlencode($email)), $clienteId,
            [Idioma::t('Entras siempre con este correo: %s', $email), Idioma::t('Puedes elegir qué avisos recibir en Ajustes, dentro del portal.')]
        );
        return [$asunto, $html, $texto];
    }

    /** Invitación a un usuario de agencia: cómo entrar al panel de equipo. */
    public function invitacionEquipo(array $usuario): bool
    {
        return (bool) Idioma::con($this->idiomaEquipo($usuario), function () use ($usuario): bool {
            $url = $this->absoluta('/equipo/entrar');
            $asunto = Idioma::t('Te dimos acceso al panel de equipo');
            [$html, $texto] = $this->componer(
                $asunto,
                Idioma::t('Desde el panel verás los clientes y proyectos que tienes asignados: tareas, contenidos para revisión y reuniones.') . "\n\n"
                    . Idioma::t('Para entrar no necesitas contraseña: escribe tu correo y te enviaremos un código.'),
                ['etiqueta' => Idioma::t('Equipo'), 'titulo' => Idioma::t('Bienvenido al panel de equipo'), 'resaltado' => Idioma::t('equipo'), 'boton' => Idioma::t('Entrar al panel')],
                Idioma::t('Hola %s,', $this->primerNombre((string) $usuario['nombre'])), $url, null,
                [Idioma::t('Entras siempre con este correo: %s', (string) $usuario['email'])]
            );
            return $this->enviarCorreo((string) $usuario['email'], $asunto, $html, $texto, false);
        });
    }

    /** Código de acceso de un usuario de agencia (front /equipo). Sale al tiro, con la marca de la agencia. */
    public function codigoAccesoEquipo(array $usuario, string $codigo): void
    {
        Idioma::con($this->idiomaEquipo($usuario), function () use ($usuario, $codigo): void {
            $asunto = Idioma::t('Tu código para el panel de equipo: %s', $codigo);
            [$html, $texto] = $this->componer(
                $asunto,
                '',
                ['etiqueta' => Idioma::t('Equipo'), 'titulo' => Idioma::t('Tu código de acceso'), 'resaltado' => Idioma::t('código'), 'compacto' => true,
                 'preheader' => Idioma::t('Tu código es %s. Vence en 10 minutos.', $codigo),
                 'bloques' => [['p' => Idioma::t('Usa este código para entrar al panel de equipo. Vence en 10 minutos.')], ['codigo' => $codigo]]],
                Idioma::t('Hola %s,', $this->primerNombre((string) $usuario['nombre'])), '', null,
                [Idioma::t('Si no pediste este código, puedes ignorar este correo.')]
            );
            $this->enviarCorreo((string) $usuario['email'], $asunto, $html, $texto, true);
        });
    }

    /** Idioma en que se escribe un correo para este contacto (el suyo, el de su cliente o el de la agencia). @param array<string, mixed> $contacto */
    public function idiomaContacto(array $contacto): string
    {
        return Idioma::de($this->ajustes(), 'contacto', (string) ($contacto['id'] ?? ''), (string) ($contacto['cliente_id'] ?? ''));
    }

    /** Idioma de una persona del equipo. @param array<string, mixed> $usuario */
    public function idiomaEquipo(array $usuario): string
    {
        return Idioma::de($this->ajustes(), 'equipo', (string) ($usuario['id'] ?? ''));
    }

    public function primerNombre(string $n): string
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

    /** País de una persona del cliente: el suyo si vive en otro país, si no el del cliente. */
    public function paisDeContacto(?string $contactoId, string $clienteId): string
    {
        return Zona::paisPersona($this->pdo, $clienteId, $contactoId);
    }

    /** Instante (UTC) desde el que se puede enviar a este cliente (o a esa persona, si vive en otro país). */
    public function cuandoEnviar(string $clienteId, bool $inmediato = false, ?string $contactoId = null, ?\DateTimeImmutable $desde = null): \DateTimeImmutable
    {
        $ahora = $desde ?? $this->ahoraUtc();
        [$respetar, $ini, $fin] = $this->horario();
        if ($inmediato || !$respetar) {
            return $ahora;
        }
        return HorarioHabil::proximoEnvio($ahora, $this->paisDeContacto($contactoId, $clienteId), $ini, $fin)->setTimezone(new \DateTimeZone('UTC'));
    }

    // ---- Cola ----------------------------------------------------------------------------

    private function encolar(string $clienteId, string $contactoId, string $to, string $asunto, string $texto, string $html, \DateTimeImmutable $cuando, ?string $ics = null, ?string $vigencia = null): void
    {
        Schema::asegurar($this->pdo);
        $this->pdo->prepare(
            'INSERT INTO ' . self::COLA . ' (id, cliente_id, contacto_id, destino, asunto, texto, html, ics, vigencia, enviar_desde, estado, intentos, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)'
        )->execute([typedock_uuid7(), $clienteId, $contactoId, $to, mb_substr($asunto, 0, 500), $texto, $html, $ics, $vigencia, $cuando->format('Y-m-d H:i:s'), 'pendiente', $this->ahoraUtc()->format('Y-m-d H:i:s')]);
    }

    /**
     * Un correo ya armado para un contacto del cliente: sale ahora si está en su horario hábil
     * (o si es inmediato); si no, espera en la cola.
     *
     * @param array<string, mixed> $contacto id, email
     */
    public function aContacto(array $contacto, string $clienteId, string $asunto, string $html, string $texto, bool $inmediato = false, ?string $ics = null, ?string $vigencia = null): void
    {
        $cuando = $this->cuandoEnviar($clienteId, $inmediato, isset($contacto['id']) ? (string) $contacto['id'] : null);
        if ($cuando <= $this->ahoraUtc()) {
            $this->enviarCorreo((string) $contacto['email'], $asunto, $html, $texto, true, $ics);
        } else {
            $this->encolar($clienteId, (string) $contacto['id'], (string) $contacto['email'], $asunto, $texto, $html, $cuando, $ics, $vigencia);
        }
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
            // Si lo que avisaba ya se resolvió (la tarea se completó, el contenido se aprobó…), no sale.
            if (!Vigencia::sigue($this->pdo, ($f['vigencia'] ?? null) ?: null)) {
                $this->pdo->prepare('UPDATE ' . self::COLA . " SET estado = 'descartado', error = ? WHERE id = ? AND estado = 'pendiente'")
                    ->execute(['Ya no hacía falta: se resolvió antes de enviarlo.', $f['id']]);
                continue;
            }
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
        $ok = $this->enviarCorreo((string) $f['destino'], (string) $f['asunto'], (string) $f['html'], (string) $f['texto'], true, ($f['ics'] ?? '') !== '' ? (string) $f['ics'] : null);
        if ($ok) {
            $this->pdo->prepare('UPDATE ' . self::COLA . " SET estado = 'enviado', enviado_en = ?, error = NULL WHERE id = ?")->execute([$ahora->format('Y-m-d H:i:s'), $f['id']]);
            if (str_starts_with((string) ($f['vigencia'] ?? ''), 'invitacion:')) {
                (new ContactoService($this->pdo))->marcarInvitado(substr((string) $f['vigencia'], 11));
            }
            return true;
        }
        if ($this->topeCorreo) {
            // Tope diario: no es un fallo del servidor, así que no gasta intentos; vuelve mañana temprano.
            $manana = $ahora->modify('tomorrow')->modify('+5 minutes')->format('Y-m-d H:i:s');
            $this->pdo->prepare('UPDATE ' . self::COLA . " SET estado = 'pendiente', intentos = intentos - 1, enviar_desde = ? WHERE id = ?")->execute([$manana, $f['id']]);
            return false;
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

    // ---- Correo de Prisma (compartido) vs correo propio -------------------------------------

    /** Se pasó el tope diario del correo de Prisma: el envío queda en cola para mañana. */
    private bool $topeCorreo = false;

    /**
     * Con qué correo sale lo de esta agencia: 'propio' (su SMTP), 'prisma' (el de la beta) o '' (ninguno).
     * Ajuste «correo_origen»: 'propio' | 'prisma' | '' (automático: el propio si lo configuró, si no el de Prisma).
     * Si la agencia eligió el propio y no está completo NO se usa el de Prisma: no se manda con una identidad que no eligió.
     */
    public function origenCorreo(): string
    {
        $pref = $this->ajustes()->get('global', 'portal', 'correo_origen');
        $propio = $this->smtpConfig() !== null;
        $prisma = Compartido::correo() !== null;
        if ($pref === 'propio') {
            return $propio ? 'propio' : '';
        }
        if ($pref === 'prisma') {
            return $prisma ? 'prisma' : ($propio ? 'propio' : '');
        }
        return $propio ? 'propio' : ($prisma ? 'prisma' : '');
    }

    /**
     * @return array{cfg: array<string, mixed>, origen: string, nombre: string, responder: ?string}|null
     */
    private function configEnvio(): ?array
    {
        $origen = $this->origenCorreo();
        $copia = trim($this->ajustes()->get('global', 'portal', 'email_avisos'));
        $copia = filter_var($copia, FILTER_VALIDATE_EMAIL) ? $copia : null;
        if ($origen === 'prisma') {
            $c = Compartido::correo();
            return $c === null ? null : ['cfg' => $c, 'origen' => 'prisma', 'nombre' => $this->marca()->nombreEquipo() . ' vía Prisma', 'responder' => $copia];
        }
        $c = $origen === 'propio' ? $this->smtpConfig() : null;
        return $c === null || $c['desde'] === '' ? null : ['cfg' => $c, 'origen' => 'propio', 'nombre' => $this->marca()->nombreEquipo(), 'responder' => $copia];
    }

    /** Tope diario de correos del correo de Prisma (por agencia). */
    public function topeDiarioPrisma(): int
    {
        return Compartido::correo()['tope'] ?? 0;
    }

    /** Correos enviados hoy (UTC) por el correo de Prisma. */
    public function enviadosHoyPrisma(): int
    {
        $v = $this->ajustes()->get('global', 'portal', 'correo_dia');
        [$dia, $n] = array_pad(explode(':', $v, 2), 2, '0');
        return $dia === $this->ahoraUtc()->format('Y-m-d') ? max(0, (int) $n) : 0;
    }

    private function contarEnvioPrisma(): void
    {
        $this->ajustes()->set('global', 'portal', 'correo_dia', $this->ahoraUtc()->format('Y-m-d') . ':' . ($this->enviadosHoyPrisma() + 1));
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
    public function enviarCorreo(string $to, string $asunto, string $html, string $texto, bool $silencioso = true, ?string $ics = null): bool
    {
        $modo = $this->modo();
        $this->topeCorreo = false;
        if (in_array($modo, ['auto', 'smtp'], true) && ($env = $this->configEnvio()) !== null) {
            if ($env['origen'] === 'prisma' && $this->enviadosHoyPrisma() >= $env['cfg']['tope']) {
                $this->topeCorreo = true;   // mañana sale solo desde la cola
                $this->ajustes()->set('global', 'portal', 'smtp_ultimo_error', 'Se alcanzó el tope diario del correo de Prisma (' . $env['cfg']['tope'] . '). Lo pendiente sale mañana.');
                return false;
            }
            try {
                $this->clienteSmtp($env['cfg'])->enviar($env['cfg']['desde'], $env['nombre'], $to, $asunto, $html, $texto, $env['responder'], $ics);
                if ($env['origen'] === 'prisma') {
                    $this->contarEnvioPrisma();
                }
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

    /** Correo que firma las invitaciones de calendario (el remitente SMTP o, si no hay, el de copia de avisos). */
    public function correoOrganizador(): string
    {
        $env = $this->configEnvio();
        $c = $env !== null ? $env['cfg']['desde'] : trim($this->ajustes()->get('global', 'portal', 'email_avisos'));
        return filter_var($c, FILTER_VALIDATE_EMAIL) ? $c : 'no-responder@' . preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
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

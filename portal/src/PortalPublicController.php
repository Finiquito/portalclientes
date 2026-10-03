<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

use TypeDock\Core\PluginContext;

/**
 * Portal público del cliente: login sin contraseña (código por correo),
 * inicio, tareas con comentarios/archivos/aprobación, archivos, reuniones
 * y ajustes personales.
 *
 * Reglas de acceso:
 *  - Todo se filtra por el cliente_id del contacto en sesión. Nunca se confía
 *    en un id de la URL sin comprobar que pertenece a ese cliente.
 *  - rol "viewer": ve y comenta. rol "aprobador": además sube archivos,
 *    completa tareas y aprueba / pide cambios.
 *  - Todo POST exige token CSRF de sesión.
 */
class PortalPublicController
{
    /** Una tarea es visible para el cliente si no está oculta o si se la asignaron a él. */
    private const VISIBLE = "(t.visible_cliente = 1 OR t.responsable_tipo = 'cliente')";

    private const FRASES_BASE = [
        'Todo el avance de tu proyecto, en un solo lugar.',
        'Revisa lo pendiente, comenta y sube tus archivos.',
        'Gracias por trabajar con nosotros, {nombre}.',
    ];

    /** @var array<string, string> */
    private static array $assets = [];

    public function __construct(protected readonly PluginContext $ctx) {}

    // ---------------------------------------------------------------------
    // Infraestructura
    // ---------------------------------------------------------------------

    protected function publicUrl(string $path = '', array $query = []): string
    {
        $url = match ($path) {
            ''          => '/portal',
            'login'     => '/login',
            'verificar' => '/verificar',
            'logout'    => '/logout',
            default     => '/' . ltrim($path, '/'),
        };
        return $query === [] ? $url : $url . '?' . http_build_query($query);
    }

    protected function redirectTo(string $path, array $query = []): void
    {
        $url = $this->publicUrl($path, $query);
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
            // Subida con barra de progreso: el JS navega él mismo (así el mensaje flash
            // no se consume en una redirección seguida por XHR).
            header('Content-Type: application/json');
            echo json_encode(['redirect' => $url]);
            $this->terminate();
            return;
        }
        \Flight::redirect($url);
        $this->terminate();
    }

    /** Punto único de salida para poder probarlo sin matar el proceso. */
    protected function terminate(): void
    {
        exit;
    }

    protected function pdo(): \PDO
    {
        return $this->ctx->db()->pdo();
    }

    protected function contactos(): ContactoService
    {
        return new ContactoService($this->pdo());
    }

    private function auth(): ContactoAuthService
    {
        return new ContactoAuthService($this->pdo());
    }

    protected function ajustes(): AjustesService
    {
        return new AjustesService($this->pdo());
    }

    protected function archivos(): ArchivoService
    {
        return new ArchivoService($this->pdo());
    }

    protected function comentarios(): ComentarioService
    {
        return new ComentarioService($this->pdo());
    }

    protected function actividad(): ActividadService
    {
        return new ActividadService($this->pdo());
    }

    protected function notificador(): Notifier
    {
        return new Notifier($this->ctx, $this->pdo());
    }

    protected function marca(): MarcaService
    {
        return new MarcaService($this->pdo());
    }

    /** Logo del equipo para los correos (público: los clientes de correo no tienen sesión). */
    public function marcaAgencia(): void
    {
        if (!$this->marca()->enviarLogoAgencia()) {
            http_response_code(404);
            echo 'No encontrado.';
        }
        $this->terminate();
    }

    public function marcaCliente(string $id): void
    {
        if (!$this->marca()->enviarLogoCliente($id)) {
            http_response_code(404);
            echo 'No encontrado.';
        }
        $this->terminate();
    }

    /**
     * Tarea programada (cron, cada 15 minutos): GET /portal/cron/correos?k=CLAVE envía lo que ya llegó a su
     * horario, los avisos agrupados, el resumen de la mañana, los vencimientos y los recordatorios.
     */
    public function cronCorreos(): void
    {
        $esperada = (string) $this->ajustes()->get('global', 'portal', 'cron_token');
        $k = (string) ($_GET['k'] ?? '');
        header('Content-Type: text/plain; charset=utf-8');
        if ($esperada === '' || !hash_equals($esperada, $k)) {
            http_response_code(403);
            echo 'Clave incorrecta.';
        } else {
            $aj = $this->ajustes();
            $aj->set('global', 'portal', 'cron_ultimo', (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'));
            $r = (new Avisos($this->ctx, $this->pdo(), $this->notificador()))->correr();
            echo 'ok ' . $this->notificador()->vaciarCola(50)
                . ' · agrupados ' . $r['barrer'] . ' · resúmenes ' . $r['resumenes'] . ' · vencimientos ' . $r['vencimientos'] . ' · recordatorios ' . $r['recordatorios'];
        }
        $this->terminate();
    }

    /**
     * Reuniones en la hora del cliente: se guardan en la de la agencia.
     *
     * @param array<int, array<string, mixed>> $filas
     * @return array<int, array<string, mixed>>
     */
    protected function enHoraDelCliente(array $filas, string $clienteId): array
    {
        $pais = (new SolicitudService($this->pdo()))->paisCliente($clienteId);
        foreach ($filas as &$f) {
            foreach (['fecha', 'prox_fecha'] as $k) {
                if (isset($f[$k]) && $f[$k] !== null) {
                    $f[$k] = Zona::aPais((string) $f[$k], $pais);
                }
            }
        }
        return $filas;
    }

    /** @param array<int, mixed> $params */
    protected function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @param array<int, mixed> $params */
    protected function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        $r = $stmt->fetch();
        return $r !== false ? $r : null;
    }

    /** @param array<int, string> $ids */
    protected function placeholders(array $ids): string
    {
        return implode(',', array_fill(0, count($ids), '?'));
    }

    protected function maxMb(): int
    {
        return max(1, (int) $this->ajustes()->get('global', 'portal', 'max_mb', '20'));
    }

    /** @return array<string, mixed>|null */
    protected function currentContacto(): ?array
    {
        PortalSession::iniciar();
        $id = $_SESSION[PortalSession::CONTACTO] ?? null;
        return is_string($id) ? $this->contactos()->find($id) : null;
    }

    /** @return array<string, mixed> */
    protected function requerirContacto(): array
    {
        $c = $this->currentContacto();
        if ($c === null) {
            $this->redirectTo('login');
            throw new \LogicException('redirectTo() debe terminar la request');
        }
        return $c;
    }

    protected function esColaborador(array $contacto): bool
    {
        return ($contacto['rol'] ?? 'viewer') === 'aprobador';
    }

    /** Corta la request si el token CSRF no coincide. */
    protected function exigirCsrf(string $volverA): void
    {
        if (PortalSession::vistaPrevia() !== null) {
            PortalSession::flash('error', 'Estás en vista previa: aquí puedes mirar todo, pero no enviar ni cambiar nada.');
            $this->redirectTo($volverA);
            return;
        }
        if (!PortalSession::csrfValido()) {
            PortalSession::flash('error', 'Tu sesión expiró. Vuelve a intentarlo.');
            $this->redirectTo($volverA);
        }
    }

    protected function tomarString(string $clave, int $max = 4000): string
    {
        return mb_substr(trim((string) ($_POST[$clave] ?? '')), 0, $max);
    }

    /**
     * Variables comunes a todas las páginas con el marco del portal
     * (sidebar, marca del cliente, tema, avisos).
     *
     * @param array<string, mixed> $contacto
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    protected function contexto(array $contacto, string $nav, array $extra = []): array
    {
        $clienteId = (string) $contacto['cliente_id'];
        // Aprovecha la visita para enviar los correos que ya llegaron a su horario hábil.
        try {
            $this->notificador()->vaciarCola();
            (new Avisos($this->ctx, $this->pdo(), $this->notificador()))->correrSiToca();
        } catch (\Throwable) {
            // nunca romper una página por un correo
        }
        $cliente   = $this->fetchOne('SELECT * FROM portal_clientes WHERE id = ?', [$clienteId]) ?? ['nombre' => '', 'empresa' => ''];
        $cfg       = $this->ajustes()->todos('cliente', $clienteId);
        $pref      = $this->ajustes()->todos('contacto', (string) $contacto['id']);

        $color = AjustesService::colorValido($cfg['color'] ?? '');
        $tema  = in_array($pref['tema'] ?? '', ['claro', 'oscuro'], true) ? $pref['tema'] : 'auto';

        $pendientes = (int) ($this->fetchOne(
            "SELECT COUNT(*) AS n FROM portal_tareas t JOIN portal_proyectos p ON p.id = t.proyecto_id
             WHERE p.cliente_id = ? AND t.responsable_tipo = 'cliente' AND t.estado IN ('pendiente', 'en_progreso')",
            [$clienteId]
        )['n'] ?? 0);

        $titulo = trim($cfg['titulo'] ?? '');
        if ($titulo === '') {
            $titulo = trim((string) ($cliente['empresa'] ?: $cliente['nombre'])) ?: 'Portal de clientes';
        }

        return [
            'fmt'           => new Fmt(),
            'colorProy'     => Fmt::coloresProyectos($this->fetchAll('SELECT id FROM portal_proyectos WHERE cliente_id = ? ORDER BY created_at, id', [$clienteId]), (string) $color),
            'contacto'      => $contacto,
            'cliente'       => $cliente,
            'marca'         => [
                'color'  => $color,
                'texto'  => AjustesService::colorTexto($color),
                'titulo' => $titulo,
                'logo'   => !empty($cfg['logo_id']) ? '/portal/archivo/' . $cfg['logo_id'] . '?i=1' : null,
            ],
            'tema'          => $tema,
            'csrf'          => PortalSession::csrf(),
            'flash'         => PortalSession::tomarFlash(),
            'nav'           => $nav,
            'pendientes'    => $pendientes,
            'porRevisar'    => (new EntregaService($this->pdo()))->pendientesDelCliente($clienteId),
            'proximasReuniones' => count(array_filter($this->fetchAll(
                'SELECT r.fecha, r.duracion_min, r.resumen, r.analisis FROM portal_reuniones r JOIN portal_proyectos p ON p.id = r.proyecto_id
                 WHERE p.cliente_id = ? AND r.publicada = 1 AND r.fecha >= ?',
                [$clienteId, (new \DateTimeImmutable('now', new \DateTimeZone(Zona::agencia())))->format('Y-m-d')]
            ), fn(array $r): bool => ReunionService::estado($r) === 'proxima')),
            'solicitudesCliente' => (int) ($this->fetchOne("SELECT COUNT(*) AS n FROM portal_solicitudes WHERE cliente_id = ? AND estado = 'cotizada'", [$clienteId])['n'] ?? 0),
            'paisCliente'   => HorarioHabil::paisValido((string) ($cliente['pais'] ?? '')),
            'vistaPrevia'   => PortalSession::vistaPrevia(),
            'hayRevisiones' => (int) ($this->fetchOne("SELECT COUNT(*) AS n FROM portal_entregas WHERE cliente_id = ? AND estado <> 'borrador'", [$clienteId])['n'] ?? 0) > 0,
            'puedeColaborar' => $this->esColaborador($contacto),
            'maxMb'         => (int) round($this->archivos()->limiteBytes($this->maxMb()) / 1048576),
            'portalBoot'    => $this->asset('theme.js'),
            'portalJs'      => $this->asset('portal.js'),
        ] + $extra;
    }

    /**
     * El JS va inline en la página (variable + |noescape): así no dependemos de
     * que el Core sirva archivos .js desde /plugins/portal/assets/ (el .css ya
     * se sirve, pero no está confirmado para otras extensiones).
     */
    protected function asset(string $nombre): string
    {
        if (!isset(self::$assets[$nombre])) {
            $f = dirname(__DIR__) . '/assets/' . $nombre;
            self::$assets[$nombre] = is_file($f) ? (string) file_get_contents($f) : '';
        }
        return self::$assets[$nombre];
    }

    // ---------------------------------------------------------------------
    // Login
    // ---------------------------------------------------------------------

    public function loginForm(): void
    {
        $email = trim((string) ($_GET['email'] ?? ''));
        $this->ctx->view('templates/public/login.latte', [
            'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '',
            'error' => $_GET['error'] ?? null,
            'csrf'  => PortalSession::csrf(),
            'portalBoot' => $this->asset('theme.js'),
            'portalJs' => $this->asset('portal.js'),
        ]);
    }

    public function requestCode(): void
    {
        if (!PortalSession::csrfValido()) {
            $this->redirectTo('login', ['error' => 'sesion']);
            return;
        }

        $email    = trim(strtolower((string) ($_POST['email'] ?? '')));
        $contacto = $this->contactos()->findByEmail($email);

        // No revelamos si el email existe ni si se envió: la respuesta es siempre la misma.
        if ($contacto !== null && !$this->auth()->demasiadosCodigos($contacto['id'])) {
            $codigo = $this->auth()->generarCodigo($contacto['id']);
            try {
                $this->notificador()->codigoAcceso($contacto, $codigo);
            } catch (\Throwable) {
                // Si el SMTP falla igual mostramos "revisa tu correo"; el código expira solo.
            }
        }

        PortalSession::iniciar();
        $_SESSION['portal_login_email'] = $email;

        $this->redirectTo('verificar');
    }

    public function verifyForm(): void
    {
        PortalSession::iniciar();
        $email = $_SESSION['portal_login_email'] ?? null;
        if ($email === null) {
            $this->redirectTo('login');
            return;
        }
        $this->ctx->view('templates/public/verificar.latte', [
            'email' => $email,
            'error' => $_GET['error'] ?? null,
            'csrf'  => PortalSession::csrf(),
            'portalBoot' => $this->asset('theme.js'),
            'portalJs' => $this->asset('portal.js'),
        ]);
    }

    public function verifyCode(): void
    {
        PortalSession::iniciar();
        $email = $_SESSION['portal_login_email'] ?? null;
        if ($email === null) {
            $this->redirectTo('login');
            return;
        }
        if (!PortalSession::csrfValido()) {
            $this->redirectTo('verificar', ['error' => 'sesion']);
            return;
        }

        $contacto = $this->contactos()->findByEmail((string) $email);
        $codigo   = preg_replace('/\D+/', '', (string) ($_POST['codigo'] ?? '')) ?? '';

        if ($contacto !== null && $this->auth()->bloqueado($contacto['id'])) {
            $this->redirectTo('verificar', ['error' => 'bloqueado']);
            return;
        }
        if ($contacto === null || !$this->auth()->verificarCodigo($contacto['id'], $codigo)) {
            $this->redirectTo('verificar', ['error' => '1']);
            return;
        }

        // Nueva sesión al autenticarse (evita fijación de sesión).
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_regenerate_id(true);
        }
        unset($_SESSION['portal_login_email']);
        $_SESSION[PortalSession::CONTACTO] = $contacto['id'];
        try {
            $this->contactos()->marcarAcceso((string) $contacto['id']);
        } catch (\Throwable) {
            // sin las columnas nuevas todavía: no impide entrar
        }

        $this->redirectTo('');
    }

    /** Sale de «Ver como cliente» y vuelve a la ficha desde donde se entró. */
    public function salirVistaPrevia(): void
    {
        $v = PortalSession::vistaPrevia();
        unset($_SESSION[PortalSession::CONTACTO], $_SESSION[PortalSession::VISTA]);
        $volver = $v !== null && preg_match('#^/(equipo|admin)[A-Za-z0-9/_\-]*$#', $v['volver']) === 1 ? $v['volver'] : '/equipo';
        $this->redirectTo($volver);
    }

    public function logout(): void
    {
        PortalSession::iniciar();
        if (PortalSession::vistaPrevia() !== null) {
            $this->salirVistaPrevia();
            return;
        }
        unset($_SESSION[PortalSession::CONTACTO]);
        $this->redirectTo('login');
    }

    // ---------------------------------------------------------------------
    // Inicio
    // ---------------------------------------------------------------------

    public function dashboard(): void
    {
        $c         = $this->requerirContacto();
        $clienteId = (string) $c['cliente_id'];
        $fmt       = new Fmt();

        $proyectos = $this->fetchAll('SELECT * FROM portal_proyectos WHERE cliente_id = ? ORDER BY nombre', [$clienteId]);
        $ids       = array_column($proyectos, 'id');

        $tareas = $this->tareasDelCliente($clienteId);
        $fases  = $ids === [] ? [] : $this->fetchAll(
            'SELECT * FROM portal_fases WHERE proyecto_id IN (' . $this->placeholders($ids) . ') ORDER BY orden, nombre', $ids
        );

        // Proyecto seleccionado para el bloque de avance (?p=...).
        $sel = $proyectos[0] ?? null;
        foreach ($proyectos as $p) {
            if ($p['id'] === ($_GET['p'] ?? null)) {
                $sel = $p;
            }
        }
        $progreso = null;
        if ($sel !== null) {
            $progreso = ProgresoService::calcular(
                array_values(array_filter($fases, fn($f) => $f['proyecto_id'] === $sel['id'])),
                array_values(array_filter($tareas, fn($t) => $t['proyecto_id'] === $sel['id']))
            );
        }

        $meTocan    = array_values(array_filter($tareas, fn($t) => $this->turno($t) === 'cliente'));
        $enRevision = array_values(array_filter($tareas, fn($t) => $t['responsable_tipo'] === 'cliente' && $this->turno($t) === 'equipo'));

        $hoy = (new \DateTimeImmutable())->format('Y-m-d');
        $reuniones = $ids === [] ? [] : $this->fetchAll(
            'SELECT r.*, p.nombre AS proyecto_nombre FROM portal_reuniones r JOIN portal_proyectos p ON p.id = r.proyecto_id
             WHERE r.proyecto_id IN (' . $this->placeholders($ids) . ') AND r.publicada = 1 AND r.fecha >= ? ORDER BY r.fecha LIMIT 6',
            [...$ids, $hoy]
        );
        $reuniones = $this->enHoraDelCliente(array_slice(array_values(array_filter($reuniones, fn(array $r): bool => ReunionService::estado($r) === 'proxima')), 0, 3), $clienteId);

        $nArchivos = (int) ($this->fetchOne(
            "SELECT COUNT(*) AS n FROM portal_archivos a JOIN portal_tareas t ON t.id = a.entidad_id
             WHERE a.cliente_id = ? AND a.entidad_tipo = 'tarea' AND " . self::VISIBLE,
            [$clienteId]
        )['n'] ?? 0);

        $pref   = $this->ajustes()->todos('contacto', (string) $c['id']);
        $nombre = trim($pref['apodo'] ?? '') !== '' ? trim($pref['apodo']) : $fmt->primerNombre((string) $c['nombre']);

        $this->ctx->view('templates/public/inicio.latte', $this->contexto($c, 'inicio', [
            'nombre'      => $nombre,
            'frase'       => $this->frase($pref, $clienteId, $nombre, $fmt),
            'proyectos'   => $proyectos,
            'seleccion'   => $sel,
            'progreso'    => $progreso,
            'meTocan'     => $meTocan,
            'enRevision'  => $enRevision,
            'nArchivos'   => $nArchivos,
            'reuniones'   => $reuniones,
            'actividad'   => $this->actividad()->deCliente($clienteId, 8),
            'multiples'   => count($proyectos) > 1,
            'pasos'       => $this->primerosPasos($c, $pref, $meTocan),
        ]));
    }

    /** @param array<string, string> $pref */
    private function frase(array $pref, string $clienteId, string $nombre, Fmt $fmt): string
    {
        $propia = trim($pref['frase_propia'] ?? '');
        if ($propia !== '') {
            $f = $propia;
        } else {
            $lista = $this->ajustes()->frasesDeCliente($clienteId) ?: self::FRASES_BASE;
            $f = $lista[$fmt->diaDelAnio() % count($lista)];
        }
        return str_replace('{nombre}', $nombre, $f);
    }

    // ---------------------------------------------------------------------
    // Tareas
    // ---------------------------------------------------------------------

    /** @return array<array<string, mixed>> tareas visibles del cliente, con proyecto/fase/contacto */
    private function tareasDelCliente(string $clienteId): array
    {
        return $this->fetchAll(
            'SELECT t.*, p.nombre AS proyecto_nombre, f.nombre AS fase_nombre, ct.nombre AS contacto_nombre,
                    (SELECT COUNT(*) FROM portal_comentarios c WHERE c.entidad_tipo = \'tarea\' AND c.entidad_id = t.id) AS n_comentarios,
                    (SELECT COUNT(*) FROM portal_archivos a WHERE a.entidad_tipo = \'tarea\' AND a.entidad_id = t.id) AS n_archivos
             FROM portal_tareas t
             JOIN portal_proyectos p ON p.id = t.proyecto_id
             LEFT JOIN portal_fases f ON f.id = t.fase_id
             LEFT JOIN portal_contactos ct ON ct.id = t.responsable_contacto_id
             WHERE p.cliente_id = ? AND ' . self::VISIBLE . '
             ORDER BY (t.fecha_vencimiento IS NULL), t.fecha_vencimiento, t.created_at',
            [$clienteId]
        );
    }

    /** @return array<string, mixed>|null */
    private function tareaDelCliente(string $id, string $clienteId): ?array
    {
        return $this->fetchOne(
            'SELECT t.*, p.nombre AS proyecto_nombre, p.cliente_id AS cliente_id, f.nombre AS fase_nombre, ct.nombre AS contacto_nombre
             FROM portal_tareas t
             JOIN portal_proyectos p ON p.id = t.proyecto_id
             LEFT JOIN portal_fases f ON f.id = t.fase_id
             LEFT JOIN portal_contactos ct ON ct.id = t.responsable_contacto_id
             WHERE t.id = ? AND p.cliente_id = ? AND ' . self::VISIBLE,
            [$id, $clienteId]
        );
    }

    /** De quién es el turno: 'cliente', 'equipo' o 'listo'. */
    private function turno(array $t): string
    {
        if ($t['estado'] === 'hecha') {
            return 'listo';
        }
        if (in_array($t['estado'], ['entregada', 'cambios'], true)) {
            return 'equipo';
        }
        return $t['responsable_tipo'] === 'cliente' ? 'cliente' : 'equipo';
    }

    public function tareas(): void
    {
        $c     = $this->requerirContacto();
        $todas = $this->tareasDelCliente((string) $c['cliente_id']);
        $ctx   = $this->contexto($c, 'tareas');

        $grupos = [
            'mias'     => array_values(array_filter($todas, fn($t) => $this->turno($t) === 'cliente')),
            'equipo'   => array_values(array_filter($todas, fn($t) => $this->turno($t) === 'equipo')),
            'hechas'   => array_values(array_filter($todas, fn($t) => $t['estado'] === 'hecha')),
            'todas'    => $todas,
        ];
        $filtro = $_GET['f'] ?? ($grupos['mias'] !== [] ? 'mias' : 'todas');
        if (!isset($grupos[$filtro])) {
            $filtro = 'todas';
        }

        $this->ctx->view('templates/public/tareas.latte', $ctx + [
            'filtro'   => $filtro,
            'grupos'   => $grupos,
            'lista'    => $grupos[$filtro],
            'multiples' => count(array_unique(array_column($todas, 'proyecto_id'))) > 1,
        ]);
    }

    public function tarea(string $id): void
    {
        $c = $this->requerirContacto();
        $t = $this->tareaDelCliente($id, (string) $c['cliente_id']);
        if ($t === null) {
            PortalSession::flash('error', 'No encontramos esa tarea.');
            $this->redirectTo('portal/tareas');
            return;
        }

        $this->marcarPaso($c, 'tarea');
        $archivos = $this->archivos()->deEntidad('tarea', $id);
        $delEquipo = array_values(array_filter($archivos, fn($a) => $a['subido_por_tipo'] === 'equipo'));
        $tuyos     = array_values(array_filter($archivos, fn($a) => $a['subido_por_tipo'] !== 'equipo'));

        $colabora = $this->esColaborador($c);
        $abierta  = in_array($t['estado'], ['pendiente', 'en_progreso'], true);
        $suya     = $t['responsable_tipo'] === 'cliente';

        $this->ctx->view('templates/public/tarea.latte', $this->contexto($c, 'tareas') + [
            't'           => $t,
            'turno'       => $this->turno($t),
            'comentarios' => $this->comentarios()->listar('tarea', $id),
            'delEquipo'   => $delEquipo,
            'tuyos'       => $tuyos,
            'puede'       => [
                'subir'     => $colabora && $t['estado'] !== 'hecha' && $t['estado'] !== 'entregada',
                'completar' => $colabora && $suya && $t['tipo'] === 'tarea' && $abierta,
                'entregar'  => $colabora && $suya && $t['tipo'] === 'archivo' && $abierta && $tuyos !== [],
                'aprobar'   => $colabora && $suya && $t['tipo'] === 'revision' && $abierta,
            ],
            'esArchivo'   => $t['tipo'] === 'archivo',
            'abierta'     => $abierta,
        ]);
    }

    /** Acciones del cliente sobre una tarea: completar / entregar / aprobar / pedir cambios. */
    public function accionTarea(string $id): void
    {
        $c   = $this->requerirContacto();
        $vol = 'portal/tareas/' . $id;
        $this->exigirCsrf($vol);

        $t = $this->tareaDelCliente($id, (string) $c['cliente_id']);
        if ($t === null) {
            $this->redirectTo('portal/tareas');
            return;
        }
        if (!$this->esColaborador($c) || $t['responsable_tipo'] !== 'cliente') {
            PortalSession::flash('error', 'Tu acceso es de sólo lectura para esta acción.');
            $this->redirectTo($vol);
            return;
        }

        $accion  = (string) ($_POST['accion'] ?? '');
        $abierta = in_array($t['estado'], ['pendiente', 'en_progreso'], true);
        $tareas  = new TareaService($this->pdo());
        $clienteId = (string) $c['cliente_id'];
        $nombre  = (string) $c['nombre'];

        switch ($accion) {
            case 'completar':
                if (!$abierta || $t['tipo'] !== 'tarea') {
                    break;
                }
                $tareas->cambiarEstado($id, 'hecha');
                $this->actividad()->registrar($clienteId, $t['proyecto_id'], 'contacto', $nombre, 'completo', 'tarea', $id, $t['titulo']);
                $this->avisarEquipo("{$nombre} completó «{$t['titulo']}»", $t, 'Marcó la tarea como lista.', $nombre);
                PortalSession::flash('ok', '¡Listo! Marcamos la tarea como completada.');
                break;

            case 'entregar':
                $tuyos = array_filter($this->archivos()->deEntidad('tarea', $id), fn($a) => $a['subido_por_tipo'] !== 'equipo');
                if (!$abierta || $t['tipo'] !== 'archivo' || $tuyos === []) {
                    PortalSession::flash('error', 'Sube al menos un archivo antes de entregar.');
                    break;
                }
                $tareas->cambiarEstado($id, 'entregada');
                $this->actividad()->registrar($clienteId, $t['proyecto_id'], 'contacto', $nombre, 'entrego', 'tarea', $id, $t['titulo'], count($tuyos) . ' archivo(s)');
                $this->avisarEquipo("{$nombre} entregó archivos: «{$t['titulo']}»", $t, count($tuyos) . ' archivo(s) subido(s).', $nombre);
                PortalSession::flash('ok', 'Entregado. Te avisaremos si necesitamos algo más.');
                break;

            case 'aprobar':
                if (!$abierta || $t['tipo'] !== 'revision') {
                    break;
                }
                $tareas->cambiarEstado($id, 'hecha');
                $this->actividad()->registrar($clienteId, $t['proyecto_id'], 'contacto', $nombre, 'aprobo', 'tarea', $id, $t['titulo']);
                $this->avisarEquipo("{$nombre} aprobó «{$t['titulo']}»", $t, 'Aprobado por el cliente.', $nombre);
                PortalSession::flash('ok', 'Aprobado. ¡Gracias!');
                break;

            case 'cambios':
                $texto = $this->tomarString('cuerpo');
                if (!$abierta || $t['tipo'] !== 'revision') {
                    break;
                }
                if ($texto === '') {
                    PortalSession::flash('error', 'Cuéntanos qué cambiarías: el comentario es obligatorio.');
                    break;
                }
                $this->comentarios()->crear($clienteId, 'tarea', $id, 'contacto', (string) $c['id'], $nombre, $texto);
                $tareas->cambiarEstado($id, 'cambios');
                $this->actividad()->registrar($clienteId, $t['proyecto_id'], 'contacto', $nombre, 'pidio_cambios', 'tarea', $id, $t['titulo'], mb_substr($texto, 0, 200));
                $this->avisarEquipo("{$nombre} pidió cambios: «{$t['titulo']}»", $t, $texto, $nombre);
                PortalSession::flash('ok', 'Enviamos tus comentarios al equipo.');
                break;
        }

        $this->redirectTo($vol);
    }

    public function comentar(string $id): void
    {
        $c   = $this->requerirContacto();
        $vol = 'portal/tareas/' . $id;
        $this->exigirCsrf($vol);

        $t = $this->tareaDelCliente($id, (string) $c['cliente_id']);
        if ($t === null) {
            $this->redirectTo('portal/tareas');
            return;
        }

        $texto = $this->tomarString('cuerpo');
        if ($texto === '') {
            PortalSession::flash('error', 'Escribe algo antes de enviar.');
            $this->redirectTo($vol . '#conversacion');
            return;
        }

        $this->comentarios()->crear((string) $c['cliente_id'], 'tarea', $id, 'contacto', (string) $c['id'], (string) $c['nombre'], $texto);
        $this->actividad()->registrar((string) $c['cliente_id'], $t['proyecto_id'], 'contacto', (string) $c['nombre'], 'comento', 'tarea', $id, $t['titulo'], mb_substr($texto, 0, 200));
        $this->avisarEquipo("{$c['nombre']} comentó en «{$t['titulo']}»", $t, $texto, (string) $c['nombre']);

        PortalSession::flash('ok', 'Comentario enviado.');
        $this->redirectTo($vol . '#conversacion');
    }

    public function subirArchivos(string $id): void
    {
        $c   = $this->requerirContacto();
        $vol = 'portal/tareas/' . $id;

        if (ArchivoService::postExcedido()) {
            PortalSession::flash('error', 'Los archivos superan el máximo permitido (' . (int) round($this->archivos()->limiteBytes($this->maxMb()) / 1048576) . ' MB en total).');
            $this->redirectTo($vol);
            return;
        }
        $this->exigirCsrf($vol);

        $t = $this->tareaDelCliente($id, (string) $c['cliente_id']);
        if ($t === null) {
            $this->redirectTo('portal/tareas');
            return;
        }
        if (!$this->esColaborador($c) || in_array($t['estado'], ['hecha', 'entregada'], true)) {
            PortalSession::flash('error', 'No puedes subir archivos a esta tarea ahora.');
            $this->redirectTo($vol);
            return;
        }

        $lista = ArchivoService::normalizar($_FILES['archivos'] ?? null);
        if ($lista === []) {
            PortalSession::flash('error', 'Elige al menos un archivo.');
            $this->redirectTo($vol);
            return;
        }

        $r = $this->archivos()->guardarVarios(
            array_slice($lista, 0, 10), (string) $c['cliente_id'], $t['proyecto_id'], 'tarea', $id,
            ['tipo' => 'contacto', 'id' => (string) $c['id'], 'nombre' => (string) $c['nombre']],
            $this->maxMb()
        );

        $n = count($r['ok']);
        if ($n > 0) {
            if ($t['tipo'] === 'archivo' && $t['estado'] === 'pendiente') {
                (new TareaService($this->pdo()))->cambiarEstado($id, 'en_progreso');
            }
            $this->actividad()->registrar((string) $c['cliente_id'], $t['proyecto_id'], 'contacto', (string) $c['nombre'], 'subio_archivo', 'tarea', $id, $t['titulo'], $n . ' archivo(s)');
            if ($t['tipo'] !== 'archivo') {
                $this->avisarEquipo("{$c['nombre']} subió archivos en «{$t['titulo']}»", $t, $n . ' archivo(s).', (string) $c['nombre']);
            }
        }

        if ($r['errores'] !== []) {
            PortalSession::flash('error', ($n > 0 ? "Se subieron {$n}, pero: " : 'No se pudo subir: ') . implode(' · ', $r['errores']));
        } else {
            PortalSession::flash('ok', $n === 1 ? 'Archivo subido.' : "{$n} archivos subidos.");
        }
        $this->redirectTo($vol . '#archivos');
    }

    public function borrarArchivo(string $id): void
    {
        $c = $this->requerirContacto();
        $a = $this->archivos()->find($id);
        $vol = $a !== null && $a['entidad_tipo'] === 'tarea' ? 'portal/tareas/' . $a['entidad_id'] : 'portal/archivos';
        $this->exigirCsrf($vol);

        if ($a === null || $a['cliente_id'] !== $c['cliente_id'] || $a['entidad_tipo'] !== 'tarea') {
            $this->redirectTo('portal/archivos');
            return;
        }
        $t = $this->tareaDelCliente((string) $a['entidad_id'], (string) $c['cliente_id']);
        $propio = $a['subido_por_tipo'] === 'contacto' && $a['subido_por_id'] === $c['id'];
        if ($t === null || !$propio || !in_array($t['estado'], ['pendiente', 'en_progreso', 'cambios'], true)) {
            PortalSession::flash('error', 'No puedes borrar este archivo.');
            $this->redirectTo($vol);
            return;
        }

        $this->archivos()->borrar($id);
        PortalSession::flash('ok', 'Archivo eliminado.');
        $this->redirectTo($vol . '#archivos');
    }

    /** Descarga / vista de un archivo, validando que sea del cliente en sesión. */
    public function archivo(string $id): void
    {
        $c = $this->requerirContacto();
        $a = $this->archivos()->find($id);

        $permitido = false;
        if ($a !== null && $a['cliente_id'] === $c['cliente_id']) {
            if ($a['entidad_tipo'] === 'cliente_logo') {
                $permitido = true;
            } elseif ($a['entidad_tipo'] === 'tarea') {
                $permitido = $this->tareaDelCliente((string) $a['entidad_id'], (string) $c['cliente_id']) !== null;
            } elseif ($a['entidad_tipo'] === 'solicitud') {
                $permitido = (new SolicitudService($this->pdo()))->findDelCliente((string) $a['entidad_id'], (string) $c['cliente_id']) !== null;
            } elseif ($a['entidad_tipo'] === 'version') {
                // Archivos de contenidos: sólo si la entrega es de este cliente y ya no es borrador.
                $permitido = $this->fetchOne(
                    "SELECT v.id FROM portal_versiones v
                     JOIN portal_contenidos x ON x.id = v.contenido_id
                     JOIN portal_entregas e ON e.id = x.entrega_id
                     WHERE v.id = ? AND e.cliente_id = ? AND e.estado <> 'borrador'",
                    [$a['entidad_id'], $c['cliente_id']]
                ) !== null;
            }
        }
        if (!$permitido || !$this->archivos()->enviar($a, !empty($_GET['t']), !empty($_GET['i']))) {
            http_response_code(404);
            echo 'Archivo no encontrado.';
        }
        $this->terminate();
    }

    // ---------------------------------------------------------------------
    // Archivos y reuniones
    // ---------------------------------------------------------------------

    public function archivosPagina(): void
    {
        $c = $this->requerirContacto();
        $filas = $this->fetchAll(
            "SELECT a.*, t.titulo AS tarea_titulo, t.id AS tarea_id
             FROM portal_archivos a JOIN portal_tareas t ON t.id = a.entidad_id
             WHERE a.cliente_id = ? AND a.entidad_tipo = 'tarea' AND " . self::VISIBLE . '
             ORDER BY a.created_at DESC, a.id DESC',
            [$c['cliente_id']]
        );

        $this->ctx->view('templates/public/archivos.latte', $this->contexto($c, 'archivos') + [
            'delEquipo' => array_values(array_filter($filas, fn($a) => $a['subido_por_tipo'] === 'equipo')),
            'tuyos'     => array_values(array_filter($filas, fn($a) => $a['subido_por_tipo'] !== 'equipo')),
        ]);
    }

    public function reuniones(): void
    {
        $c = $this->requerirContacto();
        $todas = $this->fetchAll(
            'SELECT r.*, p.nombre AS proyecto_nombre FROM portal_reuniones r
             JOIN portal_proyectos p ON p.id = r.proyecto_id WHERE p.cliente_id = ? AND r.publicada = 1 ORDER BY r.fecha DESC',
            [$c['cliente_id']]
        );
        // El estado se calcula con la hora de la agencia (la guardada); después se pasa a la del cliente.
        $grupos = ['proxima' => [], 'pasada' => [], 'archivada' => []];
        foreach ($todas as $r) {
            $grupos[ReunionService::estado($r)][] = $r;
        }
        $cid = (string) $c['cliente_id'];
        $this->ctx->view('templates/public/reuniones.latte', $this->contexto($c, 'reuniones') + [
            'proximas'   => $this->enHoraDelCliente(array_reverse($grupos['proxima']), $cid),   // la más cercana primero
            'anteriores' => $this->enHoraDelCliente($grupos['pasada'], $cid),                   // la más reciente primero
            'archivadas' => $this->enHoraDelCliente($grupos['archivada'], $cid),
            'diasArchivo' => ReunionService::DIAS_ARCHIVO,
        ]);
    }

    /** Detalle de una reunión: Meet, resumen y acuerdos (si están publicados) y las tareas que salieron de ella. */
    public function reunion(string $id): void
    {
        $c = $this->requerirContacto();
        $svc = new ReunionService($this->pdo());
        $r = $svc->findDelCliente($id, (string) $c['cliente_id']);
        if ($r === null) {
            $this->redirectTo('portal/reuniones');
            return;
        }
        $hoy = (new \DateTimeImmutable('now', new \DateTimeZone(Zona::agencia())))->format('Y-m-d');
        $proximaR = ReunionService::estado($r) === 'proxima';
        $r = $this->enHoraDelCliente([$r], (string) $c['cliente_id'])[0];
        $verResumen = (int) $r['resumen_publicado'] === 1;
        $this->ctx->view('templates/public/reunion.latte', $this->contexto($c, 'reuniones') + [
            'r'          => $r,
            'proxima'    => $proximaR,
            'verResumen' => $verResumen,
            'acuerdos'   => $verResumen ? array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $r['acuerdos']) ?: []))) : [],
            'tareas'     => $svc->tareasVisibles($id),
            'siguiente'  => $r['prox_reunion_id'] && ($sg = $svc->findDelCliente((string) $r['prox_reunion_id'], (string) $c['cliente_id'])) !== null
                ? $this->enHoraDelCliente([$sg], (string) $c['cliente_id'])[0] : null,
            'estadosT'   => TareaService::ESTADOS,
        ]);
    }

    /** Descarga .ics (evento con el link de Meet) para agregarlo al calendario del cliente. */
    public function reunionIcs(string $id): void
    {
        $c = $this->requerirContacto();
        $svc = new ReunionService($this->pdo());
        $r = $svc->findDelCliente($id, (string) $c['cliente_id']);
        $ics = $r !== null ? $svc->ics($r, Notifier::baseUrl() . '/portal/reuniones/' . $id) : '';
        if ($ics === '') {
            $this->redirectTo('portal/reuniones');
            return;
        }
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="reunion.ics"');
        header('X-Content-Type-Options: nosniff');
        echo $ics;
        $this->terminate();
    }

    /**
     * Descarga .ics desde el enlace de una invitación por correo (firmado, sin iniciar sesión):
     * sirve para Outlook, Apple y cualquier calendario.
     */
    public function reunionIcsFirmado(string $id): void
    {
        $conv = new Convocados($this->ctx, $this->pdo());
        $svc = new ReunionService($this->pdo());
        $r = $conv->tokenValido($id, (string) ($_GET['t'] ?? '')) ? $svc->find($id) : null;
        $ics = $r !== null ? $svc->ics($r, Notifier::baseUrl() . '/portal/reuniones/' . $id) : '';
        if ($ics === '') {
            http_response_code(404);
            echo 'La reunión ya no existe o el enlace no es válido.';
            $this->terminate();
            return;
        }
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="reunion.ics"');
        header('X-Content-Type-Options: nosniff');
        echo $ics;
        $this->terminate();
    }

    // ---------------------------------------------------------------------
    // Bienvenida: «¿Cómo funciona?» y primeros pasos
    // ---------------------------------------------------------------------

    /** Primeros pasos que el contacto va completando (se guardan en sus ajustes). */
    protected function marcarPaso(array $contacto, string $paso): void
    {
        if (PortalSession::vistaPrevia() !== null) {
            return;   // mirar como el cliente no le marca sus primeros pasos
        }
        try {
            $this->ajustes()->set('contacto', (string) $contacto['id'], 'paso_' . $paso, '1');
        } catch (\Throwable) {
            // no es crítico
        }
    }

    /**
     * Tarjeta «Primeros pasos» del inicio. Null si ya la completó, la cerró o lleva más de 45 días usando el portal.
     *
     * @param array<string, mixed> $c
     * @param array<int, array<string, mixed>> $meTocan
     * @return array<int, array{0: string, 1: string, 2: bool}>|null [texto, enlace, hecho]
     */
    private function primerosPasos(array $c, array $pref, array $meTocan): ?array
    {
        if (($pref['primeros_pasos'] ?? '') === 'oculto') {
            return null;
        }
        $desde = (string) ($c['primer_acceso'] ?? '');
        if ($desde !== '' && strtotime($desde) < strtotime('-45 days')) {
            return null;
        }
        $tarea = $meTocan[0] ?? null;
        $pasos = [
            // Se marca con el primer acceso real (en «Ver como cliente» puede que la persona aún no haya entrado).
            ['Entraste a tu portal', '/portal', !empty($c['primer_acceso'])],
            [$tarea ? 'Revisa tu primera tarea: «' . $tarea['titulo'] . '»' : 'Mira en qué va tu proyecto', $tarea ? '/portal/tareas/' . $tarea['id'] : '/portal/tareas', !empty($pref['paso_tarea'])],
            ['Lee cómo funciona el portal (dos minutos)', '/portal/ayuda', !empty($pref['paso_ayuda'])],
            ['Conoce cómo pedirnos algo', '/portal/solicitudes', !empty($pref['paso_solicitudes'])],
            ['Elige qué avisos quieres recibir', '/portal/ajustes', !empty($pref['paso_avisos'])],
        ];
        foreach ($pasos as $p) {
            if (!$p[2]) {
                return $pasos;
            }
        }
        return null;
    }

    public function ayuda(): void
    {
        $c = $this->requerirContacto();
        $this->marcarPaso($c, 'ayuda');
        $this->ctx->view('templates/public/ayuda.latte', $this->contexto($c, 'ayuda') + [
            'estados'  => Fmt::ESTADOS,
            'agencia'  => (new MarcaService($this->pdo()))->nombreEquipo(),
            'maxUrgentes' => (new SolicitudService($this->pdo()))->maxUrgentes(),
        ]);
    }

    public function ocultarPrimerosPasos(): void
    {
        $c = $this->requerirContacto();
        $this->exigirCsrf('portal');
        $this->ajustes()->set('contacto', (string) $c['id'], 'primeros_pasos', 'oculto');
        $this->redirectTo('portal');
    }

    // ---------------------------------------------------------------------
    // Ajustes personales
    // ---------------------------------------------------------------------

    public function ajustesForm(): void
    {
        $c = $this->requerirContacto();
        $pref = $this->ajustes()->todos('contacto', (string) $c['id']);

        $this->ctx->view('templates/public/ajustes.latte', $this->contexto($c, 'ajustes') + [
            'pref' => [
                'tema'         => in_array($pref['tema'] ?? '', ['claro', 'oscuro'], true) ? $pref['tema'] : 'auto',
                'apodo'        => $pref['apodo'] ?? '',
                'frase_propia' => $pref['frase_propia'] ?? '',
                'avisos_email' => ($pref['avisos_email'] ?? '1') !== '0',
                'resumen_diario' => ($pref['resumen_diario'] ?? '0') === '1',
            ],
        ]);
    }

    public function ajustesGuardar(): void
    {
        $c = $this->requerirContacto();
        $this->exigirCsrf('portal/ajustes');

        $tema = (string) ($_POST['tema'] ?? 'auto');
        $this->ajustes()->setMuchos('contacto', (string) $c['id'], [
            'tema'         => in_array($tema, ['claro', 'oscuro'], true) ? $tema : 'auto',
            'apodo'        => $this->tomarString('apodo', 40),
            'frase_propia' => $this->tomarString('frase_propia', 140),
            'avisos_email' => !empty($_POST['avisos_email']) ? '1' : '0',
            'resumen_diario' => !empty($_POST['resumen_diario']) ? '1' : '0',
            'paso_avisos'  => '1',
        ]);

        PortalSession::flash('ok', 'Guardamos tus preferencias.');
        $this->redirectTo('portal/ajustes');
    }

    /** Cambio rápido de tema desde el botón del encabezado (responde 204, sin redirección). */
    public function temaRapido(): void
    {
        $c = $this->requerirContacto();
        if (PortalSession::csrfValido() && PortalSession::vistaPrevia() === null) {
            $tema = (string) ($_POST['tema'] ?? 'auto');
            $this->ajustes()->set('contacto', (string) $c['id'], 'tema', in_array($tema, ['claro', 'oscuro'], true) ? $tema : 'auto');
        }
        http_response_code(204);
        $this->terminate();
    }

    // ---------------------------------------------------------------------

    /** @param array<string, mixed> $t */
    private function avisarEquipo(string $asunto, array $t, string $detalle, string $quien = ''): void
    {
        $detalle = mb_substr(trim($detalle), 0, 800);
        $bloques = [['tarjetas' => [['titulo' => (string) $t['titulo'], 'detalle' => 'Tarea']]]];
        if ($detalle !== '') {
            $bloques[] = mb_strlen($detalle) > 60 ? ['cita' => $detalle] : ['p' => $detalle];
        }
        $this->notificador()->alEquipo($asunto, '', 'tareas/' . $t['id'], [
            'etiqueta' => 'Del cliente', 'titulo' => $asunto, 'resaltado' => $quien, 'proyecto_id' => (string) $t['proyecto_id'],
            'bloques' => $bloques, 'preheader' => $detalle !== '' ? mb_substr($detalle, 0, 110) : $asunto, 'boton' => 'Abrir la tarea',
            // A quien es responsable de la tarea; si no tiene, a quienes tienen asignado el proyecto.
            'responsable' => ($t['responsable_tipo'] ?? '') === 'equipo' ? ($t['responsable_usuario_id'] ?? null) : null,
            'clave' => 'tareas/' . $t['id'], 'detalle' => $detalle !== '' ? mb_substr($detalle, 0, 160) : (string) $t['titulo'],
        ]);
    }
}

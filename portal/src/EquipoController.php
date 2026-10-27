<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

use TypeDock\Core\PluginContext;

/**
 * Front de agencia (/equipo): el panel de trabajo del equipo, con la misma
 * gráfica que el portal del cliente pero con el color de la agencia.
 *
 * Reglas de acceso:
 *  - Sólo usuarios de portal_equipo activos, con código por correo.
 *  - Todo se filtra con EquipoAcceso: nunca se confía en un id de la URL.
 *  - Todo POST exige token CSRF de sesión.
 */
class EquipoController
{
    public const SESION = 'portal_equipo_id';

    /** Estados en los que la tarea espera al equipo. */
    private const TURNO_EQUIPO = "((t.responsable_tipo = 'equipo' AND t.estado IN ('pendiente', 'en_progreso')) OR t.estado IN ('entregada', 'cambios')) AND COALESCE(t.archivada, 0) = 0";

    /** @var array<string, string> */
    private static array $assets = [];

    public function __construct(protected readonly PluginContext $ctx) {}

    // ---------------------------------------------------------------------
    // Infraestructura
    // ---------------------------------------------------------------------

    protected function pdo(): \PDO
    {
        return $this->ctx->db()->pdo();
    }

    protected function equipo(): EquipoService
    {
        return new EquipoService($this->pdo());
    }

    protected function ajustes(): AjustesService
    {
        return new AjustesService($this->pdo());
    }

    protected function redirectTo(string $url): void
    {
        \Flight::redirect($url);
        $this->terminate();
    }

    /** Punto único de salida para poder probarlo sin matar el proceso. */
    protected function terminate(): void
    {
        exit;
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

    protected function asset(string $nombre): string
    {
        if (!isset(self::$assets[$nombre])) {
            $f = dirname(__DIR__) . '/assets/' . $nombre;
            self::$assets[$nombre] = is_file($f) ? (string) file_get_contents($f) : '';
        }
        return self::$assets[$nombre];
    }

    /** @return array<string, mixed>|null */
    protected function usuarioActual(): ?array
    {
        PortalSession::iniciar();
        $id = $_SESSION[self::SESION] ?? null;
        $u = is_string($id) ? $this->equipo()->find($id) : null;
        if ($u === null || (int) $u['activo'] !== 1) {
            unset($_SESSION[self::SESION]);
            Idioma::fijar(Idioma::visitante($this->ajustes()->get('global', 'portal', 'idioma')));
            return null;
        }
        Idioma::fijar(Idioma::de($this->ajustes(), 'equipo', (string) $u['id'], '', true));
        return $u;
    }

    /** @return array<string, mixed> */
    protected function requerirUsuario(): array
    {
        $u = $this->usuarioActual();
        if ($u === null) {
            $this->redirectTo('/equipo/entrar');
            throw new \LogicException('redirectTo() debe terminar la request');
        }
        return $u;
    }

    protected function acceso(array $u): EquipoAcceso
    {
        return new EquipoAcceso($this->pdo(), $u);
    }

    /** @return array{color: string, texto: string, tinta: string, titulo: string, logo: ?string} */
    protected function marca(): array
    {
        $cfg   = $this->ajustes()->todos('global', 'portal');
        $pal   = Paleta::marca(AjustesService::colorValido($cfg['color_agencia'] ?? ''));
        $m     = new MarcaService($this->pdo());
        return [
            'color'  => $pal['color'],
            'texto'  => $pal['texto'],
            'tinta'  => $pal['tinta'],
            'titulo' => $m->nombreEquipo(),
            'logo'   => ($r = $m->rutaLogoAgencia()) !== null ? '/portal/marca/agencia?v=' . (int) filemtime($r) : null,
        ];
    }

    /** Variables de las páginas sin sesión (entrar, verificar). @return array<string, mixed> */
    protected function contextoPublico(array $extra = []): array
    {
        Idioma::fijar(Idioma::visitante($this->ajustes()->get('global', 'portal', 'idioma')));
        return [
            'marca'      => $this->marca(),
            'favicon'    => (new MarcaService($this->pdo()))->urlIcono(),
            'csrf'       => PortalSession::csrf(),
            'portalBoot' => $this->asset('theme.js'),
            'portalJs'   => $this->asset('portal.js'),
            'fmt'        => new Fmt(),
        ] + $extra;
    }

    /**
     * Variables comunes a todas las páginas con el marco del panel.
     *
     * @param array<string, mixed> $u
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    protected function contexto(array $u, string $nav, array $extra = []): array
    {
        // Correos en espera y avisos: después de entregar la página (un SMTP lento no la deja colgada).
        $ctx = $this->ctx;
        $pdo = $this->pdo();
        Diferido::alTerminar(static function () use ($ctx, $pdo): void {
            (new Notifier($ctx, $pdo))->vaciarCola();
            (new Avisos($ctx, $pdo))->correrSiToca();
        });
        $pref = $this->ajustes()->todos('equipo', (string) $u['id']);
        $tema = in_array($pref['tema'] ?? '', ['claro', 'oscuro'], true) ? $pref['tema'] : 'auto';

        [$w, $p] = $this->acceso($u)->filtroProyecto('t.proyecto_id');
        $miTurno = (int) ($this->fetchOne(
            'SELECT COUNT(*) AS n FROM portal_tareas t WHERE ' . self::TURNO_EQUIPO . ' AND ' . $w, $p
        )['n'] ?? 0);

        [$ws, $ps] = $this->acceso($u)->filtroProyecto('s.proyecto_id');
        try {
            $nSolicitudes = (int) ($this->fetchOne(
                "SELECT COUNT(*) AS n FROM portal_solicitudes s WHERE s.estado IN ('nueva', 'aprobada') AND " . $ws, $ps
            )['n'] ?? 0);
        } catch (\Throwable) {
            $nSolicitudes = 0;
        }

        // Contactos ganados: el menú aparece si alguno de sus proyectos tiene el módulo activo.
        [$wl, $pl] = $this->acceso($u)->filtroProyecto('p.id');
        try {
            $leads = $this->fetchOne(
                "SELECT COUNT(DISTINCT p.id) AS np, COUNT(l.id) AS nl FROM portal_proyectos p LEFT JOIN portal_leads l ON l.proyecto_id = p.id AND l.estado = 'nuevo'
                 WHERE p.con_leads = 1 AND " . $wl, $pl
            ) ?? [];
        } catch (\Throwable) {
            $leads = [];
        }

        try {
            $nMensajes = MensajeService::sinLeer($this->conversaciones($u));
        } catch (\Throwable) {
            $nMensajes = 0;
        }

        return [
            'fmt'        => new Fmt(),
            'nSolicitudes' => $nSolicitudes,
            'nMensajes'  => $nMensajes,
            'favicon'    => (new MarcaService($this->pdo()))->urlIcono(),
            'hayLeads'   => (int) ($leads['np'] ?? 0) > 0,
            'nLeads'     => (int) ($leads['nl'] ?? 0),
            'relojes'    => $this->relojes($u),
            'usuario'    => $u,
            'marca'      => $this->marca(),
            'tema'       => $tema,
            'csrf'       => PortalSession::csrf(),
            'flash'      => PortalSession::tomarFlash(),
            'nav'        => $nav,
            'nTurno'     => $miTurno,
            'colorProy'  => Fmt::coloresTodos($this->pdo()),
            'portalBoot' => $this->asset('theme.js'),
            'portalJs'   => $this->asset('portal.js'),
        ] + $extra;
    }

    /**
     * Hora actual en el país de cada cliente que ve el usuario (barra lateral).
     * Sólo se muestra si hay algún cliente en otra zona horaria que la de la agencia.
     *
     * @return array<int, array{pais: string, nombre: string, zona: string, hora: string, clientes: string, agencia: bool}>
     */
    protected function relojes(array $u): array
    {
        [$w, $p] = $this->acceso($u)->filtroCliente('id');
        try {
            $filas = $this->fetchAll('SELECT pais, nombre FROM portal_clientes WHERE ' . $w . ' ORDER BY nombre', $p);
        } catch (\Throwable) {
            return [];
        }
        // Personas que viven en otro país que su empresa (por ejemplo, una socia en China).
        try {
            [$w2, $p2] = $this->acceso($u)->filtroCliente('c.id');
            $personas = $this->fetchAll("SELECT ct.nombre, ct.pais, c.nombre AS cliente FROM portal_contactos ct JOIN portal_clientes c ON c.id = ct.cliente_id
                WHERE ct.pais IS NOT NULL AND ct.pais <> '' AND " . $w2 . ' ORDER BY ct.nombre', $p2);
        } catch (\Throwable) {
            $personas = [];
        }
        $ahora = new \DateTimeImmutable('now');
        $offAg = (new \DateTimeZone(Zona::agencia()))->getOffset($ahora);
        $por = [];
        foreach ($filas as $f) {
            $pais = HorarioHabil::paisValido((string) ($f['pais'] ?? ''));
            $por[$pais][] = (string) $f['nombre'];
        }
        foreach ($personas as $f) {
            $por[HorarioHabil::paisValido((string) $f['pais'])][] = $f['nombre'] . ' (' . $f['cliente'] . ')';
        }
        // Sólo los países que hoy tienen otra hora que la agencia (España y Francia, por ejemplo, comparten hora).
        $out = [];
        foreach ($por as $pais => $nombres) {
            $zona = HorarioHabil::zonaDe($pais);
            $dif = (int) round(((new \DateTimeZone($zona))->getOffset($ahora) - $offAg) / 3600);
            if ($dif === 0) {
                continue;
            }
            $out[] = [
                'pais' => $pais, 'nombre' => HorarioHabil::nombreDe($pais), 'zona' => $zona,
                'hora' => $ahora->setTimezone(new \DateTimeZone($zona))->format('H:i'),
                'dif' => ($dif > 0 ? '+' : '−') . abs($dif) . ' h',
                'clientes' => implode(', ', array_slice($nombres, 0, 8)) . (count($nombres) > 8 ? '…' : ''),
                'agencia' => false,
            ];
        }
        if ($out === []) {
            return [];
        }
        usort($out, fn($a, $b) => $a['nombre'] <=> $b['nombre']);
        array_unshift($out, [
            'pais' => Zona::pais(), 'nombre' => Zona::nombre(), 'zona' => Zona::agencia(),
            'hora' => $ahora->setTimezone(new \DateTimeZone(Zona::agencia()))->format('H:i'),
            'dif' => '', 'clientes' => '', 'agencia' => true,
        ]);
        return $out;
    }

    /** «Ver como cliente»: abre el portal de un contacto en modo de sólo lectura. */
    public function verComo(string $contactoId): void
    {
        $u = $this->requerirUsuario();
        $ct = $this->fetchOne('SELECT id, cliente_id FROM portal_contactos WHERE id = ?', [$contactoId]);
        $enviado = (string) ($_POST['_csrf'] ?? '');
        if ($ct === null || $enviado === '' || !hash_equals(PortalSession::csrf(), $enviado) || !$this->acceso($u)->puedeVerCliente((string) $ct['cliente_id'])) {
            PortalSession::flash('error', 'No puedes ver el portal de ese contacto.');
            $this->redirectTo('/equipo/clientes');
            return;
        }
        PortalSession::iniciarVistaPrevia($contactoId, (string) $u['nombre'], '/equipo/clientes/' . $ct['cliente_id']);
        $this->redirectTo('/portal');
    }

    // ---------------------------------------------------------------------
    // Quién trabaja en un cliente o proyecto (Coordinación asigna desde la ficha)
    // ---------------------------------------------------------------------

    /**
     * @return array{gente: array<int, array<string, mixed>>, disponibles: array<int, array<string, mixed>>}
     */
    protected function quien(string $clienteId, ?string $proyectoId = null): array
    {
        $svc = new EquipoService($this->pdo());
        $gente = [];
        foreach ($svc->delCliente($clienteId) as $g) {
            if ($proyectoId === null) {
                $nota = $g['completo'] ? Idioma::t('Todo el cliente') : Idioma::t('Solo %s', implode(', ', $g['proyectos']));
                $gente[] = ['id' => $g['id'], 'nombre' => $g['nombre'], 'cargo' => $g['cargo'], 'nota' => $nota, 'quitable' => true];
            } elseif ($g['completo']) {
                $gente[] = ['id' => $g['id'], 'nombre' => $g['nombre'], 'cargo' => $g['cargo'], 'nota' => Idioma::t('Por todo el cliente'), 'quitable' => false];
            } elseif (in_array($proyectoId, $g['proyecto_ids'], true)) {
                $gente[] = ['id' => $g['id'], 'nombre' => $g['nombre'], 'cargo' => $g['cargo'], 'nota' => Idioma::t('Este proyecto'), 'quitable' => true];
            }
        }
        $ids = array_column($gente, 'id');
        $disponibles = array_values(array_filter(
            $svc->activos(),
            fn($p) => $p['rol'] === 'equipo' && !in_array((string) $p['id'], $ids, true)
        ));
        return ['gente' => $gente, 'disponibles' => $disponibles];
    }

    /** Coordinación general o quien lleva la cuenta de ese cliente, + CSRF válido; si no, vuelve con un aviso. */
    private function puedeAsignar(array $u, string $volver, string $clienteId = ''): bool
    {
        $enviado = (string) ($_POST['_csrf'] ?? '');
        if (!$this->acceso($u)->gestionaCliente($clienteId) || $enviado === '' || !hash_equals(PortalSession::csrf(), $enviado)) {
            PortalSession::flash('error', 'Asigna personas quien lleva la cuenta de este cliente o Coordinación general.');
            $this->redirectTo($volver);
            return false;
        }
        return true;
    }

    /** @return array<string, mixed>|null persona activa del equipo */
    private function personaPost(): ?array
    {
        $p = (new EquipoService($this->pdo()))->find((string) ($_POST['persona_id'] ?? ''));
        return $p !== null && (int) $p['activo'] === 1 && $p['rol'] === 'equipo' ? $p : null;
    }

    public function asignarCliente(string $id): void
    {
        $u = $this->requerirUsuario();
        $volver = '/equipo/clientes/' . $id . '#equipo-asignado';
        $c = $this->fetchOne('SELECT id FROM portal_clientes WHERE id = ?', [$id]);
        $p = $this->personaPost();
        if ($c === null || !$this->puedeAsignar($u, $volver, $id)) {
            if ($c === null) {
                $this->redirectTo('/equipo/clientes');
            }
            return;
        }
        if ($p !== null) {
            (new EquipoService($this->pdo()))->asignar((string) $p['id'], $id);
            PortalSession::flash('ok', $p['nombre'] . ' ahora ve este cliente y todos sus proyectos.');
        }
        $this->redirectTo($volver);
    }

    public function quitarCliente(string $id, string $personaId): void
    {
        $u = $this->requerirUsuario();
        $volver = '/equipo/clientes/' . $id . '#equipo-asignado';
        if (!$this->puedeAsignar($u, $volver, $id)) {
            return;
        }
        (new EquipoService($this->pdo()))->desasignar($personaId, $id);
        PortalSession::flash('ok', 'Listo: ya no ve este cliente.');
        $this->redirectTo($volver);
    }

    public function asignarProyecto(string $id): void
    {
        $u = $this->requerirUsuario();
        $volver = '/equipo/proyectos/' . $id . '#equipo-asignado';
        $pr = $this->fetchOne('SELECT id, cliente_id FROM portal_proyectos WHERE id = ?', [$id]);
        if ($pr === null) {
            $this->redirectTo('/equipo/proyectos');
            return;
        }
        if (!$this->puedeAsignar($u, $volver, (string) $pr['cliente_id'])) {
            return;
        }
        $p = $this->personaPost();
        if ($p !== null) {
            (new EquipoService($this->pdo()))->asignar((string) $p['id'], (string) $pr['cliente_id'], $id);
            PortalSession::flash('ok', $p['nombre'] . ' ahora ve este proyecto.');
        }
        $this->redirectTo($volver);
    }

    public function quitarProyecto(string $id, string $personaId): void
    {
        $u = $this->requerirUsuario();
        $volver = '/equipo/proyectos/' . $id . '#equipo-asignado';
        $pr = $this->fetchOne('SELECT id, cliente_id FROM portal_proyectos WHERE id = ?', [$id]);
        if ($pr === null || !$this->puedeAsignar($u, $volver, (string) $pr['cliente_id'])) {
            return;
        }
        (new EquipoService($this->pdo()))->desasignar($personaId, (string) $pr['cliente_id'], $id);
        PortalSession::flash('ok', 'Listo: ya no ve este proyecto.');
        $this->redirectTo($volver);
    }

    protected function view(string $plantilla, array $datos): void
    {
        Vista::mostrar($this->pdo(), 'templates/equipo/' . $plantilla, $datos);
    }

    // ---------------------------------------------------------------------
    // Login (código por correo, igual que el portal del cliente)
    // ---------------------------------------------------------------------

    public function entrarForm(): void
    {
        if ($this->usuarioActual() !== null) {
            $this->redirectTo('/equipo');
            return;
        }
        $this->view('entrar.latte', $this->contextoPublico(['error' => $_GET['error'] ?? null]));
    }

    public function pedirCodigo(): void
    {
        if (!PortalSession::csrfValido()) {
            $this->redirectTo('/equipo/entrar?error=sesion');
            return;
        }
        $email = EquipoService::email((string) ($_POST['email'] ?? ''));
        $u     = $this->equipo()->findByEmail($email);
        $auth  = new EquipoAuthService($this->pdo());

        // Misma respuesta exista o no el correo (no revelamos quién tiene acceso).
        if ($u !== null && (int) $u['activo'] === 1 && !$auth->demasiadosCodigos((string) $u['id'])) {
            $codigo = $auth->generarCodigo((string) $u['id']);
            try {
                (new Notifier($this->ctx, $this->pdo()))->codigoAccesoEquipo($u, $codigo);
            } catch (\Throwable) {
                // el código expira solo
            }
        }
        PortalSession::iniciar();
        $_SESSION['portal_equipo_email'] = $email;
        $this->redirectTo('/equipo/verificar');
    }

    public function verificarForm(): void
    {
        PortalSession::iniciar();
        $email = $_SESSION['portal_equipo_email'] ?? null;
        if (!is_string($email)) {
            $this->redirectTo('/equipo/entrar');
            return;
        }
        $this->view('verificar.latte', $this->contextoPublico(['email' => $email, 'error' => $_GET['error'] ?? null]));
    }

    public function verificarCodigo(): void
    {
        PortalSession::iniciar();
        $email = $_SESSION['portal_equipo_email'] ?? null;
        if (!is_string($email)) {
            $this->redirectTo('/equipo/entrar');
            return;
        }
        if (!PortalSession::csrfValido()) {
            $this->redirectTo('/equipo/verificar?error=sesion');
            return;
        }
        $u      = $this->equipo()->findByEmail($email);
        $auth   = new EquipoAuthService($this->pdo());
        $codigo = preg_replace('/\D+/', '', (string) ($_POST['codigo'] ?? '')) ?? '';

        if ($u !== null && $auth->bloqueado((string) $u['id'])) {
            $this->redirectTo('/equipo/verificar?error=bloqueado');
            return;
        }
        if ($u === null || (int) $u['activo'] !== 1 || !$auth->verificarCodigo((string) $u['id'], $codigo)) {
            $this->redirectTo('/equipo/verificar?error=1');
            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_regenerate_id(true);
        }
        unset($_SESSION['portal_equipo_email']);
        $_SESSION[self::SESION] = (string) $u["id"];
        $elegido = (string) ($_COOKIE[Idioma::COOKIE] ?? "");
        if (Idioma::valido($elegido) && !Idioma::valido($this->ajustes()->get("equipo", (string) $u["id"], "idioma"))) {
            $this->ajustes()->set("equipo", (string) $u["id"], "idioma", $elegido);
        }
        $this->equipo()->marcarAcceso((string) $u['id']);
        $this->redirectTo('/equipo');
    }

    public function salir(): void
    {
        PortalSession::iniciar();
        unset($_SESSION[self::SESION]);
        $this->redirectTo('/equipo/entrar');
    }

    public function tema(): void
    {
        $u = $this->requerirUsuario();
        if (PortalSession::csrfValido()) {
            $tema = (string) ($_POST['tema'] ?? 'auto');
            $this->ajustes()->set('equipo', (string) $u['id'], 'tema', in_array($tema, ['claro', 'oscuro'], true) ? $tema : 'auto');
        }
        if (!headers_sent()) {
            http_response_code(204);
        }
        $this->terminate();
    }

    // ---------------------------------------------------------------------
    // Mis ajustes: avisos por correo y tema
    // ---------------------------------------------------------------------

    public function misAjustes(): void
    {
        $u    = $this->requerirUsuario();
        $pref = $this->ajustes()->todos('equipo', (string) $u['id']);
        $this->view('ajustes.latte', $this->contexto($u, 'ajustes', [
            'prefAvisos' => Avisos::preferencias($this->ajustes(), (string) $u['id']),
            'temaPref' => in_array($pref['tema'] ?? '', ['claro', 'oscuro'], true) ? $pref['tema'] : 'auto',
            'idiomaPref' => Idioma::actual(),
            'asignados' => $this->acceso($u)->todo() ? null : $this->fetchAll(
                'SELECT a.proyecto_id, c.nombre AS cliente_nombre, p.nombre AS proyecto_nombre
                 FROM portal_equipo_asignaciones a JOIN portal_clientes c ON c.id = a.cliente_id
                 LEFT JOIN portal_proyectos p ON p.id = a.proyecto_id
                 WHERE a.usuario_id = ? ORDER BY c.nombre, p.nombre',
                [(string) $u['id']]
            ),
        ]));
    }

    public function guardarAjustes(): void
    {
        $u = $this->requerirUsuario();
        if (!PortalSession::csrfValido()) {
            PortalSession::flash('error', 'Tu sesión expiró. Vuelve a intentarlo.');
            $this->redirectTo('/equipo/ajustes');
            return;
        }
        $tema = (string) ($_POST['tema'] ?? 'auto');
        $que = (string) ($_POST['avisos_que'] ?? 'mio');
        $idioma = (string) ($_POST['idioma'] ?? '');
        if (Idioma::valido($idioma)) {
            Idioma::fijar($idioma);
        }
        $this->ajustes()->setMuchos('equipo', (string) $u['id'], (Idioma::valido($idioma) ? ['idioma' => $idioma] : []) + [
            'avisos_que'     => in_array($que, ['mio', 'todo', 'nada'], true) ? $que : 'mio',
            'resumen_diario' => !empty($_POST['resumen_diario']) ? '1' : '0',
            'tema'           => in_array($tema, ['claro', 'oscuro'], true) ? $tema : 'auto',
        ]);
        PortalSession::flash('ok', 'Ajustes guardados.');
        $this->redirectTo('/equipo/ajustes');
    }

    // ---------------------------------------------------------------------
    // Inicio: la bandeja de lo que espera al equipo
    // ---------------------------------------------------------------------

    public function inicio(): void
    {
        $u   = $this->requerirUsuario();
        $acc = $this->acceso($u);
        $fmt = new Fmt();
        $hoy = (new \DateTimeImmutable('now', new \DateTimeZone(Zona::agencia())))->format('Y-m-d');

        [$wT, $pT] = $acc->filtroProyecto('t.proyecto_id');
        $turno = $this->fetchAll(
            'SELECT t.*, p.nombre AS proyecto_nombre, c.id AS cliente_id, c.nombre AS cliente_nombre, ct.nombre AS contacto_nombre
             FROM portal_tareas t
             JOIN portal_proyectos p ON p.id = t.proyecto_id
             JOIN portal_clientes c ON c.id = p.cliente_id
             LEFT JOIN portal_contactos ct ON ct.id = t.responsable_contacto_id
             WHERE ' . self::TURNO_EQUIPO . ' AND ' . $wT . '
             ORDER BY (t.fecha_vencimiento IS NULL), t.fecha_vencimiento, t.created_at',
            $pT
        );
        $uid = (string) $u['id'];
        // Del cliente (entregó o pidió cambios) primero; luego lo asignado a mí; luego el resto.
        $delCliente = array_values(array_filter($turno, fn($t) => in_array($t['estado'], ['entregada', 'cambios'], true)));
        $mias       = array_values(array_filter($turno, fn($t) => !in_array($t['estado'], ['entregada', 'cambios'], true) && $t['responsable_usuario_id'] === $uid));
        $resto      = array_values(array_filter($turno, fn($t) => !in_array($t['estado'], ['entregada', 'cambios'], true) && $t['responsable_usuario_id'] !== $uid));
        $vencidas   = count(array_filter($turno, fn($t) => $t['fecha_vencimiento'] !== null && substr((string) $t['fecha_vencimiento'], 0, 10) < $hoy));

        [$wE, $pE] = $acc->filtroProyecto('e.proyecto_id');
        $entregas = $this->fetchAll(
            "SELECT e.*, p.nombre AS proyecto_nombre, c.nombre AS cliente_nombre,
                    (SELECT COUNT(*) FROM portal_contenidos x WHERE x.entrega_id = e.id) AS n_total,
                    (SELECT COUNT(*) FROM portal_contenidos x WHERE x.entrega_id = e.id AND x.estado = 'aprobado') AS n_aprobados,
                    (SELECT COUNT(*) FROM portal_contenidos x WHERE x.entrega_id = e.id AND x.estado = 'cambios') AS n_cambios
             FROM portal_entregas e
             JOIN portal_proyectos p ON p.id = e.proyecto_id
             JOIN portal_clientes c ON c.id = e.cliente_id
             WHERE e.estado IN ('publicada', 'respondida') AND {$wE}
             ORDER BY (e.estado = 'respondida') DESC, e.updated_at DESC",
            $pE
        );
        $conCambios   = array_values(array_filter($entregas, fn($e) => $e['estado'] === 'respondida'));
        $esperandoCli = array_values(array_filter($entregas, fn($e) => $e['estado'] === 'publicada'));

        [$wR, $pR] = $acc->filtroProyecto('r.proyecto_id');
        $reuniones = $this->fetchAll(
            'SELECT r.*, p.nombre AS proyecto_nombre, c.nombre AS cliente_nombre FROM portal_reuniones r
             JOIN portal_proyectos p ON p.id = r.proyecto_id JOIN portal_clientes c ON c.id = p.cliente_id
             WHERE r.fecha >= ? AND ' . $wR . ' ORDER BY r.fecha LIMIT 5',
            [$hoy, ...$pR]
        );

        [$wA, $pA] = $acc->filtroCliente('a.cliente_id');
        [$wAp, $pAp] = $acc->filtroProyecto('a.proyecto_id');
        $actividad = $this->fetchAll(
            "SELECT a.*, c.nombre AS cliente_nombre, p.nombre AS proyecto_nombre FROM portal_actividad a JOIN portal_clientes c ON c.id = a.cliente_id
             LEFT JOIN portal_proyectos p ON p.id = a.proyecto_id
             WHERE a.actor_tipo <> 'equipo' AND {$wA} AND (a.proyecto_id IS NULL OR {$wAp})
             ORDER BY a.created_at DESC, a.id DESC LIMIT 10",
            [...$pA, ...$pAp]
        );

        [$wP, $pP] = $acc->filtroProyecto('p.id');
        $nProyectos = (int) ($this->fetchOne("SELECT COUNT(*) AS n FROM portal_proyectos p WHERE p.estado = 'activo' AND {$wP}", $pP)['n'] ?? 0);

        // Coordinación: cuánto tiene cada persona (tareas abiertas a su nombre y atrasadas).
        $carga = null;
        if ($acc->todo()) {
            $carga = $this->fetchAll(
                "SELECT eq.id, eq.nombre, eq.cargo,
                        COUNT(t.id) AS abiertas,
                        COALESCE(SUM(CASE WHEN t.fecha_vencimiento IS NOT NULL AND SUBSTR(t.fecha_vencimiento, 1, 10) < ? THEN 1 ELSE 0 END), 0) AS atrasadas
                 FROM portal_equipo eq
                 LEFT JOIN portal_tareas t ON t.responsable_usuario_id = eq.id AND t.responsable_tipo = 'equipo'
                      AND t.estado IN ('pendiente', 'en_progreso') AND COALESCE(t.archivada, 0) = 0
                 WHERE eq.activo = 1
                 GROUP BY eq.id, eq.nombre, eq.cargo
                 ORDER BY atrasadas DESC, abiertas DESC, eq.nombre",
                [$hoy]
            );
        }
        // Coordinación de cuentas: la carga de quienes trabajan en sus clientes (sólo tareas de esos clientes).
        if ($carga === null && $acc->esCuentas() && $acc->clientesACargo() !== []) {
            $ph = implode(',', array_fill(0, count($acc->clientesACargo()), '?'));
            $carga = $this->fetchAll(
                "SELECT eq.id, eq.nombre, eq.cargo, COUNT(t.id) AS abiertas,
                        COALESCE(SUM(CASE WHEN t.fecha_vencimiento IS NOT NULL AND SUBSTR(t.fecha_vencimiento, 1, 10) < ? THEN 1 ELSE 0 END), 0) AS atrasadas
                 FROM portal_tareas t
                 JOIN portal_proyectos p ON p.id = t.proyecto_id
                 JOIN portal_equipo eq ON eq.id = t.responsable_usuario_id AND eq.activo = 1
                 WHERE t.responsable_tipo = 'equipo' AND t.estado IN ('pendiente', 'en_progreso') AND COALESCE(t.archivada, 0) = 0
                   AND p.cliente_id IN ({$ph})
                 GROUP BY eq.id, eq.nombre, eq.cargo
                 ORDER BY atrasadas DESC, abiertas DESC, eq.nombre",
                [$hoy, ...$acc->clientesACargo()]
            );
        }
        $sinResponsable = $carga === null ? 0 : count(array_filter($turno, fn($t) =>
            $t['responsable_tipo'] === 'equipo' && in_array($t['estado'], ['pendiente', 'en_progreso'], true) && empty($t['responsable_usuario_id'])));

        $this->view('inicio.latte', $this->contexto($u, 'inicio', [
            'cuentas'      => $acc->todo() ? $this->resumenCuentas($hoy) : null,
            'cargaDeCuentas' => !$acc->todo(),
            'carga'        => $carga,
            'sinResponsable' => $sinResponsable,
            'nombre'       => $fmt->primerNombre((string) $u['nombre']),
            'delCliente'   => $delCliente,
            'mias'         => $mias,
            'resto'        => array_slice($resto, 0, 8),
            'nResto'       => count($resto),
            'vencidas'     => $vencidas,
            'conCambios'   => $conCambios,
            'esperandoCli' => $esperandoCli,
            'reuniones'    => $reuniones,
            'actividad'    => $actividad,
            'nProyectos'   => $nProyectos,
            'hoy'          => $hoy,
        ]));
    }

    // ---------------------------------------------------------------------
    // Clientes
    // ---------------------------------------------------------------------

    /**
     * Proyectos visibles con su avance.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function proyectosConAvance(EquipoAcceso $acc, ?string $clienteId = null): array
    {
        [$w, $p] = $acc->filtroProyecto('p.id');
        $sql = 'SELECT p.*, c.nombre AS cliente_nombre FROM portal_proyectos p JOIN portal_clientes c ON c.id = p.cliente_id WHERE ' . $w;
        if ($clienteId !== null) {
            $sql .= ' AND p.cliente_id = ?';
            $p[] = $clienteId;
        }
        $proyectos = $this->fetchAll($sql . ' ORDER BY p.created_at, p.id', $p);
        if ($proyectos === []) {
            return [];
        }
        $ids = array_column($proyectos, 'id');
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $fases  = $this->fetchAll("SELECT * FROM portal_fases WHERE proyecto_id IN ({$ph}) ORDER BY orden, nombre", $ids);
        $tareas = $this->fetchAll("SELECT id, proyecto_id, fase_id, estado, responsable_tipo, fecha_vencimiento, COALESCE(archivada, 0) AS archivada FROM portal_tareas WHERE proyecto_id IN ({$ph})", $ids);
        $hoy = (new \DateTimeImmutable())->format('Y-m-d');

        foreach ($proyectos as &$pr) {
            $tt = array_values(array_filter($tareas, fn($t) => $t['proyecto_id'] === $pr['id']));
            $pr['progreso'] = ProgresoService::calcular(array_values(array_filter($fases, fn($f) => $f['proyecto_id'] === $pr['id'])), $tt);
            $abiertas = array_filter($tt, fn($t) => (int) $t['archivada'] === 0);
            $pr['n_equipo'] = count(array_filter($abiertas, fn($t) => ($t['responsable_tipo'] === 'equipo' && in_array($t['estado'], ['pendiente', 'en_progreso'], true)) || in_array($t['estado'], ['entregada', 'cambios'], true)));
            $pr['n_cliente'] = count(array_filter($abiertas, fn($t) => $t['responsable_tipo'] === 'cliente' && in_array($t['estado'], ['pendiente', 'en_progreso'], true)));
            $pr['n_vencidas'] = count(array_filter($abiertas, fn($t) => $t['estado'] !== 'hecha' && $t['fecha_vencimiento'] !== null && substr((string) $t['fecha_vencimiento'], 0, 10) < $hoy));
        }
        return $proyectos;
    }

    /** @return array<string, array{color: string, logo: ?string}> Marca de cada cliente. */
    protected function marcasClientes(array $clienteIds): array
    {
        $out = [];
        foreach (array_unique($clienteIds) as $cid) {
            $cfg = $this->ajustes()->todos('cliente', (string) $cid);
            $pal = Paleta::marca(AjustesService::colorValido($cfg['color'] ?? ''));
            $out[$cid] = [
                'color' => $pal['color'],
                'texto' => $pal['texto'],
                'logo'  => !empty($cfg['logo_id']) ? '/portal/marca/cliente/' . $cid : null,
            ];
        }
        return $out;
    }

    public function clientes(): void
    {
        $u   = $this->requerirUsuario();
        $acc = $this->acceso($u);
        [$w, $p] = $acc->filtroCliente('c.id');
        Schema::asegurar($this->pdo());
        $clientes  = $this->fetchAll("SELECT c.* FROM portal_clientes c WHERE {$w} ORDER BY c.nombre", $p);
        $proyectos = $this->proyectosConAvance($acc);
        foreach ($clientes as &$c) {
            $c['proyectos'] = array_values(array_filter($proyectos, fn($pr) => $pr['cliente_id'] === $c['id']));
            $c['activos'] = count(array_filter($c['proyectos'], fn($pr) => $pr['estado'] === 'activo'));
        }
        unset($c);
        // Un usuario con sólo proyectos sueltos no ve clientes sin proyectos visibles (salvo los que lleva).
        $clientes = array_values(array_filter($clientes, fn($c) => $acc->todo() || $c['proyectos'] !== [] || $acc->gestionaCliente((string) $c['id'])));

        [$grupos, $filtros] = $this->agruparPorCuenta($u, $acc, $clientes);
        $this->view('clientes.latte', $this->contexto($u, 'clientes', [
            'clientes' => $clientes,
            'grupos'   => $grupos,
            'filtros'  => $filtros,
            'marcas'   => $this->marcasClientes(array_column($clientes, 'id')),
            'puedeCrearCliente' => $acc->puedeCrearClientes(),
        ]));
    }

    /**
     * Agrupa clientes por quien lleva la cuenta y aplica los filtros de la lista
     * (?ver = mias | todas | id de persona; ?estado = activos | sin | todos).
     *
     * @param array<int, array<string, mixed>> $clientes con 'responsable_id' y 'activos'
     * @return array{0: list<array{id: string, nombre: string, mio: bool, clientes: list<array<string, mixed>>}>, 1: array<string, mixed>}
     */
    protected function agruparPorCuenta(array $u, EquipoAcceso $acc, array $clientes): array
    {
        $personas = [];
        foreach ((new EquipoService($this->pdo()))->activos() as $pe) {
            $personas[(string) $pe['id']] = (string) $pe['nombre'];
        }
        $yo = (string) $u['id'];
        $tengo = count(array_filter($clientes, fn($c) => (string) ($c['responsable_id'] ?? '') === $yo)) > 0;
        $ver = (string) ($_GET['ver'] ?? ($tengo && $acc->esCuentas() ? 'mias' : 'todas'));
        $estado = in_array($_GET['estado'] ?? '', ['activos', 'sin', 'todos'], true) ? (string) $_GET['estado'] : 'activos';
        $conteo = ['activos' => 0, 'sin' => 0, 'todos' => count($clientes)];
        foreach ($clientes as $c) {
            $conteo[$c['activos'] > 0 ? 'activos' : 'sin']++;
        }
        $lista = array_filter($clientes, function ($c) use ($ver, $estado, $yo) {
            $resp = (string) ($c['responsable_id'] ?? '');
            if ($ver === 'mias' && $resp !== $yo) {
                return false;
            }
            if ($ver !== 'mias' && $ver !== 'todas' && $resp !== $ver) {
                return false;
            }
            return $estado === 'todos' || ($estado === 'activos') === ($c['activos'] > 0);
        });
        $grupos = [];
        foreach ($lista as $c) {
            $resp = (string) ($c['responsable_id'] ?? '');
            $k = isset($personas[$resp]) ? $resp : '';
            $grupos[$k] ??= ['id' => $k, 'nombre' => $k === '' ? Idioma::t('Sin responsable de cuenta') : $personas[$k], 'mio' => $k === $yo, 'clientes' => []];
            $grupos[$k]['clientes'][] = $c;
        }
        // Las mías primero, después por nombre; «sin responsable» al final.
        uasort($grupos, fn($a, $b) => [!$a['mio'], $a['id'] === '', $a['nombre']] <=> [!$b['mio'], $b['id'] === '', $b['nombre']]);
        $responsables = [];
        foreach ($clientes as $c) {
            $resp = (string) ($c['responsable_id'] ?? '');
            if (isset($personas[$resp])) {
                $responsables[$resp] = $personas[$resp];
            }
        }
        asort($responsables);
        return [array_values($grupos), ['ver' => $ver, 'estado' => $estado, 'conteo' => $conteo, 'responsables' => $responsables, 'tengo' => $tengo]];
    }

    /** Todos los proyectos que ve el usuario, con su avance, filtrados por estado. */
    /** Conversaciones que esta persona del equipo ve (según sus clientes y proyectos). */
    protected function conversaciones(array $u): array
    {
        $acc = $this->acceso($u);
        $proy = $acc->proyectoIds();
        $puede = null;
        if ($proy !== null) {
            $proy = array_flip($proy);
            $cli = array_flip($acc->clienteIds() ?? []);
            $puede = fn(array $x): bool => $x['proyecto_id'] !== '' ? isset($proy[$x['proyecto_id']]) : isset($cli[$x['cliente_id']]);
        }
        return (new MensajeService($this->pdo()))->conversaciones('equipo', (string) $u['id'], null, $puede);
    }

    /** Bandeja de mensajes del panel: lo que escriben los clientes, con respuesta en el mismo lugar. */
    public function mensajes(): void
    {
        $u = $this->requerirUsuario();
        if (($q = ListaRecordada::restaurar('/equipo/mensajes')) !== null) {
            $this->redirectTo('/equipo/mensajes?' . $q);
            return;
        }
        $svc = new MensajeService($this->pdo());
        $todas = $this->conversaciones($u);
        $pestana = in_array($_GET['t'] ?? '', ['archivadas', 'destacadas'], true) ? (string) $_GET['t'] : 'activas';
        $tipoF = isset(MensajeService::TIPOS[$_GET['tipo'] ?? '']) ? (string) $_GET['tipo'] : '';
        $cliF = (string) ($_GET['cliente'] ?? '');
        if ($cliF !== '' && !in_array($cliF, array_column($todas, 'cliente_id'), true)) {
            $cliF = '';
        }
        $grupo = MensajeService::filtrar($todas, $pestana, 'cliente_id', $cliF, $tipoF);
        $cuenta = fn(string $t): int => count(MensajeService::filtrar($todas, $t, 'cliente_id', $cliF, $tipoF));
        $pref = $this->ajustes()->todos('equipo', (string) $u['id']);
        $pag = Paginacion::desdeGet('/equipo/mensajes', count($grupo), (int) ($pref['por_pagina'] ?? 0), ['recientes' => 'Más nuevos primero']);
        if (($por = Paginacion::elegido()) !== null) {
            $this->ajustes()->set('equipo', (string) $u['id'], 'por_pagina', (string) $por);
        }
        $sel = null;
        foreach ($todas as $x) {
            if ($x['clave'] === (string) ($_GET['c'] ?? '')) {
                $sel = $x;
                break;
            }
        }
        if ($sel !== null) {
            (new NovedadesService($this->pdo()))->marcar((string) $u['id'], $sel['tipo'], $sel['id']);
            $sel['sin_leer'] = 0;
        }
        $urls = ['tarea' => '/equipo/tareas/', 'contenido' => '/equipo/contenidos/', 'solicitud' => '/equipo/solicitudes/'];
        $hilo = $sel !== null ? $svc->hilo($sel['tipo'], $sel['id']) : [];
        // La conversación abierta nunca sale de la lista: si por la pestaña o los filtros no le tocaría estar, va fijada arriba.
        $lista = $svc->conUltimo($pag->cortar($grupo));
        if ($sel !== null && !in_array($sel['clave'], array_column($lista, 'clave'), true)) {
            array_unshift($lista, ['fuera' => true] + $svc->conUltimo([$sel])[0]);
        }
        Vista::mostrar($this->pdo(), 'templates/equipo/mensajes.latte', $this->contexto($u, 'mensajes', [
            'base'        => '/equipo/mensajes',
            'pestana'     => $pestana,
            'nActivas'    => $cuenta('activas'),
            'nArchivadas' => $cuenta('archivadas'),
            'nDestacadas' => $cuenta('destacadas'),
            'filtros'     => ['campo' => 'cliente', 'valor' => $cliF, 'tipo' => $tipoF, 'etiqueta' => 'Cliente', 'todos' => 'Todos los clientes'],
            'opciones'    => MensajeService::opciones($todas, 'cliente_id', $cliF, $tipoF),
            'reacciones'  => $svc->reacciones(array_column($hilo, 'id'), 'equipo', (string) $u['id']),
            'puedeReaccionar' => true,
            'accionesBase' => '/equipo/mensajes',
            'conv'        => $lista,
            'pag'         => $pag,
            'sel'         => $sel,
            'hilo'        => $hilo,
            'lado'        => 'equipo',
            'yoId'        => (string) $u['id'],
            'accion'      => $sel !== null ? $urls[$sel['tipo']] . $sel['id'] . '/comentarios' : null,
            'urlCosa'     => $sel !== null ? $urls[$sel['tipo']] . $sel['id'] : '',
            'csrfCampo'   => '_csrf_token',
            'equipo'      => true,
            'marcasCli'   => Fmt::marcasClientes($this->pdo()),
        ]));
    }

    /** Destaca (o quita la estrella de) una conversación: sale de Archivadas y queda en Destacados. */
    public function destacarMensaje(): void
    {
        $u = $this->requerirUsuario();
        if (PortalSession::csrfValido()) {
            $svc = new MensajeService($this->pdo());
            foreach ($this->conversaciones($u) as $x) {
                if ($x['clave'] === (string) ($_POST['c'] ?? '')) {
                    $svc->alternarDestacada('equipo', (string) $u['id'], $x['tipo'], $x['id']);
                    break;
                }
            }
        }
        $this->redirectTo('/equipo/mensajes', MensajeService::consultaDeVuelta());
    }

    /** Reacciona a un mensaje (o quita la reacción): sin correo y sin marcarlo como no leído. */
    public function reaccionarMensaje(): void
    {
        $u = $this->requerirUsuario();
        if (PortalSession::csrfValido()) {
            $svc = new MensajeService($this->pdo());
            foreach ($this->conversaciones($u) as $x) {
                if ($x['clave'] === (string) ($_POST['c'] ?? '') && $svc->esDe((string) ($_POST['comentario'] ?? ''), $x['tipo'], $x['id'])) {
                    $svc->alternarReaccion((string) $_POST['comentario'], (string) ($_POST['clave'] ?? ''), 'equipo', (string) $u['id']);
                    break;
                }
            }
        }
        $this->redirectTo('/equipo/mensajes', MensajeService::consultaDeVuelta());
    }

    public function proyectos(): void
    {
        $u   = $this->requerirUsuario();
        $acc = $this->acceso($u);
        $todos = $this->proyectosConAvance($acc);
        usort($todos, fn($a, $b) => [(string) $a['cliente_nombre'], (string) $a['nombre']] <=> [(string) $b['cliente_nombre'], (string) $b['nombre']]);
        $grupos = [
            'activo'  => array_values(array_filter($todos, fn($p) => $p['estado'] === 'activo')),
            'pausado' => array_values(array_filter($todos, fn($p) => $p['estado'] === 'pausado')),
            'cerrado' => array_values(array_filter($todos, fn($p) => $p['estado'] === 'cerrado')),
            'todos'   => $todos,
        ];
        $filtro = (string) ($_GET['f'] ?? 'activo');
        $filtro = isset($grupos[$filtro]) ? $filtro : 'activo';
        // Por cuenta: quién lleva el cliente de cada proyecto.
        Schema::asegurar($this->pdo());
        $resp = [];
        foreach ($this->fetchAll('SELECT id, responsable_id FROM portal_clientes', []) as $c) {
            $resp[(string) $c['id']] = (string) ($c['responsable_id'] ?? '');
        }
        $personas = [];
        foreach ((new EquipoService($this->pdo()))->activos() as $pe) {
            $personas[(string) $pe['id']] = (string) $pe['nombre'];
        }
        $yo = (string) $u['id'];
        $responsables = [];
        foreach ($todos as $pr) {
            $r = $resp[(string) $pr['cliente_id']] ?? '';
            if (isset($personas[$r])) {
                $responsables[$r] = $personas[$r];
            }
        }
        asort($responsables);
        $tengo = isset($responsables[$yo]);
        $ver = (string) ($_GET['ver'] ?? ($tengo && $acc->esCuentas() ? 'mias' : 'todas'));
        $porCuenta = [];
        foreach ($grupos[$filtro] as $pr) {
            $r = $resp[(string) $pr['cliente_id']] ?? '';
            $r = isset($personas[$r]) ? $r : '';
            if (($ver === 'mias' && $r !== $yo) || ($ver !== 'mias' && $ver !== 'todas' && $r !== $ver)) {
                continue;
            }
            $porCuenta[$r] ??= ['id' => $r, 'nombre' => $r === '' ? Idioma::t('Sin responsable de cuenta') : $personas[$r], 'mio' => $r === $yo, 'proyectos' => []];
            $porCuenta[$r]['proyectos'][] = $pr;
        }
        uasort($porCuenta, fn($a, $b) => [!$a['mio'], $a['id'] === '', $a['nombre']] <=> [!$b['mio'], $b['id'] === '', $b['nombre']]);
        $this->view('proyectos.latte', $this->contexto($u, 'proyectos', [
            'grupos'   => $grupos,
            'filtro'   => $filtro,
            'lista'    => $grupos[$filtro],
            'porCuenta' => array_values($porCuenta),
            'ver'      => $ver,
            'responsables' => $responsables,
            'tengo'    => $tengo,
            'puedeCrear' => $acc->todo() || $acc->clienteIds() !== [],
        ]));
    }

    /**
     * Inicio de Coordinación general: una fila por quien lleva cuentas, con sus clientes activos,
     * tareas atrasadas y lo que respondió el cliente (revisiones y solicitudes nuevas).
     * @return list<array{id: string, nombre: string, clientes: int, atrasadas: int, respuestas: int}>
     */
    protected function resumenCuentas(string $hoy): array
    {
        Schema::asegurar($this->pdo());
        $filas = [];
        $sumar = function (string $sql, array $p, string $campo) use (&$filas): void {
            foreach ($this->fetchAll($sql, $p) as $f) {
                $k = (string) ($f['responsable_id'] ?? '');
                $filas[$k] ??= ['clientes' => 0, 'atrasadas' => 0, 'respuestas' => 0];
                $filas[$k][$campo] += (int) $f['n'];
            }
        };
        $sumar("SELECT c.responsable_id, COUNT(DISTINCT c.id) AS n FROM portal_clientes c JOIN portal_proyectos p ON p.cliente_id = c.id AND p.estado = 'activo' GROUP BY c.responsable_id", [], 'clientes');
        $sumar("SELECT c.responsable_id, COUNT(*) AS n FROM portal_tareas t JOIN portal_proyectos p ON p.id = t.proyecto_id JOIN portal_clientes c ON c.id = p.cliente_id
                WHERE t.estado IN ('pendiente', 'en_progreso') AND COALESCE(t.archivada, 0) = 0 AND t.fecha_vencimiento IS NOT NULL AND SUBSTR(t.fecha_vencimiento, 1, 10) < ?
                GROUP BY c.responsable_id", [$hoy], 'atrasadas');
        $sumar("SELECT c.responsable_id, COUNT(*) AS n FROM portal_entregas e JOIN portal_clientes c ON c.id = e.cliente_id WHERE e.estado = 'respondida' GROUP BY c.responsable_id", [], 'respuestas');
        $sumar("SELECT c.responsable_id, COUNT(*) AS n FROM portal_solicitudes s JOIN portal_clientes c ON c.id = s.cliente_id WHERE s.estado = 'nueva' GROUP BY c.responsable_id", [], 'respuestas');
        $nombres = [];
        foreach ((new EquipoService($this->pdo()))->activos() as $pe) {
            $nombres[(string) $pe['id']] = (string) $pe['nombre'];
        }
        // Sólo tiene sentido si alguien lleva cuentas.
        if (array_diff(array_keys($filas), ['']) === []) {
            return [];
        }
        $out = [];
        foreach ($filas as $k => $f) {
            if ($f['clientes'] === 0 && $f['atrasadas'] === 0 && $f['respuestas'] === 0) {
                continue;
            }
            $out[] = ['id' => $k, 'nombre' => $nombres[$k] ?? Idioma::t('Sin responsable de cuenta')] + $f;
        }
        usort($out, fn($a, $b) => [$a['id'] === '', -$a['atrasadas'], -$a['respuestas'], $a['nombre']] <=> [$b['id'] === '', -$b['atrasadas'], -$b['respuestas'], $b['nombre']]);
        return $out;
    }

    public function cliente(string $id): void
    {
        $u   = $this->requerirUsuario();
        $acc = $this->acceso($u);
        $c   = $this->fetchOne('SELECT * FROM portal_clientes WHERE id = ?', [$id]);
        $proyectos = $c !== null ? $this->proyectosConAvance($acc, $id) : [];
        if ($c === null || (!$acc->todo() && $proyectos === [] && !$acc->gestionaCliente($id))) {
            PortalSession::flash('error', 'No tienes acceso a ese cliente.');
            $this->redirectTo('/equipo/clientes');
            return;
        }
        $this->view('cliente.latte', $this->contexto($u, 'clientes', [
            'cliente'   => $c,
            'marcaCli'  => $this->marcasClientes([$id])[$id],
            'proyectos' => $proyectos,
            'contactos' => $this->fetchAll('SELECT * FROM portal_contactos WHERE cliente_id = ? ORDER BY nombre', [$id]),
            'actividad' => (new ActividadService($this->pdo()))->deCliente($id, 10),
            'pais'      => HorarioHabil::PAISES[$c['pais'] ?? 'CL'] ?? null,
            'quien'     => $this->quien($id),
            'gestiona'  => $acc->gestionaCliente($id),
            'responsable' => !empty($c['responsable_id']) ? (new EquipoService($this->pdo()))->find((string) $c['responsable_id']) : null,
        ]));
    }

    // ---------------------------------------------------------------------
    // Proyecto (vista general; la edición llega en las próximas fases)
    // ---------------------------------------------------------------------

    public function proyecto(string $id): void
    {
        $u   = $this->requerirUsuario();
        $acc = $this->acceso($u);
        if (!$acc->puedeVerProyecto($id)) {
            PortalSession::flash('error', 'No tienes acceso a ese proyecto.');
            $this->redirectTo('/equipo/clientes');
            return;
        }
        $pr = $this->proyectosConAvance($acc);
        $pr = array_values(array_filter($pr, fn($p) => $p['id'] === $id))[0] ?? null;
        if ($pr === null) {
            $this->redirectTo('/equipo/clientes');
            return;
        }
        $tareas = $this->fetchAll(
            'SELECT t.*, f.nombre AS fase_nombre, ct.nombre AS contacto_nombre, e.nombre AS equipo_nombre, us.name AS usuario_nombre
             FROM portal_tareas t
             LEFT JOIN portal_fases f ON f.id = t.fase_id
             LEFT JOIN portal_contactos ct ON ct.id = t.responsable_contacto_id
             LEFT JOIN portal_equipo e ON e.id = t.responsable_usuario_id
             LEFT JOIN users us ON us.id = t.responsable_usuario_id
             WHERE t.proyecto_id = ?
             ORDER BY (t.estado = \'hecha\'), (t.fecha_vencimiento IS NULL), t.fecha_vencimiento, t.created_at',
            [$id]
        );
        $entregas = (new EntregaService($this->pdo()))->delCliente((string) $pr['cliente_id']);
        $entregas = array_values(array_filter($entregas, fn($e) => $e['proyecto_id'] === $id));
        $reuniones = $this->fetchAll('SELECT * FROM portal_reuniones WHERE proyecto_id = ? ORDER BY fecha DESC LIMIT 8', [$id]);

        $sinArchivadas = array_values(array_filter($tareas, fn(array $t): bool => (int) ($t['archivada'] ?? 0) === 0));
        $this->view('proyecto.latte', $this->contexto($u, 'proyectos', [
            'panel'     => (new ProyectoPanel($this->pdo()))->paraEquipo($pr, $sinArchivadas),
            'proyecto'  => $pr,
            'quien'     => $acc->gestionaCliente((string) $pr['cliente_id']) ? $this->quien((string) $pr['cliente_id'], $id) : null,
            'marcaCli'  => $this->marcasClientes([(string) $pr['cliente_id']])[(string) $pr['cliente_id']],
            'tareas'    => $tareas,
            'entregas'  => $entregas,
            'reuniones' => $reuniones,
            'personas'  => $this->equipo()->delProyecto($id),
            'hoy'       => (new \DateTimeImmutable())->format('Y-m-d'),
        ]));
    }
}

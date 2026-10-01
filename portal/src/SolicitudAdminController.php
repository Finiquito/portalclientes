<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

use TypeDock\Core\PluginContext;

/**
 * Bandeja de solicitudes de los clientes (admin de TypeDock y panel /equipo).
 * Desde aquí el equipo acepta (→ tarea), agenda (→ reunión), cotiza, responde o rechaza,
 * y el cliente recibe un correo con lo que se decidió.
 */
class SolicitudAdminController
{
    protected readonly Pantalla $ui;

    public const FILTRO_ESTADOS = [
        'por_atender' => 'Por atender',
        'esperando'   => 'Esperando al cliente',
        'en_curso'    => 'En curso',
        'cerradas'    => 'Cerradas',
        'todas'       => 'Todas',
    ];

    public function __construct(protected readonly PluginContext $ctx, ?Pantalla $ui = null)
    {
        $this->ui = $ui ?? new PantallaAdmin($ctx);
    }

    private function pdo(): \PDO
    {
        return $this->ctx->db()->pdo();
    }

    private function service(): SolicitudService
    {
        return new SolicitudService($this->pdo());
    }

    private function url(string $ruta): string
    {
        return $this->ui->url($ruta);
    }

    private static function enGrupo(array $s, string $g): bool
    {
        $hoy = (new \DateTimeImmutable('now', new \DateTimeZone(Fmt::ZONA)))->format('Y-m-d');
        $vigente = ($s['estado'] === 'en_curso' && $s['tarea_estado'] !== 'hecha')
            || ($s['estado'] === 'agendada' && substr((string) $s['reunion_fecha'], 0, 10) >= $hoy);
        return match ($g) {
            'por_atender' => in_array($s['estado'], SolicitudService::POR_ATENDER, true),
            'esperando'   => $s['estado'] === 'cotizada',
            'en_curso'    => $vigente,
            'cerradas'    => !$vigente && !in_array($s['estado'], ['nueva', 'aprobada', 'cotizada'], true),
            default       => true,
        };
    }

    public function index(): void
    {
        $f = FiltrosLista::desdeGet($this->url('solicitudes'), ['estado' => 'por_atender', 'tipo' => '', 'proyecto' => ''], [
            'estado'   => array_keys(self::FILTRO_ESTADOS),
            'tipo'     => array_keys(SolicitudService::TIPOS),
            'proyecto' => 'uuid',
        ]);
        // Las de proyecto nuevo (presupuestos) no tienen proyecto: se ven si el cliente está asignado.
        $lista = $this->service()->listAll();
        $todas = array_merge(
            $this->ui->filtrar(array_values(array_filter($lista, fn(array $s): bool => (string) $s['proyecto_id'] !== '')), 'proyecto_id'),
            $this->ui->filtrar(array_values(array_filter($lista, fn(array $s): bool => (string) $s['proyecto_id'] === '')), 'cliente_id', 'cliente'),
        );
        $orden = array_flip(array_column($lista, 'id'));
        usort($todas, fn(array $a, array $b): int => $orden[$a['id']] <=> $orden[$b['id']]);
        $base = array_values(array_filter($todas, fn(array $s): bool => ($f->get('tipo') === '' || $s['tipo'] === $f->get('tipo'))
            && ($f->get('proyecto') === '' || $s['proyecto_id'] === $f->get('proyecto'))));
        $conteos = [];
        foreach (array_keys(self::FILTRO_ESTADOS) as $g) {
            $conteos[$g] = count(array_filter($base, fn(array $s): bool => self::enGrupo($s, $g)));
        }
        $proyectos = $this->ui->filtrar((new ProyectoService($this->pdo()))->listAll(), 'id');
        usort($proyectos, fn($a, $b) => [$a['cliente_nombre'], $a['nombre']] <=> [$b['cliente_nombre'], $b['nombre']]);

        $this->ui->view('solicitudes/index.latte', [
            'solicitudes' => array_values(array_filter($base, fn(array $s): bool => self::enGrupo($s, $f->get('estado')))),
            'filtros'     => $f,
            'conteos'     => $conteos,
            'grupos'      => self::FILTRO_ESTADOS,
            'tipos'       => SolicitudService::TIPOS,
            'estadosS'    => SolicitudService::ESTADOS,
            'urgencias'   => SolicitudService::URGENCIAS,
            'proyectosF'  => $proyectos,
            'colorProy'   => Fmt::coloresTodos($this->pdo()),
            'fmt'         => new Fmt(),
            'flash_success' => $this->ui->flash('success'),
            'flash_error'   => $this->ui->flash('error'),
        ]);
    }

    public function ver(string $id): void
    {
        $svc = $this->service();
        $s = $svc->find($id);
        if ($s === null) {
            $this->ui->redirect($this->url('solicitudes'), 'Solicitud no encontrada.', 'error');
            return;
        }
        $eq = new EquipoService($this->pdo());
        $delProyecto = (string) $s['proyecto_id'] !== '' ? array_column($eq->delProyecto((string) $s['proyecto_id']), 'id') : [];
        $usuarios = $eq->activos();
        usort($usuarios, fn($a, $b) => [!in_array($a['id'], $delProyecto, true), $a['nombre']] <=> [!in_array($b['id'], $delProyecto, true), $b['nombre']]);
        $yo = $this->ui->autorId();
        // Cada horario en la hora del cliente y en la de la agencia (la que usa la reunión).
        $hz = SolicitudService::horariosZona($s['horarios']);
        $horarios = array_map(fn(string $h): array => ['local' => $h, 'agencia' => SolicitudService::convertir($h, $hz['zona'], ReunionService::ZONA)], $hz['lista']);

        $this->ui->view('solicitudes/ver.latte', [
            's'           => $s,
            'tipos'       => SolicitudService::TIPOS,
            'estadosS'    => SolicitudService::ESTADOS,
            'urgencias'   => SolicitudService::URGENCIAS,
            'modalidades' => SolicitudService::MODALIDADES,
            'horarios'    => $horarios,
            'proyectosCliente' => array_values(array_filter(
                $this->ui->filtrar((new ProyectoService($this->pdo()))->listAll(), 'id'), fn(array $p): bool => $p['cliente_id'] === $s['cliente_id'])),
            'paisHorarios' => $hz['pais'],
            'paisCliente' => HorarioHabil::paisValido((string) $s['cliente_pais']),
            'paisAgencia' => SolicitudService::paisAgencia(),
            'horaCliente' => (new \DateTimeImmutable('now', new \DateTimeZone(HorarioHabil::zonaDe((string) $s['cliente_pais']))))->format('H:i'),
            'zonaCliente' => HorarioHabil::zonaDe((string) $s['cliente_pais']),
            'archivos'    => (new ArchivoService($this->pdo()))->deEntidad('solicitud', $id),
            'comentarios' => (new ComentarioService($this->pdo()))->listar('solicitud', $id),
            'usuarios'    => $usuarios,
            'delProyecto' => $delProyecto,
            'responsable' => $yo ?? ($delProyecto[0] ?? ''),
            'fechaSug'    => $svc->fechaSugerida((string) $s['urgencia'], (string) $s['tipo']),
            'urgentesCliente' => $svc->urgentesAbiertas((string) $s['cliente_id']),
            'firma'       => $this->ui->firma(),
            'colorProy'   => Fmt::coloresTodos($this->pdo()),
            'fmt'         => new Fmt(),
            'flash_success' => $this->ui->flash('success'),
            'flash_error'   => $this->ui->flash('error'),
        ]);
    }

    /** Aceptar: crea la tarea del equipo. */
    public function aceptar(string $id): void
    {
        $s = $this->service()->find($id);
        $tareaId = $s !== null ? $this->service()->aceptar($id, $_POST, $this->ui->firma()) : null;
        if ($tareaId === null) {
            $msg = $s !== null && (string) $s['proyecto_id'] === '' && in_array($s['estado'], ['nueva', 'aprobada'], true)
                ? 'Elige un proyecto del cliente o escribe el nombre del proyecto nuevo.'
                : 'No se pudo aceptar: puede que ya la haya atendido alguien.';
            $this->ui->redirect($this->url('solicitudes/' . $id), $msg, 'error');
            return;
        }
        $this->registrar($s, 'atendio', 'Convertida en tarea');
        $fecha = trim((string) ($_POST['fecha_vencimiento'] ?? ''));
        $this->avisarCliente($s, 'Tomamos tu solicitud: ' . $s['titulo'], [
            'etiqueta' => 'Solicitud', 'titulo' => 'Tomamos tu solicitud', 'resaltado' => 'Tomamos',
            'bloques' => array_values(array_filter([
                ['tarjetas' => [['titulo' => (string) $s['titulo'], 'detalle' => $fecha !== '' ? 'Para el ' . (new Fmt())->fecha($fecha) : 'Sin fecha por ahora']]],
                trim((string) ($_POST['mensaje'] ?? '')) !== '' ? ['cita' => mb_substr(trim((string) $_POST['mensaje']), 0, 800)] : null,
                ['p' => 'Ya es una tarea del equipo: puedes seguir su avance en tu portal.'],
            ])),
            'boton' => 'Ver la tarea',
        ], '/portal/tareas/' . $tareaId);
        $this->comentarDesdePost($s);
        $this->ui->redirect($this->url('tareas/' . $tareaId), 'Solicitud aceptada: la tarea quedó creada y le avisamos al cliente.');
    }

    /** Agendar: crea la reunión publicada. */
    public function agendar(string $id): void
    {
        $s = $this->service()->find($id);
        $fecha = trim((string) ($_POST['fecha_elegida'] ?? ''));
        if ($fecha === 'otra' || $fecha === '') {
            $d = trim((string) ($_POST['fecha_d'] ?? ''));
            $t = trim((string) ($_POST['fecha_t'] ?? ''));
            $fecha = $d !== '' ? trim($d . ' ' . $t) : '';
        }
        $reunionId = $s !== null ? $this->service()->agendar(
            $id, $fecha, (int) ($_POST['duracion_min'] ?? 60), (string) ($_POST['enlace_meet'] ?? ''), (string) ($_POST['titulo'] ?? ''), $this->ui->firma()
        ) : null;
        if ($reunionId === null) {
            $this->ui->redirect($this->url('solicitudes/' . $id), 'Elige un horario para agendar.', 'error');
            return;
        }
        $r = (new ReunionService($this->pdo()))->find($reunionId);
        $fmt = new Fmt();
        $this->registrar($s, 'atendio', 'Reunión agendada');
        $this->avisarCliente($s, 'Reunión confirmada: ' . $fmt->fecha((string) $r['fecha']) . ' ' . $fmt->hora((string) $r['fecha']), [
            'etiqueta' => 'Reunión', 'titulo' => 'Tu reunión quedó confirmada', 'resaltado' => 'confirmada',
            'bloques' => [['tarjetas' => [['titulo' => (string) $r['titulo'], 'detalle' => $fmt->fechaLarga((string) $r['fecha']) . ' · ' . $fmt->hora((string) $r['fecha'])]]],
                ['p' => $r['enlace_meet'] ? 'El enlace para conectarte está en tu portal, y puedes agregarla a tu calendario.' : 'En tu portal puedes agregarla a tu calendario.']],
            'boton' => 'Ver la reunión', 'inmediato' => true,
        ], '/portal/reuniones/' . $reunionId);
        $this->comentarDesdePost($s);
        $this->ui->redirect($this->url('reuniones/' . $reunionId), 'Reunión agendada y confirmada al cliente.');
    }

    public function cotizar(string $id): void
    {
        $s = $this->service()->find($id);
        if ($s === null || !$this->service()->cotizar($id, (string) ($_POST['monto'] ?? ''), (string) ($_POST['validez'] ?? ''), (string) ($_POST['mensaje'] ?? ''), $this->ui->firma())) {
            $this->ui->redirect($this->url('solicitudes/' . $id), 'Escribe el valor de la cotización.', 'error');
            return;
        }
        $lista = ArchivoService::normalizar($_FILES['archivos'] ?? null);
        if ($lista !== []) {
            $maxMb = max(1, (int) (new AjustesService($this->pdo()))->get('global', 'portal', 'max_mb', '20'));
            (new ArchivoService($this->pdo()))->guardarVarios(array_slice($lista, 0, 10), (string) $s['cliente_id'], ((string) $s['proyecto_id']) ?: null, 'solicitud', $id,
                ['tipo' => 'equipo', 'id' => $this->ui->autorId(), 'nombre' => $this->ui->firma()], $maxMb);
        }
        $this->registrar($s, 'cotizo', (string) ($_POST['monto'] ?? ''));
        $this->avisarCliente($s, 'Tu cotización está lista: ' . $s['titulo'], [
            'etiqueta' => 'Presupuesto', 'titulo' => 'Tu cotización está lista', 'resaltado' => 'lista',
            'bloques' => [['tarjetas' => [['titulo' => (string) $s['titulo'], 'detalle' => trim((string) $_POST['monto'])]]],
                ['p' => 'Revísala en tu portal y apruébala con un clic, o escríbenos si quieres ajustar algo.']],
            'boton' => 'Ver la cotización',
        ]);
        $this->ui->redirect($this->url('solicitudes/' . $id), 'Cotización enviada al cliente.');
    }

    /** Responder (sin crear nada) o rechazar, siempre con un mensaje para el cliente. */
    public function cerrar(string $id): void
    {
        $s = $this->service()->find($id);
        $estado = ($_POST['estado'] ?? '') === 'rechazada' ? 'rechazada' : 'respondida';
        $mensaje = (string) ($_POST['mensaje'] ?? '');
        if ($s === null || !$this->service()->cerrar($id, $estado, $mensaje, $this->ui->firma())) {
            $this->ui->redirect($this->url('solicitudes/' . $id), 'Escribe un mensaje para el cliente.', 'error');
            return;
        }
        $this->registrar($s, 'atendio', $estado === 'rechazada' ? 'No tomada' : 'Respondida');
        $this->avisarCliente($s, ($estado === 'rechazada' ? 'Sobre tu solicitud: ' : 'Respondimos tu solicitud: ') . $s['titulo'], [
            'etiqueta' => 'Solicitud', 'titulo' => $estado === 'rechazada' ? 'Sobre tu solicitud' : 'Respondimos tu solicitud',
            'bloques' => [['tarjetas' => [['titulo' => (string) $s['titulo'], 'detalle' => SolicitudService::TIPOS[$s['tipo']][0]]]], ['cita' => mb_substr(trim($mensaje), 0, 800)]],
            'boton' => 'Ver en el portal',
        ]);
        $this->ui->redirect($this->url('solicitudes'), $estado === 'rechazada' ? 'Solicitud cerrada y avisada al cliente.' : 'Respuesta enviada al cliente.');
    }

    public function comentar(string $id): void
    {
        $s = $this->service()->find($id);
        $texto = trim((string) ($_POST['cuerpo'] ?? ''));
        if ($s === null || $texto === '') {
            $this->ui->redirect($this->url('solicitudes/' . $id) . '#conversacion', 'Escribe algo antes de enviar.', 'error');
            return;
        }
        (new ComentarioService($this->pdo()))->crear((string) $s['cliente_id'], 'solicitud', $id, 'equipo', $this->ui->autorId(), $this->ui->firma(), $texto);
        (new ActividadService($this->pdo()))->registrar((string) $s['cliente_id'], ((string) $s['proyecto_id']) ?: null, 'equipo', $this->ui->firma(), 'comento', 'solicitud', $id, (string) $s['titulo'], mb_substr($texto, 0, 200));
        if (!empty($_POST['avisar'])) {
            $this->avisarCliente($s, 'Nuevo comentario en tu solicitud: ' . $s['titulo'], [
                'etiqueta' => 'Comentario', 'titulo' => 'Nuevo comentario del equipo', 'resaltado' => 'comentario', 'boton' => 'Ver y responder',
                'preheader' => mb_substr($texto, 0, 110), 'bloques' => [['p' => 'En «' . $s['titulo'] . '»:'], ['cita' => mb_substr($texto, 0, 800)]],
            ]);
        }
        $this->ui->redirect($this->url('solicitudes/' . $id) . '#conversacion', 'Comentario publicado.');
    }

    public function destroy(string $id): void
    {
        $this->service()->delete($id);
        $this->ui->redirect($this->url('solicitudes'), 'Solicitud eliminada.');
    }

    // ---------------------------------------------------------------------

    /** Si al aceptar o agendar el equipo dejó un mensaje, queda en la conversación de la solicitud. */
    private function comentarDesdePost(array $s): void
    {
        $m = trim((string) ($_POST['mensaje'] ?? ''));
        if ($m !== '') {
            (new ComentarioService($this->pdo()))->crear((string) $s['cliente_id'], 'solicitud', (string) $s['id'], 'equipo', $this->ui->autorId(), $this->ui->firma(), $m);
        }
    }

    private function registrar(array $s, string $accion, string $detalle): void
    {
        (new ActividadService($this->pdo()))->registrar((string) $s['cliente_id'], ((string) $s['proyecto_id']) ?: null, 'equipo', $this->ui->firma(), $accion, 'solicitud', (string) $s['id'], (string) $s['titulo'], $detalle);
    }

    /** @param array<string, mixed> $op */
    private function avisarCliente(array $s, string $asunto, array $op, ?string $ruta = null): void
    {
        try {
            (new Notifier($this->ctx, $this->pdo()))->alCliente(
                (string) $s['cliente_id'], $s['contacto_id'] ? (string) $s['contacto_id'] : null, $asunto, '',
                $ruta ?? '/portal/solicitudes/' . $s['id'], $op
            );
        } catch (\Throwable) {
            // un correo que falla no deshace lo que el equipo decidió
        }
    }
}

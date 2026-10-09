<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

use TypeDock\Core\PluginContext;

class TareaAdminController
{
    protected readonly Pantalla $ui;

    public function __construct(private readonly PluginContext $ctx, ?Pantalla $ui = null)
    {
        $this->ui = $ui ?? new PantallaAdmin($ctx);
    }

    private function pdo(): \PDO
    {
        return $this->ctx->db()->pdo();
    }

    private function service(): TareaService
    {
        return new TareaService($this->pdo());
    }

    private function archivos(): ArchivoService
    {
        return new ArchivoService($this->pdo());
    }

    private function comentarios(): ComentarioService
    {
        return new ComentarioService($this->pdo());
    }

    private function actividad(): ActividadService
    {
        return new ActividadService($this->pdo());
    }

    private function ajustes(): AjustesService
    {
        return new AjustesService($this->pdo());
    }

    private function proyectos(): array
    {
        return $this->ui->filtrar((new ProyectoService($this->pdo()))->porMovimiento(), 'id');
    }

    private function reuniones(): array
    {
        return $this->ui->filtrar((new ReunionService($this->pdo()))->listAll(), 'proyecto_id');
    }

    /**
     * Responsables posibles del lado del equipo: usuarios de agencia (Portal · Equipo)
     * y, para no perder tareas antiguas, los usuarios del admin de TypeDock.
     */
    private function usuariosEquipo(): array
    {
        $out = array_map(
            fn(array $e): array => ['id' => $e['id'], 'name' => $e['nombre'] . ($e['cargo'] ? ' · ' . $e['cargo'] : '')],
            (new EquipoService($this->pdo()))->activos()
        );
        try {
            $stmt = $this->pdo()->query('SELECT id, name FROM users ORDER BY name');
            foreach ($stmt ? $stmt->fetchAll() : [] as $u) {
                $out[] = ['id' => $u['id'], 'name' => $u['name'] . ' (admin)'];
            }
        } catch (\Throwable) {
            // sin tabla users (pruebas): sólo usuarios de agencia
        }
        return $out;
    }

    /** Cómo firma el equipo los comentarios y archivos (Portal · Ajustes). */
    private function firma(): string
    {
        return $this->ui->firma();
    }

    /** ¿Ve el cliente esta tarea? Sólo entonces tiene sentido registrar actividad / avisar. */
    private function visibleParaCliente(array $t): bool
    {
        return (int) ($t['visible_cliente'] ?? 1) === 1 || ($t['responsable_tipo'] ?? '') === 'cliente';
    }

    private function urlTarea(string $id): string
    {
        return $this->ui->url('tareas/' . $id);
    }

    /** @return array<string, mixed> */
    private function datosFormulario(?array $tarea): array
    {
        $extra = [];
        if ($tarea !== null) {
            $extra = [
                'comentarios' => $this->comentarios()->listar('tarea', (string) $tarea['id']),
                'archivos'    => $this->archivos()->deEntidad('tarea', (string) $tarea['id']),
            ];
        }
        return [
            'tarea'     => $tarea,
            'proyectos' => $this->proyectos(),
            'reuniones' => $this->reuniones(),
            'usuarios'  => $this->usuariosEquipo(),
            'unico'     => (new EquipoService($this->pdo()))->unico(),   // equipo de una persona: viene elegida
            'contactos' => $this->ui->filtrar($this->service()->contactosAsignables(), 'cliente_id', 'cliente'),
            'fases'     => $this->ui->filtrar($this->service()->todasLasFases(), 'proyecto_id'),
            'previas'   => $this->ui->filtrar($this->service()->paraDependencia(), 'proyecto_id'),
            'firma'     => $this->firma(),
            'fmt'       => new Fmt(),
            'maxMb'     => (int) round($this->archivos()->limiteBytes(max(1, (int) $this->ajustes()->get('global', 'portal', 'max_mb', '20'))) / 1048576),
            'estados'   => Fmt::ESTADOS,
            'tipos'     => Fmt::TIPOS,
        ] + $extra;
    }

    /** Grupos de estado de la lista (pastillas). «archivadas» va al final, en gris. */
    public const FILTRO_ESTADOS = [
        'abiertas'    => 'Abiertas',
        'pendiente'   => 'Pendientes',
        'en_progreso' => 'En progreso',
        'entregada'   => 'En revisión',
        'cambios'     => 'Cambios pedidos',
        'hecha'       => 'Listas',
        'todas'       => 'Todas',
        'archivadas'  => 'Archivadas',
    ];

    public const FILTRO_ORDEN = [
        'vence'    => 'Fecha límite',
        'turno'    => 'Le toca a',
        'proyecto' => 'Cliente y proyecto',
        'estado'   => 'Estado',
        'reciente' => 'Más recientes',
    ];

    /** ¿La tarea entra en el grupo de estado $g? */
    private static function enGrupo(array $t, string $g): bool
    {
        $arch = (int) ($t['archivada'] ?? 0) === 1;
        return match ($g) {
            'archivadas' => $arch,
            'todas'      => !$arch,
            'abiertas'   => !$arch && $t['estado'] !== 'hecha',
            default      => !$arch && $t['estado'] === $g,
        };
    }

    public function index(): void
    {
        $f = FiltrosLista::desdeGet($this->ui->url('tareas'), ['estado' => 'abiertas', 'proyecto' => '', 'turno' => '', 'orden' => 'vence'], [
            'estado'   => array_keys(self::FILTRO_ESTADOS),
            'proyecto' => 'uuid',
            'turno'    => ['equipo', 'cliente', 'mias'],
            'orden'    => array_keys(self::FILTRO_ORDEN),
        ]);
        $yo = $this->ui->autorId();

        $todas = $this->ui->filtrar($this->service()->listAll(), 'proyecto_id');
        $base = array_values(array_filter($todas, function (array $t) use ($f, $yo): bool {
            if ($f->get('proyecto') !== '' && $t['proyecto_id'] !== $f->get('proyecto')) {
                return false;
            }
            return match ($f->get('turno')) {
                'equipo'  => $t['responsable_tipo'] !== 'cliente',
                'cliente' => $t['responsable_tipo'] === 'cliente',
                'mias'    => $yo !== null && $t['responsable_tipo'] !== 'cliente' && $t['responsable_usuario_id'] === $yo,
                default   => true,
            };
        }));
        $conteos = [];
        foreach (array_keys(self::FILTRO_ESTADOS) as $g) {
            $conteos[$g] = count(array_filter($base, fn(array $t): bool => self::enGrupo($t, $g)));
        }
        $lista = array_values(array_filter($base, fn(array $t): bool => self::enGrupo($t, $f->get('estado'))));

        $vence = static fn(array $t): string => $t['fecha_vencimiento'] ? substr((string) $t['fecha_vencimiento'], 0, 10) : '9999-12-31';
        $ordenEstado = array_flip(TareaService::ESTADOS);
        usort($lista, match ($f->get('orden')) {
            'turno'    => fn($a, $b) => [$a['responsable_tipo'] === 'cliente', $a['responsable_nombre'] ?? $a['contacto_nombre'] ?? '', $vence($a)] <=> [$b['responsable_tipo'] === 'cliente', $b['responsable_nombre'] ?? $b['contacto_nombre'] ?? '', $vence($b)],
            'proyecto' => fn($a, $b) => [$a['cliente_nombre'], $a['proyecto_nombre'], $vence($a)] <=> [$b['cliente_nombre'], $b['proyecto_nombre'], $vence($b)],
            'estado'   => fn($a, $b) => [$ordenEstado[$a['estado']] ?? 9, $vence($a)] <=> [$ordenEstado[$b['estado']] ?? 9, $vence($b)],
            'reciente' => fn($a, $b) => strcmp((string) $b['created_at'], (string) $a['created_at']),
            default    => fn($a, $b) => [$vence($a), (string) $a['created_at']] <=> [$vence($b), (string) $b['created_at']],
        });

        $proyectos = $this->proyectos();
        usort($proyectos, fn($a, $b) => [$a['cliente_nombre'], $a['nombre']] <=> [$b['cliente_nombre'], $b['nombre']]);

        $this->ui->view('tareas/index.latte', [
            'tareas'        => $lista,
            'filtros'       => $f,
            'conteos'       => $conteos,
            'grupos'        => self::FILTRO_ESTADOS,
            'ordenes'       => self::FILTRO_ORDEN,
            'proyectosF'    => $proyectos,
            'colorProy'     => Fmt::coloresTodos($this->pdo()),
            'puedeMias'     => $yo !== null,
            'fmt'           => new Fmt(),
            'flash_success' => $this->ui->flash('success'),
            'flash_error'   => $this->ui->flash('error'),
        ]);
    }

    /**
     * Acciones en lote desde la lista: archivar, desarchivar o marcar como listas.
     * Sólo se tocan tareas que este usuario puede ver.
     */
    public function lote(): void
    {
        $volver = (string) ($_POST['volver'] ?? '');
        $base = $this->ui->url('tareas');
        if (!str_starts_with($volver, $base)) {
            $volver = $base;
        }
        $accion = (string) ($_POST['accion'] ?? '');
        $ids = array_values(array_filter(array_map('strval', (array) ($_POST['ids'] ?? []))));

        if ($accion === 'archivar_listas') {
            $ids = array_column(array_filter($this->service()->listAll(), fn($t) => $t['estado'] === 'hecha' && (int) ($t['archivada'] ?? 0) === 0
                && (($_POST['proyecto'] ?? '') === '' || $t['proyecto_id'] === $_POST['proyecto'])), 'id');
            $accion = 'archivar';
        }
        $visibles = $this->ui->filtrar(array_values(array_filter($this->service()->listAll(), fn($t) => in_array($t['id'], $ids, true))), 'proyecto_id');
        $ids = array_column($visibles, 'id');
        if ($ids === []) {
            $this->ui->redirect($volver, 'Marca al menos una tarea.', 'error');
            return;
        }

        switch ($accion) {
            case 'archivar':
                $n = $this->service()->archivar($ids, true);
                $this->ui->redirect($volver, $n === 1 ? 'Tarea archivada.' : "{$n} tareas archivadas.");
                return;
            case 'desarchivar':
                $n = $this->service()->archivar($ids, false);
                $this->ui->redirect($volver, $n === 1 ? 'Tarea de vuelta en la lista.' : "{$n} tareas de vuelta en la lista.");
                return;
            case 'lista':
                foreach ($visibles as $t) {
                    if ($t['estado'] === 'hecha') {
                        continue;
                    }
                    $this->service()->cambiarEstado((string) $t['id'], 'hecha');
                    if ($this->visibleParaCliente($t)) {
                        $this->actividad()->registrar((string) $t['cliente_id'], (string) $t['proyecto_id'], 'equipo', $this->ui->firma(), 'estado', 'tarea', (string) $t['id'], (string) $t['titulo'], Fmt::ESTADOS['hecha'][0]);
                    }
                }
                $this->ui->redirect($volver, count($visibles) === 1 ? 'Tarea marcada como lista.' : count($visibles) . ' tareas marcadas como listas.');
                return;
        }
        $this->ui->redirect($volver, 'Acción no válida.', 'error');
    }

    public function create(): void
    {
        if ($this->proyectos() === []) {
            $this->ui->redirect($this->ui->url('tareas'), 'Crea un proyecto primero.', 'error');
            return;
        }
        $this->ui->view('tareas/edit.latte', $this->datosFormulario(null) + [
            'flash_success' => $this->ui->flash('success'),
            'flash_error'   => $this->ui->flash('error'),
        ]);
    }

    public function store(): void
    {
        $id = $this->service()->create($_POST);
        $t  = $this->service()->find($id);

        if ($t !== null && $t['responsable_tipo'] === 'cliente') {
            $this->actividad()->registrar(
                (string) $t['cliente_id'], (string) $t['proyecto_id'], 'equipo', $this->firma(), 'asigno', 'tarea', $id, (string) $t['titulo']
            );
            if (!empty($_POST['avisar'])) {
                $this->avisarCliente($t, 'Tienes algo pendiente: ' . $t['titulo'], 'Te dejamos una nueva tarea en el portal.', ['titulo' => 'Tienes algo pendiente', 'resaltado' => 'pendiente', 'vigencia' => Vigencia::tarea((string) $t['id'])]);
            }
        }

        if ($t !== null) {
            $this->avisarAsignacion($t, null);
        }

        // Directo a la edición: ahí se adjuntan los archivos y se conversa.
        $this->ui->redirect($this->urlTarea($id), PantallaAdmin::destinoLinea('success') !== null ? 'Tarea creada.' : 'Tarea creada. Ahora puedes adjuntar archivos o dejar un comentario.');
    }

    public function edit(string $id): void
    {
        $tarea = $this->service()->find($id);
        if ($tarea === null) {
            $this->ui->redirect($this->ui->url('tareas'), 'Tarea no encontrada.', 'error');
            return;
        }
        $this->ui->view('tareas/edit.latte', $this->datosFormulario($tarea) + [
            'flash_success' => $this->ui->flash('success'),
            'flash_error'   => $this->ui->flash('error'),
        ]);
    }

    public function update(string $id): void
    {
        $antes = $this->service()->find($id);
        $this->service()->update($id, $_POST);
        $t = $this->service()->find($id);

        if ($antes !== null && $t !== null && $this->visibleParaCliente($t)) {
            $cambioEstado = $antes['estado'] !== $t['estado'];
            $seAsignoAhora = $t['responsable_tipo'] === 'cliente' && $antes['responsable_tipo'] !== 'cliente';

            if ($seAsignoAhora) {
                $this->actividad()->registrar((string) $t['cliente_id'], (string) $t['proyecto_id'], 'equipo', $this->firma(), 'asigno', 'tarea', $id, (string) $t['titulo']);
            } elseif ($cambioEstado) {
                $this->actividad()->registrar(
                    (string) $t['cliente_id'], (string) $t['proyecto_id'], 'equipo', $this->firma(), 'estado', 'tarea', $id,
                    (string) $t['titulo'], Fmt::ESTADOS[$t['estado']][0] ?? (string) $t['estado']
                );
            }
            if (!empty($_POST['avisar']) && ($cambioEstado || $seAsignoAhora)) {
                $this->avisarCliente($t, 'Novedades en: ' . $t['titulo'], $seAsignoAhora
                    ? 'Te dejamos una nueva tarea en el portal.'
                    : 'Actualizamos esta tarea: ahora está en estado «' . (Fmt::ESTADOS[$t['estado']][0] ?? $t['estado']) . '».',
                    $seAsignoAhora ? ['titulo' => 'Tienes algo pendiente', 'resaltado' => 'pendiente', 'vigencia' => Vigencia::tarea((string) $t['id'])] : ['titulo' => 'Novedades en tu tarea', 'resaltado' => 'Novedades']);
            }
        }

        if ($t !== null) {
            $this->avisarAsignacion($t, $antes);
        }
        $this->ui->redirect($this->urlTarea($id), 'Tarea actualizada.');
    }

    /**
     * «Te asignaron una tarea»: sólo a la persona del equipo que queda como responsable (si no se la
     * asignó ella misma). Si vence hoy o ya venció, no espera al correo agrupado.
     *
     * @param array<string, mixed> $t
     * @param array<string, mixed>|null $antes
     */
    private function avisarAsignacion(array $t, ?array $antes): void
    {
        $resp = (string) ($t['responsable_usuario_id'] ?? '');
        if ($t['responsable_tipo'] !== 'equipo' || $resp === '' || $resp === $this->ui->autorId()
            || ($antes !== null && $antes['responsable_tipo'] === 'equipo' && (string) $antes['responsable_usuario_id'] === $resp)) {
            return;
        }
        $fmt = new Fmt();
        $vence = (string) ($t['fecha_vencimiento'] ?? '');
        $hoy = (new \DateTimeImmutable('now', new \DateTimeZone(Zona::agencia())))->format('Y-m-d');
        $detalle = $t['proyecto_nombre'] . ($vence !== '' ? ' · para el ' . $fmt->fechaCorta($vence) : '');
        $bloques = [['tarjetas' => [['titulo' => (string) $t['titulo'], 'detalle' => $detalle]]]];
        if (trim((string) ($t['descripcion'] ?? '')) !== '') {
            $bloques[] = ['cita' => mb_substr(trim((string) $t['descripcion']), 0, 600)];
        }
        $quien = $this->ui->firma();
        (new Notifier($this->ctx, $this->pdo()))->alEquipo('Te asignaron: «' . $t['titulo'] . '»', '', 'tareas/' . $t['id'], [
            'proyecto_id' => (string) $t['proyecto_id'], 'responsable' => $resp, 'solo_responsable' => true, 'actor' => $this->ui->autorId(),
            'urgente' => $vence !== '' && substr($vence, 0, 10) <= $hoy,
            'etiqueta' => 'Tarea', 'titulo' => 'Te asignaron una tarea', 'resaltado' => 'asignaron', 'bloques' => $bloques, 'boton' => 'Abrir la tarea',
            'preheader' => ($quien !== '' ? $quien . ' te asignó: ' : '') . $t['titulo'], 'clave' => 'tareas/' . $t['id'], 'detalle' => $detalle,
            'vigencia' => Vigencia::tarea((string) $t['id']),
        ]);
    }

    public function destroy(string $id): void
    {
        // Los binarios no se borran solos con la fila: se quitan primero.
        $this->archivos()->borrarDeEntidad('tarea', $id);
        $this->comentarios()->borrarDeEntidad('tarea', $id);
        $this->service()->delete($id);
        $this->ui->redirect($this->ui->url('tareas'), 'Tarea eliminada.');
    }

    // ---- Conversación -----------------------------------------------------

    public function comentar(string $id): void
    {
        $t = $this->service()->find($id);
        if ($t === null) {
            $this->ui->redirect($this->ui->url('tareas'), 'Tarea no encontrada.', 'error');
            return;
        }
        $texto = trim((string) ($_POST['cuerpo'] ?? ''));
        if ($texto === '') {
            $this->ui->redirect($this->urlTarea($id) . '#conversacion', 'Escribe algo antes de enviar.', 'error');
            return;
        }

        $this->comentarios()->crear((string) $t['cliente_id'], 'tarea', $id, 'equipo', $this->ui->autorId(), $this->ui->firma(), $texto);
        if ($this->visibleParaCliente($t)) {
            $this->actividad()->registrar((string) $t['cliente_id'], (string) $t['proyecto_id'], 'equipo', $this->firma(), 'comento', 'tarea', $id, (string) $t['titulo'], mb_substr($texto, 0, 200));
            if (!empty($_POST['avisar'])) {
                $this->avisarCliente($t, 'Nuevo comentario en: ' . $t['titulo'], '', ['etiqueta' => 'Comentario', 'titulo' => 'Nuevo comentario del equipo', 'resaltado' => 'comentario', 'boton' => 'Ver y responder', 'preheader' => mb_substr($texto, 0, 110),
                    'bloques' => [['p' => 'En «' . $t['titulo'] . '»:'], ['cita' => mb_substr($texto, 0, 800)]]]);
            }
        }
        $this->ui->redirect($this->urlTarea($id) . '#conversacion', 'Comentario publicado.');
    }

    public function borrarComentario(string $id, string $comentarioId): void
    {
        $c = $this->comentarios()->find($comentarioId);
        if ($c !== null && $c['entidad_tipo'] === 'tarea' && $c['entidad_id'] === $id) {
            $this->comentarios()->borrar($comentarioId);
        }
        $this->ui->redirect($this->urlTarea($id) . '#conversacion', 'Comentario eliminado.');
    }

    // ---- Archivos ---------------------------------------------------------

    public function subirArchivos(string $id): void
    {
        $t = $this->service()->find($id);
        if ($t === null) {
            $this->ui->redirect($this->ui->url('tareas'), 'Tarea no encontrada.', 'error');
            return;
        }
        $volver = $this->urlTarea($id) . '#archivos';

        if (ArchivoService::postExcedido()) {
            $this->ui->redirect($volver, 'Los archivos superan el máximo del servidor (post_max_size).', 'error');
            return;
        }
        $lista = ArchivoService::normalizar($_FILES['archivos'] ?? null);
        if ($lista === []) {
            $this->ui->redirect($volver, 'Elige al menos un archivo.', 'error');
            return;
        }

        $r = $this->archivos()->guardarVarios(
            array_slice($lista, 0, 10), (string) $t['cliente_id'], (string) $t['proyecto_id'], 'tarea', $id,
            ['tipo' => 'equipo', 'id' => $this->ui->autorId(), 'nombre' => $this->firma()],
            max(1, (int) $this->ajustes()->get('global', 'portal', 'max_mb', '20'))
        );
        $n = count($r['ok']);

        if ($n > 0 && $this->visibleParaCliente($t)) {
            $this->actividad()->registrar((string) $t['cliente_id'], (string) $t['proyecto_id'], 'equipo', $this->firma(), 'subio_archivo', 'tarea', $id, (string) $t['titulo'], $n . ' archivo(s)');
            if (!empty($_POST['avisar'])) {
                $this->avisarCliente($t, 'Nuevos archivos en: ' . $t['titulo'], "Subimos {$n} archivo(s) para que los tengas a mano.", ['etiqueta' => 'Archivos', 'titulo' => 'Tienes archivos nuevos', 'resaltado' => 'archivos nuevos', 'boton' => 'Ver los archivos']);
            }
        }

        if ($r['errores'] !== []) {
            $this->ui->redirect($volver, ($n > 0 ? "Se subieron {$n}, pero: " : 'No se pudo subir: ') . implode(' · ', $r['errores']), 'error');
            return;
        }
        $this->ui->redirect($volver, $n === 1 ? 'Archivo subido.' : "{$n} archivos subidos.");
    }

    public function borrarArchivo(string $id): void
    {
        $a = $this->archivos()->find($id);
        $volver = $a !== null && $a['entidad_tipo'] === 'tarea' ? $this->urlTarea((string) $a['entidad_id']) . '#archivos' : $this->ui->url('tareas');
        if ($a !== null && $a['entidad_tipo'] === 'version') {
            $v = (new ContenidoService($this->pdo()))->version((string) $a['entidad_id']);
            $volver = $v !== null ? $this->ui->url('contenidos/' . $v['contenido_id']) : $this->ui->url('entregas');
        }
        if ($a !== null) {
            $this->archivos()->borrar($id);
        }
        $this->ui->redirect($volver, 'Archivo eliminado.');
    }

    /** Descarga / vista de un archivo desde el admin. */
    public function verArchivo(string $id): void
    {
        $a = $this->archivos()->find($id);
        if ($a === null || !$this->archivos()->enviar($a, !empty($_GET['t']), true)) {
            http_response_code(404);
            echo 'Archivo no encontrado.';
        }
        $this->terminate();
    }

    protected function terminate(): void
    {
        exit;
    }

    // ---- Correo -----------------------------------------------------------

    /** @param array<string, mixed> $t */
    private function avisarCliente(array $t, string $asunto, string $cuerpo, array $op = []): void
    {
        $contacto = $t['responsable_tipo'] === 'cliente' ? ($t['responsable_contacto_id'] ?? null) : null;
        $op += ['etiqueta' => 'Tarea', 'boton' => 'Abrir la tarea', 'bloques' => [['tarjetas' => [['titulo' => (string) $t['titulo'], 'detalle' => (string) ($t['proyecto_nombre'] ?? '')]]]]];
        (new Notifier($this->ctx, $this->pdo()))->alCliente(
            (string) $t['cliente_id'], $contacto ? (string) $contacto : null, $asunto, $cuerpo, '/portal/tareas/' . $t['id'], $op
        );
    }
}

<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

use TypeDock\Core\PluginContext;

class TareaAdminController
{
    public function __construct(private readonly PluginContext $ctx) {}

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
        return (new ProyectoService($this->pdo()))->listAll();
    }

    private function reuniones(): array
    {
        return (new ReunionService($this->pdo()))->listAll();
    }

    /** Usuarios de Core (equipo) que pueden ser responsables. */
    private function usuariosEquipo(): array
    {
        $stmt = $this->pdo()->query('SELECT id, name FROM users ORDER BY name');
        return $stmt ? $stmt->fetchAll() : [];
    }

    /** Cómo firma el equipo los comentarios y archivos (Portal · Ajustes). */
    private function firma(): string
    {
        $n = trim($this->ajustes()->get('global', 'portal', 'nombre_equipo'));
        return $n !== '' ? $n : 'Equipo';
    }

    /** ¿Ve el cliente esta tarea? Sólo entonces tiene sentido registrar actividad / avisar. */
    private function visibleParaCliente(array $t): bool
    {
        return (int) ($t['visible_cliente'] ?? 1) === 1 || ($t['responsable_tipo'] ?? '') === 'cliente';
    }

    private function urlTarea(string $id): string
    {
        return $this->ctx->adminUrl('tareas/' . $id);
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
            'contactos' => $this->service()->contactosAsignables(),
            'fases'     => $this->service()->todasLasFases(),
            'firma'     => $this->firma(),
            'fmt'       => new Fmt(),
            'maxMb'     => (int) round($this->archivos()->limiteBytes(max(1, (int) $this->ajustes()->get('global', 'portal', 'max_mb', '20'))) / 1048576),
            'estados'   => Fmt::ESTADOS,
            'tipos'     => Fmt::TIPOS,
        ] + $extra;
    }

    public function index(): void
    {
        $this->ctx->view('templates/admin/tareas/index.latte', [
            'tareas'        => $this->service()->listAll(),
            'fmt'           => new Fmt(),
            'flash_success' => $this->ctx->getFlash('success'),
            'flash_error'   => $this->ctx->getFlash('error'),
        ]);
    }

    public function create(): void
    {
        if ($this->proyectos() === []) {
            $this->ctx->redirect($this->ctx->adminUrl('tareas'), 'Crea un proyecto primero.', 'error');
            return;
        }
        $this->ctx->view('templates/admin/tareas/edit.latte', $this->datosFormulario(null) + [
            'flash_success' => $this->ctx->getFlash('success'),
            'flash_error'   => $this->ctx->getFlash('error'),
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
                $this->avisarCliente($t, 'Tienes algo pendiente: ' . $t['titulo'], 'Te dejamos una nueva tarea en el portal.', ['titulo' => 'Tienes algo pendiente', 'resaltado' => 'pendiente']);
            }
        }

        // Directo a la edición: ahí se adjuntan los archivos y se conversa.
        $this->ctx->redirect($this->urlTarea($id), 'Tarea creada. Ahora puedes adjuntar archivos o dejar un comentario.');
    }

    public function edit(string $id): void
    {
        $tarea = $this->service()->find($id);
        if ($tarea === null) {
            $this->ctx->redirect($this->ctx->adminUrl('tareas'), 'Tarea no encontrada.', 'error');
            return;
        }
        $this->ctx->view('templates/admin/tareas/edit.latte', $this->datosFormulario($tarea) + [
            'flash_success' => $this->ctx->getFlash('success'),
            'flash_error'   => $this->ctx->getFlash('error'),
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
                    $seAsignoAhora ? ['titulo' => 'Tienes algo pendiente', 'resaltado' => 'pendiente'] : ['titulo' => 'Novedades en tu tarea', 'resaltado' => 'Novedades']);
            }
        }

        $this->ctx->redirect($this->urlTarea($id), 'Tarea actualizada.');
    }

    public function destroy(string $id): void
    {
        // Los binarios no se borran solos con la fila: se quitan primero.
        $this->archivos()->borrarDeEntidad('tarea', $id);
        $this->comentarios()->borrarDeEntidad('tarea', $id);
        $this->service()->delete($id);
        $this->ctx->redirect($this->ctx->adminUrl('tareas'), 'Tarea eliminada.');
    }

    // ---- Conversación -----------------------------------------------------

    public function comentar(string $id): void
    {
        $t = $this->service()->find($id);
        if ($t === null) {
            $this->ctx->redirect($this->ctx->adminUrl('tareas'), 'Tarea no encontrada.', 'error');
            return;
        }
        $texto = trim((string) ($_POST['cuerpo'] ?? ''));
        if ($texto === '') {
            $this->ctx->redirect($this->urlTarea($id) . '#conversacion', 'Escribe algo antes de enviar.', 'error');
            return;
        }

        $this->comentarios()->crear((string) $t['cliente_id'], 'tarea', $id, 'equipo', null, $this->firma(), $texto);
        if ($this->visibleParaCliente($t)) {
            $this->actividad()->registrar((string) $t['cliente_id'], (string) $t['proyecto_id'], 'equipo', $this->firma(), 'comento', 'tarea', $id, (string) $t['titulo'], mb_substr($texto, 0, 200));
            if (!empty($_POST['avisar'])) {
                $this->avisarCliente($t, 'Nuevo comentario en: ' . $t['titulo'], '', ['etiqueta' => 'Comentario', 'titulo' => 'Nuevo comentario del equipo', 'resaltado' => 'comentario', 'boton' => 'Ver y responder', 'preheader' => mb_substr($texto, 0, 110),
                    'bloques' => [['p' => 'En «' . $t['titulo'] . '»:'], ['cita' => mb_substr($texto, 0, 800)]]]);
            }
        }
        $this->ctx->redirect($this->urlTarea($id) . '#conversacion', 'Comentario publicado.');
    }

    public function borrarComentario(string $id, string $comentarioId): void
    {
        $c = $this->comentarios()->find($comentarioId);
        if ($c !== null && $c['entidad_tipo'] === 'tarea' && $c['entidad_id'] === $id) {
            $this->comentarios()->borrar($comentarioId);
        }
        $this->ctx->redirect($this->urlTarea($id) . '#conversacion', 'Comentario eliminado.');
    }

    // ---- Archivos ---------------------------------------------------------

    public function subirArchivos(string $id): void
    {
        $t = $this->service()->find($id);
        if ($t === null) {
            $this->ctx->redirect($this->ctx->adminUrl('tareas'), 'Tarea no encontrada.', 'error');
            return;
        }
        $volver = $this->urlTarea($id) . '#archivos';

        if (ArchivoService::postExcedido()) {
            $this->ctx->redirect($volver, 'Los archivos superan el máximo del servidor (post_max_size).', 'error');
            return;
        }
        $lista = ArchivoService::normalizar($_FILES['archivos'] ?? null);
        if ($lista === []) {
            $this->ctx->redirect($volver, 'Elige al menos un archivo.', 'error');
            return;
        }

        $r = $this->archivos()->guardarVarios(
            array_slice($lista, 0, 10), (string) $t['cliente_id'], (string) $t['proyecto_id'], 'tarea', $id,
            ['tipo' => 'equipo', 'id' => null, 'nombre' => $this->firma()],
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
            $this->ctx->redirect($volver, ($n > 0 ? "Se subieron {$n}, pero: " : 'No se pudo subir: ') . implode(' · ', $r['errores']), 'error');
            return;
        }
        $this->ctx->redirect($volver, $n === 1 ? 'Archivo subido.' : "{$n} archivos subidos.");
    }

    public function borrarArchivo(string $id): void
    {
        $a = $this->archivos()->find($id);
        $volver = $a !== null && $a['entidad_tipo'] === 'tarea' ? $this->urlTarea((string) $a['entidad_id']) . '#archivos' : $this->ctx->adminUrl('tareas');
        if ($a !== null && $a['entidad_tipo'] === 'version') {
            $v = (new ContenidoService($this->pdo()))->version((string) $a['entidad_id']);
            $volver = $v !== null ? $this->ctx->adminUrl('contenidos/' . $v['contenido_id']) : $this->ctx->adminUrl('entregas');
        }
        if ($a !== null) {
            $this->archivos()->borrar($id);
        }
        $this->ctx->redirect($volver, 'Archivo eliminado.');
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

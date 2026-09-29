<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

use TypeDock\Core\PluginContext;

/** Admin de entregas: paquetes de contenido que el cliente revisa. */
class EntregaAdminController
{
    public function __construct(private readonly PluginContext $ctx) {}

    private function pdo(): \PDO
    {
        return $this->ctx->db()->pdo();
    }

    private function entregas(): EntregaService
    {
        return new EntregaService($this->pdo());
    }

    private function contenidos(): ContenidoService
    {
        return new ContenidoService($this->pdo());
    }

    private function archivos(): ArchivoService
    {
        return new ArchivoService($this->pdo());
    }

    private function ajustes(): AjustesService
    {
        return new AjustesService($this->pdo());
    }

    private function firma(): string
    {
        $n = trim($this->ajustes()->get('global', 'portal', 'nombre_equipo'));
        return $n !== '' ? $n : 'Equipo';
    }

    private function maxMb(): int
    {
        return max(1, (int) $this->ajustes()->get('global', 'portal', 'max_mb', '20'));
    }

    private function url(string $ruta): string
    {
        return $this->ctx->adminUrl($ruta);
    }

    private function flashes(): array
    {
        return ['flash_success' => $this->ctx->getFlash('success'), 'flash_error' => $this->ctx->getFlash('error')];
    }

    // ---- Entregas ---------------------------------------------------------

    public function index(): void
    {
        $this->ctx->view('templates/admin/entregas/index.latte', [
            'entregas' => $this->entregas()->listAll(),
            'estados'  => TiposContenido::ESTADOS_ENTREGA,
            'fmt'      => new Fmt(),
        ] + $this->flashes());
    }

    public function create(): void
    {
        $proyectos = (new ProyectoService($this->pdo()))->listAll();
        if ($proyectos === []) {
            $this->ctx->redirect($this->url('entregas'), 'Crea un proyecto primero.', 'error');
            return;
        }
        $this->ctx->view('templates/admin/entregas/edit.latte', [
            'entrega' => null, 'proyectos' => $proyectos, 'contenidos' => [], 'fmt' => new Fmt(),
            'estados' => TiposContenido::ESTADOS_ENTREGA, 'tipos' => TiposContenido::TIPOS,
            'estadosContenido' => TiposContenido::ESTADOS_CONTENIDO, 'reacciones' => TiposContenido::REACCIONES,
            'portadas' => [], 'resumenReacc' => [], 'maxMb' => $this->maxMb(),
        ] + $this->flashes());
    }

    public function store(): void
    {
        // Si se sube una planilla al crear, el título puede salir del nombre del archivo.
        $planilla = ArchivoService::normalizar($_FILES['archivos'] ?? null);
        if (trim((string) ($_POST['titulo'] ?? '')) === '' && $planilla !== []) {
            $_POST['titulo'] = pathinfo($this->archivos()->nombreSeguro((string) $planilla[0]['name']), PATHINFO_FILENAME);
        }
        try {
            $id = $this->entregas()->create($_POST);
        } catch (\InvalidArgumentException) {
            $this->ctx->redirect($this->url('entregas/nuevo'), 'Elige un proyecto válido.', 'error');
            return;
        }
        if ($planilla !== [] && ($e = $this->entregas()->find($id)) !== null) {
            $r = $this->expandir($e, array_slice($planilla, 0, 30), (string) ($_POST['tipo'] ?? ''), trim((string) ($_POST['cuenta'] ?? '')));
            $this->ctx->redirect($this->url('entregas/' . $id), 'Entrega creada. ' . $r['mensaje']);
            return;
        }
        $this->ctx->redirect($this->url('entregas/' . $id), 'Entrega creada. Agrega los contenidos y luego publícala.');
    }

    public function edit(string $id): void
    {
        $e = $this->entregas()->find($id);
        if ($e === null) {
            $this->ctx->redirect($this->url('entregas'), 'Entrega no encontrada.', 'error');
            return;
        }
        $lista = $this->contenidos()->listar($id);
        $portadas = [];
        $resumen  = [];
        foreach ($lista as $c) {
            if ($c['version_id'] !== null) {
                foreach ($this->contenidos()->archivos((string) $c['version_id']) as $a) {
                    if ((new Fmt())->esImagen($a['mime'])) {
                        $portadas[$c['id']] = $a['id'];
                        break;
                    }
                }
                $resumen[$c['id']] = $this->contenidos()->reacciones((string) $c['version_id']);
            }
        }
        $this->ctx->view('templates/admin/entregas/edit.latte', [
            'entrega' => $e, 'proyectos' => [], 'contenidos' => $lista, 'fmt' => new Fmt(),
            'estados' => TiposContenido::ESTADOS_ENTREGA, 'tipos' => TiposContenido::TIPOS,
            'estadosContenido' => TiposContenido::ESTADOS_CONTENIDO, 'reacciones' => TiposContenido::REACCIONES,
            'portadas' => $portadas, 'resumenReacc' => $resumen, 'maxMb' => $this->maxMb(),
        ] + $this->flashes());
    }

    public function update(string $id): void
    {
        if ($this->entregas()->find($id) !== null) {
            $this->entregas()->update($id, $_POST);
        }
        $this->ctx->redirect($this->url('entregas/' . $id), 'Entrega actualizada.');
    }

    public function destroy(string $id): void
    {
        $this->entregas()->delete($id);
        $this->ctx->redirect($this->url('entregas'), 'Entrega eliminada con todos sus contenidos.');
    }

    public function publicar(string $id): void
    {
        $e = $this->entregas()->find($id);
        if ($e === null) {
            $this->ctx->redirect($this->url('entregas'), 'Entrega no encontrada.', 'error');
            return;
        }
        if ((int) $e['n_total'] === 0) {
            $this->ctx->redirect($this->url('entregas/' . $id), 'Agrega al menos un contenido antes de publicar.', 'error');
            return;
        }
        $this->entregas()->publicar($id);
        (new ActividadService($this->pdo()))->registrar(
            (string) $e['cliente_id'], (string) $e['proyecto_id'], 'equipo', $this->firma(), 'publico', 'entrega', $id, (string) $e['titulo'],
            $e['n_total'] . ' contenido(s)'
        );
        if (!empty($_POST['avisar'])) {
            $cuerpo = trim((string) $e['mensaje']) !== '' ? (string) $e['mensaje'] : 'Dejamos contenido listo para que lo revises.';
            (new Notifier($this->ctx, $this->pdo()))->alCliente(
                (string) $e['cliente_id'], null, 'Tienes contenido para revisar: ' . $e['titulo'], $cuerpo, '/portal/entregas/' . $id,
                ['etiqueta' => 'Revisión', 'titulo' => 'Tienes contenido para revisar', 'resaltado' => 'contenido', 'boton' => 'Revisar contenido',
                 'bloques' => [['tarjetas' => [['titulo' => (string) $e['titulo'], 'detalle' => ((int) $e['n_total']) . ' contenido(s) esperando tu opinión', 'chip' => 'Para revisar']]]]]
            );
        }
        $this->ctx->redirect($this->url('entregas/' . $id), 'Entrega publicada: el cliente ya la ve en su portal.');
    }

    public function borrador(string $id): void
    {
        $this->entregas()->volverABorrador($id);
        $this->ctx->redirect($this->url('entregas/' . $id), 'La entrega volvió a borrador: el cliente ya no la ve.');
    }

    // ---- Contenidos -------------------------------------------------------

    /** Un contenido, con todos los archivos que se hayan elegido como su primera versión. */
    public function agregarContenido(string $id): void
    {
        $e = $this->entregas()->find($id);
        if ($e === null) {
            $this->ctx->redirect($this->url('entregas'), 'Entrega no encontrada.', 'error');
            return;
        }
        $volver = $this->url('entregas/' . $id) . '#contenidos';
        if (ArchivoService::postExcedido()) {
            $this->ctx->redirect($volver, 'Los archivos superan el máximo del servidor (post_max_size).', 'error');
            return;
        }
        $lista = array_slice(ArchivoService::normalizar($_FILES['archivos'] ?? null), 0, 10);
        foreach ($lista as $f) {
            if (strtolower(pathinfo((string) ($f['name'] ?? ''), PATHINFO_EXTENSION)) === 'csv') {
                $this->ctx->redirect($volver, 'Una planilla .csv no va aquí: súbela en «Subir archivos o planilla» y se despliega en un contenido por fila.', 'error');
                return;
            }
        }
        $enlace = TiposContenido::enlaceSeguro((string) ($_POST['enlace'] ?? ''));
        if (trim((string) ($_POST['enlace'] ?? '')) !== '' && $enlace === '') {
            $this->ctx->redirect($volver, 'El link debe empezar con http:// o https://', 'error');
            return;
        }

        [$cid, $vid] = $this->contenidos()->crear($e, $_POST);
        $errores = [];
        if ($lista !== []) {
            $r = $this->archivos()->guardarVarios($lista, (string) $e['cliente_id'], (string) $e['proyecto_id'], 'version', $vid, $this->autor(), $this->maxMb());
            $errores = $r['errores'];
        }
        $this->reabrirSiCorresponde($e);
        $this->ctx->redirect($volver, $errores === [] ? 'Contenido agregado.' : 'Contenido agregado, pero: ' . implode(' · ', $errores), $errores === [] ? 'success' : 'error');
    }

    /**
     * Una sola caja para todo lo masivo:
     *  - una planilla .csv se despliega en un contenido por fila;
     *  - cualquier otro archivo pasa a ser un contenido propio (tipo automático según el archivo).
     */
    public function subidaMasiva(string $id): void
    {
        $e = $this->entregas()->find($id);
        if ($e === null) {
            $this->ctx->redirect($this->url('entregas'), 'Entrega no encontrada.', 'error');
            return;
        }
        $volver = $this->url('entregas/' . $id) . '#contenidos';
        if (ArchivoService::postExcedido()) {
            $this->ctx->redirect($volver, 'Los archivos superan el máximo del servidor (post_max_size).', 'error');
            return;
        }
        $lista = array_slice(ArchivoService::normalizar($_FILES['archivos'] ?? null), 0, 30);
        if ($lista === []) {
            $this->ctx->redirect($volver, 'Elige al menos un archivo o una planilla.', 'error');
            return;
        }
        $r = $this->expandir($e, $lista, (string) ($_POST['tipo'] ?? ''), trim((string) ($_POST['cuenta'] ?? '')));
        $this->ctx->redirect($volver, $r['mensaje'], $r['creados'] > 0 ? 'success' : 'error');
    }

    /**
     * @param array<string, mixed> $e entrega
     * @param array<int, array<string, mixed>> $lista archivos subidos
     * @return array{creados: int, mensaje: string}
     */
    private function expandir(array $e, array $lista, string $tipoElegido, string $cuenta): array
    {
        $creados = 0;
        $avisos = [];
        $planillas = 0;

        foreach ($lista as $f) {
            $nombre = $this->archivos()->nombreSeguro((string) ($f['name'] ?? ''));
            $ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));

            if ($ext === 'csv') {
                $planillas++;
                if ((int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    $avisos[] = $nombre . ': no se pudo subir';
                    continue;
                }
                $r = ImportadorContenidos::leer((string) $f['tmp_name']);
                foreach ($r['filas'] as $fila) {
                    $fila['cuenta'] = $fila['cuenta'] !== '' ? $fila['cuenta'] : $cuenta;
                    $this->contenidos()->crear($e, $fila);
                    $creados++;
                }
                $avisos = array_merge($avisos, $r['errores']);
                continue;
            }

            // Primero el archivo: si es inválido no dejamos un contenido vacío.
            $tipo = TiposContenido::valido($tipoElegido) ? $tipoElegido : self::tipoPorExtension($ext);
            [$cid, $vid] = $this->contenidos()->crear($e, ['tipo' => $tipo, 'titulo' => pathinfo($nombre, PATHINFO_FILENAME), 'cuenta' => $cuenta]);
            $r = $this->archivos()->guardarVarios([$f], (string) $e['cliente_id'], (string) $e['proyecto_id'], 'version', $vid, $this->autor(), $this->maxMb());
            if ($r['ok'] === []) {
                $this->contenidos()->borrar($cid);
                $avisos = array_merge($avisos, $r['errores']);
            } else {
                $creados++;
            }
        }

        if ($creados > 0) {
            $this->reabrirSiCorresponde($e);
        }
        $msg = $creados > 0
            ? $creados . ' contenido(s) creados' . ($planillas > 0 ? ' desde la planilla. Ahora súbeles las imágenes a cada uno.' : '.')
            : 'No se creó ningún contenido.';
        if ($avisos !== []) {
            $msg .= ' Avisos: ' . implode(' · ', array_slice($avisos, 0, 6));
        }
        return ['creados' => $creados, 'mensaje' => $msg];
    }

    private static function tipoPorExtension(string $ext): string
    {
        return match (true) {
            in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true) => 'post',
            in_array($ext, ['mp4', 'mov'], true) => 'reel',
            $ext === 'pdf' => 'brandbook',
            default => 'otro',
        };
    }

    /** Descarga la plantilla en blanco. */
    public function plantilla(): void
    {
        $this->limpiarSalida();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="plantilla-contenidos.csv"');
        echo ImportadorContenidos::plantilla();
        $this->terminate();
    }

    /** Descarta salida previa (avisos, espacios) para no corromper la descarga. */
    protected function limpiarSalida(): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }

    protected function terminate(): void
    {
        exit;
    }

    /** Si la entrega ya estaba respondida, agregar contenido nuevo la vuelve a abrir. */
    private function reabrirSiCorresponde(array $e): void
    {
        $this->entregas()->reabrir((string) $e['id']);
    }

    /** @return array{tipo: string, id: ?string, nombre: string} */
    private function autor(): array
    {
        return ['tipo' => 'equipo', 'id' => null, 'nombre' => $this->firma()];
    }

    public function editContenido(string $id): void
    {
        $c = $this->contenidos()->find($id);
        if ($c === null) {
            $this->ctx->redirect($this->url('entregas'), 'Contenido no encontrado.', 'error');
            return;
        }
        $versiones = [];
        foreach (array_reverse($this->contenidos()->versiones($id)) as $v) {
            $v['archivos']    = $this->contenidos()->archivos((string) $v['id']);
            $v['reacciones']  = $this->contenidos()->reacciones((string) $v['id']);
            $versiones[] = $v;
        }
        $this->ctx->view('templates/admin/entregas/contenido.latte', [
            'c' => $c, 'versiones' => $versiones, 'tipos' => TiposContenido::TIPOS,
            'reacciones' => TiposContenido::REACCIONES, 'estadosContenido' => TiposContenido::ESTADOS_CONTENIDO,
            'comentarios' => (new ComentarioService($this->pdo()))->listar('contenido', $id),
            'fmt' => new Fmt(), 'firma' => $this->firma(), 'maxMb' => $this->maxMb(),
        ] + $this->flashes());
    }

    public function updateContenido(string $id): void
    {
        if ($this->contenidos()->find($id) !== null) {
            if (trim((string) ($_POST['enlace'] ?? '')) !== '' && TiposContenido::enlaceSeguro((string) $_POST['enlace']) === '') {
                $this->ctx->redirect($this->url('contenidos/' . $id), 'El link debe empezar con http:// o https://', 'error');
                return;
            }
            $this->contenidos()->actualizar($id, $_POST);
        }
        $this->ctx->redirect($this->url('contenidos/' . $id), 'Contenido actualizado.');
    }

    public function borrarContenido(string $id): void
    {
        $c = $this->contenidos()->find($id);
        $vol = $c !== null ? $this->url('entregas/' . $c['entrega_id']) . '#contenidos' : $this->url('entregas');
        if ($c !== null) {
            $this->contenidos()->borrar($id);
        }
        $this->ctx->redirect($vol, 'Contenido eliminado.');
    }

    public function moverContenido(string $id): void
    {
        $c = $this->contenidos()->find($id);
        if ($c !== null) {
            $this->contenidos()->mover($id, ($_POST['dir'] ?? '') === 'arriba' ? -1 : 1);
        }
        $this->ctx->redirect($c !== null ? $this->url('entregas/' . $c['entrega_id']) . '#contenidos' : $this->url('entregas'));
    }

    /** Sube una versión nueva (v2, v3…): el contenido vuelve a "por revisar". */
    public function nuevaVersion(string $id): void
    {
        $c = $this->contenidos()->find($id);
        if ($c === null) {
            $this->ctx->redirect($this->url('entregas'), 'Contenido no encontrado.', 'error');
            return;
        }
        $volver = $this->url('contenidos/' . $id);
        if (ArchivoService::postExcedido()) {
            $this->ctx->redirect($volver, 'Los archivos superan el máximo del servidor (post_max_size).', 'error');
            return;
        }
        if (trim((string) ($_POST['enlace'] ?? '')) !== '' && TiposContenido::enlaceSeguro((string) $_POST['enlace']) === '') {
            $this->ctx->redirect($volver, 'El link debe empezar con http:// o https://', 'error');
            return;
        }
        $lista = array_slice(ArchivoService::normalizar($_FILES['archivos'] ?? null), 0, 10);
        $vid = $this->contenidos()->nuevaVersion($id, $_POST);
        $errores = [];
        if ($vid !== null && $lista !== []) {
            $errores = $this->archivos()->guardarVarios($lista, (string) $c['cliente_id'], (string) $c['proyecto_id'], 'version', $vid, $this->autor(), $this->maxMb())['errores'];
        }
        if ($vid !== null && !empty($_POST['avisar'])) {
            (new Notifier($this->ctx, $this->pdo()))->alCliente(
                (string) $c['cliente_id'], null, 'Nueva versión para revisar: ' . $c['titulo'],
                'Subimos una nueva versión de «' . $c['titulo'] . '» en «' . $c['entrega_titulo'] . '». Échale un vistazo cuando puedas.', '/portal/entregas/' . $c['entrega_id'],
                ['etiqueta' => 'Nueva versión', 'titulo' => 'Hay una nueva versión para revisar', 'resaltado' => 'nueva versión', 'boton' => 'Ver la nueva versión',
                 'bloques' => [['tarjetas' => [['titulo' => (string) $c['titulo'], 'detalle' => (string) $c['entrega_titulo'], 'chip' => 'v' . ((int) $c['version_actual'] + 1)]]]]]
            );
        }
        if ($vid !== null) {
            (new ActividadService($this->pdo()))->registrar(
                (string) $c['cliente_id'], (string) $c['proyecto_id'], 'equipo', $this->firma(), 'subio_version', 'contenido', $id, (string) $c['titulo'],
                'v' . ((int) $c['version_actual'] + 1)
            );
        }
        $this->ctx->redirect($volver, $errores === [] ? 'Versión nueva publicada.' : 'Versión creada, pero: ' . implode(' · ', $errores), $errores === [] ? 'success' : 'error');
    }

    /** Agrega más archivos a la versión vigente (por ejemplo, otra lámina del carrusel). */
    public function agregarArchivos(string $id): void
    {
        $c = $this->contenidos()->find($id);
        if ($c === null || $c['version_id'] === null) {
            $this->ctx->redirect($this->url('entregas'), 'Contenido no encontrado.', 'error');
            return;
        }
        $volver = $this->url('contenidos/' . $id);
        if (ArchivoService::postExcedido()) {
            $this->ctx->redirect($volver, 'Los archivos superan el máximo del servidor (post_max_size).', 'error');
            return;
        }
        $lista = array_slice(ArchivoService::normalizar($_FILES['archivos'] ?? null), 0, 10);
        if ($lista === []) {
            $this->ctx->redirect($volver, 'Elige al menos un archivo.', 'error');
            return;
        }
        $r = $this->archivos()->guardarVarios($lista, (string) $c['cliente_id'], (string) $c['proyecto_id'], 'version', (string) $c['version_id'], $this->autor(), $this->maxMb());
        $n = count($r['ok']);
        $this->ctx->redirect($volver, $r['errores'] === [] ? ($n === 1 ? 'Archivo subido.' : "{$n} archivos subidos.") : ($n > 0 ? "Se subieron {$n}, pero: " : 'No se pudo subir: ') . implode(' · ', $r['errores']), $r['errores'] === [] ? 'success' : 'error');
    }

    // ---- Conversación -----------------------------------------------------

    public function comentar(string $id): void
    {
        $c = $this->contenidos()->find($id);
        if ($c === null) {
            $this->ctx->redirect($this->url('entregas'), 'Contenido no encontrado.', 'error');
            return;
        }
        $volver = $this->url('contenidos/' . $id) . '#conversacion';
        $texto = trim((string) ($_POST['cuerpo'] ?? ''));
        if ($texto === '') {
            $this->ctx->redirect($volver, 'Escribe algo antes de enviar.', 'error');
            return;
        }
        (new ComentarioService($this->pdo()))->crear((string) $c['cliente_id'], 'contenido', $id, 'equipo', null, $this->firma(), $texto, $c['version_id']);
        if ($c['entrega_estado'] !== 'borrador') {
            (new ActividadService($this->pdo()))->registrar(
                (string) $c['cliente_id'], (string) $c['proyecto_id'], 'equipo', $this->firma(), 'comento', 'contenido', $id, (string) $c['titulo'], mb_substr($texto, 0, 200)
            );
            if (!empty($_POST['avisar'])) {
                (new Notifier($this->ctx, $this->pdo()))->alCliente(
                    (string) $c['cliente_id'], null, 'Nuevo comentario en: ' . $c['titulo'], '', '/portal/contenidos/' . $id,
                    ['etiqueta' => 'Comentario', 'titulo' => 'Nuevo comentario del equipo', 'resaltado' => 'comentario', 'boton' => 'Ver y responder',
                     'preheader' => mb_substr($texto, 0, 110),
                     'bloques' => [['p' => 'En «' . $c['titulo'] . '»:'], ['cita' => mb_substr($texto, 0, 800)]]]
                );
            }
        }
        $this->ctx->redirect($volver, 'Comentario publicado.');
    }

    public function borrarComentario(string $id, string $comentarioId): void
    {
        $cm = new ComentarioService($this->pdo());
        $x = $cm->find($comentarioId);
        if ($x !== null && $x['entidad_tipo'] === 'contenido' && $x['entidad_id'] === $id) {
            $cm->borrar($comentarioId);
        }
        $this->ctx->redirect($this->url('contenidos/' . $id) . '#conversacion', 'Comentario eliminado.');
    }
}

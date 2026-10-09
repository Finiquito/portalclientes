<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

use TypeDock\Core\PluginContext;

/** Admin de entregas: paquetes de contenido que el cliente revisa. */
class EntregaAdminController
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
        return $this->ui->firma();
    }

    private function maxMb(): int
    {
        return max(1, (int) $this->ajustes()->get('global', 'portal', 'max_mb', '20'));
    }

    private function url(string $ruta): string
    {
        return $this->ui->url($ruta);
    }

    private function flashes(): array
    {
        return ['flash_success' => $this->ui->flash('success'), 'flash_error' => $this->ui->flash('error')];
    }

    // ---- Entregas ---------------------------------------------------------

    public const FILTRO_ESTADOS = [
        'activas'    => 'Activas',
        'borrador'   => 'Borradores',
        'publicada'  => 'Esperando al cliente',
        'respondida' => 'Respondidas',
        'aprobada'   => 'Aprobadas',
        'todas'      => 'Todas',
    ];

    public function index(): void
    {
        $f = FiltrosLista::desdeGet($this->url('entregas'), ['estado' => 'activas', 'proyecto' => ''], [
            'estado'   => array_keys(self::FILTRO_ESTADOS),
            'proyecto' => 'uuid',
        ]);
        $todas = $this->ui->filtrar($this->entregas()->listAll(), 'proyecto_id');
        $base = array_values(array_filter($todas, fn(array $e): bool => $f->get('proyecto') === '' || $e['proyecto_id'] === $f->get('proyecto')));
        $en = static fn(array $e, string $g): bool => match ($g) {
            'todas'   => true,
            'activas' => $e['estado'] !== 'aprobada',
            default   => $e['estado'] === $g,
        };
        $conteos = [];
        foreach (array_keys(self::FILTRO_ESTADOS) as $g) {
            $conteos[$g] = count(array_filter($base, fn($e) => $en($e, $g)));
        }
        // Lo que espera al equipo (respondidas) arriba; luego lo más reciente.
        $lista = array_values(array_filter($base, fn($e) => $en($e, $f->get('estado'))));
        usort($lista, fn($a, $b) => [$a['estado'] !== 'respondida', $b['updated_at']] <=> [$b['estado'] !== 'respondida', $a['updated_at']]);
        $proyectos = $this->ui->filtrar((new ProyectoService($this->pdo()))->listAll(), 'id');
        usort($proyectos, fn($a, $b) => [$a['cliente_nombre'], $a['nombre']] <=> [$b['cliente_nombre'], $b['nombre']]);

        $this->ui->view('entregas/index.latte', [
            'entregas'   => $lista,
            'filtros'    => $f,
            'conteos'    => $conteos,
            'grupos'     => self::FILTRO_ESTADOS,
            'proyectosF' => $proyectos,
            'colorProy'  => Fmt::coloresTodos($this->pdo()),
            'estados'  => TiposContenido::ESTADOS_ENTREGA,
            'fmt'      => new Fmt(),
        ] + $this->flashes());
    }

    public function create(): void
    {
        $proyectos = $this->ui->filtrar((new ProyectoService($this->pdo()))->porMovimiento(), 'id');
        if ($proyectos === []) {
            $this->ui->redirect($this->url('entregas'), 'Crea un proyecto primero.', 'error');
            return;
        }
        $this->ui->view('entregas/edit.latte', [
            'entrega' => null, 'proyectos' => $proyectos, 'contenidos' => [], 'fmt' => new Fmt(),
            'fasesRango' => $this->ui->filtrar((new FaseService($this->pdo()))->conRangos(), 'proyecto_id'),
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
            $this->ui->redirect($this->url('entregas/nuevo'), 'Elige un proyecto válido.', 'error');
            return;
        }
        if ($planilla !== [] && ($e = $this->entregas()->find($id)) !== null) {
            $r = $this->expandir($e, array_slice($planilla, 0, 30), (string) ($_POST['tipo'] ?? ''), trim((string) ($_POST['cuenta'] ?? '')));
            $this->ui->redirect($this->url('entregas/' . $id), 'Entrega creada. ' . $r['mensaje']);
            return;
        }
        $this->ui->redirect($this->url('entregas/' . $id), 'Entrega creada. Agrega los contenidos y luego publícala.');
    }

    public function edit(string $id): void
    {
        $e = $this->entregas()->find($id);
        if ($e === null) {
            $this->ui->redirect($this->url('entregas'), 'Entrega no encontrada.', 'error');
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
        $this->ui->view('entregas/edit.latte', [
            'entrega' => $e, 'proyectos' => [], 'contenidos' => $lista, 'fmt' => new Fmt(),
            'fasesRango' => $this->ui->filtrar((new FaseService($this->pdo()))->conRangos(), 'proyecto_id'),
            'estados' => TiposContenido::ESTADOS_ENTREGA, 'tipos' => TiposContenido::TIPOS,
            'estadosContenido' => TiposContenido::ESTADOS_CONTENIDO, 'reacciones' => TiposContenido::REACCIONES,
            'portadas' => $portadas, 'resumenReacc' => $resumen, 'maxMb' => $this->maxMb(),
            'iaActiva' => (new IaService($this->pdo()))->activa(),
        ] + $this->flashes());
    }

    public function update(string $id): void
    {
        if ($this->entregas()->find($id) !== null) {
            $this->entregas()->update($id, $_POST);
        }
        $this->ui->redirect($this->url('entregas/' . $id), 'Entrega actualizada.');
    }

    public function destroy(string $id): void
    {
        $this->entregas()->delete($id);
        $this->ui->redirect($this->url('entregas'), 'Entrega eliminada con todos sus contenidos.');
    }

    public function publicar(string $id): void
    {
        $e = $this->entregas()->find($id);
        if ($e === null) {
            $this->ui->redirect($this->url('entregas'), 'Entrega no encontrada.', 'error');
            return;
        }
        if ((int) $e['n_total'] === 0) {
            $this->ui->redirect($this->url('entregas/' . $id), 'Agrega al menos un contenido antes de publicar.', 'error');
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
                 'bloques' => [['tarjetas' => [['titulo' => (string) $e['titulo'], 'detalle' => ((int) $e['n_total']) . ' contenido(s) esperando tu opinión', 'chip' => 'Para revisar']]]],
                 'vigencia' => Vigencia::revision($id)]
            );
        }
        $this->ui->redirect($this->url('entregas/' . $id), 'Entrega publicada: el cliente ya la ve en su portal.');
    }

    public function borrador(string $id): void
    {
        $this->entregas()->volverABorrador($id);
        $this->ui->redirect($this->url('entregas/' . $id), 'La entrega volvió a borrador: el cliente ya no la ve.');
    }

    // ---- Contenidos -------------------------------------------------------

    /** Un contenido, con todos los archivos que se hayan elegido como su primera versión. */
    public function agregarContenido(string $id): void
    {
        $e = $this->entregas()->find($id);
        if ($e === null) {
            $this->ui->redirect($this->url('entregas'), 'Entrega no encontrada.', 'error');
            return;
        }
        $volver = $this->url('entregas/' . $id) . '#contenidos';
        if (ArchivoService::postExcedido()) {
            $this->ui->redirect($volver, 'Los archivos superan el máximo del servidor (post_max_size).', 'error');
            return;
        }
        $lista = array_slice(ArchivoService::normalizar($_FILES['archivos'] ?? null), 0, 10);
        foreach ($lista as $f) {
            if (strtolower(pathinfo((string) ($f['name'] ?? ''), PATHINFO_EXTENSION)) === 'csv') {
                $this->ui->redirect($volver, 'Una planilla .csv no va aquí: súbela en «Subir archivos o planilla» y se despliega en un contenido por fila.', 'error');
                return;
            }
        }
        $enlace = TiposContenido::enlaceSeguro((string) ($_POST['enlace'] ?? ''));
        if (trim((string) ($_POST['enlace'] ?? '')) !== '' && $enlace === '') {
            $this->ui->redirect($volver, 'El link debe empezar con http:// o https://', 'error');
            return;
        }

        [$cid, $vid] = $this->contenidos()->crear($e, $_POST);
        $errores = [];
        if ($lista !== []) {
            $r = $this->archivos()->guardarVarios($lista, (string) $e['cliente_id'], (string) $e['proyecto_id'], 'version', $vid, $this->autor(), $this->maxMb());
            $errores = $r['errores'];
        }
        $this->reabrirSiCorresponde($e);
        $this->ui->redirect($volver, $errores === [] ? 'Contenido agregado.' : 'Contenido agregado, pero: ' . implode(' · ', $errores), $errores === [] ? 'success' : 'error');
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
            $this->ui->redirect($this->url('entregas'), 'Entrega no encontrada.', 'error');
            return;
        }
        $volver = $this->url('entregas/' . $id) . '#contenidos';
        if (ArchivoService::postExcedido()) {
            $this->ui->redirect($volver, 'Los archivos superan el máximo del servidor (post_max_size).', 'error');
            return;
        }
        $lista = array_slice(ArchivoService::normalizar($_FILES['archivos'] ?? null), 0, 30);
        if ($lista === []) {
            $this->ui->redirect($volver, 'Elige al menos un archivo o una planilla.', 'error');
            return;
        }
        $r = $this->expandir($e, $lista, (string) ($_POST['tipo'] ?? ''), trim((string) ($_POST['cuenta'] ?? '')));
        $this->ui->redirect($volver, $r['mensaje'], $r['creados'] > 0 ? 'success' : 'error');
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
        return ['tipo' => 'equipo', 'id' => $this->ui->autorId(), 'nombre' => $this->firma()];
    }

    public function editContenido(string $id): void
    {
        $c = $this->contenidos()->find($id);
        if ($c === null) {
            $this->ui->redirect($this->url('entregas'), 'Contenido no encontrado.', 'error');
            return;
        }
        $versiones = [];
        foreach (array_reverse($this->contenidos()->versiones($id)) as $v) {
            $v['archivos']    = $this->contenidos()->archivos((string) $v['id']);
            $v['reacciones']  = $this->contenidos()->reacciones((string) $v['id']);
            $versiones[] = $v;
        }
        // Pines del cliente sobre la versión vigente, numerados igual que en el portal.
        $comentarios = (new ComentarioService($this->pdo()))->listar('contenido', $id);
        $fmt = new Fmt();
        $imgVigente = [];
        foreach ($versiones as $v) {
            if ((int) $v['numero'] === (int) $c['version_actual']) {
                $imgVigente = array_values(array_filter($v['archivos'], fn($a) => $fmt->esImagen($a['mime'])));
            }
        }
        $posImagen = [];
        foreach ($imgVigente as $k => $a) {
            $posImagen[(string) $a['id']] = $k + 1;
        }
        $this->ui->view('entregas/contenido.latte', [
            'c' => $c, 'versiones' => $versiones, 'tipos' => TiposContenido::TIPOS,
            'reacciones' => TiposContenido::REACCIONES, 'estadosContenido' => TiposContenido::ESTADOS_CONTENIDO,
            'comentarios' => $comentarios,
            'pines' => Ubicacion::pines($comentarios, $c['version_id'] !== null ? (string) $c['version_id'] : null, $posImagen),
            'imgVigente' => $imgVigente, 'posImagen' => $posImagen,
            // En un reel las láminas son el guion (sin imagen por fila) y la imagen es la portada.
            'esReel' => TiposContenido::visor((string) $c['tipo']) === 'reel',
            'portada' => TiposContenido::visor((string) $c['tipo']) === 'reel' ? ($imgVigente[0] ?? null) : null,
            'filasLaminas' => TiposContenido::visor((string) $c['tipo']) === 'reel' ? null : self::filasLaminas(TiposContenido::laminas($c['laminas'] ?? null), $imgVigente),
            'fmt' => new Fmt(), 'firma' => $this->firma(), 'maxMb' => $this->maxMb(),
        ] + $this->flashes());
    }

    /**
     * Filas del editor de láminas: cada lámina con su imagen de la versión vigente.
     * Si las láminas aún no tienen imagen amarrada (contenido antiguo o importado), se reparten
     * en orden. Las imágenes que no son de ninguna lámina quedan como filas al final.
     *
     * @param array<int, array<string, string>> $laminas
     * @param array<int, array<string, mixed>> $imagenes de la versión vigente, en orden
     * @return array<int, array{idea: string, texto: string, img: ?array<string, mixed>}>
     */
    public static function filasLaminas(array $laminas, array $imagenes): array
    {
        $porId = array_column($imagenes, null, 'id');
        $amarradas = array_filter(array_column($laminas, 'img'), fn($i) => isset($porId[$i]));
        $libres = array_values(array_filter($imagenes, fn($a) => !in_array($a['id'], $amarradas, true)));
        $filas = [];
        foreach ($laminas as $l) {
            $img = isset($l['img'], $porId[$l['img']]) ? $porId[$l['img']] : null;
            if ($img === null && $amarradas === [] && $libres !== []) {
                $img = array_shift($libres);   // sin amarras: por orden
            }
            $filas[] = ['idea' => $l['idea'], 'texto' => $l['texto'], 'img' => $img];
        }
        foreach ($libres as $a) {
            $filas[] = ['idea' => '', 'texto' => '', 'img' => $a];
        }
        return $filas;
    }

    /**
     * Portada de un reel: es la primera imagen de la versión vigente. Subir otra la reemplaza.
     *
     * @param array<string, mixed> $c contenido
     * @return array<int, string> errores
     */
    private function guardarPortada(array $c): array
    {
        $vid = $c['version_id'] !== null ? (string) $c['version_id'] : '';
        if ($vid === '') {
            return [];
        }
        $fmt = new Fmt();
        $actual = array_values(array_filter($this->contenidos()->archivos($vid), fn($a) => $fmt->esImagen($a['mime'])))[0] ?? null;
        if (!empty($_POST['quitar_portada']) && $actual !== null) {
            $this->archivos()->borrar((string) $actual['id']);
            $actual = null;
        }
        $f = ArchivoService::normalizar($_FILES['portada'] ?? null);
        if ($f === []) {
            return [];
        }
        $r = $this->archivos()->guardarVarios([$f[0]], (string) $c['cliente_id'], (string) $c['proyecto_id'], 'version', $vid, $this->autor(), $this->maxMb());
        if ($r['ok'] === []) {
            return $r['errores'];
        }
        $nueva = $r['ok'][0];
        if (!$fmt->esImagen((string) $nueva['mime'])) {
            $this->archivos()->borrar((string) $nueva['id']);
            return ['la portada debe ser una imagen (JPG, PNG o WebP)'];
        }
        if ($actual !== null) {
            $this->archivos()->borrar((string) $actual['id']);   // la reemplaza
        }
        // Primera de la versión: así es la portada y la miniatura del reel.
        $this->pdo()->prepare('UPDATE ' . ArchivoService::TABLE . ' SET orden = 0 WHERE id = ?')->execute([$nueva['id']]);
        return [];
    }

    /**
     * Guarda las láminas del editor: sube la imagen nueva de cada fila (reemplaza la anterior),
     * amarra cada imagen a su lámina y ordena el carrusel como las filas.
     *
     * @param array<string, mixed> $c contenido
     * @return array<int, string> errores de subida
     */
    private function guardarLaminas(array $c, array $filasPost): array
    {
        $vid = $c['version_id'] !== null ? (string) $c['version_id'] : '';
        $fmt = new Fmt();
        $imgs = $vid !== '' ? array_values(array_filter($this->contenidos()->archivos($vid), fn($a) => $fmt->esImagen($a['mime']))) : [];
        $porId = array_column($imgs, null, 'id');
        // Láminas quitadas que tenían imagen: la imagen se va con ellas (si ninguna otra fila la usa).
        $enUso = array_map(fn($l) => is_array($l) ? (string) ($l['img'] ?? '') : '', $filasPost);
        foreach (array_unique(array_map('strval', (array) ($_POST['lamina_borrar'] ?? []))) as $bid) {
            if (isset($porId[$bid]) && !in_array($bid, $enUso, true)) {
                $this->archivos()->borrar($bid);
                unset($porId[$bid]);
            }
        }
        $f = $_FILES['lamina_archivo'] ?? null;
        $errores = [];
        $laminas = [];
        foreach ($filasPost as $k => $l) {
            if (!is_array($l)) {
                continue;
            }
            $img = (string) ($l['img'] ?? '');
            if (!isset($porId[$img])) {
                $img = '';
            }
            $sube = is_array($f) && isset($f['name'][$k]) && ($f['error'][$k] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            if ($sube && $vid !== '') {
                $uno = ['name' => (string) $f['name'][$k], 'tmp_name' => (string) $f['tmp_name'][$k], 'error' => (int) $f['error'][$k], 'size' => (int) $f['size'][$k]];
                $r = $this->archivos()->guardarVarios([$uno], (string) $c['cliente_id'], (string) $c['proyecto_id'], 'version', $vid, $this->autor(), $this->maxMb());
                if ($r['ok'] !== [] && $fmt->esImagen((string) ($r['ok'][0]['mime'] ?? ''))) {
                    if ($img !== '') {
                        $this->archivos()->borrar($img);   // la reemplaza
                    }
                    $img = (string) $r['ok'][0]['id'];
                } elseif ($r['ok'] !== []) {
                    $this->archivos()->borrar((string) $r['ok'][0]['id']);
                    $errores[] = 'Lámina ' . (count($laminas) + 1) . ': sube una imagen (JPG, PNG o WebP).';
                } else {
                    $errores = array_merge($errores, $r['errores']);
                }
            }
            $laminas[] = ['idea' => (string) ($l['idea'] ?? ''), 'texto' => (string) ($l['texto'] ?? ''), 'img' => $img];
        }
        // El carrusel sigue el orden de las filas; las imágenes sueltas van al final.
        if ($vid !== '') {
            $orden = 0;
            $up = $this->pdo()->prepare('UPDATE ' . ArchivoService::TABLE . ' SET orden = ? WHERE id = ?');
            $usadas = [];
            foreach ($laminas as $l) {
                if ($l['img'] !== '' && !isset($usadas[$l['img']])) {
                    $up->execute([++$orden, $l['img']]);
                    $usadas[$l['img']] = true;
                }
            }
            foreach ($this->contenidos()->archivos($vid) as $a) {
                if (!isset($usadas[$a['id']])) {
                    $up->execute([++$orden, $a['id']]);
                }
            }
        }
        $_POST['laminas'] = $laminas;
        return $errores;
    }

    public function updateContenido(string $id): void
    {
        $c = $this->contenidos()->find($id);
        $errores = [];
        if ($c !== null) {
            if (ArchivoService::postExcedido()) {
                $this->ui->redirect($this->url('contenidos/' . $id), 'Las imágenes superan el máximo del servidor (post_max_size): súbelas en dos veces.', 'error');
                return;
            }
            if (trim((string) ($_POST['enlace'] ?? '')) !== '' && TiposContenido::enlaceSeguro((string) $_POST['enlace']) === '') {
                $this->ui->redirect($this->url('contenidos/' . $id), 'El link debe empezar con http:// o https://', 'error');
                return;
            }
            if (TiposContenido::visor((string) $c['tipo']) === 'reel') {
                $errores = $this->guardarPortada($c);
            } elseif (!empty($_POST['laminas_form']) || is_array($_POST['laminas'] ?? null)) {
                $errores = $this->guardarLaminas($c, is_array($_POST['laminas'] ?? null) ? $_POST['laminas'] : []);
            }
            $this->contenidos()->actualizar($id, $_POST);
            // Cambio que el cliente debe volver a mirar: se marca y se le avisa.
            if (!empty($_POST['avisar_cambio']) && $c['entrega_estado'] !== 'borrador') {
                $this->contenidos()->marcarActualizado($id);
                (new Notifier($this->ctx, $this->pdo()))->alCliente(
                    (string) $c['cliente_id'], null, 'Actualizamos una pieza: ' . $c['titulo'],
                    'Hicimos cambios en «' . $c['titulo'] . '» de «' . $c['entrega_titulo'] . '». Échale un vistazo cuando puedas.', '/portal/contenidos/' . $id,
                    ['etiqueta' => 'Pieza actualizada', 'titulo' => 'Actualizamos una pieza para que la revises', 'resaltado' => 'actualizamos', 'boton' => 'Ver la pieza',
                     'bloques' => [['tarjetas' => [['titulo' => (string) $c['titulo'], 'detalle' => (string) $c['entrega_titulo'], 'chip' => 'Actualizado']]]],
                     'vigencia' => Vigencia::contenido($id)]
                );
                (new ActividadService($this->pdo()))->registrar(
                    (string) $c['cliente_id'], (string) $c['proyecto_id'], 'equipo', $this->firma(), 'actualizo', 'contenido', $id, (string) $c['titulo'], 'Actualizado'
                );
                $this->ui->redirect($this->url('contenidos/' . $id), 'Contenido actualizado y le avisamos al cliente: lo verá marcado como «Actualizado».');
                return;
            }
        }
        $this->ui->redirect($this->url('contenidos/' . $id), $errores === [] ? 'Contenido actualizado.' : 'Contenido actualizado, pero: ' . implode(' · ', $errores), $errores === [] ? 'success' : 'error');
    }

    public function borrarContenido(string $id): void
    {
        $c = $this->contenidos()->find($id);
        $vol = $c !== null ? $this->url('entregas/' . $c['entrega_id']) . '#contenidos' : $this->url('entregas');
        if ($c !== null) {
            $this->contenidos()->borrar($id);
        }
        $this->ui->redirect($vol, 'Contenido eliminado.');
    }

    public function moverContenido(string $id): void
    {
        $c = $this->contenidos()->find($id);
        if ($c !== null) {
            $this->contenidos()->mover($id, ($_POST['dir'] ?? '') === 'arriba' ? -1 : 1);
        }
        $this->ui->redirect($c !== null ? $this->url('entregas/' . $c['entrega_id']) . '#contenidos' : $this->url('entregas'));
    }

    /** Sube una versión nueva (v2, v3…): el contenido vuelve a "por revisar". */
    public function nuevaVersion(string $id): void
    {
        $c = $this->contenidos()->find($id);
        if ($c === null) {
            $this->ui->redirect($this->url('entregas'), 'Contenido no encontrado.', 'error');
            return;
        }
        $volver = $this->url('contenidos/' . $id);
        if (ArchivoService::postExcedido()) {
            $this->ui->redirect($volver, 'Los archivos superan el máximo del servidor (post_max_size).', 'error');
            return;
        }
        if (trim((string) ($_POST['enlace'] ?? '')) !== '' && TiposContenido::enlaceSeguro((string) $_POST['enlace']) === '') {
            $this->ui->redirect($volver, 'El link debe empezar con http:// o https://', 'error');
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
                 'bloques' => [['tarjetas' => [['titulo' => (string) $c['titulo'], 'detalle' => (string) $c['entrega_titulo'], 'chip' => 'v' . ((int) $c['version_actual'] + 1)]]]],
                 'vigencia' => Vigencia::contenido($id)]
            );
        }
        if ($vid !== null) {
            (new ActividadService($this->pdo()))->registrar(
                (string) $c['cliente_id'], (string) $c['proyecto_id'], 'equipo', $this->firma(), 'subio_version', 'contenido', $id, (string) $c['titulo'],
                'v' . ((int) $c['version_actual'] + 1)
            );
        }
        $this->ui->redirect($volver, $errores === [] ? 'Versión nueva publicada.' : 'Versión creada, pero: ' . implode(' · ', $errores), $errores === [] ? 'success' : 'error');
    }

    /** Agrega más archivos a la versión vigente (por ejemplo, otra lámina del carrusel). */
    public function agregarArchivos(string $id): void
    {
        $c = $this->contenidos()->find($id);
        if ($c === null || $c['version_id'] === null) {
            $this->ui->redirect($this->url('entregas'), 'Contenido no encontrado.', 'error');
            return;
        }
        $volver = $this->url('contenidos/' . $id);
        if (ArchivoService::postExcedido()) {
            $this->ui->redirect($volver, 'Los archivos superan el máximo del servidor (post_max_size).', 'error');
            return;
        }
        $lista = array_slice(ArchivoService::normalizar($_FILES['archivos'] ?? null), 0, 10);
        if ($lista === []) {
            $this->ui->redirect($volver, 'Elige al menos un archivo.', 'error');
            return;
        }
        $r = $this->archivos()->guardarVarios($lista, (string) $c['cliente_id'], (string) $c['proyecto_id'], 'version', (string) $c['version_id'], $this->autor(), $this->maxMb());
        $n = count($r['ok']);
        $this->ui->redirect($volver, $r['errores'] === [] ? ($n === 1 ? 'Archivo subido.' : "{$n} archivos subidos.") : ($n > 0 ? "Se subieron {$n}, pero: " : 'No se pudo subir: ') . implode(' · ', $r['errores']), $r['errores'] === [] ? 'success' : 'error');
    }

    // ---- Conversación -----------------------------------------------------

    public function comentar(string $id): void
    {
        $c = $this->contenidos()->find($id);
        if ($c === null) {
            $this->ui->redirect($this->url('entregas'), 'Contenido no encontrado.', 'error');
            return;
        }
        $volver = $this->url('contenidos/' . $id) . '#conversacion';
        $texto = trim((string) ($_POST['cuerpo'] ?? ''));
        if ($texto === '') {
            $this->ui->redirect($volver, 'Escribe algo antes de enviar.', 'error');
            return;
        }
        (new ComentarioService($this->pdo()))->crear((string) $c['cliente_id'], 'contenido', $id, 'equipo', $this->ui->autorId(), $this->ui->firma(), $texto, $c['version_id']);
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
        $this->ui->redirect($volver, 'Comentario publicado.');
    }

    public function borrarComentario(string $id, string $comentarioId): void
    {
        $cm = new ComentarioService($this->pdo());
        $x = $cm->find($comentarioId);
        if ($x !== null && $x['entidad_tipo'] === 'contenido' && $x['entidad_id'] === $id) {
            $cm->borrar($comentarioId);
        }
        $this->ui->redirect($this->url('contenidos/' . $id) . '#conversacion', 'Comentario eliminado.');
    }
}

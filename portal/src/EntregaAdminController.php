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
        if (($volver = ListaRecordada::volverA($this->ui->url('entregas'))) !== null) {
            $this->ui->redirect($volver);
            return;
        }
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

        $pag = Paginacion::delEquipo($this->pdo(), $this->ui->autorId(), $this->ui->url('entregas'), count($lista));
        $lista = $pag->cortar($pag->sentido($lista));
        $this->ui->view('entregas/index.latte', [
            'pag'        => $pag,
            'entregas'   => $lista,
            'filtros'    => $f,
            'conteos'    => $conteos,
            'grupos'     => self::FILTRO_ESTADOS,
            'proyectosF' => $proyectos,
            'colorProy'  => Fmt::coloresTodos($this->pdo()),
            'marcasCli'  => Fmt::marcasClientes($this->pdo()),
            'estados'  => Idioma::lista(TiposContenido::ESTADOS_ENTREGA),
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
            'estados' => Idioma::lista(TiposContenido::ESTADOS_ENTREGA), 'tipos' => Idioma::lista(TiposContenido::TIPOS),
            'estadosContenido' => Idioma::lista(TiposContenido::ESTADOS_CONTENIDO), 'reacciones' => Idioma::lista(TiposContenido::REACCIONES),
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
        $miniaturas = [];
        $sinTapa = [];
        $linkTapa = [];
        $faltas = [];
        $piezasJs = [];
        $fmt = new Fmt();
        foreach ($lista as $i => $c) {
            $info = ['imagenes' => 0, 'videos' => 0, 'pdfs' => 0, 'archivos' => 0];
            if ($c['version_id'] !== null) {
                foreach ($this->contenidos()->archivos((string) $c['version_id']) as $a) {
                    $info['archivos']++;
                    $esImg = $fmt->esImagen($a['mime']);
                    if ($esImg) {
                        $info['imagenes']++;
                    } elseif (TiposContenido::esVideo($a['mime'])) {
                        $info['videos']++;
                    } elseif ($a['mime'] === 'application/pdf') {
                        $info['pdfs']++;
                    }
                    // Tapa: la imagen misma, o la miniatura que se generó para el PDF o el video.
                    if ($esImg || !empty($a['miniatura'])) {
                        $portadas[$c['id']] ??= $a['id'];
                        if (count($miniaturas[$c['id']] ?? []) < 4) {
                            $miniaturas[$c['id']][] = $a['id'];
                        }
                    } elseif (TiposContenido::esVideo($a['mime']) || $a['mime'] === 'application/pdf') {
                        $sinTapa[] = ['id' => (string) $a['id'], 'cid' => (string) $c['id'], 'tipo' => $a['mime'] === 'application/pdf' ? 'pdf' : 'video', 'src' => $this->url('archivos/' . $a['id'])];
                    }
                }
                if (!isset($miniaturas[$c['id']]) && ($emb = TiposContenido::embed((string) ($c['enlace'] ?? ''))) !== null && $emb['tipo'] === 'youtube' && preg_match('#/embed/([\w-]{11})#', $emb['src'], $m)) {
                    $linkTapa[$c['id']] = 'https://i.ytimg.com/vi/' . $m[1] . '/mqdefault.jpg';
                }
                $resumen[$c['id']] = $this->contenidos()->reacciones((string) $c['version_id']);
            }
            $info['total_img'] = $info['imagenes'];
            $faltas[$c['id']] = ['chips' => TiposContenido::faltas($c, $info), 'imagenes' => $info['imagenes']];
            // Para la zona de subida: a qué pieza va cada archivo según el número de su nombre.
            $piezasJs[] = ['id' => (string) $c['id'], 'n' => TiposContenido::numeroTitulo((string) $c['titulo']) ?? $i + 1, 'titulo' => (string) $c['titulo']];
        }
        $this->ui->view('entregas/edit.latte', [
            'entrega' => $e, 'proyectos' => [], 'contenidos' => $lista, 'fmt' => new Fmt(),
            'fasesRango' => $this->ui->filtrar((new FaseService($this->pdo()))->conRangos(), 'proyecto_id'),
            'estados' => Idioma::lista(TiposContenido::ESTADOS_ENTREGA), 'tipos' => Idioma::lista(TiposContenido::TIPOS),
            'estadosContenido' => Idioma::lista(TiposContenido::ESTADOS_CONTENIDO), 'reacciones' => Idioma::lista(TiposContenido::REACCIONES),
            'portadas' => $portadas, 'resumenReacc' => $resumen, 'maxMb' => $this->maxMb(),
            'miniaturas' => $miniaturas, 'faltas' => $faltas, 'piezasJs' => $piezasJs,
            'sinTapa' => array_slice($sinTapa, 0, 30), 'linkTapa' => $linkTapa,
            'tiposCampos' => self::CAMPOS_POR_TIPO,
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
            $cuerpo = trim((string) $e['mensaje']) !== '' ? (string) $e['mensaje'] : Idioma::f('Dejamos contenido listo para que lo revises.');
            (new Notifier($this->ctx, $this->pdo()))->alCliente(
                (string) $e['cliente_id'], null, Idioma::f('Tienes contenido para revisar: %s', (string) $e['titulo']), $cuerpo, '/portal/entregas/' . $id,
                ['etiqueta' => Idioma::f('Revisión'), 'titulo' => Idioma::f('Tienes contenido para revisar'), 'resaltado' => Idioma::f('contenido'), 'boton' => Idioma::f('Revisar contenido'),
                 'bloques' => [['tarjetas' => [['titulo' => (string) $e['titulo'], 'detalle' => Idioma::f('%d contenido(s) esperando tu opinión', (int) $e['n_total']), 'chip' => Idioma::f('Para revisar')]]]],
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
            $this->aplicarTapas($r['ok']);
            $this->portadaDesdeTapas($r['ok']);
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

    /**
     * Qué campos pide cada tipo al crear uno a mano: 2 = se pide, 1 = opcional (en «Más detalles»), 0 = no aparece.
     * Objetivo, pilar, láminas y notas van siempre en «Más detalles».
     */
    public const CAMPOS_POR_TIPO = [
        'archivos' => ['post' => 2, 'reel' => 1, 'story' => 2, 'grafica' => 2, 'logo' => 2, 'mockup' => 2, 'brandbook' => 2, 'presentacion' => 1, 'web' => 1, 'otro' => 2],
        'enlace'   => ['post' => 0, 'reel' => 2, 'story' => 0, 'grafica' => 0, 'logo' => 0, 'mockup' => 0, 'brandbook' => 0, 'presentacion' => 1, 'web' => 2, 'otro' => 1],
        'copy'     => ['post' => 2, 'reel' => 2, 'story' => 0, 'grafica' => 1, 'logo' => 0, 'mockup' => 0, 'brandbook' => 0, 'presentacion' => 0, 'web' => 0, 'otro' => 1],
        'cuenta'   => ['post' => 2, 'reel' => 2, 'story' => 2, 'grafica' => 0, 'logo' => 0, 'mockup' => 0, 'brandbook' => 0, 'presentacion' => 0, 'web' => 0, 'otro' => 0],
        'fecha'    => ['post' => 2, 'reel' => 2, 'story' => 2, 'grafica' => 1, 'logo' => 0, 'mockup' => 0, 'brandbook' => 0, 'presentacion' => 0, 'web' => 0, 'otro' => 1],
    ];

    /**
     * Zona única de subida. Llegan los archivos y, por cada uno (en el mismo orden), su destino
     * elegido en «Revisa antes de crear»:
     *   pieza:<id>  se suma a esa pieza (como lámina, ordenada por el número del nombre);
     *   nuevo       un contenido propio (tipo según el archivo);
     *   carrusel    junto con los demás «carrusel» en un post nuevo;
     *   omitir      no se sube.
     * Sin destino (sin JavaScript) se decide solo: número de pieza en el nombre o contenido nuevo.
     * Una grilla en Word o texto va al lector de grillas; una planilla .csv se despliega por filas.
     */
    public function soltar(string $id): void
    {
        $e = $this->entregas()->find($id);
        if ($e === null) {
            $this->ui->redirect($this->url('entregas'), 'Entrega no encontrada.', 'error');
            return;
        }
        $volver = $this->url('entregas/' . $id) . '#contenidos';
        if (ArchivoService::postExcedido()) {
            $this->ui->redirect($volver, 'Los archivos superan lo que acepta el servidor de una vez (' . ini_get('post_max_size') . '). Súbelos en grupos más chicos.', 'error');
            return;
        }
        $lista = array_slice(ArchivoService::normalizar($_FILES['archivos'] ?? null), 0, 80);
        $destinos = array_values(array_map('strval', (array) ($_POST['destino'] ?? [])));

        // La grilla se lee con su propia vista previa.
        foreach ($lista as $f) {
            if (in_array(strtolower(pathinfo((string) ($f['name'] ?? ''), PATHINFO_EXTENSION)), ['docx', 'txt', 'md'], true)) {
                $_FILES['grilla'] = $f;
                (new GrillaAdminController($this->ctx, $this->ui))->subir($id);
                return;
            }
        }
        if (trim((string) ($_POST['texto'] ?? '')) !== '') {
            (new GrillaAdminController($this->ctx, $this->ui))->subir($id);
            return;
        }
        if ($lista === []) {
            $this->ui->redirect($volver, 'Elige o suelta al menos un archivo.', 'error');
            return;
        }

        $inicio = (new \DateTimeImmutable('-2 seconds'))->format('Y-m-d H:i:s');
        $contenidos = $this->contenidos()->listar($id);
        $porId = array_column($contenidos, null, 'id');
        $porNumero = [];
        foreach ($contenidos as $i => $c) {
            $porNumero[TiposContenido::numeroTitulo((string) $c['titulo']) ?? $i + 1] ??= $c;
        }
        $cuenta = mb_substr(trim((string) ($_POST['cuenta'] ?? '')), 0, 120);

        $aPieza = [];
        $nuevos = [];
        $carrusel = [];
        $planillas = [];
        $omitidos = 0;
        foreach ($lista as $i => $f) {
            $nombre = (string) ($f['name'] ?? '');
            $ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
            if ($ext === 'csv') {
                $planillas[] = $f;
                continue;
            }
            $d = $destinos[$i] ?? '';
            $nums = TiposContenido::numerosArchivo($nombre);
            if ($d === '') {
                $c = $nums !== null ? ($porNumero[$nums[0]] ?? null) : null;
                $d = $c !== null ? 'pieza:' . $c['id'] : 'nuevo';
            }
            if ($d === 'omitir') {
                $omitidos++;
            } elseif (str_starts_with($d, 'pieza:') && isset($porId[substr($d, 6)]) && $porId[substr($d, 6)]['version_id'] !== null) {
                $aPieza[substr($d, 6)][] = [$nums[1] ?? 0, $nombre, $f];
            } elseif ($d === 'carrusel') {
                $carrusel[] = $f;
            } else {
                $nuevos[] = $f;
            }
        }

        $autor = $this->autor();
        $partes = [];
        $errores = [];
        $laminas = 0;
        foreach ($aPieza as $cid => $fs) {
            usort($fs, fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
            $r = $this->archivos()->guardarVarios(array_column($fs, 2), (string) $e['cliente_id'], (string) $e['proyecto_id'], 'version', (string) $porId[$cid]['version_id'], $autor, $this->maxMb());
            $laminas += count($r['ok']);
            $errores = array_merge($errores, $r['errores']);
        }
        if ($laminas > 0) {
            $partes[] = ($laminas === 1 ? '1 archivo sumado' : $laminas . ' archivos sumados') . ' a ' . (count($aPieza) === 1 ? '1 pieza' : count($aPieza) . ' piezas');
        }
        $creados = 0;
        if ($carrusel !== []) {
            usort($carrusel, fn($a, $b) => strnatcasecmp((string) $a['name'], (string) $b['name']));
            $titulo = mb_substr(trim((string) ($_POST['titulo_carrusel'] ?? '')), 0, 255) ?: 'Carrusel nuevo';
            [$cid, $vid] = $this->contenidos()->crear($e, ['tipo' => 'post', 'titulo' => $titulo, 'cuenta' => $cuenta]);
            $r = $this->archivos()->guardarVarios($carrusel, (string) $e['cliente_id'], (string) $e['proyecto_id'], 'version', $vid, $autor, $this->maxMb());
            $errores = array_merge($errores, $r['errores']);
            if ($r['ok'] === []) {
                $this->contenidos()->borrar($cid);
            } else {
                $creados++;
                $partes[] = '1 carrusel de ' . count($r['ok']) . ' láminas';
            }
        }
        $antes = $creados;
        if ($nuevos !== [] || $planillas !== []) {
            $r = $this->expandir($e, array_merge($planillas, $nuevos), '', $cuenta);
            $creados += $r['creados'];
            if ($r['creados'] > 0) {
                $partes[] = ($r['creados'] === 1 ? '1 contenido nuevo' : $r['creados'] . ' contenidos nuevos');
            } elseif (str_contains($r['mensaje'], 'Avisos:')) {
                $errores[] = trim(substr($r['mensaje'], (int) strpos($r['mensaje'], 'Avisos:') + 7));
            }
        }
        if ($laminas > 0 || $creados > $antes || $creados > 0) {
            $this->reabrirSiCorresponde($e);
        }
        $recientes = $this->archivosRecientes((string) $e['cliente_id'], $inicio);
        $this->aplicarTapas($recientes);
        $this->portadaDesdeTapas($recientes);
        $msg = $partes !== [] ? 'Listo: ' . implode(' · ', $partes) . '.' : 'No se subió nada.';
        if ($omitidos > 0) {
            $msg .= ' ' . ($omitidos === 1 ? '1 archivo quedó fuera' : $omitidos . ' archivos quedaron fuera') . ', como elegiste.';
        }
        if ($errores !== []) {
            $msg .= ' Revisa: ' . implode(' · ', array_slice($errores, 0, 6));
        }
        $this->ui->redirect($volver, $msg, $partes !== [] && $errores === [] ? 'success' : 'error');
    }

    /**
     * Tapas que generó el navegador (página 1 de un PDF, un cuadro de un video): llegan en
     * tapas[] con el nombre de su archivo en tapa_de[]; se ponen como miniatura de ese archivo.
     *
     * @param array<int, array<string, mixed>> $subidos filas recién guardadas
     */
    private function aplicarTapas(array $subidos): void
    {
        $tapas = ArchivoService::normalizar($_FILES['tapas'] ?? null);
        $de = array_values(array_map('strval', (array) ($_POST['tapa_de'] ?? [])));
        if ($tapas === [] || $subidos === []) {
            return;
        }
        $porNombre = [];
        foreach ($subidos as $a) {
            $porNombre[(string) $a['nombre_original']] ??= $a;
        }
        foreach ($tapas as $i => $t) {
            $nombre = $this->archivos()->nombreSeguro($de[$i] ?? '');
            if (isset($porNombre[$nombre]) && (int) ($t['error'] ?? 1) === UPLOAD_ERR_OK && empty($porNombre[$nombre]['miniatura'])) {
                $this->archivos()->ponerTapa($porNombre[$nombre], (string) $t['tmp_name']);
            }
        }
    }

    /**
     * En un reel, la tapa del video pasa a ser su Portada (la imagen que el cliente ve antes de
     * reproducir), si la versión todavía no tiene una.
     *
     * @param array<int, array<string, mixed>> $subidos
     */
    private function portadaDesdeTapas(array $subidos): void
    {
        $fmt = new Fmt();
        foreach ($subidos as $s) {
            $a = $this->archivos()->find((string) $s['id']);
            if ($a === null || empty($a['miniatura']) || !TiposContenido::esVideo((string) $a['mime']) || $a['entidad_tipo'] !== 'version') {
                continue;
            }
            $st = $this->pdo()->prepare('SELECT c.* FROM portal_contenidos c JOIN portal_versiones v ON v.contenido_id = c.id WHERE v.id = ?');
            $st->execute([(string) $a['entidad_id']]);
            $c = $st->fetch();
            if (!$c || TiposContenido::visor((string) $c['tipo']) !== 'reel') {
                continue;
            }
            $tiene = array_filter($this->contenidos()->archivos((string) $a['entidad_id']), fn($x) => $fmt->esImagen((string) $x['mime']));
            if ($tiene !== []) {
                continue;
            }
            $ruta = $this->archivos()->directorioBase() . '/' . $this->archivos()->segmento((string) $a['cliente_id']) . '/' . basename((string) $a['miniatura']);
            if (!is_file($ruta)) {
                continue;
            }
            $tmp = tempnam(sys_get_temp_dir(), 'po');
            copy($ruta, $tmp);
            $antes = ArchivoService::$mover;
            ArchivoService::$mover = fn(string $o, string $d): bool => rename($o, $d);
            try {
                $r = $this->archivos()->guardarVarios([['name' => 'portada.jpg', 'type' => 'image/jpeg', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => (int) filesize($tmp)]],
                    (string) $a['cliente_id'], (string) $a['proyecto_id'], 'version', (string) $a['entidad_id'], $this->autor(), $this->maxMb());
            } finally {
                ArchivoService::$mover = $antes;
            }
            if ($r['ok'] !== []) {
                $this->pdo()->prepare('UPDATE ' . ArchivoService::TABLE . ' SET orden = 0 WHERE id = ?')->execute([$r['ok'][0]['id']]);
            }
        }
    }

    /** Archivos de un cliente guardados desde $desde (los de esta misma subida). */
    private function archivosRecientes(string $clienteId, string $desde): array
    {
        $st = $this->pdo()->prepare("SELECT * FROM portal_archivos WHERE cliente_id = ? AND entidad_tipo = 'version' AND created_at >= ? ORDER BY created_at DESC");
        $st->execute([$clienteId, $desde]);
        return $st->fetchAll();
    }

    /**
     * Pone o cambia la tapa de un archivo (PDF o video): un cuadro elegido del video o una imagen.
     * Lo usa también la lista de la entrega para crear, en segundo plano, las tapas que faltan.
     */
    public function tapa(string $id): void
    {
        $a = $this->archivos()->find($id);
        $json = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
        $f = $_FILES['tapa'] ?? null;
        $ok = $a !== null && $a['entidad_tipo'] === 'version' && is_array($f) && (int) ($f['error'] ?? 1) === UPLOAD_ERR_OK
            && $this->archivos()->ponerTapa($a, (string) $f['tmp_name']);
        if ($json) {
            $this->responderJson(['ok' => $ok]);
            return;
        }
        $volver = (string) ($_POST['volver'] ?? '');
        if (!str_starts_with($volver, $this->url('contenidos/')) && !str_starts_with($volver, $this->url('entregas/'))) {
            $volver = $this->url('entregas');
        }
        $this->ui->redirect($volver, $ok ? 'Tapa actualizada.' : 'No se pudo usar esa imagen como tapa (JPG, PNG o WebP de hasta 4 MB).', $ok ? 'success' : 'error');
    }

    /**
     * Tarjeta de un link para la vista previa: título e imagen que el sitio declara para
     * compartir (og:title / og:image). Sólo http(s), sin direcciones internas, 4 s y 600 KB como máximo.
     */
    public function linkInfo(): void
    {
        $url = TiposContenido::enlaceSeguro((string) ($_GET['url'] ?? ''));
        $out = ['ok' => false];
        $emb = $url !== '' ? TiposContenido::embed($url) : null;
        if ($emb !== null && $emb['tipo'] === 'youtube' && preg_match('#/embed/([\w-]{11})#', $emb['src'], $m)) {
            $out = ['ok' => true, 'titulo' => '', 'imagen' => 'https://i.ytimg.com/vi/' . $m[1] . '/hqdefault.jpg', 'sitio' => 'YouTube'];
        } elseif ($url !== '') {
            $fuente = $emb !== null && $emb['tipo'] === 'vimeo' ? 'https://vimeo.com/api/oembed.json?url=' . rawurlencode($url) : $url;
            $html = self::traerSeguro($fuente);
            if ($html !== null && $emb !== null && $emb['tipo'] === 'vimeo') {
                $j = json_decode($html, true);
                if (is_array($j)) {
                    $out = ['ok' => true, 'titulo' => (string) ($j['title'] ?? ''), 'imagen' => (string) ($j['thumbnail_url'] ?? ''), 'sitio' => 'Vimeo'];
                }
            } elseif ($html !== null) {
                $meta = function (string $prop) use ($html): string {
                    $p = preg_quote($prop, '/');
                    if (preg_match('/<meta[^>]+(?:property|name)=["\']' . $p . '["\'][^>]*content=["\']([^"\']*)/i', $html, $m)
                        || preg_match('/<meta[^>]+content=["\']([^"\']*)["\'][^>]*(?:property|name)=["\']' . $p . '["\']/i', $html, $m)) {
                        return html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    }
                    return '';
                };
                $titulo = $meta('og:title') ?: (preg_match('/<title[^>]*>([^<]{1,200})/i', $html, $m) ? html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8') : '');
                $img = $meta('og:image') ?: $meta('twitter:image');
                if ($img !== '' && !preg_match('#^https?://#i', $img)) {
                    $p = parse_url($url);
                    $img = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . '/' . ltrim($img, '/');
                }
                $out = ['ok' => true, 'titulo' => mb_substr($titulo, 0, 160), 'imagen' => TiposContenido::enlaceSeguro($img), 'sitio' => (string) parse_url($url, PHP_URL_HOST)];
            }
        }
        $this->responderJson($out);
    }

    /** Para pruebas: reemplaza la descarga de linkInfo(). fn(string $url): ?string */
    public static ?\Closure $traer = null;

    /** Descarga una página pública sin seguir hacia direcciones internas. */
    private static function traerSeguro(string $url): ?string
    {
        if (self::$traer !== null) {
            return (self::$traer)($url);
        }
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '' || !function_exists('curl_init')) {
            return null;
        }
        $ips = @gethostbynamel($host) ?: [];
        if ($ips === []) {
            return null;
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return null;
            }
        }
        $ch = curl_init($url);
        $buf = '';
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RESOLVE        => [$host . ':443:' . $ips[0], $host . ':80:' . $ips[0]],
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 4,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; PortalClientes/1.0)',
            CURLOPT_HTTPHEADER     => ['Accept: text/html,application/json'],
            CURLOPT_WRITEFUNCTION  => function ($c, string $d) use (&$buf): int {
                $buf .= $d;
                return strlen($buf) > 600000 ? 0 : strlen($d);
            },
        ]);
        curl_exec($ch);
        $cod = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $cod >= 200 && $cod < 300 && $buf !== '' ? $buf : null;
    }

    /** Termina la respuesta (en pruebas se reemplaza). */
    /** Responde JSON y termina (en pruebas, con $traer puesto, deja la salida en el búfer y sigue). */
    private function responderJson(array $out): void
    {
        if (self::$traer !== null) {
            echo json_encode($out, JSON_UNESCAPED_UNICODE);
            return;
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
        exit;
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
        if ($this->ui->autorId() !== null) {
            (new NovedadesService($this->pdo()))->marcar($this->ui->autorId(), 'contenido', $id);
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
            'c' => $c, 'versiones' => $versiones, 'tipos' => Idioma::lista(TiposContenido::TIPOS),
            'reacciones' => Idioma::lista(TiposContenido::REACCIONES), 'estadosContenido' => Idioma::lista(TiposContenido::ESTADOS_CONTENIDO),
            'comentarios' => $comentarios,
            'pines' => Ubicacion::pines($comentarios, $c['version_id'] !== null ? (string) $c['version_id'] : null, $posImagen),
            'imgVigente' => $imgVigente, 'posImagen' => $posImagen,
            // En un reel las láminas son el guion (sin imagen por fila) y la imagen es la portada.
            'esReel' => TiposContenido::visor((string) $c['tipo']) === 'reel',
            'portada' => TiposContenido::visor((string) $c['tipo']) === 'reel' ? ($imgVigente[0] ?? null) : null,
            'videoReel' => TiposContenido::visor((string) $c['tipo']) === 'reel' ? (array_values(array_filter($c['version_id'] !== null ? $this->contenidos()->archivos((string) $c['version_id']) : [], fn($a) => TiposContenido::esVideo((string) $a['mime'])))[0] ?? null) : null,
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
                    (string) $c['cliente_id'], null, Idioma::f('Actualizamos una pieza: %s', (string) $c['titulo']),
                    Idioma::f('Hicimos cambios en «%s» de «%s». Échale un vistazo cuando puedas.', (string) $c['titulo'], (string) $c['entrega_titulo']), '/portal/contenidos/' . $id,
                    ['etiqueta' => Idioma::f('Pieza actualizada'), 'titulo' => Idioma::f('Actualizamos una pieza para que la revises'), 'resaltado' => Idioma::f('actualizamos'), 'boton' => Idioma::f('Ver la pieza'),
                     'bloques' => [['tarjetas' => [['titulo' => (string) $c['titulo'], 'detalle' => (string) $c['entrega_titulo'], 'chip' => Idioma::f('Actualizado')]]]],
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
                (string) $c['cliente_id'], null, Idioma::f('Nueva versión para revisar: %s', (string) $c['titulo']),
                Idioma::f('Subimos una nueva versión de «%s» en «%s». Échale un vistazo cuando puedas.', (string) $c['titulo'], (string) $c['entrega_titulo']), '/portal/entregas/' . $c['entrega_id'],
                ['etiqueta' => Idioma::f('Nueva versión'), 'titulo' => Idioma::f('Hay una nueva versión para revisar'), 'resaltado' => Idioma::f('nueva versión'), 'boton' => Idioma::f('Ver la nueva versión'),
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
        // Desde la lista de la entrega (soltar imágenes encima de una pieza) se vuelve a la lista.
        $aLista = $this->url('entregas/' . $c['entrega_id']);
        if (str_starts_with((string) ($_POST['volver'] ?? ''), $aLista)) {
            $volver = $aLista . '#pieza-' . $id;
        }
        if (ArchivoService::postExcedido()) {
            $this->ui->redirect($volver, 'Los archivos superan el máximo del servidor (post_max_size).', 'error');
            return;
        }
        $lista = array_slice(ArchivoService::normalizar($_FILES['archivos'] ?? null), 0, 30);
        if ($lista === []) {
            $this->ui->redirect($volver, 'Elige al menos un archivo.', 'error');
            return;
        }
        $r = $this->archivos()->guardarVarios($lista, (string) $c['cliente_id'], (string) $c['proyecto_id'], 'version', (string) $c['version_id'], $this->autor(), $this->maxMb());
        $n = count($r['ok']);
        if ($n > 0) {
            $this->entregas()->reabrir((string) $c['entrega_id']);
        }
        $this->ui->redirect($volver, $r['errores'] === [] ? ($n === 1 ? 'Archivo subido a «' . $c['titulo'] . '».' : "{$n} archivos subidos a «{$c['titulo']}».") : ($n > 0 ? "Se subieron {$n}, pero: " : 'No se pudo subir: ') . implode(' · ', $r['errores']), $r['errores'] === [] ? 'success' : 'error');
    }

    // ---- Conversación -----------------------------------------------------

    public function comentar(string $id): void
    {
        $c = $this->contenidos()->find($id);
        if ($c === null) {
            $this->ui->redirect($this->url('entregas'), 'Contenido no encontrado.', 'error');
            return;
        }
        $volver = MensajeService::destino($this->ui->url('mensajes'), $this->url('contenidos/' . $id) . '#conversacion');
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
                    (string) $c['cliente_id'], null, Idioma::f('Nuevo comentario en: %s', (string) $c['titulo']), '', '/portal/contenidos/' . $id,
                    ['etiqueta' => Idioma::f('Comentario'), 'titulo' => Idioma::f('Nuevo comentario del equipo'), 'resaltado' => Idioma::f('comentario'), 'boton' => Idioma::f('Ver y responder'),
                     'preheader' => mb_substr($texto, 0, 110),
                     'bloques' => [['p' => Idioma::f('En «%s»:', (string) $c['titulo'])], ['cita' => mb_substr($texto, 0, 800)]]]
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

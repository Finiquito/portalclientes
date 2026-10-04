<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

use TypeDock\Core\PluginContext;

/**
 * Importar una grilla (Word o texto) a una entrega, con vista previa editable, y subir
 * las imágenes de muchas piezas de una vez según el número del archivo («3-2.jpg»).
 * Sirve al admin y al panel de equipo (Pantalla), igual que EntregaAdminController.
 */
class GrillaAdminController
{
    protected readonly Pantalla $ui;

    public function __construct(private readonly PluginContext $ctx, ?Pantalla $ui = null)
    {
        $this->ui = $ui ?? new PantallaAdmin($ctx);
    }

    protected function pdo(): \PDO
    {
        return $this->ctx->db()->pdo();
    }

    protected function ia(): IaService
    {
        return new IaService($this->pdo());
    }

    protected function trabajos(): GrillaImport
    {
        return new GrillaImport((new ArchivoService($this->pdo()))->directorioBase() . '/.grillas');
    }

    private function entregas(): EntregaService
    {
        return new EntregaService($this->pdo());
    }

    private function contenidos(): ContenidoService
    {
        return new ContenidoService($this->pdo());
    }

    private function maxMb(): int
    {
        return max(1, (int) (new AjustesService($this->pdo()))->get('global', 'portal', 'max_mb', '20'));
    }

    private function url(string $ruta): string
    {
        return $this->ui->url($ruta);
    }

    protected function terminate(): void
    {
        exit;
    }

    /** Descarta salida previa (avisos, espacios) para no romper el JSON. */
    protected function limpiarSalida(): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }

    private function json(array $datos, int $http = 200): void
    {
        $this->limpiarSalida();
        if (!headers_sent()) {
            http_response_code($http);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($datos, JSON_UNESCAPED_UNICODE);
        $this->terminate();
    }

    // ---- 1. Subir -----------------------------------------------------------

    public function subir(string $id): void
    {
        $e = $this->entregas()->find($id);
        if ($e === null) {
            $this->ui->redirect($this->url('entregas'), 'Entrega no encontrada.', 'error');
            return;
        }
        $volver = $this->url('entregas/' . $id) . '#importar';
        if (ArchivoService::postExcedido()) {
            $this->ui->redirect($volver, 'El archivo supera el máximo que acepta el servidor (post_max_size = ' . ini_get('post_max_size') . '). Pega el texto de la grilla.', 'error');
            return;
        }
        $texto = trim((string) ($_POST['texto'] ?? ''));
        $nombre = '';
        $f = $_FILES['grilla'] ?? null;
        $err = is_array($f) ? (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
        if (in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            $this->ui->redirect($volver, 'El .docx pesa más de lo que acepta el servidor (' . ini_get('upload_max_filesize') . '); suele pasar cuando el Word trae fotos. Pega el texto de la grilla o sube una copia sin imágenes.', 'error');
            return;
        }
        if ($err !== UPLOAD_ERR_OK && $err !== UPLOAD_ERR_NO_FILE) {
            $this->ui->redirect($volver, 'No se pudo subir el archivo. Intenta de nuevo o pega el texto.', 'error');
            return;
        }
        if (is_array($f) && (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $nombre = (new ArchivoService($this->pdo()))->nombreSeguro((string) ($f['name'] ?? ''));
            $ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
            try {
                $texto = match ($ext) {
                    'docx' => LectorDocx::texto((string) $f['tmp_name']),
                    'txt', 'md' => (string) file_get_contents((string) $f['tmp_name'], false, null, 0, GrillaImport::MAX_TEXTO * 4),
                    default => throw new \RuntimeException($ext === 'csv'
                        ? 'Una planilla .csv va en «Subir archivos o planilla».'
                        : 'Sube un .docx (Word o Google Docs descargado como Word) o pega el texto.'),
                };
            } catch (\RuntimeException $ex) {
                $this->ui->redirect($volver, $ex->getMessage(), 'error');
                return;
            }
            if (!mb_check_encoding($texto, 'UTF-8')) {
                $texto = mb_convert_encoding($texto, 'UTF-8', 'Windows-1252');
            }
        }
        if (mb_strlen($texto) < 40) {
            $this->ui->redirect($volver, 'Sube el .docx de la grilla o pega su texto.', 'error');
            return;
        }

        $d = GrillaImport::dividir($texto);
        if ($d['bloques'] === []) {
            $this->ui->redirect($volver, 'No encontramos piezas en el documento.', 'error');
            return;
        }
        $usaIa = ($_POST['modo'] ?? 'ia') !== 'simple' && $this->ia()->activa();
        $trabajo = [
            'entrega_id' => $id,
            'creado'     => time(),
            'archivo'    => $nombre,
            'cuenta'     => mb_substr(trim((string) ($_POST['cuenta'] ?? '')), 0, 120),
            'general'    => $d['general'],
            'bloques'    => $d['bloques'],
            'tandas'     => GrillaImport::tandas($d['bloques']),
            'hechas'     => [],
            'piezas'     => [],
            'ia'         => $usaIa,
            'avisos'     => [],
        ];
        if (!$usaIa) {
            $this->leerSinIa($trabajo, array_keys($d['bloques']));
            $trabajo['hechas'] = array_keys($trabajo['tandas']);
        }
        $token = bin2hex(random_bytes(16));
        $t = $this->trabajos();
        $t->limpiar();
        $t->guardar($token, $trabajo);
        $this->ui->redirect($this->url('entregas/' . $id . '/grilla/' . $token));
    }

    /**
     * @param array<string, mixed> $trabajo
     * @param array<int, int> $indices
     */
    private function leerSinIa(array &$trabajo, array $indices): void
    {
        foreach ($indices as $i) {
            $b = (string) $trabajo['bloques'][$i];
            $trabajo['piezas'][$i] = GrillaImport::normalizar(GrillaImport::leer($b, $i + 1), $b, $i + 1);
        }
    }

    // ---- 2. Vista previa y tandas con IA ------------------------------------

    /** @return array<string, mixed>|null */
    private function trabajo(string $id, string $token): ?array
    {
        $t = $this->trabajos()->leerTrabajo($token);
        return $t !== null && ($t['entrega_id'] ?? '') === $id ? $t : null;
    }

    public function ver(string $id, string $token): void
    {
        $e = $this->entregas()->find($id);
        $t = $e !== null ? $this->trabajo($id, $token) : null;
        if ($t === null) {
            $this->ui->redirect($this->url($e !== null ? 'entregas/' . $id : 'entregas'), 'Esa importación ya no existe (se descarta a los dos días). Sube la grilla de nuevo.', 'error');
            return;
        }
        $piezas = $t['piezas'];
        ksort($piezas);
        $anexos = array_filter(array_map(fn($p) => (string) ($p['anexo'] ?? ''), $piezas));
        $this->ui->view('entregas/grilla.latte', [
            'entrega'   => $e,
            'token'     => $token,
            't'         => $t,
            'piezas'    => $piezas,
            'pendientes' => array_values(array_diff(array_keys($t['tandas']), $t['hechas'])),
            'general'   => trim($t['general'] . ($anexos !== [] ? "\n\n" . implode("\n\n", $anexos) : '')),
            'tipos'     => array_intersect_key(TiposContenido::TIPOS, array_flip(GrillaImport::TIPOS)),
            'flash_success' => $this->ui->flash('success'), 'flash_error' => $this->ui->flash('error'),
        ]);
    }

    /** Procesa una tanda con IA y responde JSON. Si la IA falla, esa tanda se lee sin IA. */
    public function tanda(string $id, string $token, string $n): void
    {
        $t = $this->trabajo($id, $token);
        $k = (int) $n;
        if ($t === null || !isset($t['tandas'][$k])) {
            $this->json(['error' => 'Importación no encontrada.'], 404);
            return;
        }
        $aviso = '';
        if (!in_array($k, $t['hechas'], true)) {
            $indices = $t['tandas'][$k];
            $bloques = [];
            foreach ($indices as $i) {
                $bloques[$i + 1] = (string) $t['bloques'][$i];
            }
            try {
                $r = $this->ia()->analizarGrilla($bloques, (string) $t['general']);
                foreach ($indices as $i) {
                    $b = (string) $t['bloques'][$i];
                    $t['piezas'][$i] = GrillaImport::normalizar($r[$i + 1] ?? GrillaImport::leer($b, $i + 1), $b, $i + 1);
                    if (!isset($r[$i + 1])) {
                        $t['piezas'][$i]['aviso'] = 'La IA no devolvió esta pieza: se leyó sin IA.';
                    }
                }
            } catch (\RuntimeException $ex) {
                $this->leerSinIa($t, $indices);
                $aviso = $ex->getMessage() . ' Estas piezas se leyeron sin IA: revísalas.';
                foreach ($indices as $i) {
                    $t['piezas'][$i]['aviso'] = 'Leída sin IA (la IA falló).';
                }
                if (!in_array($aviso, $t['avisos'], true)) {
                    $t['avisos'][] = $aviso;   // una vez, aunque fallen varias tandas
                }
            }
            $t['hechas'][] = $k;
            $this->trabajos()->guardar($token, $t);
        }
        $this->json(['ok' => true, 'hechas' => count($t['hechas']), 'total' => count($t['tandas']), 'aviso' => $aviso]);
    }

    // ---- 3. Crear -----------------------------------------------------------

    public function crear(string $id, string $token): void
    {
        $e = $this->entregas()->find($id);
        $t = $e !== null ? $this->trabajo($id, $token) : null;
        if ($e === null || $t === null) {
            $this->ui->redirect($this->url('entregas'), 'Esa importación ya no existe. Sube la grilla de nuevo.', 'error');
            return;
        }
        $form = is_array($_POST['p'] ?? null) ? $_POST['p'] : [];
        $cuenta = mb_substr(trim((string) ($_POST['cuenta'] ?? $t['cuenta'])), 0, 120);
        $piezas = $t['piezas'];
        ksort($piezas);
        $creados = 0;
        foreach (array_keys($piezas) as $i) {
            $f = $form[$i] ?? null;
            if (!is_array($f) || empty($f['incluir'])) {
                continue;
            }
            $this->contenidos()->crear($e, [
                'tipo'     => (string) ($f['tipo'] ?? 'post'),
                'titulo'   => (string) ($f['titulo'] ?? ''),
                'cuenta'   => $cuenta,
                'fecha_publicacion' => (string) ($f['fecha'] ?? ''),
                'copy'     => (string) ($f['copy'] ?? ''),
                'pilar'    => (string) ($f['pilar'] ?? ''),
                'objetivo' => (string) ($f['objetivo'] ?? ''),
                'laminas'  => TiposContenido::laminasDesdeTexto((string) ($f['laminas'] ?? '')),
                'notas'    => (string) ($f['notas'] ?? ''),
            ]);
            $creados++;
        }
        $general = trim(str_replace("\r\n", "\n", (string) ($_POST['general'] ?? '')));
        if (!empty($_POST['usar_general']) && $general !== '') {
            $previo = trim((string) ($e['mensaje'] ?? ''));
            $this->pdo()->prepare('UPDATE portal_entregas SET mensaje = ?, updated_at = ? WHERE id = ?')->execute([
                mb_substr($previo !== '' ? $previo . "\n\n" . $general : $general, 0, 20000),
                (new \DateTimeImmutable())->format('Y-m-d H:i:s'), $id,
            ]);
        }
        if ($creados > 0) {
            $this->entregas()->reabrir($id);
        }
        $this->trabajos()->borrar($token);
        $this->ui->redirect(
            $this->url('entregas/' . $id) . '#imagenes',
            $creados > 0 ? $creados . ' contenido(s) creados desde la grilla. Ahora sube las imágenes con «Imágenes por número».' : 'No se creó ningún contenido (no marcaste ninguna pieza).',
            $creados > 0 ? 'success' : 'error'
        );
    }

    public function descartar(string $id, string $token): void
    {
        if ($this->trabajo($id, $token) !== null) {
            $this->trabajos()->borrar($token);
        }
        $this->ui->redirect($this->url('entregas/' . $id) . '#importar', 'Importación descartada.');
    }

    // ---- Imágenes por número ------------------------------------------------

    /**
     * «3.jpg», «post-3-2.png» o «03_02.webp» van a la pieza 3 (lámina 2). La pieza se busca
     * primero por el número de su título («Post 3 · …») y, si no, por su posición en la entrega.
     */
    public function imagenes(string $id): void
    {
        $e = $this->entregas()->find($id);
        if ($e === null) {
            $this->ui->redirect($this->url('entregas'), 'Entrega no encontrada.', 'error');
            return;
        }
        $volver = $this->url('entregas/' . $id) . '#imagenes';
        if (ArchivoService::postExcedido()) {
            $this->ui->redirect($volver, 'Las imágenes superan el máximo del servidor (post_max_size). Súbelas en grupos más chicos.', 'error');
            return;
        }
        $lista = array_slice(ArchivoService::normalizar($_FILES['imagenes'] ?? null), 0, 80);
        if ($lista === []) {
            $this->ui->redirect($volver, 'Elige las imágenes.', 'error');
            return;
        }
        $contenidos = $this->contenidos()->listar($id);
        $porTitulo = [];
        foreach ($contenidos as $c) {
            if (preg_match('/^\s*(?:post|reel|story|pieza|historia|carrusel|video)?\s*(\d+)\b/iu', (string) $c['titulo'], $m) === 1) {
                $porTitulo[(int) $m[1]] ??= $c;
            }
        }
        $grupos = [];
        $sinPieza = [];
        foreach ($lista as $f) {
            $base = pathinfo((string) ($f['name'] ?? ''), PATHINFO_FILENAME);
            if (preg_match_all('/\d+/', $base, $m) === 0) {
                $sinPieza[] = (string) ($f['name'] ?? '');
                continue;
            }
            $n = (int) $m[0][0];
            $c = $porTitulo[$n] ?? ($contenidos[$n - 1] ?? null);
            if ($c === null || $c['version_id'] === null) {
                $sinPieza[] = (string) ($f['name'] ?? '');
                continue;
            }
            $grupos[$c['id']]['c'] = $c;
            $grupos[$c['id']]['f'][] = [(int) ($m[0][1] ?? 0), (string) ($f['name'] ?? ''), $f];
        }
        $autor = ['tipo' => 'equipo', 'id' => $this->ui->autorId(), 'nombre' => $this->ui->firma()];
        $archivos = new ArchivoService($this->pdo());
        $subidas = 0;
        $errores = [];
        foreach ($grupos as $g) {
            usort($g['f'], fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
            $r = $archivos->guardarVarios(array_column($g['f'], 2), (string) $e['cliente_id'], (string) $e['proyecto_id'], 'version', (string) $g['c']['version_id'], $autor, $this->maxMb());
            $subidas += count($r['ok']);
            $errores = array_merge($errores, $r['errores']);
        }
        if ($subidas > 0) {
            $this->entregas()->reabrir($id);
        }
        $msg = $subidas . ' imagen(es) repartidas en ' . count($grupos) . ' pieza(s).';
        if ($sinPieza !== []) {
            $msg .= ' Sin pieza (revisa el número del nombre): ' . implode(', ', array_slice($sinPieza, 0, 8)) . (count($sinPieza) > 8 ? '…' : '') . '.';
        }
        if ($errores !== []) {
            $msg .= ' Errores: ' . implode(' · ', array_slice($errores, 0, 5));
        }
        $this->ui->redirect($volver, $msg, $subidas > 0 && $errores === [] && $sinPieza === [] ? 'success' : 'error');
    }
}

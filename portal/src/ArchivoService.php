<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * Subida, almacenamiento y entrega de archivos.
 *
 * Reglas de seguridad (en hosting compartido esto es lo que más importa):
 *  - Los binarios se guardan FUERA del web-root (typedock/storage/portal_uploads/<cliente>/),
 *    con nombre aleatorio y sin extensión. Nunca hay una URL directa al archivo.
 *  - Se entregan por controlador, que valida sesión y cliente_id.
 *  - Extensión en lista blanca + tipo MIME real (finfo), no el que declara el navegador.
 *  - SVG y HTML quedan fuera (llevan scripts). Sólo imágenes/PDF salen "inline";
 *    todo lo demás se fuerza como descarga, con nosniff.
 */
class ArchivoService
{
    public const TABLE = 'portal_archivos';

    /** ext => familia de MIME esperada ('' = no se valida, formatos binarios propietarios). */
    private const PERMITIDOS = [
        'jpg' => 'image', 'jpeg' => 'image', 'png' => 'image', 'gif' => 'image', 'webp' => 'image',
        'pdf' => 'pdf',
        'doc' => 'office', 'docx' => 'office', 'xls' => 'office', 'xlsx' => 'office',
        'ppt' => 'office', 'pptx' => 'office', 'odt' => 'office', 'ods' => 'office', 'odp' => 'office',
        'txt' => 'text', 'csv' => 'text', 'rtf' => 'text',
        'zip' => 'zip',
        'psd' => '', 'ai' => '', 'eps' => '', 'fig' => '', 'sketch' => '', 'xd' => '', 'indd' => '',
        'mp4' => 'video', 'mov' => 'video', 'mp3' => 'audio', 'wav' => 'audio', 'm4a' => 'audio',
    ];

    private const MIME_PROHIBIDOS = [
        'text/x-php', 'application/x-httpd-php', 'text/html', 'application/xhtml+xml', 'image/svg+xml',
        'application/x-sh', 'text/x-shellscript', 'application/x-msdownload', 'application/x-dosexec',
        'application/x-executable', 'text/javascript', 'application/javascript',
    ];

    public const INLINE = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf', 'video/mp4', 'video/quicktime'];

    /** Sólo pruebas: reemplaza move_uploaded_file. */
    public static ?\Closure $mover = null;

    /** Sólo pruebas: recibe la ruta en vez de imprimirla con readfile(). */
    public static ?\Closure $emitir = null;

    public function __construct(private readonly \PDO $pdo, private readonly ?string $baseDir = null) {}

    // ---- Configuración ----------------------------------------------------

    public function directorioBase(): string
    {
        if ($this->baseDir !== null) {
            $dir = $this->baseDir;
        } elseif (defined('PORTAL_UPLOAD_DIR')) {
            $dir = (string) constant('PORTAL_UPLOAD_DIR');
        } else {
            // .../typedock/plugins/portal/src -> .../typedock/storage/portal_uploads
            $dir = dirname(__DIR__, 3) . '/storage/portal_uploads';
        }
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        // Por si alguien apunta esto dentro del web-root: bloquear todo acceso directo.
        if (is_dir($dir) && !is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
            @file_put_contents($dir . '/index.html', '');
        }
        return rtrim($dir, '/');
    }

    /** Límite efectivo en bytes: el menor entre el ajuste del portal y php.ini. */
    public function limiteBytes(int $maxMbPortal = 20): int
    {
        $ini = min(self::iniBytes((string) ini_get('upload_max_filesize')), self::iniBytes((string) ini_get('post_max_size')));
        $portal = max(1, $maxMbPortal) * 1048576;
        return $ini > 0 ? min($ini, $portal) : $portal;
    }

    public static function iniBytes(string $v): int
    {
        $v = trim($v);
        if ($v === '' || $v === '-1') {
            return 0;
        }
        $n = (int) $v;
        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1073741824,
            'm' => $n * 1048576,
            'k' => $n * 1024,
            default => $n,
        };
    }

    /** true si el POST completo superó post_max_size (PHP entonces vacía $_POST y $_FILES). */
    public static function postExcedido(): bool
    {
        $max = self::iniBytes((string) ini_get('post_max_size'));
        $len = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        return $max > 0 && $len > $max;
    }

    /** @return string[] extensiones permitidas, para mostrar en la UI. */
    public static function extensiones(): array
    {
        return array_keys(self::PERMITIDOS);
    }

    // ---- Guardar ----------------------------------------------------------

    /**
     * Normaliza $_FILES['campo'] (simple o múltiple) a una lista de archivos.
     * @return array<int, array{name: string, tmp_name: string, error: int, size: int}>
     */
    public static function normalizar(mixed $f): array
    {
        if (!is_array($f) || !isset($f['name'])) {
            return [];
        }
        if (!is_array($f['name'])) {
            return $f['error'] === UPLOAD_ERR_NO_FILE ? [] : [$f];
        }
        $out = [];
        foreach ($f['name'] as $i => $name) {
            if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $out[] = [
                'name' => (string) $name, 'tmp_name' => (string) $f['tmp_name'][$i],
                'error' => (int) $f['error'][$i], 'size' => (int) $f['size'][$i],
            ];
        }
        return $out;
    }

    /**
     * Guarda uno o varios archivos. Devuelve cuántos se guardaron y los errores
     * por archivo (uno malo no cancela a los demás).
     *
     * @param array<int, array<string, mixed>> $archivos
     * @param array{tipo: string, id: ?string, nombre: string} $autor
     * @return array{ok: array<int, array<string, mixed>>, errores: string[]}
     */
    public function guardarVarios(
        array $archivos,
        string $clienteId,
        ?string $proyectoId,
        string $entidadTipo,
        string $entidadId,
        array $autor,
        int $maxMbPortal = 20
    ): array {
        $ok = [];
        $errores = [];
        foreach ($archivos as $f) {
            try {
                $ok[] = $this->guardarUno($f, $clienteId, $proyectoId, $entidadTipo, $entidadId, $autor, $maxMbPortal);
            } catch (\RuntimeException $e) {
                $errores[] = $this->nombreSeguro((string) ($f['name'] ?? 'archivo')) . ': ' . $e->getMessage();
            }
        }
        return ['ok' => $ok, 'errores' => $errores];
    }

    /**
     * @param array<string, mixed> $f
     * @param array{tipo: string, id: ?string, nombre: string} $autor
     * @return array<string, mixed> fila insertada
     */
    public function guardarUno(
        array $f,
        string $clienteId,
        ?string $proyectoId,
        string $entidadTipo,
        string $entidadId,
        array $autor,
        int $maxMbPortal = 20
    ): array {
        $limite = $this->limiteBytes($maxMbPortal);
        $error  = (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException(match ($error) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'pesa más de ' . (int) round($limite / 1048576) . ' MB (el máximo permitido)',
                UPLOAD_ERR_PARTIAL => 'la subida se cortó, inténtalo de nuevo',
                UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'el servidor no pudo guardarlo, avísanos',
                default => 'no se pudo subir',
            });
        }

        $tmp  = (string) ($f['tmp_name'] ?? '');
        $size = (int) ($f['size'] ?? 0);
        if ($tmp === '' || !is_file($tmp)) {
            throw new \RuntimeException('no se pudo leer el archivo');
        }
        if ($size <= 0) {
            throw new \RuntimeException('el archivo está vacío');
        }
        if ($size > $limite) {
            throw new \RuntimeException('pesa más de ' . (int) round($limite / 1048576) . ' MB (el máximo permitido)');
        }

        $nombre = $this->nombreSeguro((string) ($f['name'] ?? 'archivo'));
        $ext    = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
        if (!array_key_exists($ext, self::PERMITIDOS)) {
            throw new \RuntimeException('este tipo de archivo no está permitido (.' . ($ext !== '' ? $ext : '?') . ')');
        }

        $mime = $this->detectarMime($tmp);
        if (in_array($mime, self::MIME_PROHIBIDOS, true)) {
            throw new \RuntimeException('el contenido del archivo no está permitido');
        }
        $familia = self::PERMITIDOS[$ext];
        if ($familia === 'image' && (!str_starts_with($mime, 'image/') || @getimagesize($tmp) === false)) {
            throw new \RuntimeException('no parece una imagen válida');
        }
        if ($familia === 'pdf' && $mime !== 'application/pdf') {
            throw new \RuntimeException('no parece un PDF válido');
        }
        if ($familia === 'zip' && !in_array($mime, ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'], true)) {
            throw new \RuntimeException('no parece un ZIP válido');
        }

        $dir = $this->directorioBase() . '/' . $this->segmento($clienteId);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('el servidor no pudo guardarlo, avísanos');
        }
        $ruta = bin2hex(random_bytes(16));
        $destino = $dir . '/' . $ruta;

        $mover = self::$mover ?? 'move_uploaded_file';
        if (!$mover($tmp, $destino)) {
            throw new \RuntimeException('el servidor no pudo guardarlo, avísanos');
        }
        @chmod($destino, 0640);

        $miniatura = null;
        if ($familia === 'image') {
            $miniatura = $this->crearMiniatura($destino, $dir . '/' . $ruta . '_t') ? $ruta . '_t' : null;
        }

        // Orden de subida dentro de la entidad (un carrusel debe salir como se subió; el id no lo garantiza).
        $mx = $this->pdo->prepare('SELECT COALESCE(MAX(orden), 0) FROM ' . self::TABLE . ' WHERE entidad_tipo = ? AND entidad_id = ?');
        $mx->execute([$entidadTipo, $entidadId]);
        $orden = (int) $mx->fetchColumn() + 1;

        $id = typedock_uuid7();
        $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . '
             (id, cliente_id, proyecto_id, entidad_tipo, entidad_id, nombre_original, ruta, miniatura, mime, tamano,
              subido_por_tipo, subido_por_id, subido_por_nombre, created_at, orden)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $id, $clienteId, $proyectoId, $entidadTipo, $entidadId, $nombre, $ruta, $miniatura, $mime, $size,
            $autor['tipo'], $autor['id'], $autor['nombre'], (new \DateTimeImmutable())->format('Y-m-d H:i:s'), $orden,
        ]);

        return $this->find($id) ?? [];
    }

    private function detectarMime(string $ruta): string
    {
        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            if ($fi !== false) {
                $m = finfo_file($fi, $ruta);
                finfo_close($fi);
                if (is_string($m) && $m !== '') {
                    return strtolower($m);
                }
            }
        }
        return 'application/octet-stream';
    }

    /** Sin rutas, sin caracteres de control ni comillas; conserva tildes y espacios. */
    public function nombreSeguro(string $nombre): string
    {
        $nombre = basename(str_replace('\\', '/', $nombre));
        $nombre = preg_replace('/[\x00-\x1F\x7F"<>|?*:]/u', '', $nombre) ?? '';
        $nombre = trim($nombre, " .\t");
        if ($nombre === '') {
            $nombre = 'archivo';
        }
        if (mb_strlen($nombre) > 150) {
            $ext = pathinfo($nombre, PATHINFO_EXTENSION);
            $nombre = mb_substr(pathinfo($nombre, PATHINFO_FILENAME), 0, 140) . ($ext !== '' ? '.' . $ext : '');
        }
        return $nombre;
    }

    public function segmento(string $id): string
    {
        return preg_replace('/[^A-Za-z0-9_-]/', '', $id) ?: 'x';
    }

    /** Miniatura JPEG de hasta 480 px con GD. Devuelve false si no se puede (no es grave). */
    private function crearMiniatura(string $origen, string $destino, int $max = 480): bool
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
            return false;
        }
        $info = @getimagesize($origen);
        if ($info === false) {
            return false;
        }
        [$w, $h] = $info;
        if ($w < 1 || $h < 1 || $w * $h > 24_000_000) {
            return false; // evita reventar memory_limit con fotos gigantes
        }
        try {
            $bin = @file_get_contents($origen);
            $src = $bin !== false ? @imagecreatefromstring($bin) : false;
            unset($bin);
            if ($src === false) {
                return false;
            }
            $esc = min(1.0, $max / max($w, $h));
            $nw = max(1, (int) round($w * $esc));
            $nh = max(1, (int) round($h * $esc));
            $dst = imagecreatetruecolor($nw, $nh);
            imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            $ok = imagejpeg($dst, $destino, 80);
            imagedestroy($src);
            imagedestroy($dst);
            if ($ok) {
                @chmod($destino, 0640);
            }
            return $ok;
        } catch (\Throwable) {
            return false;
        }
    }

    // ---- Consultas --------------------------------------------------------

    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = ?');
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        return $r !== false ? $r : null;
    }

    /** @return array<array<string, mixed>> */
    public function deEntidad(string $entidadTipo, string $entidadId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . self::TABLE . ' WHERE entidad_tipo = ? AND entidad_id = ? ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute([$entidadTipo, $entidadId]);
        return $stmt->fetchAll();
    }

    // ---- Borrar -----------------------------------------------------------

    public function borrar(string $id): void
    {
        $a = $this->find($id);
        if ($a === null) {
            return;
        }
        $this->borrarFisicos([$a]);
        $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE id = ?')->execute([$id]);
    }

    public function borrarDeEntidad(string $entidadTipo, string $entidadId): void
    {
        $this->borrarFisicos($this->deEntidad($entidadTipo, $entidadId));
        $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE entidad_tipo = ? AND entidad_id = ?')
            ->execute([$entidadTipo, $entidadId]);
    }

    /** Antes de borrar un cliente: la FK borra las filas, pero los binarios hay que quitarlos a mano. */
    public function borrarFisicosDeCliente(string $clienteId): void
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE cliente_id = ?');
        $stmt->execute([$clienteId]);
        $this->borrarFisicos($stmt->fetchAll());
    }

    public function borrarFisicosDeProyecto(string $proyectoId): void
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE proyecto_id = ?');
        $stmt->execute([$proyectoId]);
        $filas = $stmt->fetchAll();
        $this->borrarFisicos($filas);
        $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE proyecto_id = ?')->execute([$proyectoId]);
    }

    /** @param array<array<string, mixed>> $filas */
    private function borrarFisicos(array $filas): void
    {
        $base = $this->directorioBase();
        foreach ($filas as $a) {
            $dir = $base . '/' . $this->segmento((string) $a['cliente_id']);
            foreach ([$a['ruta'] ?? null, $a['miniatura'] ?? null] as $n) {
                if (is_string($n) && $n !== '') {
                    @unlink($dir . '/' . basename($n));
                }
            }
        }
    }

    // ---- Entrega ----------------------------------------------------------

    /**
     * Envía el archivo al navegador. El llamador ya validó permisos.
     * @param array<string, mixed> $a
     */
    public function enviar(array $a, bool $miniatura = false, bool $inline = false): bool
    {
        $dir  = $this->directorioBase() . '/' . $this->segmento((string) $a['cliente_id']);
        $usarMini = $miniatura && !empty($a['miniatura']);
        $ruta = $dir . '/' . basename((string) ($usarMini ? $a['miniatura'] : $a['ruta']));
        if (!is_file($ruta)) {
            return false;
        }

        $mime = $usarMini ? 'image/jpeg' : (string) ($a['mime'] ?: 'application/octet-stream');
        $puedeInline = $inline && in_array($mime, self::INLINE, true);
        if (!$puedeInline) {
            $mime = 'application/octet-stream';
        }

        $nombre = (string) $a['nombre_original'];
        $ascii  = preg_replace('/[^A-Za-z0-9._-]+/', '_', $nombre) ?: 'archivo';

        if (self::$emitir === null) {
            // Descarta cualquier salida previa (avisos, espacios) para no corromper el binario.
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
        }
        $total = (int) filesize($ruta);
        $desde = 0;
        $hasta = $total - 1;
        $parcial = false;
        // Los <video> (Safari sobre todo) piden rangos: sin 206 no se pueden adelantar.
        if (self::$emitir === null && $puedeInline && str_starts_with($mime, 'video/')
            && preg_match('/^bytes=(\d*)-(\d*)$/', (string) ($_SERVER['HTTP_RANGE'] ?? ''), $m) === 1 && ($m[1] !== '' || $m[2] !== '')) {
            if ($m[1] === '') {
                $desde = max(0, $total - (int) $m[2]);
            } else {
                $desde = (int) $m[1];
                $hasta = $m[2] !== '' ? min((int) $m[2], $total - 1) : $total - 1;
            }
            if ($desde > $hasta || $desde >= $total) {
                http_response_code(416);
                header('Content-Range: bytes */' . $total);
                return true;
            }
            $parcial = true;
            http_response_code(206);
            header('Content-Range: bytes ' . $desde . '-' . $hasta . '/' . $total);
        }
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . ($hasta - $desde + 1));
        header('Accept-Ranges: bytes');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=3600');
        header('Content-Disposition: ' . ($puedeInline ? 'inline' : 'attachment')
            . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($nombre));
        if (self::$emitir !== null) {
            (self::$emitir)($ruta);
        } elseif ($parcial) {
            $fh = fopen($ruta, 'rb');
            if ($fh !== false) {
                fseek($fh, $desde);
                $resto = $hasta - $desde + 1;
                while ($resto > 0 && !feof($fh)) {
                    $trozo = fread($fh, min(8192, $resto));
                    if ($trozo === false) {
                        break;
                    }
                    echo $trozo;
                    $resto -= strlen($trozo);
                }
                fclose($fh);
            }
        } else {
            readfile($ruta);
        }
        return true;
    }
}

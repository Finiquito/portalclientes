<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * Identidad que aparece en los correos: logo y nombre del equipo (global) y logo/color de cada cliente.
 * Los logos se sirven por rutas públicas (/portal/marca/...) porque los clientes de correo no tienen
 * sesión; sólo se exponen imágenes de marca, nunca otros archivos.
 */
class MarcaService
{
    public const LOGO_EXT = ['png', 'jpg', 'jpeg', 'webp'];
    public const MAX_BYTES = 2097152;

    public function __construct(private readonly \PDO $pdo, private readonly ?string $baseDir = null) {}

    private function ajustes(): AjustesService
    {
        return new AjustesService($this->pdo);
    }

    private function dir(): string
    {
        $d = (new ArchivoService($this->pdo, $this->baseDir))->directorioBase() . '/.marca';
        if (!is_dir($d)) {
            @mkdir($d, 0750, true);
        }
        return $d;
    }

    public function nombreEquipo(): string
    {
        $n = trim($this->ajustes()->get('global', 'portal', 'nombre_equipo'));
        return $n !== '' ? $n : 'Equipo';
    }

    // ---- Logo del equipo -----------------------------------------------------

    /** @return string|null mensaje de error, o null si quedó guardado */
    public function guardarLogoAgencia(array $f): ?string
    {
        $ext = strtolower(pathinfo((string) ($f['name'] ?? ''), PATHINFO_EXTENSION));
        $tmp = (string) ($f['tmp_name'] ?? '');
        if (!in_array($ext, self::LOGO_EXT, true)) {
            return 'El logo debe ser PNG, JPG o WEBP (los correos no muestran bien SVG).';
        }
        if ($tmp === '' || !is_file($tmp) || (int) filesize($tmp) > self::MAX_BYTES) {
            return 'El logo pesa más de 2 MB o no se pudo leer.';
        }
        $info = @getimagesize($tmp);
        if ($info === false || !in_array($info[2] ?? 0, [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
            return 'El archivo no parece una imagen PNG, JPG o WEBP válida.';
        }
        $this->quitarLogoAgencia();
        $destino = $this->dir() . '/agencia.' . ($ext === 'jpeg' ? 'jpg' : $ext);
        $ok = is_uploaded_file($tmp) ? @move_uploaded_file($tmp, $destino) : @copy($tmp, $destino);
        if (!$ok) {
            return 'No se pudo guardar el logo en el servidor.';
        }
        @chmod($destino, 0640);
        $this->ajustes()->set('global', 'portal', 'logo_agencia', basename($destino));
        return null;
    }

    public function quitarLogoAgencia(): void
    {
        foreach (glob($this->dir() . '/agencia.*') ?: [] as $f) {
            @unlink($f);
        }
        $this->ajustes()->set('global', 'portal', 'logo_agencia', '');
    }

    public function rutaLogoAgencia(): ?string
    {
        $n = basename($this->ajustes()->get('global', 'portal', 'logo_agencia'));
        $ruta = $this->dir() . '/' . $n;
        return $n !== '' && is_file($ruta) ? $ruta : null;
    }

    public function urlLogoAgencia(string $base): string
    {
        $r = $this->rutaLogoAgencia();
        return $r === null ? '' : rtrim($base, '/') . '/portal/marca/agencia?v=' . (int) filemtime($r);
    }

    // ---- Logo y color del cliente -----------------------------------------------

    public function colorCliente(string $clienteId): string
    {
        return AjustesService::colorValido($this->ajustes()->get('cliente', $clienteId, 'color'));
    }

    /** @return array<string, mixed>|null fila de portal_archivos del logo */
    public function archivoLogoCliente(string $clienteId): ?array
    {
        $id = trim($this->ajustes()->get('cliente', $clienteId, 'logo_id'));
        if ($id === '') {
            return null;
        }
        $a = (new ArchivoService($this->pdo, $this->baseDir))->find($id);
        return $a !== null && $a['entidad_tipo'] === 'cliente_logo' && $a['cliente_id'] === $clienteId ? $a : null;
    }

    public function urlLogoCliente(string $clienteId, string $base): string
    {
        $a = $this->archivoLogoCliente($clienteId);
        return $a === null ? '' : rtrim($base, '/') . '/portal/marca/cliente/' . rawurlencode($clienteId) . '?v=' . rawurlencode((string) $a['id']);
    }

    // ---- Salida de imágenes ---------------------------------------------------------

    /** Envía el logo del equipo. Devuelve false si no hay. */
    public function enviarLogoAgencia(): bool
    {
        $r = $this->rutaLogoAgencia();
        return $r !== null && $this->emitir($r);
    }

    public function enviarLogoCliente(string $clienteId): bool
    {
        $a = $this->archivoLogoCliente($clienteId);
        if ($a === null) {
            return false;
        }
        $ruta = (new ArchivoService($this->pdo, $this->baseDir))->directorioBase() . '/' . (new ArchivoService($this->pdo, $this->baseDir))->segmento($clienteId) . '/' . basename((string) $a['ruta']);
        return is_file($ruta) && $this->emitir($ruta);
    }

    /** Sólo pruebas: reemplaza la salida real (cabeceras + readfile). */
    public static ?\Closure $emisor = null;

    protected function emitir(string $ruta): bool
    {
        if (self::$emisor !== null) {
            (self::$emisor)($ruta);
            return true;
        }
        $mime = match (strtolower(pathinfo($ruta, PATHINFO_EXTENSION))) { 'png' => 'image/png', 'webp' => 'image/webp', default => 'image/jpeg' };
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=86400');
        header('Content-Length: ' . filesize($ruta));
        readfile($ruta);
        return true;
    }
}

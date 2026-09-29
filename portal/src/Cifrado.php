<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * Cifra secretos guardados en la base (contraseña SMTP): AES-256-GCM con una clave aparte en
 * storage/portal_uploads/.ia_secret (fuera de la BD), la misma que usa IaService para sus claves.
 */
final class Cifrado
{
    public function __construct(private readonly \PDO $pdo, private readonly ?string $baseDir = null) {}

    private function secreto(): string
    {
        $dir  = (new ArchivoService($this->pdo, $this->baseDir))->directorioBase();
        $ruta = $dir . '/.ia_secret';
        if (!is_file($ruta)) {
            @file_put_contents($ruta, bin2hex(random_bytes(32)), LOCK_EX);
            @chmod($ruta, 0600);
        }
        $s = is_file($ruta) ? trim((string) file_get_contents($ruta)) : '';
        return hash('sha256', $s !== '' ? $s : 'sin-secreto', true);
    }

    public function cifrar(string $claro): string
    {
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt($claro, 'aes-256-gcm', $this->secreto(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) {
            throw new \RuntimeException('No se pudo cifrar.');
        }
        return 'v1:' . base64_encode($iv . $tag . $ct);
    }

    public function descifrar(string $guardado): string
    {
        if (!str_starts_with($guardado, 'v1:')) {
            return '';
        }
        $raw = base64_decode(substr($guardado, 3), true);
        if ($raw === false || strlen($raw) < 29) {
            return '';
        }
        $claro = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $this->secreto(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $claro === false ? '' : $claro;
    }
}

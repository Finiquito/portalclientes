<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Invitaciones;

/**
 * Ajustes del plugin. La clave de Mailchimp se guarda cifrada (AES-256-GCM) con un secreto
 * que vive en un archivo fuera de la base de datos: un respaldo de la BD por sí solo no la expone.
 * La variable de entorno MAILCHIMP_API_KEY, si existe, tiene prioridad.
 */
final class Ajustes
{
    public function __construct(private readonly \PDO $pdo, private readonly ?string $dirSecreto = null) {}

    public function get(string $clave, string $def = ''): string
    {
        $st = $this->pdo->prepare('SELECT valor FROM invitaciones_ajustes WHERE clave = ?');
        $st->execute([$clave]);
        $v = $st->fetchColumn();
        return $v === false || $v === null ? $def : (string) $v;
    }

    public function set(string $clave, string $valor): void
    {
        $ahora = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $up = $this->pdo->prepare('UPDATE invitaciones_ajustes SET valor = ?, updated_at = ? WHERE clave = ?');
        $up->execute([$valor, $ahora, $clave]);
        if ($up->rowCount() === 0 && $this->get($clave, "\0") === "\0") {
            $this->pdo->prepare('INSERT INTO invitaciones_ajustes (clave, valor, updated_at) VALUES (?, ?, ?)')->execute([$clave, $valor, $ahora]);
        }
    }

    // ---- Clave de Mailchimp --------------------------------------------------

    public function clave(): string
    {
        $env = trim((string) getenv('MAILCHIMP_API_KEY'));
        if ($env !== '') {
            return $env;
        }
        $g = $this->get('mc_clave');
        return $g !== '' ? $this->descifrar($g) : '';
    }

    public function origenClave(): string
    {
        return trim((string) getenv('MAILCHIMP_API_KEY')) !== '' ? 'entorno' : ($this->get('mc_clave') !== '' ? 'guardada' : '');
    }

    public function guardarClave(string $clave): void
    {
        $this->set('mc_clave', $clave === '' ? '' : $this->cifrar($clave));
    }

    /** Los últimos 4 caracteres, para mostrar qué clave hay sin revelarla. */
    public function claveVisible(): string
    {
        $c = $this->clave();
        return $c === '' ? '' : '••••' . substr($c, -4);
    }

    private function dir(): string
    {
        if ($this->dirSecreto !== null) {
            $d = $this->dirSecreto;
        } elseif (defined('INVITACIONES_DIR')) {
            $d = (string) constant('INVITACIONES_DIR');
        } else {
            // .../typedock/plugins/invitaciones/src -> .../typedock/storage/invitaciones
            $d = dirname(__DIR__, 3) . '/storage/invitaciones';
        }
        if (!is_dir($d)) {
            @mkdir($d, 0750, true);
            @file_put_contents($d . '/.htaccess', "Require all denied\nDeny from all\n");
        }
        return rtrim($d, '/');
    }

    private function secreto(): string
    {
        $ruta = $this->dir() . '/.secreto';
        if (!is_file($ruta)) {
            @file_put_contents($ruta, bin2hex(random_bytes(32)), LOCK_EX);
            @chmod($ruta, 0600);
        }
        $s = is_file($ruta) ? trim((string) file_get_contents($ruta)) : '';
        if ($s === '') {
            throw new \RuntimeException('No se pudo crear el archivo del secreto en ' . $this->dir() . '. Revisa los permisos de la carpeta storage.');
        }
        return hash('sha256', $s, true);
    }

    private function cifrar(string $claro): string
    {
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt($claro, 'aes-256-gcm', $this->secreto(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) {
            throw new \RuntimeException('No se pudo cifrar la clave.');
        }
        return 'v1:' . base64_encode($iv . $tag . $ct);
    }

    private function descifrar(string $g): string
    {
        if (!str_starts_with($g, 'v1:')) {
            return '';
        }
        $b = base64_decode(substr($g, 3), true);
        if ($b === false || strlen($b) < 29) {
            return '';
        }
        $claro = openssl_decrypt(substr($b, 28), 'aes-256-gcm', $this->secreto(), OPENSSL_RAW_DATA, substr($b, 0, 12), substr($b, 12, 16));
        return $claro === false ? '' : $claro;
    }
}

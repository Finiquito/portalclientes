<?php
declare(strict_types=1);

namespace TypeDock\Core;

/**
 * Imitación mínima del PluginContext de TypeDock, sólo para desarrollo y pruebas.
 * Implementa lo que usa el plugin portal: migraciones, BD, rutas de admin, menú,
 * vistas Latte, redirecciones con flash y correo.
 */
class PluginContext
{
    public const ADMIN_PREFIX = '/admin/portal';

    /** @var array<int, array{0: string, 1: string}> */
    public array $menu = [];

    /** @var array<int, array{to: string, subject: string, body: string}> */
    public array $correos = [];

    private ?\Latte\Engine $latte = null;

    private readonly string $prefijo;

    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $pluginDir,
        private readonly string $cacheDir,
        string $slug = 'portal',
    ) {
        $this->prefijo = '/admin/' . $slug;
    }

    public function db(): object
    {
        $pdo = $this->pdo;
        return new class($pdo) {
            public function __construct(private readonly \PDO $pdo) {}
            public function pdo(): \PDO { return $this->pdo; }
        };
    }

    public function migrate(string $dir): void
    {
        $files = glob(rtrim($dir, '/') . '/*.sql') ?: [];
        sort($files);
        foreach ($files as $f) {
            $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($f)) ?? '';
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                $this->pdo->exec($stmt);
            }
        }
    }

    public function registerAdminRoute(string $method, string $path, callable $handler): void
    {
        $url = $this->prefijo . ($path === '' ? '' : '/' . $path);
        \Flight::route($method . ' ' . ($url === '' ? '/' : $url), $handler);
    }

    public function addAdminMenuItem(string $label, string $path): void
    {
        $this->menu[] = [$label, $path];
    }

    public function adminUrl(string $path = ''): string
    {
        return $this->prefijo . ($path === '' ? '' : '/' . ltrim($path, '/'));
    }

    public function latte(): \Latte\Engine
    {
        if ($this->latte === null) {
            $this->latte = new \Latte\Engine();
            $this->latte->setTempDirectory($this->cacheDir);
        }
        return $this->latte;
    }

    /** @param array<string, mixed> $data */
    public function view(string $template, array $data = []): void
    {
        echo $this->renderView($template, $data);
    }

    /** @param array<string, mixed> $data */
    public function renderView(string $template, array $data = []): string
    {
        typedock_session_start();
        $defaults = [
            'plugin_ui_layout' => __DIR__ . '/admin-layout.latte',
            'admin_url'        => fn(string $p = '') => $this->adminUrl($p),
            'csrf_token'       => 'dev',
            'admin_menu'       => $this->menu,
        ];
        return $this->latte()->renderToString($this->pluginDir . '/' . ltrim($template, '/'), $data + $defaults);
    }

    public function redirect(string $url, ?string $message = null, string $type = 'success'): void
    {
        typedock_session_start();
        if ($message !== null) {
            $_SESSION['td_flash'][$type] = $message;
        }
        \Flight::redirect($url);
        throw new RedirectException($url);
    }

    public function getFlash(string $type): ?string
    {
        typedock_session_start();
        $m = $_SESSION['td_flash'][$type] ?? null;
        unset($_SESSION['td_flash'][$type]);
        return $m;
    }

    public function mail(): object
    {
        $ctx = $this;
        return new class($ctx) {
            public function __construct(private readonly PluginContext $ctx) {}
            public function send(string $to, string $subject, string $body): bool
            {
                $this->ctx->correos[] = ['to' => $to, 'subject' => $subject, 'body' => $body];
                $log = getenv('PORTAL_MAIL_LOG');
                if ($log) {
                    @file_put_contents($log, "=== " . date('c') . " → {$to}\n{$subject}\n\n{$body}\n\n", FILE_APPEND);
                }
                return true;
            }
        };
    }
}

class RedirectException extends \RuntimeException {}

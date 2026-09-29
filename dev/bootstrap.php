<?php
declare(strict_types=1);

/**
 * Arranque del entorno de desarrollo: núcleo simulado + plugin portal.
 * BD: PORTAL_DB_DSN (por defecto SQLite en dev/storage/dev.sqlite), PORTAL_DB_USER, PORTAL_DB_PASS.
 */

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/core/functions.php';
require __DIR__ . '/core/PluginInterface.php';
require __DIR__ . '/core/PluginContext.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'TypeDock\\Plugin\\Portal\\';
    if (str_starts_with($class, $prefix)) {
        $f = dirname(__DIR__) . '/portal/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($f)) {
            require $f;
        }
    }
});

function portal_dev_pdo(?string $dsn = null): PDO
{
    $dsn ??= getenv('PORTAL_DB_DSN') ?: 'sqlite:' . __DIR__ . '/storage/dev.sqlite';
    if (str_starts_with($dsn, 'sqlite:') && $dsn !== 'sqlite::memory:') {
        @mkdir(dirname(substr($dsn, 7)), 0777, true);
    }
    $pdo = new PDO($dsn, getenv('PORTAL_DB_USER') ?: null, getenv('PORTAL_DB_PASS') ?: null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
    // Tabla de usuarios del núcleo (el plugin la lee para nombres de responsables antiguos).
    $pdo->exec('CREATE TABLE IF NOT EXISTS users (id VARCHAR(36) PRIMARY KEY, name VARCHAR(255), email VARCHAR(255))');
    return $pdo;
}

function portal_dev_contexto(PDO $pdo): TypeDock\Core\PluginContext
{
    $cache = __DIR__ . '/storage/latte';
    @mkdir($cache, 0777, true);
    if (!defined('PORTAL_UPLOAD_DIR')) {
        define('PORTAL_UPLOAD_DIR', getenv('PORTAL_UPLOAD_DIR') ?: __DIR__ . '/storage/portal_uploads');
    }
    $ctx = new TypeDock\Core\PluginContext($pdo, dirname(__DIR__) . '/portal', $cache);
    (new TypeDock\Plugin\Portal\PortalPlugin())->register($ctx);
    return $ctx;
}

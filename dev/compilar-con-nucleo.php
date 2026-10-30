<?php
/**
 * Compila todas las plantillas del plugin con el Latte que trae el TypeDock real (otra versión que la de dev/).
 *   TYPEDOCK_CORE_DIR=/ruta/al/clon php dev/compilar-con-nucleo.php
 * Sale con 0 si todas compilan sin errores ni avisos; con 2 si no hay un clon con vendor/ (se omite).
 */
$core = getenv('TYPEDOCK_CORE_DIR') ?: '/home/user/typedock/core';
if (!is_file($core . '/vendor/autoload.php')) {
    fwrite(STDERR, "sin TypeDock en $core\n");
    exit(2);
}
require $core . '/vendor/autoload.php';
spl_autoload_register(function (string $c): void {
    $p = 'TypeDock\\Plugin\\Portal\\';
    if (str_starts_with($c, $p) && is_file($f = dirname(__DIR__) . '/portal/src/' . substr($c, strlen($p)) . '.php')) {
        require $f;
    }
});
require dirname(__DIR__) . '/portal/src/funciones.php';

$motor = new Latte\Engine();
TypeDock\Plugin\Portal\IdiomaExtension::instalar($motor);
$problemas = [];
set_error_handler(function (int $n, string $m) use (&$problemas): bool { $problemas[] = 'AVISO ' . $m; return true; });
$n = 0;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/portal/templates')) as $f) {
    if ($f->getExtension() !== 'latte') {
        continue;
    }
    $n++;
    try {
        $motor->compile($f->getPathname());
    } catch (Throwable $e) {
        $problemas[] = basename($f->getPathname()) . ': ' . $e->getMessage();
    }
}
echo "Latte " . Latte\Engine::Version . ": $n plantillas, " . count($problemas) . " problemas\n" . implode("\n", array_slice($problemas, 0, 6)) . "\n";
exit($problemas === [] ? 0 : 1);

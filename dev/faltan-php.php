<?php
/**
 * Frases del PHP pedidas con Idioma::t('…'), o mostradas con flash/redirect, que aún no están en portal/lang/<código>/.
 *   php dev/faltan-php.php [en] [archivo.php …]
 */
require __DIR__ . '/vendor/autoload.php';
spl_autoload_register(function (string $c): void {
    $p = 'TypeDock\\Plugin\\Portal\\';
    if (str_starts_with($c, $p) && is_file($f = dirname(__DIR__) . '/portal/src/' . substr($c, strlen($p)) . '.php')) {
        require $f;
    }
});
$cod = $argv[1] ?? 'en';
$t = TypeDock\Plugin\Portal\Idioma::tabla($cod);
$archivos = array_slice($argv, 2) ?: array_map('basename', glob(dirname(__DIR__) . '/portal/src/*.php'));
$lit = "'((?:[^'\\\\\\\\]|\\\\\\\\.)*)'";
foreach ($archivos as $a) {
    $src = file_get_contents(dirname(__DIR__) . '/portal/src/' . $a);
    $patrones = [
        "/Idioma::(?:t|f|lista)\\(\\s*$lit/u",
        "/(?:PortalSession::flash|->flash)\\(\\s*[^,]+,\\s*$lit/u",
        "/->redirect\\([^;]*?,\\s*$lit\\s*[,)]/u",
    ];
    foreach ($patrones as $p) {
        if (preg_match_all($p, $src, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as [$txt, $off]) {
                $k = stripcslashes($txt);
                if (!isset($t[$k]) && preg_match('/\p{L}{3}/u', $k)) {
                    echo $a, ':', substr_count(substr($src, 0, $off), "\n") + 1, "\t", $k, "\n";
                }
            }
        }
    }
}

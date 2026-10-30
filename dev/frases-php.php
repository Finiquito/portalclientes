<?php
/**
 * Lista las frases en español escritas dentro del PHP (para revisar cuáles se muestran a las personas).
 *   php dev/frases-php.php archivo.php …          (rutas relativas a portal/src)
 * Marca con * las que ya están traducidas al inglés.
 */
require __DIR__ . '/vendor/autoload.php';
spl_autoload_register(function (string $c): void {
    $p = 'TypeDock\\Plugin\\Portal\\';
    if (str_starts_with($c, $p) && is_file($f = dirname(__DIR__) . '/portal/src/' . substr($c, strlen($p)) . '.php')) {
        require $f;
    }
});
$t = TypeDock\Plugin\Portal\Idioma::tabla('en');
foreach (array_slice($argv, 1) as $a) {
    $f = dirname(__DIR__) . '/portal/src/' . $a;
    $linea = 1;
    foreach (token_get_all(file_get_contents($f)) as $tok) {
        if (!is_array($tok)) {
            continue;
        }
        $linea = $tok[2];
        if ($tok[0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }
        $v = stripcslashes(substr($tok[1], 1, -1));
        if (!preg_match('/\p{L}{3}/u', $v) || preg_match('/^(SELECT|INSERT|UPDATE|DELETE|CREATE|ALTER|DROP)\b/', $v) || preg_match('/^[a-z_.\-\/:%#0-9 ]+$/', $v)) {
            continue;
        }
        if (!preg_match('/\s/u', $v) && !preg_match('/^[\p{Lu}¿¡]/u', $v)) {
            continue;
        }
        echo isset($t[$v]) ? '*' : ' ', $a, ':', $linea, "\t", $v, "\n";
    }
}

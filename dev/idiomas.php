<?php
/**
 * Detector de frases sin traducir.
 *   php dev/idiomas.php [en] [carpeta…]     lista las claves que faltan en portal/lang/en.php
 *   php dev/idiomas.php --todas en          vuelca todas las claves de las plantillas (para armar el archivo)
 * Compila cada plantilla con la pasada de idioma y recoge las frases que se imprimirían.
 */
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';
spl_autoload_register(function (string $c): void {
    $p = 'TypeDock\\Plugin\\Portal\\';
    if (str_starts_with($c, $p) && is_file($f = dirname(__DIR__) . '/portal/src/' . substr($c, strlen($p)) . '.php')) {
        require $f;
    }
});

use TypeDock\Plugin\Portal\Idioma;
use TypeDock\Plugin\Portal\IdiomaExtension;

$args = array_slice($argv, 1);
$todas = in_array('--todas', $args, true);
$args = array_values(array_filter($args, fn($a) => $a !== '--todas'));
$cod = $args[0] ?? 'en';
$carpetas = array_slice($args, 1) ?: ['public', 'equipo', 'admin', '.'];

$motor = new Latte\Engine();
IdiomaExtension::instalar($motor);
$base = dirname(__DIR__) . '/portal/templates';
$claves = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base)) as $f) {
    if ($f->getExtension() !== 'latte') {
        continue;
    }
    $rel = substr($f->getPathname(), strlen($base) + 1);
    $dirPlantilla = str_contains($rel, '/') ? explode('/', $rel)[0] : '.';
    if (!in_array($dirPlantilla, $carpetas, true)) {
        continue;
    }
    $php = $motor->compile($f->getPathname());
    if (preg_match_all('/Idioma::tx\((\'(?:[^\'\\\\]|\\\\.)*\')\)/', $php, $m)) {
        foreach ($m[1] as $lit) {
            $txt = eval('return ' . $lit . ';');
            $k = Idioma::clave($txt);
            $claves[$k][$rel] = true;
        }
    }
}
$tabla = Idioma::tabla($cod);
ksort($claves);
$n = 0;
foreach ($claves as $k => $archivos) {
    if (!$todas && isset($tabla[$k])) {
        continue;
    }
    $n++;
    echo $k, "\t", implode(',', array_slice(array_keys($archivos), 0, 3)), "\n";
}
fwrite(STDERR, ($todas ? 'frases en plantillas' : 'sin traducir en ' . $cod) . ': ' . $n . ' de ' . count($claves) . "\n");

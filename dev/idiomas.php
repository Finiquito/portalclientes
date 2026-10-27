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

if (($argv[1] ?? '') === '--literales') {
    // Textos en español escritos dentro de expresiones ({$x ? 'a' : 'b'}), que la pasada automática no ve.
    $m2 = new Latte\Engine();
    IdiomaExtension::instalar($m2);
    $base = dirname(__DIR__) . '/portal/templates';
    $zonas = array_slice($argv, 2) ?: ['public'];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base)) as $f) {
        $rel = substr($f->getPathname(), strlen($base) + 1);
        if ($f->getExtension() !== 'latte' || !in_array(str_contains($rel, '/') ? explode('/', $rel)[0] : '.', $zonas, true)) {
            continue;
        }
        $ast = $m2->parse(file_get_contents($f->getPathname()));
        (new Latte\Compiler\NodeTraverser())->traverse($ast, function (Latte\Compiler\Node $n) use ($rel) {
            if ($n instanceof Latte\Compiler\Nodes\PrintNode) {
                foreach ($n->modifier->filters as $fl) {
                    if ($fl->name->name === 'translate') {
                        return Latte\Compiler\NodeTraverser::DontTraverseChildren;
                    }
                }
                $claves = [];
                (new Latte\Compiler\NodeTraverser())->traverse($n->expression, function (Latte\Compiler\Node $h) use (&$claves) {
                    if ($h instanceof Latte\Compiler\Nodes\Php\Expression\ArrayAccessNode && $h->index) {
                        $claves[spl_object_id($h->index)] = true;
                    }
                    if ($h instanceof Latte\Compiler\Nodes\Php\ArrayItemNode && $h->key) {
                        $claves[spl_object_id($h->key)] = true;
                    }
                    return null;
                });
                (new Latte\Compiler\NodeTraverser())->traverse($n->expression, function (Latte\Compiler\Node $h) use ($rel, $n, $claves) {
                    if ($h instanceof Latte\Compiler\Nodes\Php\Expression\FunctionCallNode && $h->name instanceof Latte\Compiler\Nodes\Php\NameNode && $h->name->name === 't') {
                        return Latte\Compiler\NodeTraverser::DontTraverseChildren;
                    }
                    if ($h instanceof Latte\Compiler\Nodes\Php\Scalar\StringNode && !isset($claves[spl_object_id($h)]) && preg_match('/\p{L}{3}/u', $h->value) && !IdiomaExtension::pareceTexto($h->value) && !preg_match('/[-\/.#:\d_]/', $h->value)) {
                        echo $rel, ':', $n->position?->line, "\t", $h->value, "\n";
                    }
                    return null;
                });
            }
            return null;
        });
    }
    exit;
}

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
    if (preg_match_all('/(?:Idioma::tx?\(|fn->t\)\(\$this, |translate\)\()(\'(?:[^\'\\\\]|\\\\.)*\')/', $php, $m)) {
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

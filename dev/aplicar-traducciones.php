<?php
/**
 * Arma un archivo de traducciones a partir de una lista numerada de frases y sus traducciones.
 *   php dev/aplicar-traducciones.php pendientes.txt traducciones.txt salida.php "comentario"
 * pendientes.txt: una frase en español por línea (el número es el de la línea).
 * traducciones.txt: «N|texto en inglés» por línea.
 */
[$_, $pend, $trad, $salida, $coment] = $argv + [null, null, null, null, ''];
$claves = file($pend, FILE_IGNORE_NEW_LINES);
$out = [];
foreach (file($trad, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
    if (!preg_match('/^(\d+)\|(.*)$/', $l, $m)) {
        fwrite(STDERR, "línea ignorada: $l\n");
        continue;
    }
    $k = $claves[(int) $m[1] - 1] ?? null;
    if ($k === null) {
        fwrite(STDERR, "no existe la frase {$m[1]}\n");
        continue;
    }
    $out[$k] = $m[2];
}
$faltan = array_diff($claves, array_keys($out));
foreach ($faltan as $i => $k) {
    fwrite(STDERR, 'sin traducir: ' . ($i + 1) . ' ' . $k . "\n");
}
$php = "<?php\n// " . $coment . "\nreturn [\n";
foreach ($out as $k => $v) {
    $php .= '    ' . var_export($k, true) . ' => ' . var_export($v, true) . ",\n";
}
file_put_contents($salida, $php . "];\n");
echo count($out), " traducciones escritas en $salida\n";

<?php
declare(strict_types=1);

/**
 * Pruebas de la herramienta `prisma` (prisma/bin/prisma) contra un TypeDock real.
 *
 *   TYPEDOCK_CORE_DIR=/ruta/a/typedock-core php tests/prisma.php
 *
 * Necesita un clon de github.com/typedock/core con `composer install --no-dev` hecho.
 * Arma una «distribución» de hosting compartido en una carpeta temporal, instala una agencia
 * (SQLite) y levanta el servidor de PHP para ver que el plugin enlazado arranca de verdad.
 * Si no hay clon, se omite.
 */

$core = getenv('TYPEDOCK_CORE_DIR') ?: '/home/user/typedock/core';
if (!is_file($core . '/vendor/autoload.php')) {
    echo "Pruebas de prisma OMITIDAS: no hay TypeDock en {$core} (TYPEDOCK_CORE_DIR, con composer install).\n";
    exit(0);
}

$ok = 0;
$fallas = [];
function check(bool $c, string $m): void
{
    global $ok, $fallas;
    if ($c) {
        $ok++;
        echo '.';
    } else {
        $fallas[] = $m;
        echo 'F';
    }
}

$repo = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/prisma_t_' . bin2hex(random_bytes(3));
$home = $tmp . '/prisma';
mkdir($home, 0750, true);
$sh = fn(string $c) => shell_exec($c . ' 2>&1');

/** Corre prisma con las mismas funciones deshabilitadas que Hostinger (symlink, exec, popen…). @return array{0:int,1:string} */
function prisma(string $home, array $args): array
{
    $env = ['PRISMA_HOME' => $home, 'PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'HOME' => $home];
    $p = proc_open(array_merge([PHP_BINARY, '-d', 'disable_functions=system,exec,shell_exec,passthru,ini_alter,dl,symlink,link,chgrp,leak,popen,apache_child_terminate,virtual,mb_send_mail', dirname(__DIR__) . '/prisma/bin/prisma'], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    return [proc_close($p), $out];
}

echo "Preparando distribución de TypeDock…\n";
$dist = $home . '/distribucion/typedock-shared';
mkdir($dist . '/typedock', 0755, true);
foreach (['src', 'vendor', 'config', 'admin', 'cli', 'migrations', 'plugins', 'themes', 'schema', 'seeds', 'resources', 'storage'] as $d) {
    if (is_dir("$core/$d")) {
        $sh('cp -a ' . escapeshellarg("$core/$d") . ' ' . escapeshellarg("$dist/typedock/$d"));
    }
}
foreach (['config.php.example', 'composer.json'] as $f) {
    copy("$core/$f", "$dist/typedock/$f");
}
@unlink("$dist/typedock/config.php");
$sh('rm -rf ' . escapeshellarg("$dist/typedock/storage/.installed"));
$sh('cp -a ' . escapeshellarg("$core/public") . ' ' . escapeshellarg("$dist/public_html"));
foreach (['index.php', 'install.php'] as $f) {   // lo mismo que hace build/make-shared-zip.sh
    $s = file_get_contents("$dist/public_html/$f");
    file_put_contents("$dist/public_html/$f", preg_replace('#dirname\(__DIR__\)#', "dirname(__DIR__) . '/typedock'", $s, 1));
}

// Versión 1 del plugin (el del repo) como zip, igual que scripts/zip.sh.
$zip1 = $tmp . '/portal-1.zip';
$sh('cd ' . escapeshellarg($repo) . ' && zip -qr ' . escapeshellarg($zip1) . ' portal -x "*.DS_Store"');
$ver1 = json_decode(file_get_contents("$repo/portal/plugin.json"), true)['version'];

// Compartido y secretos
mkdir("$home/secretos/compartido", 0700, true);
mkdir("$home/secretos/instancias", 0700, true);
file_put_contents("$home/secretos/compartido/correo.json", json_encode(['host' => 'smtp.hostinger.com', 'puerto' => 465, 'seg' => 'ssl', 'usuario' => 'notificaciones@usaprisma.com', 'clave' => 'clave-de-prueba', 'desde' => 'notificaciones@usaprisma.com', 'tope_diario' => 50]));
$secreto = function (string $slug, string $ruta) use ($home): void {
    $f = "$home/secretos/instancias/$slug.json";
    file_put_contents($f, json_encode(['db' => ['driver' => 'sqlite', 'ruta' => $ruta]]));
    chmod($f, 0600);
};

// --- versiones y canales ---
[$c, $o] = prisma($home, ['crear', 'agencia1', '--host=a1.test', '--docroot=' . $tmp . '/pub1', '--correo=jefa@agencia1.cl', '--sin-http', '--simular']);
check($c === 1 && str_contains($o, 'no existe') || str_contains($o, 'no apunta'), 'sin versión en el canal no deja crear');
[$c, $o] = prisma($home, ['version', 'agregar', $zip1]);
check($c === 0 && is_file("$home/releases/portal-$ver1/src/PortalPlugin.php"), 'agrega la versión desde el zip');
[$c, $o] = prisma($home, ['version', 'agregar', $zip1]);
check($c === 1 && str_contains($o, 'Ya existe'), 'una versión no se pisa');
[$c, $o] = prisma($home, ['actualizar', '--canal=canary', '--a=portal-' . $ver1, '--sin-http']);
check($c === 0 && is_link("$home/canales/canary") && basename((string) readlink("$home/canales/canary")) === "portal-$ver1", 'el canal apunta a la versión');
[$c, $o] = prisma($home, ['actualizar', '--canal=canary', '--a=../etc']);
check($c === 1, 'no acepta rutas raras como versión');

// --- crear: validaciones ---
mkdir("$tmp/pub1", 0755, true);
$secreto('agencia1', "$tmp/db/agencia1.sqlite");
mkdir("$tmp/db", 0755, true);
file_put_contents("$tmp/pub1/mis-cosas.txt", 'x');
[$c, $o] = prisma($home, ['crear', 'agencia1', '--host=a1.test', '--docroot=' . $tmp . '/pub1', '--correo=jefa@agencia1.cl', '--canal=canary', '--sin-http']);
check($c === 1 && str_contains($o, 'no está vacía'), 'no pisa un sitio con archivos');
unlink("$tmp/pub1/mis-cosas.txt");
chmod("$home/secretos/instancias/agencia1.json", 0644);
[$c, $o] = prisma($home, ['crear', 'agencia1', '--host=a1.test', '--docroot=' . $tmp . '/pub1', '--correo=jefa@agencia1.cl', '--canal=canary', '--sin-http']);
check($c === 1 && str_contains($o, 'legible por otros'), 'exige permisos 600 en las claves');
chmod("$home/secretos/instancias/agencia1.json", 0600);
[$c, $o] = prisma($home, ['crear', 'Mala Idea', '--host=a1.test', '--docroot=' . $tmp . '/pub1', '--correo=x@y.cl']);
check($c === 1 && str_contains($o, 'minúsculas'), 'valida el nombre corto');
[$c, $o] = prisma($home, ['crear', 'agencia1', '--host=a1.test', '--docroot=' . $tmp . '/pub1', '--correo=jefa@agencia1.cl', '--canal=canary', '--simular']);
check($c === 0 && str_contains($o, 'simulación') && !is_dir("$home/instancias/agencia1") && !is_file("$tmp/pub1/index.php"), 'la simulación no toca nada');

// --- crear de verdad ---
[$c, $o] = prisma($home, ['crear', 'agencia1', '--host=a1.test', '--docroot=' . $tmp . '/pub1', '--correo=jefa@agencia1.cl', '--nombre=Estudio Uno', '--canal=canary', '--url=http://127.0.0.1:8099', '--sin-http']);
check($c === 0, 'crea la agencia' . ($c === 0 ? '' : " ($o)"));
$td = "$home/instancias/agencia1/typedock";
check(str_contains(file_get_contents("$tmp/pub1/index.php"), $td) && str_contains(file_get_contents("$tmp/pub1/install.php"), $td), 'el sitio público apunta a SU typedock/');
$cfg = is_file("$td/config.php") ? file_get_contents("$td/config.php") : '';
check(is_file("$td/config.php") && (fileperms("$td/config.php") & 0077) === 0, 'config.php con permisos 0600');
check(str_contains($cfg, "'PORTAL_UPLOAD_DIR' => '$td/storage/portal_uploads'") && str_contains($cfg, "'PORTAL_SECRETOS_DIR' => '$home/secretos/compartido'") && str_contains($cfg, "'PLUGIN_PORTAL' => 'true'"), 'config.php lleva su carpeta de archivos, los secretos compartidos y el plugin activo');
check(str_contains($cfg, "'SELF_UPDATE_ENABLED' => 'false'") && str_contains($cfg, "'MAIL_FROM_ADDRESS' => 'notificaciones@usaprisma.com'") && str_contains($cfg, 'Estudio Uno vía Prisma'), 'el núcleo no se auto-actualiza y manda como «vía Prisma»');
check(is_link("$td/plugins/portal") && basename((string) readlink("$td/plugins/portal")) === 'canary' && is_file("$td/plugins/portal/plugin.json"), 'el plugin es un enlace al canal');
check(is_dir("$tmp/pub1/plugins/portal/assets"), 'los assets del plugin quedaron publicados en el sitio');
preg_match('#(http://127\.0\.0\.1:8099/admin/password-reset\?token=[0-9a-f]{64})#', $o, $m);
check(isset($m[1]) && !str_contains($o, 'clave-de-prueba'), 'entrega el enlace de primer ingreso y no imprime claves');
$pdo = new PDO('sqlite:' . $tmp . '/db/agencia1.sqlite');
$u = $pdo->query("SELECT id, email, role, password_hash FROM users")->fetchAll(PDO::FETCH_ASSOC);
check(count($u) === 1 && $u[0]['email'] === 'jefa@agencia1.cl' && $u[0]['role'] === 'admin', 'usuario 0 creado como admin');
$tok = $pdo->query("SELECT token_hash, expires_at FROM password_resets")->fetch(PDO::FETCH_ASSOC);
check($tok && hash('sha256', substr($m[1] ?? '', -64)) === $tok['token_hash'] && strtotime($tok['expires_at']) > time() + 6 * 86400, 'el enlace vale 7 días y solo se guarda su hash');
$reg = json_decode(file_get_contents("$home/instancias.json"), true)['instancias']['agencia1'] ?? [];
check(($reg['canal'] ?? '') === 'canary' && ($reg['version'] ?? '') === $ver1 && ($reg['estado'] ?? '') === 'activa', 'queda en el registro');
[$c, $o] = prisma($home, ['enlace', 'agencia1']);
preg_match('#token=([0-9a-f]{64})#', $o, $m2);
$hashes = $pdo->query("SELECT token_hash FROM password_resets")->fetchAll(PDO::FETCH_COLUMN);
check($c === 0 && isset($m2[1]) && $hashes === [hash('sha256', $m2[1])], 'prisma enlace genera un enlace nuevo e invalida el anterior');
[$c, $o] = prisma($home, ['enlace', 'agencia1', '--correo=nadie@x.cl']);
check($c === 1 && str_contains($o, 'No hay un usuario'), 'prisma enlace avisa si el correo no existe');
[$c, $o] = prisma($home, ['crear', 'agencia1', '--host=a1.test', '--docroot=' . $tmp . '/pubX', '--correo=x@y.cl', '--canal=canary']);
check($c === 1 && str_contains($o, 'Ya existe'), 'no crea dos veces la misma agencia');

// --- el plugin enlazado arranca de verdad (servidor de PHP) ---
$puerto = 8099;
$srv = proc_open([PHP_BINARY, '-S', "127.0.0.1:$puerto", '-t', "$tmp/pub1", "$tmp/pub1/index.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', "$tmp/srv.log", 'w']], $sp);
usleep(800000);
$http = function (string $ruta) use ($puerto): array {
    $ctx = stream_context_create(['http' => ['timeout' => 20, 'ignore_errors' => true]]);
    $b = @file_get_contents("http://127.0.0.1:$puerto$ruta", false, $ctx);
    preg_match('#HTTP/\S+\s+(\d{3})#', (string) ($http_response_header[0] ?? ''), $mm);
    return [(int) ($mm[1] ?? 0), (string) $b];
};
[$cod] = $http('/admin/login');
check($cod === 200, "el admin de la agencia abre (HTTP $cod)");
[$cod, $body] = $http('/portal');
check($cod > 0 && $cod < 500, "el portal responde (HTTP $cod)");
$pdo2 = new PDO('sqlite:' . $tmp . '/db/agencia1.sqlite');
$tablas = $pdo2->query("SELECT name FROM sqlite_master WHERE name = 'portal_clientes'")->fetchColumn();
check($tablas === 'portal_clientes', 'las migraciones del plugin corrieron en la base de ESA agencia');
check(!is_dir("$home/releases/portal-$ver1/storage") && !glob("$home/releases/*/.ia_secret"), 'no se escribió nada de datos dentro de releases/');
proc_terminate($srv);

// --- actualizar: versión 2 ---
$p2 = $tmp . '/p2';
$sh('mkdir ' . escapeshellarg($p2) . ' && cp -a ' . escapeshellarg("$repo/portal") . ' ' . escapeshellarg("$p2/portal"));
$j = json_decode(file_get_contents("$p2/portal/plugin.json"), true);
$j['version'] = '9.9.9-prueba';
file_put_contents("$p2/portal/plugin.json", json_encode($j));
$zip2 = $tmp . '/portal-2.zip';
$sh('cd ' . escapeshellarg($p2) . ' && zip -qr ' . escapeshellarg($zip2) . ' portal');
[$c] = prisma($home, ['version', 'agregar', $zip2]);
[$c, $o] = prisma($home, ['actualizar', '--canal=canary', '--a=portal-9.9.9-prueba', '--sin-http']);
$reg = json_decode(file_get_contents("$home/instancias.json"), true)['instancias']['agencia1'];
check($c === 0 && $reg['version'] === '9.9.9-prueba' && str_contains(file_get_contents("$td/plugins/portal/plugin.json"), '9.9.9-prueba'), 'actualizar mueve a todas las instancias del canal');
[$c, $o] = prisma($home, ['actualizar', '--canal=canary', '--a=portal-' . $ver1, '--sin-http']);
$reg = json_decode(file_get_contents("$home/instancias.json"), true)['instancias']['agencia1'];
check($c === 0 && $reg['version'] === $ver1, 'volver atrás es apuntar a la versión anterior');
[$c, $o] = prisma($home, ['estado']);
check($c === 0 && str_contains($o, 'agencia1') && str_contains($o, 'canary'), 'estado lista las instancias');

// --- fallo a medias: se deshace ---
mkdir("$tmp/pub3", 0755, true);
$secreto('agencia3', '/proc/no-se-puede/db.sqlite');
[$c, $o] = prisma($home, ['crear', 'agencia3', '--host=a3.test', '--docroot=' . $tmp . '/pub3', '--correo=x@y.cl', '--canal=canary', '--sin-http']);
check($c === 1 && !is_dir("$home/instancias/agencia3") && !is_file("$tmp/pub3/index.php") && !isset(json_decode(file_get_contents("$home/instancias.json"), true)['instancias']['agencia3']), 'si falla a medias deja todo como estaba');

// --- plantilla de secretos ---
[$c, $o] = prisma($home, ['secreto', 'agencia4']);
$f = "$home/secretos/instancias/agencia4.json";
check($c === 0 && is_file($f) && (fileperms($f) & 0077) === 0, 'prisma secreto crea la plantilla con permisos 600');

$sh('rm -rf ' . escapeshellarg($tmp));
echo "\n\n$ok comprobaciones OK, " . count($fallas) . " fallas.\n";
foreach ($fallas as $f) {
    echo "  ✗ $f\n";
}
exit($fallas === [] ? 0 : 1);

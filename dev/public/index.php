<?php
declare(strict_types=1);

// Servidor de desarrollo:  php -S localhost:8080 -t dev/public dev/public/index.php

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (str_starts_with($uri, '/plugins/portal/assets/')) {
    $f = realpath(dirname(__DIR__, 2) . '/portal/assets/' . substr($uri, strlen('/plugins/portal/assets/')));
    $base = realpath(dirname(__DIR__, 2) . '/portal/assets');
    if ($f && $base && str_starts_with($f, $base) && is_file($f)) {
        $tipos = ['css' => 'text/css', 'js' => 'text/javascript', 'mjs' => 'text/javascript', 'png' => 'image/png', 'svg' => 'image/svg+xml', 'woff2' => 'font/woff2', 'json' => 'application/json'];
        header('Content-Type: ' . ($tipos[pathinfo($f, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
        readfile($f);
        return true;
    }
}

// Archivos del tema Prisma (theme/prisma/assets) en /themes/prisma/assets/
if (str_starts_with($uri, '/themes/prisma/assets/')) {
    $base = realpath(dirname(__DIR__, 2) . '/theme/prisma/assets');
    $f = realpath(dirname(__DIR__, 2) . '/theme/prisma/assets/' . substr($uri, strlen('/themes/prisma/assets/')));
    if ($f && $base && str_starts_with($f, $base) && is_file($f)) {
        $tipos = ['css' => 'text/css', 'js' => 'text/javascript', 'jpg' => 'image/jpeg', 'png' => 'image/png', 'svg' => 'image/svg+xml', 'webp' => 'image/webp', 'woff2' => 'font/woff2'];
        header('Content-Type: ' . ($tipos[pathinfo($f, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
        readfile($f);
        return true;
    }
}

require dirname(__DIR__) . '/bootstrap.php';

$pdo = portal_dev_pdo();
$ctx = portal_dev_contexto($pdo);
$ctxInv = portal_dev_invitaciones($pdo);
Flight::route('GET /', fn() => Flight::redirect('/equipo'));
// Atajos sólo de desarrollo: entrar sin código.
Flight::route('GET /dev/cliente', function () use ($ctx) {
    typedock_session_start();
    $c = (new TypeDock\Plugin\Portal\ContactoService($ctx->db()->pdo()))->findByEmail((string) ($_GET['email'] ?? ''));
    $_SESSION['portal_contacto_id'] = $c['id'] ?? null;
    Flight::redirect('/portal');
});
Flight::route('GET /dev/equipo', function () use ($ctx) {
    typedock_session_start();
    $u = (new TypeDock\Plugin\Portal\EquipoService($ctx->db()->pdo()))->findByEmail((string) ($_GET['email'] ?? ''));
    $_SESSION['portal_equipo_id'] = $u['id'] ?? null;
    Flight::redirect('/equipo');
});
// Landing Prisma (tema): la plantilla se dibuja con Latte, igual que en TypeDock.
Flight::route('GET /landing', function () use ($ctx) {
    echo $ctx->latte()->renderToString(dirname(__DIR__, 2) . '/theme/prisma/layouts/home.latte', []);
});
Flight::route('GET /admin', fn() => Flight::redirect('/admin/portal'));
// Desarrollo: deja el error completo en storage/errores.log.
Flight::map('error', function (Throwable $e) {
    file_put_contents(dirname(__DIR__) . '/storage/errores.log', date('c') . ' ' . $e . "\n\n", FILE_APPEND);
    http_response_code(500);
    echo '<h1>500 Internal Server Error</h1><pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
});
try {
    Flight::start();
} catch (TypeDock\Core\RedirectException) {
    // redirect() ya dejó la cabecera Location
}

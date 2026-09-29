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

require dirname(__DIR__) . '/bootstrap.php';

$ctx = portal_dev_contexto(portal_dev_pdo());
Flight::route('GET /', fn() => Flight::redirect('/equipo'));
// Atajos sólo de desarrollo: entrar sin código.
Flight::route('GET /dev/cliente', function () use ($ctx) {
    typedock_session_start();
    $c = (new TypeDock\Plugin\Portal\ContactoService($ctx->db()->pdo()))->findByEmail((string) ($_GET['email'] ?? ''));
    $_SESSION['portal_contacto_id'] = $c['id'] ?? null;
    Flight::redirect('/portal');
});
Flight::route('GET /admin', fn() => Flight::redirect('/admin/portal'));
try {
    Flight::start();
} catch (TypeDock\Core\RedirectException) {
    // redirect() ya dejó la cabecera Location
}

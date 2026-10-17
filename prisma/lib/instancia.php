<?php
declare(strict_types=1);

/**
 * Se ejecuta DENTRO de una instancia de TypeDock (con su vendor/), como proceso aparte.
 * Recibe un JSON por stdin (nunca por argumentos: así las claves no aparecen en `ps`) y
 * responde con una última línea JSON {"ok": true, ...} o {"ok": false, "error": "..."}.
 *
 *   php instancia.php instalar   {root, public, config: {...}, admin: {email, name}}
 *   php instancia.php assets     {root, public}
 *   php instancia.php config     {root, public, set: {CLAVE: valor}}  (cambia valores de config.php)
 *   php instancia.php acceso     {root, public, email}               (código de ingreso al panel /equipo)
 *   php instancia.php clave      {root, public, email}               (clave temporal nueva para un usuario)
 */

/** Clave temporal legible (sin 0/O, 1/l/I). */
function clave_temporal(int $largo = 20): string
{
    $a = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $c = '';
    for ($i = 0; $i < $largo; $i++) {
        $c .= $a[random_int(0, strlen($a) - 1)];
    }
    return $c;
}

function responder(array $d, int $code = 0): never
{
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit($code);
}

$accion = $argv[1] ?? '';
$in = json_decode((string) stream_get_contents(STDIN), true);
if (!is_array($in) || !is_dir((string) ($in['root'] ?? ''))) {
    responder(['ok' => false, 'error' => 'Entrada inválida.'], 2);
}

define('TYPEDOCK_ROOT', rtrim((string) $in['root'], '/'));
define('TYPEDOCK_PUBLIC_DIR', rtrim((string) ($in['public'] ?? TYPEDOCK_ROOT . '/public'), '/'));

try {
    if (!is_file(TYPEDOCK_ROOT . '/vendor/autoload.php')) {
        throw new RuntimeException('Falta vendor/ en ' . TYPEDOCK_ROOT . ': ¿se copió la distribución completa de TypeDock?');
    }
    require TYPEDOCK_ROOT . '/vendor/autoload.php';

    if ($accion === 'assets') {
        typedock_load_config(TYPEDOCK_ROOT);
        $n = count((new TypeDock\Core\AssetPublisher(TYPEDOCK_ROOT, TYPEDOCK_PUBLIC_DIR))->publishAll());
        responder(['ok' => true, 'publicados' => $n]);
    }

    if ($accion === 'config') {
        $actual = is_file(TYPEDOCK_ROOT . '/config.php') ? require TYPEDOCK_ROOT . '/config.php' : null;
        if (!is_array($actual)) {
            throw new RuntimeException('No encuentro config.php en esta instancia.');
        }
        $set = (array) ($in['set'] ?? []);
        foreach ($set as $k => $v) {
            if (preg_match('/^[A-Z][A-Z0-9_]{1,63}$/', (string) $k) !== 1) {
                throw new RuntimeException('Nombre de variable inválido: ' . $k);
            }
        }
        (new TypeDock\Install\Installer(TYPEDOCK_ROOT))->writeConfig(array_map('strval', array_merge($actual, $set)));
        responder(['ok' => true, 'cambiadas' => array_keys($set)]);
    }

    if ($accion === 'acceso') {
        typedock_load_config(TYPEDOCK_ROOT);
        $db = require TYPEDOCK_ROOT . '/config/database.php';
        $pdo = TypeDock\Core\Database\ConnectionFactory::create($db, TYPEDOCK_ROOT);
        // El plugin vive en plugins/portal (enlace al canal): se carga sin arrancar TypeDock.
        spl_autoload_register(static function (string $c): void {
            $pre = 'TypeDock\\Plugin\\Portal\\';
            if (strncmp($c, $pre, strlen($pre)) === 0) {
                $f = TYPEDOCK_ROOT . '/plugins/portal/src/' . str_replace('\\', '/', substr($c, strlen($pre))) . '.php';
                if (is_file($f)) {
                    require $f;
                }
            }
        });
        try {
            $u = (new TypeDock\Plugin\Portal\EquipoService($pdo))->findByEmail(strtolower((string) ($in['email'] ?? '')));
        } catch (Throwable $e) {
            throw new RuntimeException('Todavía no existe el panel de equipo en esta agencia: ábrela una vez en el navegador (/equipo/entrar) para que se cree.');
        }
        if ($u === null) {
            throw new RuntimeException('No hay una persona de la agencia con ese correo (¿ya abrieron /equipo/entrar una vez?).');
        }
        $codigo = (new TypeDock\Plugin\Portal\EquipoAuthService($pdo))->generarCodigo((string) $u['id']);
        responder(['ok' => true, 'codigo' => $codigo, 'login' => rtrim((string) ($_ENV['APP_URL'] ?? ''), '/') . '/equipo/verificar']);
    }

    if ($accion === 'clave') {
        typedock_load_config(TYPEDOCK_ROOT);
        $db = require TYPEDOCK_ROOT . '/config/database.php';
        $pdo = TypeDock\Core\Database\ConnectionFactory::create($db, TYPEDOCK_ROOT);
        $st = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $st->execute([(string) ($in['email'] ?? '')]);
        $userId = $st->fetchColumn();
        if ($userId === false) {
            throw new RuntimeException('No hay un usuario con ese correo en esta instancia.');
        }
        $clave = clave_temporal();
        $pdo->prepare('UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?')->execute([
            password_hash($clave, PASSWORD_BCRYPT), (new DateTimeImmutable())->format('Y-m-d H:i:s'), $userId,
        ]);
        $pdo->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$userId]);   // cierra sesiones abiertas
        responder(['ok' => true, 'clave' => $clave, 'login' => rtrim((string) ($_ENV['APP_URL'] ?? ''), '/') . '/admin/login']);
    }

    if ($accion === 'instalar') {
        $installer = new TypeDock\Install\Installer(TYPEDOCK_ROOT);
        if (TypeDock\Install\Installer::isInstalled(TYPEDOCK_ROOT)) {
            throw new RuntimeException('Esta instancia ya está instalada.');
        }
        $admin = (array) ($in['admin'] ?? []);
        $installer->writeConfig(array_map('strval', (array) ($in['config'] ?? [])));
        typedock_load_config(TYPEDOCK_ROOT);

        $res = $installer->runMigrations();
        if (!empty($res['errors'])) {
            $e = $res['errors'][0];
            throw new RuntimeException('Migración ' . $e['name'] . ': ' . $e['message']);
        }

        $db = require TYPEDOCK_ROOT . '/config/database.php';
        // Clave temporal: se muestra una sola vez y la persona la cambia al entrar (Usuarios → su usuario → Contraseña).
        $clave = clave_temporal();
        $userId = $installer->createAdmin($db, (string) ($admin['email'] ?? ''), (string) ($admin['name'] ?? ''), $clave);
        $installer->seedSiteOptions($db, ['name' => (string) ($_ENV['APP_NAME'] ?? 'TypeDock')]);
        $installer->activateTheme($db, 'default');
        $installer->lock(defined('TYPEDOCK_VERSION') ? TYPEDOCK_VERSION : 'desconocida');
        (new TypeDock\Core\AssetPublisher(TYPEDOCK_ROOT, TYPEDOCK_PUBLIC_DIR))->publishAll();

        responder([
            'ok' => true, 'user_id' => $userId, 'nucleo' => defined('TYPEDOCK_VERSION') ? TYPEDOCK_VERSION : '',
            'clave' => $clave, 'login' => rtrim((string) ($_ENV['APP_URL'] ?? ''), '/') . '/admin/login',
        ]);
    }

    throw new RuntimeException('Acción desconocida: ' . $accion);
} catch (Throwable $e) {
    responder(['ok' => false, 'error' => $e->getMessage()], 1);
}

<?php
declare(strict_types=1);

namespace Prisma;

final class Cli
{
    private const AYUDA = <<<'TXT'
prisma — administra las agencias beta de Prisma

  prisma estado                                  instancias, canales y versiones
  prisma secreto <slug>                          crea la plantilla secretos/instancias/<slug>.json (permisos 600)
  prisma crear <slug> --host=… --docroot=… --correo=<de la dueña> [--nombre=…] [--canal=estable|canary]
                                                 [--admin-correo=soporte@usaprisma.com]
                                                 [--simular] [--sin-http] [--reemplazar-docroot] [--zona=America/Santiago]
  prisma config <slug> CLAVE=valor [CLAVE=valor…] cambia valores del config.php de una agencia (ej.: PORTAL_RAIZ=login)
  prisma clave <slug> [--correo=…]               clave temporal nueva del admin de respaldo (se muestra una vez)
  prisma acceso <slug> [--correo=…]              código de ingreso de emergencia al panel /equipo (vale 10 min)
  prisma cola [<slug>]                           envía lo que espera en la cola de cada agencia (para el cron cada 10 min)
  prisma version agregar <zip>                   deja una versión del plugin en releases/
  prisma actualizar --canal=… --a=<release>      mueve el canal a esa versión (volver atrás = apuntar a la anterior)
                                                 [--simular] [--sin-http]

Carpeta de trabajo: $PRISMA_HOME (por defecto ~/prisma).
TXT;

    /** @param list<string> $argv */
    public static function correr(array $argv): int
    {
        array_shift($argv);
        [$pos, $op] = self::parsear($argv);
        $casa = Casa::desdeEntorno();
        $dec = function (string $t): void {
            fwrite(STDOUT, $t . "\n");
        };
        $cmd = $pos[0] ?? 'ayuda';

        switch ($cmd) {
            case 'crear':
                if (!isset($pos[1])) {
                    $dec('✗ Falta el nombre corto de la agencia: prisma crear <slug> …');
                    return 2;
                }
                return (new Crear($casa, $dec))->ejecutar($pos[1], [
                    'host' => $op['host'] ?? '', 'docroot' => $op['docroot'] ?? '', 'correo' => $op['correo'] ?? '',
                    'nombre' => $op['nombre'] ?? '', 'canal' => $op['canal'] ?? 'estable', 'zona' => $op['zona'] ?? 'America/Santiago',
                    'url' => $op['url'] ?? null, 'simular' => isset($op['simular']), 'sin_http' => isset($op['sin-http']),
                    'reemplazar' => isset($op['reemplazar-docroot']), 'admin_correo' => $op['admin-correo'] ?? '',
                ]);
            case 'config':
                return self::config($casa, array_slice($pos, 1), $dec);
            case 'acceso':
                return self::acceso($casa, $pos[1] ?? '', $op, $dec);
            case 'cola':
                return self::cola($casa, $pos[1] ?? '', $dec);
            case 'clave':
                return self::clave($casa, $pos[1] ?? '', $op, $dec);
            case 'secreto':
                return self::secreto($casa, $pos[1] ?? '', $dec);
            case 'version':
                if (($pos[1] ?? '') === 'agregar' && isset($pos[2])) {
                    return (new Versiones($casa, $dec))->agregar($pos[2], isset($op['simular']));
                }
                $dec('✗ Uso: prisma version agregar <zip>');
                return 2;
            case 'actualizar':
                return (new Versiones($casa, $dec))->actualizar((string) ($op['canal'] ?? ''), (string) ($op['a'] ?? ''), isset($op['simular']), isset($op['sin-http']));
            case 'estado':
                return self::estado($casa, $dec);
            default:
                $dec(self::AYUDA);
                return $cmd === 'ayuda' || $cmd === '--help' ? 0 : 2;
        }
    }

    /** @param list<string> $argv @return array{0: list<string>, 1: array<string, string|true>} */
    private static function parsear(array $argv): array
    {
        $pos = [];
        $op = [];
        foreach ($argv as $a) {
            if (str_starts_with($a, '--')) {
                $kv = explode('=', substr($a, 2), 2);
                $op[$kv[0]] = $kv[1] ?? true;
            } else {
                $pos[] = $a;
            }
        }
        return [$pos, $op];
    }

    /** @param list<string> $args slug y pares CLAVE=valor */
    private static function config(Casa $casa, array $args, \Closure $dec): int
    {
        $slug = array_shift($args) ?? '';
        $i = $casa->instancias()[$slug] ?? null;
        if ($i === null || $args === []) {
            $dec('✗ Uso: prisma config <slug> CLAVE=valor [CLAVE=valor…]   (la instancia debe existir)');
            return 2;
        }
        $set = [];
        foreach ($args as $a) {
            $kv = explode('=', $a, 2);
            if (count($kv) !== 2) {
                $dec("✗ «{$a}» no es CLAVE=valor");
                return 2;
            }
            $set[$kv[0]] = $kv[1];
        }
        try {
            $r = Proc::instancia('config', ['root' => $i['typedock'], 'public' => $i['docroot'], 'set' => $set]);
        } catch (\Throwable $e) {
            $dec('✗ ' . $e->getMessage());
            return 1;
        }
        $dec('✓ config.php de «' . $slug . '» actualizado: ' . implode(', ', $r['cambiadas']));
        return 0;
    }

    /** @param array<string, string|true> $op */
    private static function clave(Casa $casa, string $slug, array $op, \Closure $dec): int
    {
        $i = $casa->instancias()[$slug] ?? null;
        if ($i === null) {
            $dec("✗ No existe la instancia «{$slug}». Mira prisma estado.");
            return 1;
        }
        $correo = is_string($op['correo'] ?? null) ? $op['correo'] : (string) ($i['admin_correo'] ?? $i['correo'] ?? '');
        try {
            $r = Proc::instancia('clave', ['root' => $i['typedock'], 'public' => $i['docroot'], 'email' => $correo]);
        } catch (\Throwable $e) {
            $dec('✗ ' . $e->getMessage());
            return 1;
        }
        $dec("Clave temporal nueva para {$correo} (las sesiones abiertas se cerraron; la anterior ya no sirve):");
        $dec($r['clave']);
        $dec("Entra en {$r['login']} y cámbiala en Usuarios → tu usuario → Contraseña. No la pegues en chats.");
        return 0;
    }

    /** @param array<string, string|true> $op */
    private static function acceso(Casa $casa, string $slug, array $op, \Closure $dec): int
    {
        $i = $casa->instancias()[$slug] ?? null;
        if ($i === null) {
            $dec("✗ No existe la instancia «{$slug}». Mira prisma estado.");
            return 1;
        }
        $correo = is_string($op['correo'] ?? null) ? $op['correo'] : (string) ($i['correo'] ?? '');
        try {
            $r = Proc::instancia('acceso', ['root' => $i['typedock'], 'public' => $i['docroot'], 'email' => $correo]);
        } catch (\Throwable $e) {
            $dec('✗ ' . $e->getMessage());
            return 1;
        }
        $dec("Código de ingreso para {$correo} (vale 10 minutos; invalida el anterior):");
        $dec($r['codigo']);
        $dec("Que lo escriba en {$r['login']}. Si pide el correo de nuevo, se genera otro código: hazlo en ese orden (primero ella, después este comando).");
        return 0;
    }

    /** Recorre la tarea programada de cada agencia: envía la cola de correos y los avisos. Pensado para un cron cada 10 minutos. */
    private static function cola(Casa $casa, string $slug, \Closure $dec): int
    {
        $malas = 0;
        foreach ($casa->instancias() as $s => $i) {
            if (($slug !== '' && $s !== $slug) || ($i['estado'] ?? '') !== 'activa') {
                continue;
            }
            $cfgF = (string) ($i['typedock'] ?? '') . '/config.php';
            $cfg = is_file($cfgF) ? (require $cfgF) : [];
            $token = is_array($cfg) ? (string) ($cfg['PORTAL_CRON_TOKEN'] ?? '') : '';
            $url = rtrim((string) ($i['url'] ?? ''), '/');
            if ($token === '' || $url === '') {
                $dec("  ! {$s}: no tiene clave de cron en su config.php");
                $malas++;
                continue;
            }
            $ctx = stream_context_create(['http' => ['timeout' => 40, 'ignore_errors' => true]]);
            $r = @file_get_contents($url . '/portal/cron/correos?k=' . rawurlencode($token), false, $ctx);
            $codigo = 0;
            foreach ((array) ($http_response_header ?? []) as $h) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                    $codigo = (int) $m[1];
                }
            }
            $ok = $r !== false && $codigo === 200;
            $dec(($ok ? '  ✓ ' : '  ✗ ') . $s . ': ' . ($ok ? trim((string) $r) : 'HTTP ' . ($codigo ?: 'sin respuesta')));
            $malas += $ok ? 0 : 1;
        }
        return $malas > 0 ? 1 : 0;
    }

    private static function secreto(Casa $casa, string $slug, \Closure $dec): int
    {
        if (!Casa::esSlug($slug)) {
            $dec('✗ Uso: prisma secreto <slug>');
            return 2;
        }
        $dir = $casa->ruta('secretos', 'instancias');
        $f = $dir . '/' . $slug . '.json';
        if (file_exists($f)) {
            $dec("✗ Ya existe {$f}");
            return 1;
        }
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $plantilla = json_encode(['db' => ['driver' => 'mysql', 'host' => 'localhost', 'port' => 3306, 'database' => '', 'username' => '', 'password' => '']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        $h = fopen($f, 'x');
        chmod($f, 0600);
        fwrite($h, $plantilla);
        fclose($h);
        $dec("✓ Creada {$f} (permisos 600). Ábrela con un editor del servidor (nano) y completa la base de datos de hPanel. No la pegues en chats ni la subas al repo.");
        return 0;
    }

    private static function estado(Casa $casa, \Closure $dec): int
    {
        $v = new Versiones($casa, $dec);
        $dec('Canales:');
        foreach (Casa::CANALES as $c) {
            $dec(sprintf('  %-8s %s', $c, $v->actual($c) ?? '(sin versión)'));
        }
        $dec('Versiones disponibles: ' . (implode(', ', $v->lista()) ?: '(ninguna)'));
        $dec('');
        $inst = $casa->instancias();
        if ($inst === []) {
            $dec('Sin instancias todavía.');
            return 0;
        }
        $dec(sprintf('%-14s %-8s %-14s %-9s %s', 'INSTANCIA', 'CANAL', 'VERSIÓN', 'ESTADO', 'URL'));
        foreach ($inst as $slug => $i) {
            $real = Casa::versionDe((string) ($i['typedock'] ?? '') . '/plugins/portal');
            $marca = $real !== null && $real !== ($i['version'] ?? null) ? ' (en disco: ' . $real . ')' : '';
            $dec(sprintf('%-14s %-8s %-14s %-9s %s%s', $slug, $i['canal'] ?? '-', $i['version'] ?? '-', $i['estado'] ?? '-', $i['url'] ?? '-', $marca));
        }
        return 0;
    }
}

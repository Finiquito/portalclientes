<?php
declare(strict_types=1);

namespace Prisma;

/**
 * La «casa» de Prisma en el hosting (por defecto ~/prisma):
 *
 *   distribucion/typedock-shared/{public_html,typedock}   TypeDock limpio (el zip de hosting compartido, descomprimido)
 *   releases/<nombre>/                                    una carpeta por versión del plugin
 *   canales/{canary,estable} → ../releases/<nombre>       qué versión usa cada canal
 *   instancias/<slug>/typedock/                           el TypeDock de cada agencia
 *   secretos/compartido/{correo.json,ia.json}             lo que ve el plugin de TODAS las agencias (PORTAL_SECRETOS_DIR)
 *   secretos/instancias/<slug>.json                       claves de BD de cada agencia (solo las lee esta herramienta)
 *   instancias.json                                       registro
 */
final class Casa
{
    public const CANALES = ['canary', 'estable'];

    public function __construct(public readonly string $raiz) {}

    public static function desdeEntorno(): self
    {
        $r = getenv('PRISMA_HOME');
        if (!is_string($r) || $r === '') {
            $home = getenv('HOME');
            $r = (is_string($home) && $home !== '' ? $home : sys_get_temp_dir()) . '/prisma';
        }
        return new self(rtrim($r, '/'));
    }

    public function ruta(string ...$partes): string
    {
        return $this->raiz . ($partes === [] ? '' : '/' . implode('/', $partes));
    }

    public function distribucion(): string
    {
        return $this->ruta('distribucion', 'typedock-shared');
    }

    public function canal(string $canal): string
    {
        return $this->ruta('canales', $canal);
    }

    /** @return array<string, array<string, mixed>> */
    public function instancias(): array
    {
        $f = $this->ruta('instancias.json');
        $j = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
        return is_array($j['instancias'] ?? null) ? $j['instancias'] : [];
    }

    /** @param callable(array<string, array<string, mixed>>): array<string, array<string, mixed>> $cambio */
    public function actualizarRegistro(callable $cambio): void
    {
        if (!is_dir($this->raiz)) {
            mkdir($this->raiz, 0750, true);
        }
        $f = $this->ruta('instancias.json');
        $h = fopen($f, 'c+');
        if ($h === false) {
            throw new \RuntimeException('No pude abrir ' . $f);
        }
        flock($h, LOCK_EX);
        $j = json_decode((string) stream_get_contents($h), true);
        $nuevo = $cambio(is_array($j['instancias'] ?? null) ? $j['instancias'] : []);
        ftruncate($h, 0);
        rewind($h);
        fwrite($h, json_encode(['instancias' => $nuevo], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        fflush($h);
        flock($h, LOCK_UN);
        fclose($h);
        @chmod($f, 0640);
    }

    public function carpetaCompartida(): string
    {
        return $this->ruta('secretos', 'compartido');
    }

    /** @return array<string, mixed>|null */
    public function leerSecreto(string $ruta): ?array
    {
        $f = $this->ruta('secretos', $ruta . '.json');
        if (!is_file($f)) {
            return null;
        }
        $j = json_decode((string) file_get_contents($f), true);
        return is_array($j) ? $j : null;
    }

    /** ¿El archivo de secretos es legible por otros usuarios del servidor? */
    public function secretoExpuesto(string $ruta): bool
    {
        $f = $this->ruta('secretos', $ruta . '.json');
        return is_file($f) && ((fileperms($f) & 0077) !== 0);
    }

    public static function esSlug(string $s): bool
    {
        return preg_match('/^[a-z][a-z0-9-]{1,30}$/', $s) === 1;
    }

    /** Versión (plugin.json) de una carpeta de plugin o de un enlace de canal. */
    public static function versionDe(string $dir): ?string
    {
        $f = $dir . '/plugin.json';
        $j = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
        return is_array($j) ? (string) ($j['version'] ?? '') ?: null : null;
    }

    /** Copia recursiva conservando permisos y enlaces (cp -a si existe: mucho más rápido con vendor/). */
    public static function copiar(string $origen, string $destino): void
    {
        if (!is_dir($destino)) {
            mkdir($destino, 0755, true);
        }
        [$code, , $err] = Proc::correr(['cp', '-a', rtrim($origen, '/') . '/.', rtrim($destino, '/') . '/']);
        if ($code !== 0) {
            throw new \RuntimeException('No pude copiar ' . $origen . ': ' . trim($err));
        }
    }

    /**
     * Crea un enlace simbólico con `ln -s`. No usa symlink() de PHP: Hostinger lo trae en disable_functions
     * (pero el comando ln sí funciona).
     */
    public static function enlazar(string $destino, string $enlace): void
    {
        [$code, , $err] = Proc::correr(['ln', '-s', '--', $destino, $enlace]);
        if ($code !== 0 || !is_link($enlace)) {
            throw new \RuntimeException('No pude crear el enlace simbólico ' . $enlace . ': ' . trim($err));
        }
    }

    /** Funciones de PHP que la herramienta necesita y el hosting podría tener deshabilitadas. @return list<string> */
    public static function funcionesFaltantes(): array
    {
        $off = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        return array_values(array_filter(['proc_open', 'rename', 'file_put_contents', 'readlink'], fn($f) => !function_exists($f) || in_array($f, $off, true)));
    }

    public static function borrar(string $ruta): void
    {
        if (is_link($ruta) || is_file($ruta)) {
            @unlink($ruta);
            return;
        }
        if (is_dir($ruta)) {
            Proc::correr(['rm', '-rf', $ruta]);
        }
    }
}

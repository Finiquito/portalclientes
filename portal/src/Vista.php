<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * Dibuja las pantallas del portal del cliente y del panel del equipo con un motor Latte propio,
 * que lleva la traducción de plantillas (IdiomaExtension). Así no se toca el motor de TypeDock:
 * el admin y el resto del sitio siguen exactamente como estaban.
 */
final class Vista
{
    private static ?\Latte\Engine $motor = null;

    public static function motor(\PDO $pdo): \Latte\Engine
    {
        if (self::$motor === null) {
            $m = new \Latte\Engine();
            IdiomaExtension::instalar($m);
            $dir = (new ArchivoService($pdo))->directorioBase() . '/.latte';
            if (!is_dir($dir)) {
                @mkdir($dir, 0750, true);
            }
            $m->setTempDirectory(is_dir($dir) && is_writable($dir) ? $dir : sys_get_temp_dir());
            self::$motor = $m;
        }
        return self::$motor;
    }

    /** @param array<string, mixed> $datos plantilla: ruta dentro del plugin, p. ej. 'templates/public/inicio.latte' */
    public static function mostrar(\PDO $pdo, string $plantilla, array $datos): void
    {
        echo self::renderizar($pdo, $plantilla, $datos);
    }

    /** @param array<string, mixed> $datos */
    public static function renderizar(\PDO $pdo, string $plantilla, array $datos): string
    {
        return self::motor($pdo)->renderToString(dirname(__DIR__) . '/' . ltrim($plantilla, '/'), $datos);
    }
}

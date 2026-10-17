<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * Que cualquier color de marca se lea bien en el portal (WCAG AA: 4,5:1 para texto).
 *
 *  - texto(): blanco o casi negro, el que más contraste dé sobre el color (botones, iniciales, etiquetas).
 *  - tinta(): cuánto del color de marca lleva el «texto de acento» (--brand-ink, que se mezcla con negro en modo claro
 *    y con blanco en oscuro). Con un color claro como el ámbar, 82 % de ámbar sobre negro no basta: se baja el
 *    porcentaje hasta que el texto de acento cumpla sobre el fondo suave y sobre la superficie.
 *
 * Las mezclas se hacen en OKLab, igual que `color-mix(in oklab, …)` del CSS (ver portal.src.css).
 * La lámina dev/maquetas/colores.html muestra el antes y el después de los 12 colores de la paleta del asistente.
 */
final class ColorMarca
{
    public const TEXTO_OSCURO = '#12131a';
    public const MINIMO = 4.5;

    private const FONDO = ['claro' => ['soft' => 11, 'base' => '#ffffff', 'superficie' => '#ffffff', 'tinta' => 82, 'fin' => '#000000'],
                           'oscuro' => ['soft' => 26, 'base' => '#10111b', 'superficie' => '#181a28', 'tinta' => 52, 'fin' => '#ffffff']];

    /** @var array<string, int> */
    private static array $cache = [];

    public static function texto(string $hex): string
    {
        $hex = AjustesService::colorValido($hex);
        return self::contraste('#ffffff', $hex) >= self::contraste(self::TEXTO_OSCURO, $hex) ? '#ffffff' : self::TEXTO_OSCURO;
    }

    /** Porcentaje de color de marca en el texto de acento, para el tema claro u oscuro. */
    public static function tinta(string $hex, bool $oscuro): int
    {
        $hex = AjustesService::colorValido($hex);
        $t = $oscuro ? 'oscuro' : 'claro';
        $k = $t . $hex;
        if (isset(self::$cache[$k])) {
            return self::$cache[$k];
        }
        $f = self::FONDO[$t];
        $soft = self::mezcla($hex, $f['soft'], $f['base']);
        $elegido = $f['tinta'];
        for ($p = $f['tinta']; $p >= 10; $p -= 2) {
            $ink = self::mezcla($hex, $p, $f['fin']);
            if (self::contraste($ink, $soft) >= self::MINIMO && self::contraste($ink, $f['superficie']) >= self::MINIMO) {
                $elegido = $p;
                break;
            }
            $elegido = $p;
        }
        return self::$cache[$k] = $elegido;
    }

    public static function contraste(string $a, string $b): float
    {
        $x = self::luminancia($a);
        $y = self::luminancia($b);
        if ($x < $y) {
            [$x, $y] = [$y, $x];
        }
        return ($x + 0.05) / ($y + 0.05);
    }

    /** color-mix(in oklab, $a $pct%, $b) @return string #rrggbb */
    public static function mezcla(string $a, int $pct, string $b): string
    {
        $A = self::aOklab(self::rgb($a));
        $B = self::aOklab(self::rgb($b));
        $p = $pct / 100;
        return self::aHex(self::deOklab([$A[0] * $p + $B[0] * (1 - $p), $A[1] * $p + $B[1] * (1 - $p), $A[2] * $p + $B[2] * (1 - $p)]));
    }

    private static function luminancia(string $hex): float
    {
        [$r, $g, $b] = array_map([self::class, 'lineal'], self::rgb($hex));
        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    /** @return array{0: float, 1: float, 2: float} */
    private static function rgb(string $hex): array
    {
        $h = ltrim($hex, '#');
        return [hexdec(substr($h, 0, 2)) / 255, hexdec(substr($h, 2, 2)) / 255, hexdec(substr($h, 4, 2)) / 255];
    }

    private static function lineal(float $c): float
    {
        return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    }

    private static function gamma(float $c): float
    {
        return $c <= 0.0031308 ? 12.92 * $c : 1.055 * ($c ** (1 / 2.4)) - 0.055;
    }

    /** @param array{0: float, 1: float, 2: float} $rgb @return array{0: float, 1: float, 2: float} */
    private static function aOklab(array $rgb): array
    {
        [$r, $g, $b] = array_map([self::class, 'lineal'], $rgb);
        $l = (0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b) ** (1 / 3);
        $m = (0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b) ** (1 / 3);
        $s = (0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b) ** (1 / 3);
        return [0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s,
                1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s,
                0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s];
    }

    /** @param array{0: float, 1: float, 2: float} $L @return array{0: float, 1: float, 2: float} */
    private static function deOklab(array $L): array
    {
        $l = ($L[0] + 0.3963377774 * $L[1] + 0.2158037573 * $L[2]) ** 3;
        $m = ($L[0] - 0.1055613458 * $L[1] - 0.0638541728 * $L[2]) ** 3;
        $s = ($L[0] - 0.0894841775 * $L[1] - 1.2914855480 * $L[2]) ** 3;
        $rgb = [4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s,
                -1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s,
                -0.0041960863 * $l - 0.7034186147 * $m + 1.7076147010 * $s];
        return array_map(fn(float $c) => self::gamma(max(0.0, min(1.0, $c))), $rgb);
    }

    /** @param array<int, float> $c */
    private static function aHex(array $c): string
    {
        return '#' . implode('', array_map(fn(float $x) => str_pad(dechex((int) max(0, min(255, round($x * 255)))), 2, '0', STR_PAD_LEFT), $c));
    }
}

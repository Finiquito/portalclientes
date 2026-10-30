<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * Colores de marca elegibles (agencia y cada cliente) y sus normas de contraste.
 *
 * Normas: el texto del botón se lee con al menos 4,5:1 y el anillo de foco con al menos 3:1,
 * en claro y en oscuro. Cada color trae su valor ajustado (si el original no llega a 4,5:1 con
 * texto blanco) y el color de texto que va encima. Lo que se guarda es el color elegido; el ajuste
 * se aplica al pintar, así que un cambio de normas no obliga a volver a elegir.
 */
final class Paleta
{
    /** Texto oscuro sobre colores claros (ámbar, azul claro, cian, turquesa). */
    public const TEXTO_OSCURO = '#10131f';
    public const BLANCO = '#ffffff';
    public const MIN_TEXTO = 4.5;

    /**
     * clave => [nombre, color elegido, color ajustado, texto sobre el botón]
     * @var array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public const COLORES = [
        'naranjo'     => ['Naranjo Prisma', '#e8552d', '#d54318', self::BLANCO],
        'azul'        => ['Azul',           '#2f5bea', '#2f5bea', self::BLANCO],
        'verde'       => ['Verde',          '#118a6b', '#038567', self::BLANCO],
        'violeta'     => ['Violeta',        '#8a4fd6', '#8a4fd6', self::BLANCO],
        'rosa'        => ['Rosa',           '#d63a7c', '#d4387b', self::BLANCO],
        'grafito'     => ['Grafito',        '#2b2f3a', '#2b2f3a', self::BLANCO],
        'ambar'       => ['Ámbar',          '#ffc107', '#ffc107', self::TEXTO_OSCURO],
        'azul_claro'  => ['Azul claro',     '#2196f3', '#2196f3', self::TEXTO_OSCURO],
        'gris_azulado' => ['Gris azulado',  '#607d8b', '#5d7a88', self::BLANCO],
        'cafe'        => ['Café',           '#795548', '#795548', self::BLANCO],
        'cian'        => ['Cian',           '#00bcd4', '#00bcd4', self::TEXTO_OSCURO],
        'turquesa'    => ['Verde turquesa', '#30dfc4', '#30dfc4', self::TEXTO_OSCURO],
    ];

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string}> color elegido => fila */
    private static function porHex(): array
    {
        $o = [];
        foreach (self::COLORES as $c) {
            $o[$c[1]] = $c;
        }
        return $o;
    }

    public static function esElegible(string $hex): bool
    {
        return isset(self::porHex()[strtolower($hex)]);
    }

    /**
     * El color que llega del formulario, o el que ya había si no es uno de la paleta.
     * Así un portal con un color antiguo no se pierde, pero no se pueden elegir colores fuera de la lista.
     */
    public static function elegir(string $enviado, string $actual): string
    {
        $enviado = strtolower(trim($enviado));
        if (self::esElegible($enviado)) {
            return $enviado;
        }
        return preg_match('/^#[0-9a-f]{6}$/i', $actual) === 1 ? strtolower($actual) : '';
    }

    /**
     * Lo que se pinta para un color de marca.
     * @return array{color: string, texto: string, tinta: string} color del botón, texto encima y tinta de acento sobre fondo claro
     */
    public static function marca(string $hex): array
    {
        $hex = AjustesService::colorValido($hex);
        $fila = self::porHex()[$hex] ?? null;
        if ($fila !== null) {
            [$color, $texto] = [$fila[2], $fila[3]];
        } else {
            [$color, $texto] = self::ajustar($hex);
        }
        return ['color' => $color, 'texto' => $texto, 'tinta' => self::tinta($color)];
    }

    /**
     * Para un color fuera de la lista: blanco si ya llega a 4,5:1; si no, se oscurece hasta un 12 %;
     * si aun así no llega y el texto oscuro sí, va texto oscuro; si nada llega, el de mayor contraste.
     * @return array{0: string, 1: string}
     */
    public static function ajustar(string $hex): array
    {
        if (self::contraste($hex, self::BLANCO) >= self::MIN_TEXTO) {
            return [$hex, self::BLANCO];
        }
        for ($p = 1; $p <= 12; $p++) {
            $c = self::mezclar($hex, '#000000', $p / 100);
            if (self::contraste($c, self::BLANCO) >= self::MIN_TEXTO) {
                return [$c, self::BLANCO];
            }
        }
        $cw = self::contraste($hex, self::BLANCO);
        $cd = self::contraste($hex, self::TEXTO_OSCURO);
        return [$hex, $cd >= $cw ? self::TEXTO_OSCURO : self::BLANCO];
    }

    /** Acento para texto y enlaces sobre fondo claro: el color oscurecido hasta llegar a 4,5:1 con blanco. */
    public static function tinta(string $hex): string
    {
        $c = self::mezclar($hex, '#000000', 0.18);
        for ($p = 18; $p <= 80 && self::contraste($c, self::BLANCO) < self::MIN_TEXTO; $p += 2) {
            $c = self::mezclar($hex, '#000000', $p / 100);
        }
        return $c;
    }

    public static function contraste(string $a, string $b): float
    {
        $la = self::luminancia($a);
        $lb = self::luminancia($b);
        [$l1, $l2] = $la >= $lb ? [$la, $lb] : [$lb, $la];
        return ($l1 + 0.05) / ($l2 + 0.05);
    }

    private static function luminancia(string $hex): float
    {
        $lin = static fn(int $v): float => ($v /= 255) <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        $h = ltrim($hex, '#');
        return 0.2126 * $lin((int) hexdec(substr($h, 0, 2))) + 0.7152 * $lin((int) hexdec(substr($h, 2, 2))) + 0.0722 * $lin((int) hexdec(substr($h, 4, 2)));
    }

    /** Mezcla $a con $b; $t es la parte de $b (0 a 1). */
    private static function mezclar(string $a, string $b, float $t): string
    {
        $a = ltrim($a, '#');
        $b = ltrim($b, '#');
        $o = '#';
        foreach ([0, 2, 4] as $i) {
            $o .= str_pad(dechex((int) round(hexdec(substr($a, $i, 2)) * (1 - $t) + hexdec(substr($b, $i, 2)) * $t)), 2, '0', STR_PAD_LEFT);
        }
        return $o;
    }
}

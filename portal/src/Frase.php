<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * Una frase que todavía no se traduce: guarda el texto en español y sus valores, y recién al pasarla
 * a texto toma el idioma que esté activo. Así un aviso armado por una persona del equipo en español
 * se escribe en inglés para el cliente que lo lee en inglés (Idioma::f()).
 */
final class Frase implements \Stringable
{
    /** @param array<int, string|int|float|\Stringable> $valores */
    public function __construct(private readonly string $texto, private readonly array $valores = [])
    {
    }

    public function __toString(): string
    {
        return Idioma::t($this->texto, ...array_map(static fn($v) => is_string($v) || is_int($v) || is_float($v) ? $v : (string) $v, $this->valores));
    }
}

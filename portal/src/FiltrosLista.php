<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * Filtros de una lista (tareas, contenidos, reuniones) leídos de la URL: sólo acepta
 * valores conocidos (o un id con forma de UUID para el proyecto) y arma los enlaces
 * de las «pastillas» conservando el resto de los filtros.
 */
final class FiltrosLista
{
    /**
     * @param array<string, string> $valores
     * @param array<string, string> $defecto
     */
    private function __construct(private readonly string $base, private readonly array $valores, private readonly array $defecto) {}

    /**
     * @param array<string, string> $defecto clave => valor por defecto
     * @param array<string, array<int, string>|string> $permitidos clave => valores válidos, o 'uuid'
     */
    public static function desdeGet(string $base, array $defecto, array $permitidos): self
    {
        $v = [];
        foreach ($defecto as $k => $def) {
            $x = trim((string) ($_GET[$k] ?? ''));
            $ok = match (true) {
                $x === ''                             => false,
                ($permitidos[$k] ?? null) === 'uuid'  => preg_match('/^[0-9a-f-]{36}$/i', $x) === 1,
                is_array($permitidos[$k] ?? null)     => in_array($x, $permitidos[$k], true),
                default                               => false,
            };
            $v[$k] = $ok ? $x : $def;
        }
        return new self($base, $v, $defecto);
    }

    public function get(string $k): string
    {
        return $this->valores[$k] ?? '';
    }

    public function es(string $k, string $v): bool
    {
        return $this->get($k) === $v;
    }

    /** @return array<string, string> */
    public function todos(): array
    {
        return $this->valores;
    }

    /** URL con estos cambios; los valores por defecto no se escriben. */
    public function url(array $cambios = []): string
    {
        $q = [];
        foreach (array_merge($this->valores, $cambios) as $k => $v) {
            if ($v !== '' && $v !== ($this->defecto[$k] ?? null)) {
                $q[$k] = $v;
            }
        }
        return $this->base . ($q !== [] ? '?' . http_build_query($q) : '');
    }

    /** URL actual (para volver después de una acción en lote). */
    public function actual(): string
    {
        return $this->url();
    }
}

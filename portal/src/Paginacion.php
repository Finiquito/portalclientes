<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * Paginación de listas: 20, 30 o 50 por página, página actual y orden (más nuevos o más
 * antiguos primero, o por una columna de la tabla). Lee ?p=, ?por= y ?orden= de la URL y
 * arma los enlaces conservando el resto de los filtros.
 *
 * Cuántos por página se recuerda por persona (ajuste «por_pagina»): lo guarda quien la crea
 * con recordar().
 */
final class Paginacion
{
    public const TAMANOS = [20, 30, 50];

    public readonly int $pagina;
    public readonly int $paginas;
    public readonly int $desde;
    public readonly int $hasta;

    /**
     * @param array<string, string> $ordenes clave => etiqueta (la primera es la por defecto)
     */
    private function __construct(
        public readonly int $total,
        public readonly int $porPagina,
        int $pagina,
        public readonly string $orden,
        public readonly array $ordenes,
        private readonly string $base,
    ) {
        $this->paginas = max(1, (int) ceil($total / $porPagina));
        $this->pagina  = min(max(1, $pagina), $this->paginas);
        $this->desde   = $total === 0 ? 0 : ($this->pagina - 1) * $porPagina + 1;
        $this->hasta   = min($total, $this->pagina * $porPagina);
    }

    /**
     * @param string   $base      ruta de la lista (sin query)
     * @param int      $total     filas en total (ya filtradas)
     * @param int|null $guardado  lo que eligió esta persona la última vez
     * @param array<string, string> $ordenes clave => etiqueta; la primera es la por defecto
     */
    public static function desdeGet(string $base, int $total, ?int $guardado = null, array $ordenes = ['recientes' => 'Más nuevos primero', 'antiguos' => 'Más antiguos primero']): self
    {
        $por = (int) ($_GET['por'] ?? 0);
        if (!in_array($por, self::TAMANOS, true)) {
            $por = in_array($guardado, self::TAMANOS, true) ? (int) $guardado : self::TAMANOS[0];
        }
        $orden = (string) ($_GET['orden'] ?? '');
        if (!isset($ordenes[$orden])) {
            $orden = (string) array_key_first($ordenes);
        }
        return new self($total, $por, (int) ($_GET['p'] ?? 1), $orden, $ordenes, $base);
    }

    /** Si la persona eligió otro tamaño en esta visita, lo devuelve para guardarlo. */
    public static function elegido(): ?int
    {
        $por = (int) ($_GET['por'] ?? 0);
        return in_array($por, self::TAMANOS, true) ? $por : null;
    }

    /**
     * Las filas de esta página.
     *
     * @template T
     * @param array<int, T> $filas
     * @return array<int, T>
     */
    public function cortar(array $filas): array
    {
        return array_slice(array_values($filas), $this->desde > 0 ? $this->desde - 1 : 0, $this->porPagina);
    }

    /** Para SQL: LIMIT y OFFSET. */
    public function limite(): string
    {
        return ' LIMIT ' . $this->porPagina . ' OFFSET ' . max(0, $this->desde - 1);
    }

    /** Ordena en PHP según la clave elegida: $criterios = clave => fn($a, $b): int (ascendente). */
    public function ordenar(array $filas, array $criterios): array
    {
        $f = $criterios[$this->orden] ?? null;
        if ($f !== null) {
            usort($filas, $f);
        }
        return $filas;
    }

    /** URL con estos cambios; conserva el resto de la query. La página vuelve a 1 si cambia otra cosa. */
    public function url(array $cambios = []): string
    {
        $q = $_GET;
        unset($q['p']);
        foreach ($cambios as $k => $v) {
            $q[$k] = $v;
        }
        if ((int) ($q['p'] ?? 1) <= 1) {
            unset($q['p']);
        }
        if ((string) ($q['orden'] ?? '') === (string) array_key_first($this->ordenes)) {
            unset($q['orden']);
        }
        $q = array_filter($q, fn($v) => $v !== '' && $v !== null && !is_array($v));
        return $this->base . ($q !== [] ? '?' . http_build_query($q) : '');
    }

    public function pag(int $n): string
    {
        return $this->url(['p' => $n, 'orden' => $this->orden]);
    }

    /** Orden de una columna: si ya está en «asc», el clic la pasa a «desc» y viceversa. */
    public function urlColumna(string $col): string
    {
        return $this->url(['orden' => $this->orden === $col . '_asc' ? $col . '_desc' : $col . '_asc']);
    }

    /** 'asc', 'desc' o '' si la tabla no está ordenada por esa columna. */
    public function sentido(string $col): string
    {
        return match ($this->orden) {
            $col . '_asc'  => 'asc',
            $col . '_desc' => 'desc',
            default        => '',
        };
    }

    /**
     * Números a mostrar: 1 … 4 5 [6] 7 8 … 20 (null = «…»).
     *
     * @return array<int, int|null>
     */
    public function numeros(): array
    {
        $n = $this->paginas;
        $p = $this->pagina;
        if ($n <= 7) {
            return range(1, $n);
        }
        $vec = array_unique(array_merge([1, $n], range(max(1, $p - 1), min($n, $p + 1))));
        sort($vec);
        $out = [];
        $prev = 0;
        foreach ($vec as $x) {
            if ($x - $prev > 1) {
                $out[] = null;
            }
            $out[] = $x;
            $prev = $x;
        }
        return $out;
    }

    /** Campos ocultos para el formulario del selector (mantienen los filtros). */
    public function ocultos(): array
    {
        $q = $_GET;
        unset($q['p'], $q['por'], $q['orden']);
        return array_filter($q, fn($v) => is_string($v) && $v !== '');
    }
}

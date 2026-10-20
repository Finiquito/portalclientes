<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * Números de los contactos ganados para los gráficos: contactos acumulados en el tiempo, embudo
 * (cuántos llegan a cada etapa) y cuánto tardan entre etapas. Sólo calcula: no toca la base.
 */
final class LeadStats
{
    private const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    /** Una campaña muestra su gráfico cuando lleva al menos tantos días recibiendo contactos. */
    public const DIAS_PARA_GRAFICO = 14;

    public static function etiqueta(\DateTimeInterface $d): string
    {
        return (int) $d->format('j') . ' ' . self::MESES[(int) $d->format('n') - 1];
    }

    /**
     * Contactos acumulados semana a semana (o día a día si el rango es corto), con los ganados y perdidos
     * acumulados por cuándo ocurrió el cambio de estado.
     *
     * @param array<int, array<string, mixed>> $leads filas con id, created_at
     * @param array<int, array<string, mixed>> $eventos de LeadLinea::deEstado()
     * @param string|null $desde Y-m-d; vacío = desde el primer contacto
     * @return array{etiquetas: array<int, string>, contactos: array<int, int>, ganados: array<int, int>, perdidos: array<int, int>, paso: string, total: int, dias: int}
     */
    public static function acumulado(array $leads, array $eventos, ?string $desde = null, ?\DateTimeImmutable $hoy = null): array
    {
        $hoy ??= new \DateTimeImmutable('today');
        $vacio = ['etiquetas' => [], 'contactos' => [], 'ganados' => [], 'perdidos' => [], 'paso' => 'dia', 'total' => 0, 'dias' => 0];
        if ($leads === []) {
            return $vacio;
        }
        $llegadas = array_map(fn(array $l): string => substr((string) $l['created_at'], 0, 10), $leads);
        sort($llegadas);
        $primero = new \DateTimeImmutable($llegadas[0]);
        $dias = max(0, (int) $primero->diff($hoy)->format('%r%a'));
        $ini = $primero;
        if ($desde !== null && $desde !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) === 1 && new \DateTimeImmutable($desde) > $primero) {
            $ini = new \DateTimeImmutable($desde);
        }
        if ($ini > $hoy) {
            $ini = $hoy;
        }
        $rango = (int) $ini->diff($hoy)->format('%a');
        $paso = $rango <= 45 ? 'dia' : 'semana';
        $salto = $paso === 'dia' ? 1 : 7;

        // Primera vez que cada contacto llegó a ganado o a perdido.
        $primera = ['ganado' => [], 'perdido' => []];
        foreach ($eventos as $e) {
            $a = (string) ($e['a_estado'] ?? '');
            if ((string) $e['tipo'] === 'estado' && isset($primera[$a])) {
                $d = substr((string) $e['ocurrio_en'], 0, 10);
                $l = (string) $e['lead_id'];
                if (!isset($primera[$a][$l]) || $d < $primera[$a][$l]) {
                    $primera[$a][$l] = $d;
                }
            }
        }
        $cuenta = static fn(array $fechas, string $hasta): int => count(array_filter($fechas, static fn(string $d): bool => $d <= $hasta));

        $out = ['etiquetas' => [], 'contactos' => [], 'ganados' => [], 'perdidos' => [], 'paso' => $paso, 'total' => count($leads), 'dias' => $dias];
        $p = $ini;
        $puntos = [];
        while ($p < $hoy) {
            $puntos[] = $p;
            $p = $p->modify('+' . $salto . ' days');
        }
        $puntos[] = $hoy;
        foreach ($puntos as $d) {
            $f = $d->format('Y-m-d');
            $out['etiquetas'][] = self::etiqueta($d);
            $out['contactos'][] = $cuenta($llegadas, $f);
            $out['ganados'][] = $cuenta(array_values($primera['ganado']), $f);
            $out['perdidos'][] = $cuenta(array_values($primera['perdido']), $f);
        }
        return $out;
    }

    /**
     * Cuántos contactos llegaron a cada etapa. Perdido va aparte (se puede perder en cualquier etapa).
     *
     * @return array{llegaron: int, contactados: int, calificados: int, ganados: int, perdidos: int, pasos: array<int, int|null>}
     *   pasos: % que pasa de una etapa a la siguiente (null si no hay base)
     */
    public static function embudo(array $leads, array $eventos): array
    {
        $max = [];
        foreach ($leads as $l) {
            $max[(string) $l['id']] = LeadLinea::ORDEN[(string) $l['estado']] ?? 0;
        }
        foreach ($eventos as $e) {
            $r = LeadLinea::ORDEN[(string) ($e['a_estado'] ?? '')] ?? null;
            $id = (string) $e['lead_id'];
            if ($r !== null && isset($max[$id]) && $r > $max[$id]) {
                $max[$id] = $r;
            }
        }
        $n = [0, 0, 0, 0];
        foreach ($max as $r) {
            for ($i = 0; $i <= $r; $i++) {
                $n[$i]++;
            }
        }
        $perdidos = count(array_filter($leads, fn(array $l): bool => (string) $l['estado'] === 'perdido'));
        $pct = static fn(int $a, int $b): ?int => $b > 0 ? (int) round($a * 100 / $b) : null;
        return [
            'llegaron' => $n[0], 'contactados' => $n[1], 'calificados' => $n[2], 'ganados' => $n[3], 'perdidos' => $perdidos,
            'pasos' => [$pct($n[1], $n[0]), $pct($n[2], $n[1]), $pct($n[3], $n[2])],
        ];
    }

    /**
     * Días promedio entre una etapa y la siguiente, contando desde la primera vez que cada contacto la alcanzó
     * (la fecha corregida a mano también cuenta).
     *
     * @return array{tramos: array<int, array{de: string, a: string, dias: float|null}>, a_ganado_pct: float|null}
     */
    public static function tiempos(array $leads, array $eventos): array
    {
        $llegada = [];
        foreach ($leads as $l) {
            $llegada[(string) $l['id']] = strtotime((string) $l['created_at']) ?: 0;
        }
        // Primera vez que cada contacto alcanzó cada etapa (o una posterior).
        $alcance = [];
        foreach ($eventos as $e) {
            $r = LeadLinea::ORDEN[(string) ($e['a_estado'] ?? '')] ?? null;
            $id = (string) $e['lead_id'];
            if ($r === null || !isset($llegada[$id]) || (string) $e['tipo'] !== 'estado') {
                continue;
            }
            $t = strtotime((string) $e['ocurrio_en']) ?: 0;
            for ($i = 1; $i <= $r; $i++) {
                if (!isset($alcance[$id][$i]) || $t < $alcance[$id][$i]) {
                    $alcance[$id][$i] = $t;
                }
            }
        }
        $nombres = array_keys(LeadLinea::ORDEN);
        $tramos = [];
        for ($i = 1; $i <= 3; $i++) {
            $d = [];
            foreach ($alcance as $id => $a) {
                if (isset($a[$i])) {
                    $ant = $i === 1 ? $llegada[$id] : ($a[$i - 1] ?? null);
                    if ($ant !== null) {
                        $d[] = max(0, $a[$i] - $ant) / 86400;
                    }
                }
            }
            $tramos[] = ['de' => $nombres[$i - 1], 'a' => $nombres[$i], 'dias' => $d === [] ? null : round(array_sum($d) / count($d), 1)];
        }
        $ganados = count(array_filter($alcance, static fn(array $a): bool => isset($a[3])));
        return ['tramos' => $tramos, 'a_ganado_pct' => $leads === [] ? null : round($ganados * 100 / count($leads), 1)];
    }

    /** Contactos que llegaron en los últimos 7 días. */
    public static function nuevosSemana(array $leads, ?\DateTimeImmutable $hoy = null): int
    {
        $desde = ($hoy ?? new \DateTimeImmutable('today'))->modify('-6 days')->format('Y-m-d');
        return count(array_filter($leads, static fn(array $l): bool => substr((string) $l['created_at'], 0, 10) >= $desde));
    }

    /**
     * Lo que lee el gráfico del navegador (assets/graficos.js): etiquetas y series. Los ganados y perdidos
     * vienen apagados; se encienden con los botones de arriba del gráfico.
     *
     * @param array<string, mixed> $acum de acumulado()
     * @return array<string, mixed>
     */
    public static function datosGrafico(array $acum): array
    {
        return [
            'etiquetas' => $acum['etiquetas'],
            'series' => [
                ['id' => 'contactos', 'nombre' => 'Contactos', 'datos' => $acum['contactos']],
                ['id' => 'ganados', 'nombre' => 'Ganados', 'datos' => $acum['ganados'], 'oculta' => true],
                ['id' => 'perdidos', 'nombre' => 'Perdidos', 'datos' => $acum['perdidos'], 'oculta' => true],
            ],
        ];
    }

    /** Proyectos «activos» de un cliente que ya llevan sus 14 días con contactos: los que muestran gráfico en Inicio. */
    public static function conGrafico(array $leads, ?\DateTimeImmutable $hoy = null): bool
    {
        if ($leads === []) {
            return false;
        }
        $primero = min(array_map(fn(array $l): string => substr((string) $l['created_at'], 0, 10), $leads));
        $hoy ??= new \DateTimeImmutable('today');
        return (int) (new \DateTimeImmutable($primero))->diff($hoy)->format('%r%a') >= self::DIAS_PARA_GRAFICO;
    }
}

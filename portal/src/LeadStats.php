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
     * Lo que dibuja el gráfico: el volumen (cuántos han llegado y cuántos fueron contactados, acumulados) y,
     * aparte, los hitos (calificados, ganados y perdidos) contados en el día —o la semana, si el rango es largo—
     * en que ocurrió cada uno. Los hitos son pocos: se marcan, no se acumulan en una línea.
     *
     * Contactado cuenta a todos los que llegaron a esa etapa o más lejos (si no, la línea bajaría al avanzar).
     * Un hito es la primera vez que un contacto llegó a ese estado, con la fecha corregida a mano si la hay.
     *
     * @param array<int, array<string, mixed>> $leads filas con id, created_at
     * @param array<int, array<string, mixed>> $eventos de LeadLinea::deEstado()
     * @param string|null $desde Y-m-d; vacío = desde el primer contacto
     * @return array{etiquetas: array<int, string>, llegaron: array<int, int>, contactados: array<int, int>, hitos: array<string, array<int, int>>, totales: array<string, int>, paso: string, total: int, dias: int}
     */
    public static function evolucion(array $leads, array $eventos, ?string $desde = null, ?\DateTimeImmutable $hoy = null): array
    {
        $hoy ??= new \DateTimeImmutable('today');
        $vacio = ['etiquetas' => [], 'llegaron' => [], 'contactados' => [], 'llegaron_dia' => [], 'contactados_dia' => [], 'hitos' => ['calificado' => [], 'ganado' => [], 'perdido' => []],
            'totales' => ['llegaron' => 0, 'contactados' => 0, 'calificado' => 0, 'ganado' => 0, 'perdido' => 0], 'paso' => 'dia', 'total' => 0, 'dias' => 0];
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
        $paso = (int) $ini->diff($hoy)->format('%a') <= 45 ? 'dia' : 'semana';
        $salto = $paso === 'dia' ? 1 : 7;
        $puntos = [];
        for ($p = $ini; $p < $hoy; $p = $p->modify('+' . $salto . ' days')) {
            $puntos[] = $p->format('Y-m-d');
        }
        $puntos[] = $hoy->format('Y-m-d');
        $etiquetas = [];
        foreach ($puntos as $f) {
            $etiquetas[] = self::etiqueta(new \DateTimeImmutable($f));
        }

        // Primera vez que cada contacto llegó a cada estado.
        $primera = ['contactado' => [], 'calificado' => [], 'ganado' => [], 'perdido' => []];
        foreach ($eventos as $e) {
            $a = (string) ($e['a_estado'] ?? '');
            if ((string) $e['tipo'] !== 'estado') {
                continue;
            }
            $d = substr((string) $e['ocurrio_en'], 0, 10);
            $l = (string) $e['lead_id'];
            $claves = [];
            if (isset($primera[$a])) {
                $claves[] = $a;
            }
            if ((LeadLinea::ORDEN[$a] ?? 0) >= 1 || $a === 'perdido') {
                $claves[] = 'contactado';   // calificado y ganado también pasaron por contactado; a quien se marca perdido ya se le habló
            }
            foreach (array_unique($claves) as $k) {
                if (!isset($primera[$k][$l]) || $d < $primera[$k][$l]) {
                    $primera[$k][$l] = $d;
                }
            }
        }
        $cuenta = static fn(array $fechas, string $hasta): int => count(array_filter($fechas, static fn(string $d): bool => $d <= $hasta));
        $out = $vacio;
        $out['etiquetas'] = $etiquetas;
        $out['paso'] = $paso;
        $out['total'] = count($leads);
        $out['dias'] = $dias;
        $contactadas = array_values($primera['contactado']);
        $anterior = $ini->modify('-1 day')->format('Y-m-d');
        foreach ($puntos as $f) {
            $out['llegaron'][] = $cuenta($llegadas, $f);
            $out['contactados'][] = $cuenta($contactadas, $f);
            // Por día (o por semana): los que cayeron solo en este tramo, sin arrastrar lo anterior.
            $out['llegaron_dia'][] = $cuenta($llegadas, $f) - $cuenta($llegadas, $anterior);
            $out['contactados_dia'][] = $cuenta($contactadas, $f) - $cuenta($contactadas, $anterior);
            $anterior = $f;
        }
        $out['totales']['llegaron'] = end($out['llegaron']) ?: 0;
        $out['totales']['contactados'] = end($out['contactados']) ?: 0;
        // Hitos: cada uno cae en el punto cuyo tramo lo contiene (el primero junta lo anterior que entra en el rango).
        foreach (['calificado', 'ganado', 'perdido'] as $k) {
            $cont = array_fill(0, count($puntos), 0);
            foreach ($primera[$k] as $d) {
                if ($d < $ini->format('Y-m-d') || $d > $puntos[count($puntos) - 1]) {
                    continue;
                }
                foreach ($puntos as $i => $f) {
                    if ($d <= $f) {
                        $cont[$i]++;
                        break;
                    }
                }
            }
            $out['hitos'][$k] = $cont;
            $out['totales'][$k] = array_sum($cont);
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
            // «Perdido» no está en la escala, pero no se pierde a alguien con quien nunca se habló: cuenta como contactado.
            $max[(string) $l['id']] = LeadLinea::ORDEN[(string) $l['estado']] ?? ((string) $l['estado'] === 'perdido' ? 1 : 0);
        }
        foreach ($eventos as $e) {
            $r = LeadLinea::ORDEN[(string) ($e['a_estado'] ?? '')] ?? ((string) ($e['a_estado'] ?? '') === 'perdido' ? 1 : null);
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
     * Lo que lee el gráfico del navegador (assets/graficos.js): etiquetas, las dos líneas de volumen y los hitos
     * (cantidad por punto, en carriles aparte).
     *
     * @param array<string, mixed> $ev de evolucion()
     * @return array<string, mixed>
     */
    public static function datosGrafico(array $ev): array
    {
        return [
            'etiquetas' => $ev['etiquetas'],
            'paso' => $ev['paso'],
            'totales' => $ev['totales'],
            'volumen' => [
                ['id' => 'llegaron', 'nombre' => 'Llegaron', 'datos' => $ev['llegaron']],
                ['id' => 'contactados', 'nombre' => 'Contactados', 'datos' => $ev['contactados']],
            ],
            'volumen_dia' => [
                ['id' => 'llegaron', 'nombre' => 'Llegaron', 'datos' => $ev['llegaron_dia']],
                ['id' => 'contactados', 'nombre' => 'Contactados', 'datos' => $ev['contactados_dia']],
            ],
            'hitos' => [
                ['id' => 'calificado', 'nombre' => 'Calificados', 'uno' => 'calificado', 'varios' => 'calificados', 'datos' => $ev['hitos']['calificado']],
                ['id' => 'ganado', 'nombre' => 'Ganados', 'uno' => 'ganado', 'varios' => 'ganados', 'datos' => $ev['hitos']['ganado']],
                ['id' => 'perdido', 'nombre' => 'Perdidos', 'uno' => 'perdido', 'varios' => 'perdidos', 'datos' => $ev['hitos']['perdido']],
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

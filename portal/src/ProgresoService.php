<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * Calcula el avance de un proyecto a partir de sus fases y tareas, sin
 * necesidad de mantener un "estado" manual en cada fase:
 *  - Una fase con tareas está lista cuando todas sus tareas están "hecha".
 *  - Una fase sin tareas se considera lista si su fecha_fin ya pasó.
 *  - La primera fase no lista es la "actual"; las siguientes quedan pendientes.
 */
final class ProgresoService
{
    /**
     * @param array<array<string, mixed>> $fases  ordenadas por `orden`
     * @param array<array<string, mixed>> $tareas del proyecto (ya filtradas por visibilidad)
     * @return array{pct: int, hechas: int, total: int, completo: bool, fases: array<array<string, mixed>>}
     */
    public static function calcular(array $fases, array $tareas, ?\DateTimeImmutable $hoy = null): array
    {
        $hoy = ($hoy ?? new \DateTimeImmutable())->format('Y-m-d');

        $total  = count($tareas);
        $hechas = count(array_filter($tareas, static fn($t) => $t['estado'] === 'hecha'));

        $out = [];
        $actualAsignada = false;
        foreach ($fases as $f) {
            $deFase = array_values(array_filter($tareas, static fn($t) => ($t['fase_id'] ?? null) === $f['id']));
            $n      = count($deFase);
            $nHechas = count(array_filter($deFase, static fn($t) => $t['estado'] === 'hecha'));

            if ($n > 0) {
                $lista = $nHechas === $n;
            } else {
                $fin   = substr((string) ($f['fecha_fin'] ?? ''), 0, 10);
                $lista = $fin !== '' && $fin < $hoy;
            }

            if ($lista) {
                $estado = 'hecha';
            } elseif (!$actualAsignada) {
                $estado = 'actual';
                $actualAsignada = true;
            } else {
                $estado = 'pendiente';
            }

            $out[] = [
                'id'       => $f['id'],
                'nombre'   => $f['nombre'],
                'estado'   => $estado,
                'tareas'   => $n,
                'hechas'   => $nHechas,
                'inicio'   => $f['fecha_inicio'] ?? null,
                'fin'      => $f['fecha_fin'] ?? null,
            ];
        }

        // Sin fases o sin tareas asociadas a fases, el % igual sale de las tareas del proyecto.
        $pct = $total > 0 ? (int) round($hechas * 100 / $total) : 0;
        if ($total === 0 && $out !== []) {
            $listas = count(array_filter($out, static fn($f) => $f['estado'] === 'hecha'));
            $pct = (int) round($listas * 100 / count($out));
        }

        return [
            'pct'      => $pct,
            'hechas'   => $hechas,
            'total'    => $total,
            'completo' => $out !== [] && !$actualAsignada,
            'fases'    => $out,
        ];
    }
}

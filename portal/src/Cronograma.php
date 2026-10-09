<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * Línea de tiempo de un proyecto: fases, tareas, hitos, reuniones y entregas en un eje de días.
 *
 * Tareas con dependencia: una tarea puede depender de otra y durar N días hábiles (lunes a
 * viernes; no se consideran feriados). Mientras la anterior no termine, sus fechas son
 * ESTIMADAS (parte el día hábil siguiente al fin de la anterior, o desde hoy si la anterior va
 * atrasada). Cuando la anterior se completa, fijar() le escribe inicio y vencimiento reales.
 */
final class Cronograma
{
    public function __construct(private readonly \PDO $pdo) {}

    // ---- Días hábiles -------------------------------------------------------------

    private static function d(string $f): \DateTimeImmutable
    {
        return new \DateTimeImmutable(substr($f, 0, 10));
    }

    public static function esHabil(string $f): bool
    {
        return (int) self::d($f)->format('N') <= 5;
    }

    /** La misma fecha si es hábil; si no, el lunes siguiente. */
    public static function habilDesde(string $f): string
    {
        $d = self::d($f);
        while ((int) $d->format('N') > 5) {
            $d = $d->modify('+1 day');
        }
        return $d->format('Y-m-d');
    }

    /** El día hábil siguiente a $f. */
    public static function siguienteHabil(string $f): string
    {
        return self::habilDesde(self::d($f)->modify('+1 day')->format('Y-m-d'));
    }

    /** Fecha de término de algo que parte en $inicio y dura $dias hábiles (1 = el mismo día). */
    public static function finTras(string $inicio, int $dias): string
    {
        $d = self::habilDesde($inicio);
        for ($i = 1; $i < max(1, $dias); $i++) {
            $d = self::siguienteHabil($d);
        }
        return $d;
    }

    /** Días hábiles entre dos fechas, ambas incluidas (mínimo 1). */
    public static function habilesEntre(string $desde, string $hasta): int
    {
        $a = self::d($desde);
        $b = self::d($hasta);
        if ($b < $a) {
            return 1;
        }
        $n = 0;
        for ($x = $a; $x <= $b; $x = $x->modify('+1 day')) {
            $n += (int) $x->format('N') <= 5 ? 1 : 0;
        }
        return max(1, $n);
    }

    public static function hoy(): string
    {
        return (Notifier::$ahora ?? new \DateTimeImmutable('now'))->setTimezone(new \DateTimeZone(Zona::agencia()))->format('Y-m-d');
    }

    // ---- Dependencias ----------------------------------------------------------------

    /**
     * ¿Se puede hacer que $tareaId dependa de $anteriorId? (mismo proyecto, distinta, sin ciclos)
     */
    public function dependenciaValida(string $tareaId, string $anteriorId, string $proyectoId): bool
    {
        if ($anteriorId === '' || $anteriorId === $tareaId) {
            return false;
        }
        $st = $this->pdo->prepare('SELECT id, depende_de, proyecto_id FROM portal_tareas WHERE id = ?');
        $vistos = [];
        $x = $anteriorId;
        for ($i = 0; $i < 200 && $x !== null && $x !== ''; $i++) {
            if ($x === $tareaId || isset($vistos[$x])) {
                return false;   // ciclo
            }
            $vistos[$x] = true;
            $st->execute([$x]);
            $f = $st->fetch();
            if ($f === false || ($i === 0 && $f['proyecto_id'] !== $proyectoId)) {
                return false;
            }
            $x = $f['depende_de'] !== null ? (string) $f['depende_de'] : null;
        }
        return true;
    }

    /**
     * La tarea $id terminó: las que dependen de ella reciben fechas reales (parten el día hábil
     * siguiente y duran lo que dicen). Se llama al marcar una tarea como hecha.
     */
    public function fijar(string $id): int
    {
        try {
            $st = $this->pdo->prepare('SELECT completada_en FROM portal_tareas WHERE id = ?');
            $st->execute([$id]);
            $fin = (string) ($st->fetchColumn() ?: '');
            if ($fin === '') {
                return 0;
            }
            $dep = $this->pdo->prepare("SELECT id, duracion_dias FROM portal_tareas WHERE depende_de = ? AND estado <> 'hecha'");
            $dep->execute([$id]);
            $n = 0;
            $up = $this->pdo->prepare('UPDATE portal_tareas SET fecha_inicio = ?, fecha_vencimiento = ?, updated_at = ? WHERE id = ?');
            foreach ($dep->fetchAll() as $t) {
                $ini = self::siguienteHabil(substr($fin, 0, 10));
                $up->execute([$ini, self::finTras($ini, (int) ($t['duracion_dias'] ?: 1)), (new \DateTimeImmutable())->format('Y-m-d H:i:s'), $t['id']]);
                $n++;
            }
            return $n;
        } catch (\Throwable) {
            return 0;   // sin columnas aún (primera visita tras actualizar)
        }
    }

    // ---- Datos para la vista ---------------------------------------------------------------

    /**
     * @param bool $cliente sólo lo que ve el cliente (tareas visibles, hitos visibles, reuniones publicadas)
     * @return array<string, mixed>
     */
    public function datos(string $proyectoId, bool $cliente = false): array
    {
        Schema::asegurar($this->pdo);
        $hoy = self::hoy();
        $q = function (string $sql, array $p = []): array {
            $st = $this->pdo->prepare($sql);
            $st->execute($p);
            return $st->fetchAll();
        };

        $fases = $q('SELECT * FROM portal_fases WHERE proyecto_id = ? ORDER BY orden, nombre', [$proyectoId]);
        $tareasTodas = $q(
            "SELECT t.*, e.nombre AS equipo_nombre, ct.nombre AS contacto_nombre FROM portal_tareas t
             LEFT JOIN portal_equipo e ON e.id = t.responsable_usuario_id
             LEFT JOIN portal_contactos ct ON ct.id = t.responsable_contacto_id
             WHERE t.proyecto_id = ? AND COALESCE(t.archivada, 0) = 0
             ORDER BY (t.fecha_inicio IS NULL), t.fecha_inicio, (t.fecha_vencimiento IS NULL), t.fecha_vencimiento, t.created_at",
            [$proyectoId]
        );
        $porId = array_column($tareasTodas, null, 'id');

        // Fechas de cada tarea (reales o estimadas por dependencia).
        $memo = [];
        $calc = function (string $id, int $prof = 0) use (&$calc, &$memo, $porId, $hoy): array {
            if (isset($memo[$id])) {
                return $memo[$id];
            }
            $t = $porId[$id];
            $ini = $t['fecha_inicio'] ? substr((string) $t['fecha_inicio'], 0, 10) : null;
            $fin = $t['fecha_vencimiento'] ? substr((string) $t['fecha_vencimiento'], 0, 10) : null;
            $estimada = false;
            $ant = (string) ($t['depende_de'] ?? '');
            if ($ant !== '' && isset($porId[$ant]) && $prof < 50 && $t['estado'] !== 'hecha') {
                $a = $porId[$ant];
                if ($a['estado'] !== 'hecha') {
                    // La anterior sigue abierta: se estima desde su fin (o desde hoy si va atrasada).
                    $finAnt = $calc($ant, $prof + 1)['fin'];
                    $ini = $finAnt === null || $finAnt < $hoy ? self::habilDesde($hoy) : self::siguienteHabil($finAnt);
                    $fin = self::finTras($ini, (int) ($t['duracion_dias'] ?: 1));
                    $estimada = true;
                }
            }
            if ($ini === null && $fin !== null) {
                $ini = $fin;   // sólo vencimiento: un día
            }
            if ($fin === null && $ini !== null) {
                $fin = $ini;
            }
            if ($ini !== null && $fin !== null && $fin < $ini) {
                $fin = $ini;
            }
            return $memo[$id] = ['ini' => $ini, 'fin' => $fin, 'estimada' => $estimada];
        };

        $tareas = [];
        foreach ($tareasTodas as $t) {
            if ($cliente && (int) $t['visible_cliente'] !== 1 && $t['responsable_tipo'] !== 'cliente') {
                continue;
            }
            $f = $calc((string) $t['id']);
            $tareas[] = [
                'id' => (string) $t['id'], 'titulo' => (string) $t['titulo'], 'fase_id' => $t['fase_id'] ? (string) $t['fase_id'] : null,
                'ini' => $f['ini'], 'fin' => $f['fin'], 'estimada' => $f['estimada'], 'estado' => (string) $t['estado'],
                'atrasada' => $t['estado'] !== 'hecha' && $f['fin'] !== null && $f['fin'] < $hoy,
                'depende_de' => $t['depende_de'] ? (string) $t['depende_de'] : null, 'duracion' => $t['duracion_dias'] !== null ? (int) $t['duracion_dias'] : null,
                'quien' => $t['responsable_tipo'] === 'cliente' ? 'cliente' : 'equipo',
                'responsable' => $t['responsable_tipo'] === 'cliente' ? ((string) ($t['contacto_nombre'] ?? '') ?: 'Cliente') : (string) ($t['equipo_nombre'] ?? ''),
                'visible' => (int) $t['visible_cliente'] === 1 || $t['responsable_tipo'] === 'cliente',
            ];
        }

        // Fases: con sus fechas o, si no tienen, el rango de sus tareas.
        $progreso = ProgresoService::calcular($fases, array_map(fn($t) => ['fase_id' => $t['fase_id'], 'estado' => $t['estado']], $tareas));
        $estFase = array_column($progreso['fases'], null, 'id');
        $listaFases = [];
        foreach ($fases as $f) {
            $deFase = array_values(array_filter($tareas, fn($t) => $t['fase_id'] === (string) $f['id']));
            $inis = array_filter(array_column($deFase, 'ini'));
            $fins = array_filter(array_column($deFase, 'fin'));
            $listaFases[] = [
                'id' => (string) $f['id'], 'nombre' => (string) $f['nombre'],
                'ini' => $f['fecha_inicio'] ? substr((string) $f['fecha_inicio'], 0, 10) : ($inis ? min($inis) : null),
                'fin' => $f['fecha_fin'] ? substr((string) $f['fecha_fin'], 0, 10) : ($fins ? max($fins) : null),
                'fechas_propias' => (bool) ($f['fecha_inicio'] && $f['fecha_fin']),
                'estado' => $estFase[$f['id']]['estado'] ?? 'pendiente',
                'pct' => count($deFase) > 0 ? (int) round(100 * count(array_filter($deFase, fn($t) => $t['estado'] === 'hecha')) / count($deFase)) : 0,
                'tareas' => $deFase,
            ];
        }
        $sinFase = array_values(array_filter($tareas, fn($t) => $t['fase_id'] === null || !isset($estFase[$t['fase_id']])));

        // Hitos
        $hitos = [];
        foreach ($q('SELECT * FROM portal_hitos WHERE proyecto_id = ?' . ($cliente ? ' AND visible_cliente = 1' : '') . ' ORDER BY fecha, nombre', [$proyectoId]) as $h) {
            $porFase = $h['fase_id'] && isset($estFase[$h['fase_id']]) && $estFase[$h['fase_id']]['estado'] === 'hecha' && ($estFase[$h['fase_id']]['tareas'] ?? 0) > 0;
            $cumplido = $h['cumplido_en'] !== null || $porFase;
            $hitos[] = [
                'id' => (string) $h['id'], 'nombre' => (string) $h['nombre'], 'fecha' => (string) $h['fecha'],
                'fase_id' => $h['fase_id'] ? (string) $h['fase_id'] : null, 'visible' => (int) $h['visible_cliente'] === 1,
                'cumplido' => $cumplido, 'manual' => $h['cumplido_en'] !== null,
                'estado' => $cumplido ? 'cumplido' : ((string) $h['fecha'] < $hoy ? 'atrasado' : 'pendiente'),
            ];
        }

        // Reuniones y entregas (en hora de la agencia)
        $reuniones = array_map(fn($r) => ['id' => (string) $r['id'], 'titulo' => (string) $r['titulo'], 'fecha' => substr((string) $r['fecha'], 0, 10),
            'hora' => strlen((string) $r['fecha']) > 10 ? substr((string) $r['fecha'], 11, 5) : '', 'fecha_completa' => (string) $r['fecha']],
            $q('SELECT id, titulo, fecha FROM portal_reuniones WHERE proyecto_id = ? AND fecha IS NOT NULL AND fecha <> \'\'' . ($cliente ? ' AND publicada = 1' : '') . ' ORDER BY fecha', [$proyectoId]));
        $entregas = array_map(fn($e) => ['id' => (string) $e['id'], 'titulo' => (string) $e['titulo'], 'fecha' => substr((string) $e['fecha_limite'], 0, 10), 'estado' => (string) $e['estado']],
            $q("SELECT id, titulo, fecha_limite, estado FROM portal_entregas WHERE proyecto_id = ? AND fecha_limite IS NOT NULL AND fecha_limite <> ''" . ($cliente ? " AND estado <> 'borrador'" : '') . ' ORDER BY fecha_limite', [$proyectoId]));

        // Rango del eje: de lo primero a lo último, con margen, y siempre con hoy a la vista.
        $fechas = [$hoy];
        foreach ($tareas as $t) {
            array_push($fechas, ...array_filter([$t['ini'], $t['fin']]));
        }
        foreach ($listaFases as $f) {
            array_push($fechas, ...array_filter([$f['ini'], $f['fin']]));
        }
        foreach ([$hitos, $reuniones, $entregas] as $lista) {
            foreach ($lista as $x) {
                $fechas[] = $x['fecha'];
            }
        }
        $fechas = array_filter($fechas, fn($f) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $f) === 1);
        $desde = self::d(min($fechas))->modify('monday this week')->modify('-7 days');
        $hasta = self::d(max($fechas))->modify('sunday this week')->modify('+7 days');
        if ($hasta->diff($desde)->days < 41) {
            $hasta = $desde->modify('+41 days');   // al menos 6 semanas
        }

        return [
            'hoy' => $hoy, 'desde' => $desde->format('Y-m-d'), 'hasta' => $hasta->format('Y-m-d'),
            'dias' => (int) $desde->diff($hasta)->days + 1,
            'fases' => $listaFases, 'sinFase' => $sinFase, 'hitos' => $hitos, 'reuniones' => $reuniones, 'entregas' => $entregas,
            'tareas' => $tareas, 'pct' => $progreso['pct'],
            'sinFecha' => array_values(array_filter($tareas, fn($t) => $t['ini'] === null)),
        ];
    }

    /**
     * Cabecera del eje: meses (con su primer día y largo) y días (número, fin de semana, lunes).
     * @return array{meses: list<array{nombre: string, ini: int, dias: int}>, dias: list<array{n: int, finde: bool, lunes: bool, fecha: string}>}
     */
    public static function eje(string $desde, int $dias): array
    {
        $nombres = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
        $meses = [];
        $lista = [];
        $d = new \DateTimeImmutable($desde);
        for ($i = 0; $i < $dias; $i++, $d = $d->modify('+1 day')) {
            $clave = $d->format('Y-m');
            if ($meses === [] || $meses[count($meses) - 1]['clave'] !== $clave) {
                $meses[] = ['clave' => $clave, 'nombre' => $nombres[(int) $d->format('n')] . ' ' . $d->format('Y'), 'ini' => $i, 'dias' => 0];
            }
            $meses[count($meses) - 1]['dias']++;
            $lista[] = ['n' => (int) $d->format('j'), 'finde' => (int) $d->format('N') >= 6, 'lunes' => $d->format('N') === '1', 'fecha' => $d->format('Y-m-d')];
        }
        return ['meses' => $meses, 'dias' => $lista];
    }

    /** Día (Y-m-d) → número de columna desde $desde. */
    public static function columna(string $desde): \Closure
    {
        $base = new \DateTimeImmutable($desde);
        return static fn(?string $f): int => $f === null || $f === '' ? 0 : (int) $base->diff(new \DateTimeImmutable(substr($f, 0, 10)))->format('%r%a');
    }
}

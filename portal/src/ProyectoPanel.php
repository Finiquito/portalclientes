<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * Datos del panel de control de un proyecto (panel del equipo) y de «Tus proyectos» (portal del cliente).
 *
 * Todo se calcula a partir de lo que el portal ya guarda: tareas, fases, contenidos, reuniones, solicitudes,
 * archivos, actividad y contactos ganados. Nada se guarda aparte.
 */
final class ProyectoPanel
{
    /** Días sin respuesta del cliente a partir de los cuales una revisión «requiere atención». */
    public const DIAS_SIN_RESPUESTA = 3;

    private \DateTimeImmutable $hoy;

    public function __construct(private readonly \PDO $pdo, ?\DateTimeImmutable $hoy = null)
    {
        $this->hoy = $hoy ?? new \DateTimeImmutable('today');
    }

    /** @param array<int, mixed> $params @return array<int, array<string, mixed>> */
    private function filas(string $sql, array $params = []): array
    {
        try {
            $st = $this->pdo->prepare($sql);
            $st->execute($params);
            return $st->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    private function dias(string $desdeFecha): int
    {
        try {
            return max(0, (int) (new \DateTimeImmutable(substr($desdeFecha, 0, 10)))->diff($this->hoy)->format('%r%a'));
        } catch (\Throwable) {
            return 0;
        }
    }

    // ---- Contenidos -------------------------------------------------------------------------

    /**
     * Dónde está cada pieza del proyecto: borrador, esperando al cliente, con cambios pedidos o aprobada.
     *
     * @return array{total: int, borrador: int, pendiente: int, cambios: int, aprobado: int, semana: array<int, array<string, mixed>>, sin_respuesta: array<int, array<string, mixed>>, por_revisar: array<int, array<string, mixed>>}
     */
    public function contenidos(string $proyectoId): array
    {
        $filas = $this->filas(
            'SELECT c.id, c.titulo, c.tipo, c.estado, c.fecha_publicacion, c.entrega_id, c.actualizado_en,
                    e.estado AS entrega_estado, e.titulo AS entrega_titulo, e.publicada_en
             FROM portal_contenidos c JOIN portal_entregas e ON e.id = c.entrega_id WHERE c.proyecto_id = ?',
            [$proyectoId]
        );
        $o = ['total' => count($filas), 'borrador' => 0, 'pendiente' => 0, 'cambios' => 0, 'aprobado' => 0, 'semana' => [], 'sin_respuesta' => [], 'por_revisar' => []];
        $hasta = $this->hoy->modify('+7 days')->format('Y-m-d');
        $hoy = $this->hoy->format('Y-m-d');
        foreach ($filas as $f) {
            $bucket = (string) $f['entrega_estado'] === 'borrador' ? 'borrador' : match ((string) $f['estado']) {
                'aprobado' => 'aprobado', 'cambios' => 'cambios', default => 'pendiente',
            };
            $o[$bucket]++;
            $f['bucket'] = $bucket;
            $f['dias_espera'] = 0;
            if ($bucket === 'pendiente') {
                $desde = max((string) ($f['publicada_en'] ?? ''), (string) ($f['actualizado_en'] ?? ''));
                $f['dias_espera'] = $desde !== '' ? $this->dias($desde) : 0;
                $o['por_revisar'][] = $f;
                if ($f['dias_espera'] >= self::DIAS_SIN_RESPUESTA) {
                    $o['sin_respuesta'][] = $f;
                }
            }
            $pub = substr((string) ($f['fecha_publicacion'] ?? ''), 0, 10);
            if ($pub !== '' && $pub >= $hoy && $pub <= $hasta) {
                $o['semana'][] = $f;
            }
        }
        usort($o['semana'], fn(array $a, array $b): int => strcmp((string) $a['fecha_publicacion'], (string) $b['fecha_publicacion']));
        usort($o['sin_respuesta'], fn(array $a, array $b): int => $b['dias_espera'] <=> $a['dias_espera']);
        return $o;
    }

    // ---- Reuniones --------------------------------------------------------------------------

    /**
     * La próxima y la última reunión, y las tareas que salieron de la última.
     *
     * @return array{proxima: ?array<string, mixed>, ultima: ?array<string, mixed>, n_proximas: int, n_hechas: int, tareas: ?array<string, int>}
     */
    public function reuniones(string $proyectoId, bool $soloPublicadas = false): array
    {
        $filas = $this->filas(
            'SELECT * FROM portal_reuniones WHERE proyecto_id = ?' . ($soloPublicadas ? ' AND publicada = 1' : '') . ' ORDER BY fecha',
            [$proyectoId]
        );
        $ahora = (new \DateTimeImmutable('now', new \DateTimeZone(Zona::agencia())))->format('Y-m-d H:i');
        $proximas = array_values(array_filter($filas, fn(array $r): bool => $this->esFutura((string) $r['fecha'], $ahora)));
        $hechas = array_values(array_filter($filas, fn(array $r): bool => !$this->esFutura((string) $r['fecha'], $ahora)));
        $ultima = $hechas === [] ? null : $hechas[count($hechas) - 1];
        $tareas = null;
        if ($ultima !== null) {
            $tt = $this->filas(
                'SELECT estado, responsable_tipo, fecha_vencimiento FROM portal_tareas WHERE reunion_origen_id = ? AND COALESCE(archivada, 0) = 0',
                [(string) $ultima['id']]
            );
            if ($tt !== []) {
                $hoy = $this->hoy->format('Y-m-d');
                $eq = array_values(array_filter($tt, fn(array $t): bool => $t['responsable_tipo'] !== 'cliente'));
                $tareas = [
                    'total' => count($tt), 'equipo' => count($eq), 'cliente' => count($tt) - count($eq),
                    'equipo_listas' => count(array_filter($eq, fn(array $t): bool => $t['estado'] === 'hecha')),
                    'equipo_en_curso' => count(array_filter($eq, fn(array $t): bool => $t['estado'] === 'en_progreso')),
                    'equipo_vencidas' => count(array_filter($eq, fn(array $t): bool => $t['estado'] !== 'hecha' && $t['fecha_vencimiento'] !== null && substr((string) $t['fecha_vencimiento'], 0, 10) < $hoy)),
                ];
            }
        }
        return ['proxima' => $proximas[0] ?? null, 'ultima' => $ultima, 'n_proximas' => count($proximas), 'n_hechas' => count($hechas), 'tareas' => $tareas];
    }

    private function esFutura(string $fecha, string $ahora): bool
    {
        // Sin hora, la reunión cuenta hasta que termina el día.
        return str_contains($fecha, ':') ? $fecha >= $ahora : substr($fecha, 0, 10) >= substr($ahora, 0, 10);
    }

    // ---- Ritmo del equipo -------------------------------------------------------------------

    /**
     * Tareas cerradas por semana (las últimas $semanas, la última es la actual) y cuánto falta al ritmo de la media.
     *
     * @return array{semanas: array<int, int>, promedio: float, abiertas: int, proyeccion: ?int}
     */
    public function ritmo(string $proyectoId, int $semanas = 6): array
    {
        $lunes = $this->hoy->modify('monday this week');
        $desde = $lunes->modify('-' . (($semanas - 1) * 7) . ' days')->format('Y-m-d');
        $filas = $this->filas(
            "SELECT completada_en FROM portal_tareas WHERE proyecto_id = ? AND estado = 'hecha' AND completada_en IS NOT NULL AND completada_en >= ?",
            [$proyectoId, $desde]
        );
        $cuenta = array_fill(0, $semanas, 0);
        foreach ($filas as $f) {
            $d = substr((string) $f['completada_en'], 0, 10);
            $i = intdiv((int) (new \DateTimeImmutable($desde))->diff(new \DateTimeImmutable($d))->format('%r%a'), 7);
            if ($i >= 0 && $i < $semanas) {
                $cuenta[$i]++;
            }
        }
        $abiertas = (int) ($this->filas(
            "SELECT COUNT(*) AS n FROM portal_tareas WHERE proyecto_id = ? AND estado <> 'hecha' AND COALESCE(archivada, 0) = 0",
            [$proyectoId]
        )[0]['n'] ?? 0);
        $prom = array_sum($cuenta) / max(1, $semanas);
        return [
            'semanas' => $cuenta, 'promedio' => round($prom, 1), 'abiertas' => $abiertas,
            'proyeccion' => $prom > 0 && $abiertas > 0 ? (int) ceil($abiertas / $prom) : null,
        ];
    }

    // ---- Salud y atención -------------------------------------------------------------------

    /**
     * Semáforo del proyecto.
     *  - completo: todas las fases están listas.
     *  - atrasado: hay una fase que ya debía haber terminado y no está lista.
     *  - riesgo: hay tareas vencidas o revisiones del cliente sin respuesta desde hace días.
     *  - plazo: nada de lo anterior.
     *
     * @param array<string, mixed> $progreso de ProgresoService::calcular()
     * @return array{nivel: string, etiqueta: string, motivo: string}
     */
    public function salud(array $progreso, int $vencidas, int $sinRespuesta): array
    {
        if (!empty($progreso['completo'])) {
            return ['nivel' => 'completo', 'etiqueta' => Idioma::t('Completo'), 'motivo' => Idioma::t('todas las fases están listas')];
        }
        $hoy = $this->hoy->format('Y-m-d');
        foreach ($progreso['fases'] ?? [] as $f) {
            $fin = substr((string) ($f['fin'] ?? ''), 0, 10);
            if ($f['estado'] !== 'hecha' && $fin !== '' && $fin < $hoy) {
                return ['nivel' => 'atrasado', 'etiqueta' => Idioma::t('Atrasado'), 'motivo' => Idioma::t('la fase «%s» ya debía haber terminado', $f['nombre'])];
            }
        }
        $motivos = [];
        if ($vencidas > 0) {
            $motivos[] = $vencidas === 1 ? Idioma::t('1 tarea vencida') : Idioma::t('%d tareas vencidas', $vencidas);
        }
        if ($sinRespuesta > 0) {
            $motivos[] = $sinRespuesta === 1 ? Idioma::t('1 revisión sin respuesta') : Idioma::t('%d revisiones sin respuesta', $sinRespuesta);
        }
        if ($motivos !== []) {
            return ['nivel' => 'riesgo', 'etiqueta' => Idioma::t('En riesgo'), 'motivo' => implode(' · ', $motivos)];
        }
        return ['nivel' => 'plazo', 'etiqueta' => Idioma::t('En plazo'), 'motivo' => Idioma::t('sin vencidas ni revisiones esperando')];
    }

    /**
     * Fechas del proyecto según sus fases: inicio, entrega estimada y días que faltan.
     *
     * @param array<string, mixed> $progreso
     * @return array{inicio: ?string, fin: ?string, quedan: ?int}
     */
    public function fechas(array $progreso): array
    {
        $ini = array_filter(array_map(fn($f) => substr((string) ($f['inicio'] ?? ''), 0, 10), $progreso['fases'] ?? []));
        $fin = array_filter(array_map(fn($f) => substr((string) ($f['fin'] ?? ''), 0, 10), $progreso['fases'] ?? []));
        $f = $fin === [] ? null : max($fin);
        return [
            'inicio' => $ini === [] ? null : min($ini),
            'fin'    => $f,
            'quedan' => $f === null ? null : (int) $this->hoy->diff(new \DateTimeImmutable($f))->format('%r%a'),
        ];
    }

    /**
     * Una barra por fase: cuánto lleva avanzado y, en la fase en curso, dónde cae «hoy» dentro de sus fechas.
     *
     * @param array<string, mixed> $progreso
     * @return array<int, array<string, mixed>>
     */
    public function barras(array $progreso): array
    {
        $hoy = $this->hoy->format('Y-m-d');
        $out = [];
        foreach ($progreso['fases'] ?? [] as $f) {
            $ini = substr((string) ($f['inicio'] ?? ''), 0, 10);
            $fin = substr((string) ($f['fin'] ?? ''), 0, 10);
            if ($f['estado'] === 'hecha') {
                $pct = 100;
            } elseif ((int) $f['tareas'] > 0) {
                $pct = (int) round($f['hechas'] * 100 / $f['tareas']);
            } else {
                $pct = 0;
            }
            $marca = null;
            if ($f['estado'] === 'actual' && $ini !== '' && $fin !== '' && $fin > $ini) {
                $a = (new \DateTimeImmutable($ini))->getTimestamp();
                $b = (new \DateTimeImmutable($fin))->getTimestamp();
                $marca = (int) max(0, min(100, round(((new \DateTimeImmutable($hoy))->getTimestamp() - $a) * 100 / ($b - $a))));
            }
            $out[] = ['nombre' => (string) $f['nombre'], 'estado' => (string) $f['estado'], 'ini' => $ini, 'fin' => $fin, 'pct' => $pct, 'marca' => $marca];
        }
        return $out;
    }

    // ---- Lo último del proyecto -------------------------------------------------------------

    /** @return array<int, array<string, mixed>> */
    public function solicitudes(string $proyectoId, int $n = 2): array
    {
        return $this->filas(
            'SELECT id, titulo, tipo, urgencia, estado, created_at FROM portal_solicitudes WHERE proyecto_id = ? ORDER BY created_at DESC LIMIT ' . $n,
            [$proyectoId]
        );
    }

    /** Solicitudes del proyecto que el equipo todavía tiene que atender. @return array<int, array<string, mixed>> */
    public function solicitudesPorAtender(string $proyectoId): array
    {
        $ph = implode(',', array_fill(0, count(SolicitudService::POR_ATENDER), '?'));
        return $this->filas(
            "SELECT id, titulo, urgencia FROM portal_solicitudes WHERE proyecto_id = ? AND estado IN ({$ph}) ORDER BY created_at DESC",
            [$proyectoId, ...SolicitudService::POR_ATENDER]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function archivos(string $proyectoId, int $n = 2): array
    {
        return $this->filas(
            'SELECT id, nombre_original, subido_por_nombre, created_at FROM portal_archivos WHERE proyecto_id = ? ORDER BY created_at DESC LIMIT ' . $n,
            [$proyectoId]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function actividad(string $proyectoId, int $n = 5): array
    {
        return $this->filas(
            'SELECT * FROM portal_actividad WHERE proyecto_id = ? ORDER BY created_at DESC, id DESC LIMIT ' . $n,
            [$proyectoId]
        );
    }

    // ---- Contactos ganados ------------------------------------------------------------------

    /**
     * Resumen semanal de los contactos ganados de un conjunto de proyectos del cliente (o de uno solo).
     *
     * @param array<int, string> $proyectoIds
     * @return ?array<string, mixed> null si no hay contactos
     */
    public function contactos(string $clienteId, array $proyectoIds): ?array
    {
        $svc = new LeadService($this->pdo);
        $leads = [];
        foreach ($proyectoIds as $pid) {
            $leads = array_merge($leads, $svc->lista(['cliente_id' => $clienteId, 'proyecto_id' => $pid]));
        }
        if ($leads === []) {
            return null;
        }
        $eventos = (new LeadLinea($this->pdo))->deEstado($clienteId, $proyectoIds);
        return LeadStats::semanal($leads, $eventos, $this->hoy);
    }

    // ---- Panel del equipo -------------------------------------------------------------------

    /**
     * Todo lo que necesita la página del proyecto en el panel del equipo.
     *
     * @param array<string, mixed> $proyecto con 'progreso', 'n_vencidas'
     * @param array<int, array<string, mixed>> $tareas del proyecto (sin archivadas)
     * @return array<string, mixed>
     */
    public function paraEquipo(array $proyecto, array $tareas): array
    {
        $pid = (string) $proyecto['id'];
        $prog = $proyecto['progreso'];
        $cont = $this->contenidos($pid);
        $reun = $this->reuniones($pid);
        $hoy = $this->hoy->format('Y-m-d');

        $abiertas = array_values(array_filter($tareas, fn(array $t): bool => $t['estado'] !== 'hecha'));
        $vencidas = array_values(array_filter($abiertas, fn(array $t): bool => $t['fecha_vencimiento'] !== null && substr((string) $t['fecha_vencimiento'], 0, 10) < $hoy));
        usort($vencidas, fn(array $a, array $b): int => strcmp((string) $a['fecha_vencimiento'], (string) $b['fecha_vencimiento']));
        $porAtender = $this->solicitudesPorAtender($pid);
        $urgentes = array_filter($porAtender, fn(array $s): bool => $s['urgencia'] === 'urgente');

        // Avance global: tareas y contenidos aprobados cuentan igual.
        $hechas = (int) $prog['hechas'];
        $totalT = (int) $prog['total'];
        $base = $totalT + $cont['total'];
        $pct = $base > 0 ? (int) round(($hechas + $cont['aprobado']) * 100 / $base) : (int) $prog['pct'];

        return [
            'pct'        => $pct,
            'tareas'     => ['hechas' => $hechas, 'total' => $totalT, 'abiertas' => count($abiertas)],
            'contenidos' => $cont,
            'reuniones'  => $reun,
            'salud'      => $this->salud($prog, count($vencidas), count($cont['sin_respuesta'])),
            'fechas'     => $this->fechas($prog),
            'barras'     => $this->barras($prog),
            'vencidas'   => $vencidas,
            'dias_vencida' => $vencidas === [] ? 0 : $this->dias((string) $vencidas[0]['fecha_vencimiento']),
            'por_atender' => $porAtender,
            'urgentes'   => count($urgentes),
            'ritmo'      => $this->ritmo($pid),
            'solicitudes' => $this->solicitudes($pid),
            'archivos'   => $this->archivos($pid),
            'actividad'  => $this->actividad($pid),
            'contactos'  => (int) ($proyecto['con_leads'] ?? 0) === 1 ? $this->contactos((string) $proyecto['cliente_id'], [$pid]) : null,
        ];
    }

    // ---- Calendario del mes (portal del cliente) --------------------------------------------

    /**
     * Lo que cae en cada fecha del calendario: tareas (del cliente y del equipo), reuniones, hitos y piezas para revisar.
     *
     * @param array<string, mixed> $d de Cronograma::datos($proyecto, true)
     * @return array<string, array<int, array<string, string|null>>> fecha => eventos
     */
    private function eventosDelCalendario(array $d): array
    {
        $eventos = [];
        $poner = function (string $fecha, string $tipo, string $titulo, ?string $url, string $hora = '') use (&$eventos): void {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) === 1) {
                $eventos[$fecha][] = ['tipo' => $tipo, 'titulo' => $titulo, 'url' => $url, 'hora' => $hora];
            }
        };
        foreach ($d['hitos'] ?? [] as $h) {
            $poner((string) $h['fecha'], 'hito', (string) $h['nombre'], null);
        }
        foreach ($d['reuniones'] ?? [] as $r) {
            $poner((string) $r['fecha'], $r['es_hito'] ? 'hito' : 'reunion', (string) $r['titulo'], '/portal/reuniones/' . $r['id'], (string) $r['hora']);
        }
        foreach ($d['entregas'] ?? [] as $e) {
            $poner((string) $e['fecha'], 'revisar', (string) $e['titulo'], '/portal/entregas/' . $e['id']);
        }
        foreach ($d['tareas'] ?? [] as $t) {
            if ($t['estado'] !== 'hecha' && $t['fin'] !== null) {
                $poner((string) $t['fin'], $t['quien'] === 'cliente' ? 'tarea' : 'equipo', (string) $t['titulo'], '/portal/tareas/' . $t['id']);
            }
        }
        return $eventos;
    }

    /**
     * Una semana (de lunes a domingo) como agenda: es la vista del celular, donde la grilla del mes no deja leer los títulos.
     *
     * @param array<string, mixed> $d de Cronograma::datos($proyecto, true)
     * @param string $desde cualquier fecha 'Y-m-d' de la semana pedida (si no es válida, la de hoy)
     * @return array<string, mixed>
     */
    public function semana(array $d, string $desde = ''): array
    {
        $hoy = $this->hoy->format('Y-m-d');
        $ref = preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) === 1 ? $desde : $hoy;
        try {
            $lunes = (new \DateTimeImmutable($ref))->modify('monday this week');
        } catch (\Throwable) {
            $lunes = $this->hoy->modify('monday this week');
        }
        $eventos = $this->eventosDelCalendario($d);
        $dias = [];
        $nombres = Idioma::lista(['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo']);
        $meses = Idioma::lista(['', 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic']);
        for ($i = 0; $i < 7; $i++) {
            $dia = $lunes->modify('+' . $i . ' days');
            $f = $dia->format('Y-m-d');
            $dias[] = ['fecha' => $f, 'nombre' => $nombres[$i], 'dia' => (int) $dia->format('j'), 'mes' => $meses[(int) $dia->format('n')], 'hoy' => $f === $hoy, 'eventos' => $eventos[$f] ?? []];
        }
        $fin = $lunes->modify('+6 days');
        return [
            'desde' => $lunes->format('Y-m-d'), 'hasta' => $fin->format('Y-m-d'),
            'titulo' => (int) $lunes->format('j') . ' ' . $meses[(int) $lunes->format('n')] . ' – ' . (int) $fin->format('j') . ' ' . $meses[(int) $fin->format('n')],
            'anterior' => $lunes->modify('-7 days')->format('Y-m-d'), 'siguiente' => $lunes->modify('+7 days')->format('Y-m-d'),
            'es_actual' => $lunes->format('Y-m-d') === $this->hoy->modify('monday this week')->format('Y-m-d'), 'dias' => $dias,
        ];
    }

    /**
     * Un mes en forma de calendario (semanas de lunes a domingo) con lo que cae en cada día: tareas que le tocan al
     * cliente y del equipo, reuniones, hitos y piezas para revisar (lo que se publica en redes no va aquí).
     *
     * @param array<string, mixed> $d de Cronograma::datos($proyecto, true)
     * @param string $mes 'Y-m' (si no es válido, el de hoy)
     * @return array<string, mixed>
     */
    public function mes(array $d, string $mes = ''): array
    {
        $hoy = $this->hoy->format('Y-m-d');
        $mes = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes) === 1 ? $mes : substr($hoy, 0, 7);
        $primero = new \DateTimeImmutable($mes . '-01');

        $eventos = $this->eventosDelCalendario($d);

        $lunes = $primero->modify('monday this week');
        $ultimo = $primero->modify('last day of this month');
        $fin = $ultimo->modify('sunday this week');
        $semanas = [];
        $lista = [];
        for ($ini = $lunes; $ini <= $fin; $ini = $ini->modify('+7 days')) {
            $fila = [];
            for ($i = 0; $i < 7; $i++) {
                $dia = $ini->modify('+' . $i . ' days');
                $f = $dia->format('Y-m-d');
                $delMes = $dia->format('Y-m') === $mes;
                $ev = $eventos[$f] ?? [];
                $fila[] = ['fecha' => $f, 'dia' => (int) $dia->format('j'), 'del_mes' => $delMes, 'hoy' => $f === $hoy, 'eventos' => $ev];
                if ($delMes) {
                    foreach ($ev as $x) {
                        $lista[] = ['fecha' => $f] + $x;
                    }
                }
            }
            $semanas[] = $fila;
        }
        $nombres = Idioma::lista(['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre']);
        return [
            'mes' => $mes, 'titulo' => $nombres[(int) $primero->format('n')] . ' ' . $primero->format('Y'),
            'anterior' => $primero->modify('-1 month')->format('Y-m'), 'siguiente' => $primero->modify('+1 month')->format('Y-m'),
            'nombre_anterior' => $nombres[(int) $primero->modify('-1 month')->format('n')], 'nombre_siguiente' => $nombres[(int) $primero->modify('+1 month')->format('n')],
            'es_actual' => $mes === substr($hoy, 0, 7), 'semanas' => $semanas, 'lista' => $lista,
        ];
    }

    // ---- Portal del cliente -----------------------------------------------------------------

    /**
     * «Tus proyectos»: una tarjeta por proyecto con su avance, la etapa en curso y lo que le toca a la persona.
     *
     * @param array<int, array<string, mixed>> $proyectos del cliente
     * @param array<int, array<string, mixed>> $fases de esos proyectos
     * @param array<int, array<string, mixed>> $tareas visibles para el cliente (con 'turno' calculado por el controlador en 'le_toca')
     * @return array<int, array<string, mixed>> ordenadas: primero donde más le toca actuar
     */
    public function paraCliente(array $proyectos, array $fases, array $tareas, string $clienteId, string $contactoId): array
    {
        $out = [];
        foreach ($proyectos as $p) {
            $pid = (string) $p['id'];
            $tt = array_values(array_filter($tareas, fn(array $t): bool => $t['proyecto_id'] === $pid));
            $prog = ProgresoService::calcular(array_values(array_filter($fases, fn(array $f): bool => $f['proyecto_id'] === $pid)), $tt);
            $cont = $this->contenidos($pid);
            $revisar = $cont['pendiente'];
            $meTocan = count(array_filter($tt, fn(array $t): bool => !empty($t['le_toca'])));
            $base = $prog['total'] + $cont['total'] - $cont['borrador'];
            $pct = $base > 0 ? (int) round(($prog['hechas'] + $cont['aprobado']) * 100 / $base) : $prog['pct'];
            $actual = null;
            $sigue = null;
            foreach ($prog['fases'] as $i => $f) {
                if ($f['estado'] === 'actual') {
                    $actual = ['n' => $i + 1, 'nombre' => $f['nombre']];
                    $sigue = $prog['fases'][$i + 1] ?? null;
                    break;
                }
            }
            $reun = $this->reuniones($pid, true);
            $out[] = [
                'id' => $pid, 'nombre' => (string) $p['nombre'], 'estado' => (string) $p['estado'], 'pct' => $pct,
                'barras' => $this->barras($prog), 'fases' => $prog['fases'], 'actual' => $actual, 'sigue' => $sigue, 'completo' => $prog['completo'],
                'me_tocan' => $meTocan, 'revisar' => $revisar, 'fechas' => $this->fechas($prog),
                'reunion' => $reun['proxima'], 'con_leads' => (int) ($p['con_leads'] ?? 0) === 1,
            ];
        }
        usort($out, fn(array $a, array $b): int => ($b['me_tocan'] + $b['revisar']) <=> ($a['me_tocan'] + $a['revisar']));
        return $out;
    }
}

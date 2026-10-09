<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * Plantillas de proyecto: la estructura de un tipo de trabajo (Web, Branding, Campaña digital…)
 * para armar un proyecto nuevo en un paso.
 *
 * Estructura (JSON):
 *   fases:  [{nombre}]
 *   tareas: [{titulo, fase (índice o null), dias (hábiles), quien (equipo|cliente), tras?, desde?}]
 *           · desde: N  → fechas fijas, N días hábiles después del inicio del proyecto.
 *           · tras: i   → parte cuando termina la tarea i (dependencia).
 *           · sin ninguna de las dos → parte cuando termina la anterior de la lista
 *             (la primera de todas parte el día de inicio).
 *   hitos:  [{nombre, fase (índice) | desde (días hábiles)}] · con fase: el día que termina esa fase.
 */
class PlantillaService
{
    public const TABLE = 'portal_plantillas';

    public function __construct(private readonly \PDO $pdo) {}

    /** @return list<array<string, mixed>> */
    public function lista(): array
    {
        Schema::asegurar($this->pdo);
        $st = $this->pdo->query('SELECT * FROM ' . self::TABLE . ' ORDER BY orden, nombre');
        return array_map(fn(array $p): array => $p + ['e' => self::decodificar((string) $p['estructura'])], $st ? $st->fetchAll() : []);
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        Schema::asegurar($this->pdo);
        $st = $this->pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = ?');
        $st->execute([$id]);
        $p = $st->fetch();
        return $p !== false ? $p + ['e' => self::decodificar((string) $p['estructura'])] : null;
    }

    /** @return array{fases: list<array<string, mixed>>, tareas: list<array<string, mixed>>, hitos: list<array<string, mixed>>} */
    public static function decodificar(string $json): array
    {
        $e = json_decode($json, true);
        $e = is_array($e) ? $e : [];
        return ['fases' => array_values($e['fases'] ?? []), 'tareas' => array_values($e['tareas'] ?? []), 'hitos' => array_values($e['hitos'] ?? [])];
    }

    /**
     * Duración aproximada de la plantilla en días hábiles (simulando la cadena de tareas).
     * @param array<string, mixed> $e
     */
    public static function diasTotales(array $e): int
    {
        $fin = [];
        foreach ($e['tareas'] as $i => $t) {
            $dias = max(1, (int) ($t['dias'] ?? 1));
            if (isset($t['desde'])) {
                $ini = (int) $t['desde'];
            } else {
                $prev = isset($t['tras']) ? (int) $t['tras'] : $i - 1;
                $ini = $prev >= 0 && isset($fin[$prev]) ? $fin[$prev] : 0;
            }
            $fin[$i] = $ini + $dias;
        }
        return $fin !== [] ? max($fin) : 0;
    }

    /** @param array<string, mixed> $estructura */
    public function crear(string $nombre, string $descripcion, array $estructura, int $orden = 100): ?string
    {
        Schema::asegurar($this->pdo);
        $nombre = Fmt::mayusculaInicial(mb_substr(trim($nombre), 0, 255));
        if ($nombre === '' || ($estructura['tareas'] ?? []) === []) {
            return null;
        }
        $id = typedock_uuid7();
        $ahora = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->pdo->prepare('INSERT INTO ' . self::TABLE . ' (id, nombre, descripcion, estructura, orden, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$id, $nombre, mb_substr(trim($descripcion), 0, 500), json_encode($estructura, JSON_UNESCAPED_UNICODE), $orden, $ahora, $ahora]);
        return $id;
    }

    public function renombrar(string $id, string $nombre, string $descripcion): void
    {
        $nombre = Fmt::mayusculaInicial(mb_substr(trim($nombre), 0, 255));
        if ($nombre !== '') {
            $this->pdo->prepare('UPDATE ' . self::TABLE . ' SET nombre = ?, descripcion = ?, updated_at = ? WHERE id = ?')
                ->execute([$nombre, mb_substr(trim($descripcion), 0, 500), (new \DateTimeImmutable())->format('Y-m-d H:i:s'), $id]);
        }
    }

    public function borrar(string $id): void
    {
        $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE id = ?')->execute([$id]);
    }

    /**
     * Arma el proyecto con la plantilla: crea fases, tareas e hitos a partir de $inicio.
     * Devuelve cuántas tareas creó.
     */
    public function aplicar(string $plantillaId, string $proyectoId, string $inicio): int
    {
        $p = $this->find($plantillaId);
        if ($p === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $inicio) !== 1) {
            return 0;
        }
        $e = $p['e'];
        $inicio = Cronograma::habilDesde($inicio);
        $tope = (int) ($this->pdo->query('SELECT COALESCE(MAX(orden), 0) FROM portal_fases WHERE proyecto_id = ' . $this->pdo->quote($proyectoId))?->fetchColumn() ?: 0);

        $fases = new FaseService($this->pdo);
        $faseIds = [];
        foreach ($e['fases'] as $i => $f) {
            $faseIds[$i] = $fases->create(['proyecto_id' => $proyectoId, 'nombre' => Fmt::mayusculaInicial((string) ($f['nombre'] ?? 'Fase')), 'orden' => $tope + $i + 1]);
        }

        $ts = new TareaService($this->pdo);
        $ids = [];
        foreach ($e['tareas'] as $i => $t) {
            $dias = max(1, min(250, (int) ($t['dias'] ?? 1)));
            $d = [
                'proyecto_id' => $proyectoId,
                'titulo' => (string) ($t['titulo'] ?? 'Tarea'),
                'fase_id' => isset($t['fase']) && isset($faseIds[(int) $t['fase']]) ? $faseIds[(int) $t['fase']] : '',
                'asignado' => ($t['quien'] ?? '') === 'cliente' ? 'cliente' : 'equipo',
                'visible_cliente' => '1',
                'tipo' => 'tarea',
            ];
            $previa = isset($t['tras']) ? ($ids[(int) $t['tras']] ?? null) : ($i > 0 ? ($ids[$i - 1] ?? null) : null);
            if (isset($t['desde']) || $previa === null) {
                $ini = Cronograma::habilDesde(Cronograma::finTras($inicio, max(1, (int) ($t['desde'] ?? 0) + 1)));
                $d += ['fecha_inicio' => $ini, 'fecha_vencimiento' => Cronograma::finTras($ini, $dias)];
            } else {
                $d += ['depende_de' => $previa, 'duracion_dias' => $dias];
            }
            $ids[$i] = $ts->create($d);
        }

        // Hitos: al terminar su fase (según la estimación) o N días hábiles después del inicio.
        $datos = (new Cronograma($this->pdo))->datos($proyectoId);
        $finFase = array_column($datos['fases'], 'fin', 'id');
        $hs = new HitoService($this->pdo);
        foreach ($e['hitos'] as $h) {
            $fase = isset($h['fase']) ? ($faseIds[(int) $h['fase']] ?? null) : null;
            $fecha = $fase !== null ? ($finFase[$fase] ?? null) : Cronograma::finTras($inicio, max(1, (int) ($h['desde'] ?? 0) + 1));
            if ($fecha !== null) {
                $hs->create(['proyecto_id' => $proyectoId, 'nombre' => (string) ($h['nombre'] ?? 'Hito'), 'fecha' => $fecha, 'fase_id' => $fase ?? '', 'visible_cliente' => '1']);
            }
        }
        return count($ids);
    }

    /**
     * La estructura de un proyecto existente, para guardarla como plantilla:
     * sus fases, tareas (duración, de quién, dependencias) e hitos, sin fechas ni datos del cliente.
     * @return array<string, mixed>
     */
    public function estructuraDe(string $proyectoId): array
    {
        $d = (new Cronograma($this->pdo))->datos($proyectoId);
        $fases = [];
        $idxFase = [];
        foreach ($d['fases'] as $f) {
            $idxFase[$f['id']] = count($fases);
            $fases[] = ['nombre' => $f['nombre']];
        }
        // Tareas en el orden de la línea: por fase y luego las sin fase.
        $lista = [];
        foreach ($d['fases'] as $f) {
            array_push($lista, ...$f['tareas']);
        }
        array_push($lista, ...$d['sinFase']);
        $inicio = null;
        foreach ($lista as $t) {
            if ($t['ini'] !== null && !$t['depende_de'] && ($inicio === null || $t['ini'] < $inicio)) {
                $inicio = $t['ini'];
            }
        }
        $inicio ??= $d['hoy'];
        $idx = array_flip(array_column($lista, 'id'));
        $tareas = [];
        foreach ($lista as $t) {
            $x = ['titulo' => $t['titulo'], 'fase' => $t['fase_id'] !== null ? ($idxFase[$t['fase_id']] ?? null) : null,
                'dias' => $t['duracion'] ?? ($t['ini'] !== null ? Cronograma::habilesEntre($t['ini'], (string) $t['fin']) : 1), 'quien' => $t['quien']];
            if ($t['depende_de'] && isset($idx[$t['depende_de']])) {
                $x['tras'] = $idx[$t['depende_de']];
            } elseif ($t['ini'] !== null) {
                $x['desde'] = max(0, Cronograma::habilesEntre($inicio, $t['ini']) - 1);
            } elseif ($tareas === []) {
                $x['desde'] = 0;
            }
            $tareas[] = $x;
        }
        $hitos = [];
        foreach ($d['hitos'] as $h) {
            $hitos[] = $h['fase_id'] !== null && isset($idxFase[$h['fase_id']])
                ? ['nombre' => $h['nombre'], 'fase' => $idxFase[$h['fase_id']]]
                : ['nombre' => $h['nombre'], 'desde' => max(0, Cronograma::habilesEntre($inicio, $h['fecha']) - 1)];
        }
        return ['fases' => $fases, 'tareas' => $tareas, 'hitos' => $hitos];
    }

    /** Las tres de ejemplo, la primera vez. Se pueden borrar o reemplazar por las propias. */
    public static function sembrar(\PDO $pdo): void
    {
        // Una sola vez por instalación: si después las borran todas, no vuelven.
        $ajustes = new AjustesService($pdo);
        try {
            if ($ajustes->get('global', 'portal', 'plantillas_sembradas') === '1') {
                return;
            }
        } catch (\Throwable) {
            return;   // sin tabla de ajustes todavía
        }
        $ajustes->set('global', 'portal', 'plantillas_sembradas', '1');
        $st = $pdo->query('SELECT COUNT(*) FROM ' . self::TABLE);
        if ($st !== false && (int) $st->fetchColumn() > 0) {
            return;
        }
        $svc = new self($pdo);
        foreach (self::EJEMPLOS as $i => [$nombre, $desc, $fases, $hitos]) {
            $f = [];
            $t = [];
            foreach ($fases as $nf => [$nombreFase, $tareas]) {
                $f[] = ['nombre' => $nombreFase];
                foreach ($tareas as $tarea) {
                    $x = ['titulo' => $tarea[0], 'fase' => $nf, 'dias' => $tarea[1], 'quien' => $tarea[2] ?? 'equipo'];
                    if (isset($tarea[3])) {
                        $x['tras'] = $tarea[3];   // en paralelo: parte tras esa tarea (índice global)
                    }
                    $t[] = $x;
                }
            }
            $svc->crear($nombre, $desc, ['fases' => $f, 'tareas' => $t, 'hitos' => array_map(fn($h) => ['nombre' => $h[0], 'fase' => $h[1]], $hitos)], $i + 1);
        }
    }

    /**
     * [nombre, descripción, fases: [[nombre, [[tarea, días hábiles, quién, tras?]]]], hitos: [[nombre, índice de fase]]]
     * Las tareas van en cadena (cada una parte cuando termina la anterior), salvo las que dicen «tras».
     */
    private const EJEMPLOS = [
        ['Sitio web', 'Del levantamiento a la publicación: diseño, contenidos, desarrollo y lanzamiento.', [
            ['Brief', [['Reunión de levantamiento', 1], ['Cuestionario del sitio', 3, 'cliente'], ['Mapa del sitio', 2]]],
            ['Diseño', [['Propuesta visual de la portada', 5], ['Revisión de la propuesta', 2, 'cliente'], ['Ajustes a la propuesta', 2], ['Diseño de páginas interiores', 5]]],
            ['Contenidos', [['Entrega de textos e imágenes', 5, 'cliente', 2]]],
            ['Desarrollo', [['Maquetación', 10, 'equipo', 6], ['Carga de contenidos', 3]]],
            ['Lanzamiento', [['Revisión final del sitio', 3, 'cliente'], ['Correcciones', 2], ['Publicación', 1]]],
        ], [['Diseño aprobado', 1], ['Sitio publicado', 4]]],
        ['Branding', 'Identidad de marca: descubrimiento, concepto, logo, sistema y manual.', [
            ['Descubrimiento', [['Reunión de levantamiento', 1], ['Cuestionario de marca', 3, 'cliente'], ['Investigación y referentes', 3]]],
            ['Concepto', [['Moodboard', 2], ['Revisión del moodboard', 2, 'cliente']]],
            ['Logo', [['Propuestas de logo (2 a 3 rutas)', 5], ['Elección de ruta', 2, 'cliente'], ['Desarrollo de la ruta elegida', 4], ['Aprobación del logo', 2, 'cliente']]],
            ['Sistema de marca', [['Paleta y tipografías', 2], ['Aplicaciones', 4], ['Manual de marca', 4]]],
            ['Entrega', [['Revisión final', 2, 'cliente'], ['Entrega de archivos', 1]]],
        ], [['Logo aprobado', 2], ['Marca entregada', 4]]],
        ['Campaña digital', 'Estrategia, producción de piezas, lanzamiento y seguimiento con informe.', [
            ['Estrategia', [['Brief de la campaña', 1], ['Material de la marca y accesos', 3, 'cliente'], ['Estrategia y calendario', 3], ['Aprobación de la estrategia', 2, 'cliente']]],
            ['Producción', [['Diseño de piezas', 5], ['Copys', 3, 'equipo', 3], ['Revisión de piezas', 2, 'cliente', 4], ['Ajustes', 2]]],
            ['Lanzamiento', [['Configuración de la pauta', 2], ['Publicación', 1]]],
            ['Seguimiento', [['Monitoreo y optimización', 10], ['Informe de resultados', 2]]],
        ], [['Estrategia aprobada', 0], ['Campaña en el aire', 2], ['Informe entregado', 3]]],
    ];
}

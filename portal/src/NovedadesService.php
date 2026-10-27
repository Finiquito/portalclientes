<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * Lo nuevo para cada persona del cliente.
 *
 * Guarda cuándo abrió cada solicitud, tarea, revisión o contenido (portal_vistos) y con eso
 * sabe qué movió el equipo después. Arma también el cuadro «Novedades» de Inicio: una línea
 * por cosa (no por evento), separada por tipo, con su estado actual y un resumen de lo que pasó.
 */
final class NovedadesService
{
    public const TABLE = 'portal_vistos';

    /** Lo que el equipo hizo hace más de esto no se marca como nuevo (aunque nunca lo haya abierto). */
    public const DIAS_NUEVO = 30;

    /** Secciones del cuadro, en orden. */
    public const SECCIONES = [
        'solicitud' => 'Solicitudes',
        'revision'  => 'Revisiones',
        'tarea'     => 'Tareas',
        'reunion'   => 'Reuniones',
    ];

    public function __construct(private readonly \PDO $pdo) {}

    private static function ahora(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }

    private static function desde(): string
    {
        return (new \DateTimeImmutable('-' . self::DIAS_NUEVO . ' days'))->format('Y-m-d H:i:s');
    }

    // ---- Vistos ---------------------------------------------------------------------

    public function marcar(string $contactoId, string $tipo, string $id): void
    {
        if ($contactoId === '' || $id === '') {
            return;
        }
        $up = $this->pdo->prepare('UPDATE ' . self::TABLE . ' SET visto_en = ? WHERE contacto_id = ? AND entidad_tipo = ? AND entidad_id = ?');
        $up->execute([self::ahora(), $contactoId, $tipo, $id]);
        if ($up->rowCount() > 0) {
            return;
        }
        try {
            $this->pdo->prepare('INSERT INTO ' . self::TABLE . ' (contacto_id, entidad_tipo, entidad_id, visto_en) VALUES (?, ?, ?, ?)')
                ->execute([$contactoId, $tipo, $id, self::ahora()]);
        } catch (\PDOException) {
            // Ya estaba (MySQL no cuenta la fila si la hora no cambió): nada que hacer.
        }
    }

    /** @return array<string, string> «tipo:id» => visto_en */
    public function vistos(string $contactoId): array
    {
        $st = $this->pdo->prepare('SELECT entidad_tipo, entidad_id, visto_en FROM ' . self::TABLE . ' WHERE contacto_id = ?');
        $st->execute([$contactoId]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[$r['entidad_tipo'] . ':' . $r['entidad_id']] = (string) $r['visto_en'];
        }
        return $out;
    }

    // ---- Solicitudes -----------------------------------------------------------------

    /**
     * Estado de cada solicitud para esta persona: si le toca hacer algo (aprobar una cotización,
     * revisar lo que entregamos) y si hay movimientos del equipo que todavía no ha visto.
     *
     * @return array<string, array{toca: bool, nueva: bool}>
     */
    public function solicitudes(string $clienteId, string $contactoId): array
    {
        $st = $this->pdo->prepare(
            "SELECT s.id, s.estado, s.tarea_id, t.estado AS tarea_estado, v.visto_en,
                    (SELECT MAX(a.created_at) FROM portal_actividad a
                      WHERE a.cliente_id = s.cliente_id AND a.actor_tipo = 'equipo' AND a.created_at >= ?
                        AND ((a.entidad_tipo = 'solicitud' AND a.entidad_id = s.id) OR (a.entidad_tipo = 'tarea' AND a.entidad_id = s.tarea_id))) AS ultimo_equipo
             FROM portal_solicitudes s
             LEFT JOIN portal_tareas t ON t.id = s.tarea_id
             LEFT JOIN " . self::TABLE . " v ON v.contacto_id = ? AND v.entidad_tipo = 'solicitud' AND v.entidad_id = s.id
             WHERE s.cliente_id = ?"
        );
        $st->execute([self::desde(), $contactoId, $clienteId]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[(string) $r['id']] = [
                'toca'  => self::meToca($r),
                'nueva' => $r['ultimo_equipo'] !== null && ($r['visto_en'] === null || (string) $r['ultimo_equipo'] > (string) $r['visto_en']),
            ];
        }
        return $out;
    }

    /** Le toca al cliente: aprobar una cotización o revisar lo que le entregamos. */
    public static function meToca(array $s): bool
    {
        return $s['estado'] === 'cotizada' || ($s['estado'] === 'en_curso' && ($s['tarea_estado'] ?? '') === 'entregada');
    }

    /** Para la barra lateral: cuántas solicitudes tienen algo que hacer o algo nuevo. */
    public function contarSolicitudes(string $clienteId, string $contactoId): int
    {
        return count(array_filter($this->solicitudes($clienteId, $contactoId), fn(array $x): bool => $x['toca'] || $x['nueva']));
    }

    /** Estado que ve el cliente en la lista: [etiqueta, tono]. */
    public static function estadoSolicitud(array $s): array
    {
        if ($s['estado'] === 'en_curso') {
            return match ($s['tarea_estado'] ?? '') {
                'entregada' => [Idioma::t('Te toca revisar'), 'warn'],
                'cambios'   => [Idioma::t('Aplicando tus cambios'), 'info'],
                'hecha'     => [Idioma::t('Lista'), 'ok'],
                default     => Idioma::lista(SolicitudService::ESTADOS_CLIENTE)['en_curso'],
            };
        }
        return Idioma::lista(SolicitudService::ESTADOS_CLIENTE)[$s['estado']] ?? ['—', 'muted'];
    }

    // ---- Cuadro de Inicio ----------------------------------------------------------------

    /**
     * Últimos movimientos agrupados por cosa y separados por sección.
     *
     * @return array<string, array<int, array<string, mixed>>> sección => ítems
     */
    public function cuadro(string $clienteId, string $contactoId, int $maxItems = 8): array
    {
        $st = $this->pdo->prepare('SELECT * FROM portal_actividad WHERE cliente_id = ? ORDER BY created_at DESC, id DESC LIMIT 80');
        $st->execute([$clienteId]);
        $eventos = $st->fetchAll();
        if ($eventos === []) {
            return [];
        }

        // Las tareas que nacieron de una solicitud se muestran dentro de la solicitud.
        $sols = [];
        $q = $this->pdo->prepare('SELECT s.id, s.titulo, s.estado, s.tarea_id, t.estado AS tarea_estado FROM portal_solicitudes s LEFT JOIN portal_tareas t ON t.id = s.tarea_id WHERE s.cliente_id = ?');
        $q->execute([$clienteId]);
        $porTarea = [];
        foreach ($q->fetchAll() as $s) {
            $sols[(string) $s['id']] = $s;
            if ($s['tarea_id']) {
                $porTarea[(string) $s['tarea_id']] = (string) $s['id'];
            }
        }

        $grupos = [];
        foreach ($eventos as $a) {
            $tipo = (string) ($a['entidad_tipo'] ?? '');
            $id   = (string) ($a['entidad_id'] ?? '');
            if ($tipo === 'tarea' && isset($porTarea[$id])) {
                [$tipo, $id] = ['solicitud', $porTarea[$id]];
            }
            $clave = $tipo . ':' . ($id !== '' ? $id : (string) $a['id']);
            if (!isset($grupos[$clave])) {
                if (count($grupos) >= $maxItems) {
                    continue;
                }
                $grupos[$clave] = ['tipo' => $tipo, 'id' => $id, 'titulo' => (string) $a['titulo'], 'fecha' => (string) $a['created_at'], 'eventos' => []];
            }
            $grupos[$clave]['eventos'][] = $a;
        }

        $vistos = $this->vistos($contactoId);
        $desde = self::desde();
        $estados = $this->estados($grupos, $sols);
        $out = [];
        foreach ($grupos as $clave => $g) {
            $seccion = match ($g['tipo']) {
                'solicitud' => 'solicitud',
                'entrega', 'contenido' => 'revision',
                'reunion' => 'reunion',
                default => 'tarea',
            };
            $ultimoEquipo = null;
            foreach ($g['eventos'] as $a) {
                if ($a['actor_tipo'] === 'equipo') {
                    $ultimoEquipo = max($ultimoEquipo ?? '', (string) $a['created_at']);
                }
            }
            $visto = $vistos[$clave] ?? null;
            $out[$seccion][] = [
                'tipo'    => $g['tipo'],
                'id'      => $g['id'],
                'titulo'  => $g['tipo'] === 'solicitud' && isset($sols[$g['id']]) ? (string) $sols[$g['id']]['titulo'] : $g['titulo'],
                'url'     => self::url($g['tipo'], $g['id']),
                'fecha'   => $g['fecha'],
                'estado'  => $estados[$clave] ?? null,
                'resumen' => self::resumen($g['eventos']),
                'icono'   => (new Fmt())->iconoActividad((string) $g['eventos'][0]['accion']),
                'nueva'   => $g['tipo'] !== 'reunion' && $ultimoEquipo !== null && $ultimoEquipo >= $desde && ($visto === null || $ultimoEquipo > $visto),
            ];
        }
        $orden = [];
        foreach (array_keys(self::SECCIONES) as $s) {
            if (!empty($out[$s])) {
                $orden[$s] = $out[$s];
            }
        }
        return $orden;
    }

    private static function url(string $tipo, string $id): ?string
    {
        if ($id === '') {
            return null;
        }
        return match ($tipo) {
            'solicitud' => '/portal/solicitudes/' . $id,
            'tarea'     => '/portal/tareas/' . $id,
            'entrega'   => '/portal/entregas/' . $id,
            'contenido' => '/portal/contenidos/' . $id,
            'reunion'   => '/portal/reuniones/' . $id,
            default     => null,
        };
    }

    /**
     * Estado actual de cada cosa, en una consulta por tipo.
     *
     * @param array<string, array<string, mixed>> $grupos
     * @param array<string, array<string, mixed>> $sols
     * @return array<string, array{0: string, 1: string}>
     */
    private function estados(array $grupos, array $sols): array
    {
        $ids = [];
        foreach ($grupos as $g) {
            if ($g['id'] !== '') {
                $ids[$g['tipo']][] = $g['id'];
            }
        }
        $out = [];
        foreach ($ids['solicitud'] ?? [] as $id) {
            if (isset($sols[$id])) {
                $out['solicitud:' . $id] = self::estadoSolicitud($sols[$id]);
            }
        }
        $tablas = [
            'tarea'     => ['portal_tareas', Fmt::ESTADOS],
            'entrega'   => ['portal_entregas', Idioma::lista(TiposContenido::ESTADOS_ENTREGA)],
            'contenido' => ['portal_contenidos', Idioma::lista(TiposContenido::ESTADOS_CONTENIDO)],
        ];
        foreach ($tablas as $tipo => [$tabla, $etiquetas]) {
            if (empty($ids[$tipo])) {
                continue;
            }
            $st = $this->pdo->prepare("SELECT id, estado FROM {$tabla} WHERE id IN (" . implode(',', array_fill(0, count($ids[$tipo]), '?')) . ')');
            $st->execute($ids[$tipo]);
            foreach ($st->fetchAll() as $r) {
                if (isset($etiquetas[$r['estado']])) {
                    $out[$tipo . ':' . $r['id']] = $etiquetas[$r['estado']];
                }
            }
        }
        return $out;
    }

    /**
     * Qué pasó, en pocas palabras: «Te respondimos · 2 archivos · 1 comentario».
     *
     * @param array<int, array<string, mixed>> $eventos los de una cosa, del más nuevo al más viejo
     */
    public static function resumen(array $eventos): string
    {
        $partes = [];
        $cuenta = ['archivo' => 0, 'comentario' => 0];
        foreach ($eventos as $a) {
            $equipo = $a['actor_tipo'] === 'equipo';
            $texto = match ((string) $a['accion']) {
                'estado'        => Idioma::t('cambió de estado'),   // el estado actual va en el chip
                'atendio'       => Idioma::t('te respondimos'),
                'cotizo'        => Idioma::t('te enviamos la cotización'),
                'asigno'        => Idioma::t('te pedimos algo'),
                'publico'       => Idioma::t('te compartimos contenido'),
                'subio_version' => Idioma::t('versión nueva'),
                'actualizo'     => Idioma::t('actualizado'),
                'reunion'       => Idioma::t('novedades de la reunión'),
                'entrego'       => $equipo ? Idioma::t('entregado') : Idioma::t('%s entregó', self::nombre($a)),
                'completo'      => $equipo ? Idioma::t('completada') : Idioma::t('%s la completó', self::nombre($a)),
                'aprobo'        => Idioma::t('%s aprobó', self::nombre($a)),
                'pidio_cambios' => Idioma::t('%s pidió cambios', self::nombre($a)),
                'respondio'     => Idioma::t('%s envió su revisión', self::nombre($a)),
                'decidio'       => Idioma::t('%s respondió la cotización', self::nombre($a)),
                'solicito'      => Idioma::t('%s la pidió', self::nombre($a)),
                default         => null,
            };
            if ($a['accion'] === 'subio_archivo') {
                $cuenta['archivo']++;
            } elseif ($a['accion'] === 'comento') {
                $cuenta['comentario']++;
            } elseif ($texto !== null && !in_array($texto, $partes, true)) {
                $partes[] = $texto;
            }
        }
        if ($cuenta['archivo'] > 0) {
            $partes[] = $cuenta['archivo'] === 1 ? Idioma::t('1 archivo') : Idioma::t('%d archivos', $cuenta['archivo']);
        }
        if ($cuenta['comentario'] > 0) {
            $partes[] = $cuenta['comentario'] === 1 ? Idioma::t('1 comentario') : Idioma::t('%d comentarios', $cuenta['comentario']);
        }
        $s = implode(' · ', array_slice($partes, 0, 3));
        return $s === '' ? '' : mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
    }

    private static function nombre(array $a): string
    {
        return strtok(trim((string) $a['actor_nombre']), ' ') ?: Idioma::t('Alguien');
    }
}

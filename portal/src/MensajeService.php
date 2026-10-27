<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * Bandeja «Mensajes»: los comentarios de tareas, contenidos y solicitudes agrupados en una
 * conversación por cosa, para el cliente y para el equipo.
 *
 * - Sin leer: la otra parte escribió después de la última vez que esta persona abrió la
 *   conversación o la cosa (portal_vistos, la misma marca de Novedades).
 * - Archivada: la cosa terminó (tarea lista, contenido aprobado, solicitud cerrada) y no
 *   quedan mensajes sin leer. Si alguien vuelve a escribir, vuelve a Activas.
 * - Responder usa las mismas acciones de comentar de cada cosa (avisos y actividad incluidos);
 *   sólo cambia a dónde se vuelve (ver destino()).
 */
final class MensajeService
{
    public const TIPOS = ['tarea' => 'Tarea', 'contenido' => 'Contenido', 'solicitud' => 'Solicitud'];

    /** Reacciones disponibles: clave => [emoji, nombre]. Se guarda la clave. Las últimas son las menos positivas. */
    public const REACCIONES = [
        'pulgar' => ['👍', 'Me parece bien'], 'aplauso' => ['👏', 'Aplausos'], 'corazon' => ['❤️', 'Me encanta'], 'fiesta' => ['🎉', 'A celebrar'],
        'listo' => ['✅', 'Listo'], 'gracias' => ['🙏', 'Gracias'], 'risa' => ['😄', 'Me da risa'], 'ojos' => ['👀', 'Lo reviso'],
        'asombro' => ['😮', 'Sorpresa'], 'duda' => ['😕', 'No me convence'], 'triste' => ['😢', 'Me da pena'], 'pulgar_abajo' => ['👎', 'No me gusta'],
    ];

    /** Segundos que una conversación terminada y recién leída sigue en «Activas». */
    public const GRACIA_ARCHIVO = 120;

    public function __construct(private readonly \PDO $pdo) {}

    /**
     * Conversaciones que ve una persona, de la más reciente a la más antigua.
     *
     * @param string      $lado        'contacto' (cliente) o 'equipo': los mensajes del otro lado son los que cuentan como nuevos
     * @param string      $personaId   id del contacto o del usuario del equipo
     * @param string|null $clienteId   el cliente (portal); null = todos los que deja $puedeVer
     * @param \Closure|null $puedeVer  fn(array $cosa): bool — permisos del panel (proyecto o cliente asignado)
     * @return array<int, array<string, mixed>>
     */
    public function conversaciones(string $lado, string $personaId, ?string $clienteId, ?\Closure $puedeVer = null): array
    {
        $desde = (new \DateTimeImmutable('-' . NovedadesService::DIAS_NUEVO . ' days'))->format('Y-m-d H:i:s');
        $sql = "SELECT k.entidad_tipo, k.entidad_id, k.cliente_id, MAX(k.created_at) AS ultimo, COUNT(*) AS n, MAX(v.visto_en) AS visto_en,
                       SUM(CASE WHEN k.autor_tipo <> ? AND k.created_at >= ? AND (v.visto_en IS NULL OR k.created_at > v.visto_en) THEN 1 ELSE 0 END) AS sin_leer
                FROM portal_comentarios k
                LEFT JOIN " . NovedadesService::TABLE . " v ON v.contacto_id = ? AND v.entidad_tipo = k.entidad_tipo AND v.entidad_id = k.entidad_id
                WHERE k.entidad_tipo IN ('tarea', 'contenido', 'solicitud')" . ($clienteId !== null ? ' AND k.cliente_id = ?' : '') . '
                GROUP BY k.entidad_tipo, k.entidad_id, k.cliente_id';
        $st = $this->pdo->prepare($sql);
        $st->execute(array_merge([$lado, $desde, $personaId], $clienteId !== null ? [$clienteId] : []));
        $filas = $st->fetchAll();
        if ($filas === []) {
            return [];
        }

        $cosas = $this->cosas($filas, $lado);
        $dest = $this->destacadas($lado, $personaId);
        $graciaDesde = (new \DateTimeImmutable('-' . self::GRACIA_ARCHIVO . ' seconds'))->format('Y-m-d H:i:s');
        $out = [];
        foreach ($filas as $f) {
            $cosa = $cosas[$f['entidad_tipo'] . ':' . $f['entidad_id']] ?? null;
            if ($cosa === null) {
                continue;   // borrada
            }
            // El cliente sólo ve lo que se le mostró (tareas visibles, contenidos de revisiones publicadas).
            if ($lado === 'contacto' && !$cosa['visible']) {
                continue;
            }
            if ($puedeVer !== null && !$puedeVer($cosa)) {
                continue;
            }
            $sinLeer = (int) $f['sin_leer'];
            // Recién leída y ya terminada: se queda un momento en «Activas» para que se entienda que
            // pasa a «Archivadas» (en la lista se desvanece sola a los pocos segundos).
            $clave = $f['entidad_tipo'] . ':' . $f['entidad_id'];
            $destacada = isset($dest[$clave]);
            $recien = $cosa['terminada'] && $sinLeer === 0 && (string) ($f['visto_en'] ?? '') >= $graciaDesde;
            $out[] = $cosa + [
                'destacada'  => $destacada,
                'clave'      => $f['entidad_tipo'] . ':' . $f['entidad_id'],
                'ultimo'     => (string) $f['ultimo'],
                'n'          => (int) $f['n'],
                'sin_leer'   => $sinLeer,
                'archivada'  => $cosa['terminada'] && $sinLeer === 0 && !$recien && !$destacada,   // una destacada se queda en «Destacados»
                'archivando' => $recien,
            ];
        }
        usort($out, fn(array $a, array $b): int => strcmp($b['ultimo'], $a['ultimo']));
        return $out;
    }

    // ---- Destacadas -------------------------------------------------------------------------

    /** @return array<string, true> claves «tipo:id» de las conversaciones que esta persona destacó */
    public function destacadas(string $lado, string $personaId): array
    {
        try {
            $st = $this->pdo->prepare('SELECT entidad_tipo, entidad_id FROM portal_mensaje_destacadas WHERE persona_tipo = ? AND persona_id = ?');
            $st->execute([$lado, $personaId]);
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($st->fetchAll() as $f) {
            $out[$f['entidad_tipo'] . ':' . $f['entidad_id']] = true;
        }
        return $out;
    }

    /** Destaca o quita la estrella de una conversación. @return bool true si queda destacada */
    public function alternarDestacada(string $lado, string $personaId, string $tipo, string $id): bool
    {
        $st = $this->pdo->prepare('DELETE FROM portal_mensaje_destacadas WHERE persona_tipo = ? AND persona_id = ? AND entidad_tipo = ? AND entidad_id = ?');
        $st->execute([$lado, $personaId, $tipo, $id]);
        if ($st->rowCount() > 0) {
            return false;
        }
        $this->pdo->prepare('INSERT INTO portal_mensaje_destacadas (persona_tipo, persona_id, entidad_tipo, entidad_id, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$lado, $personaId, $tipo, $id, (new \DateTimeImmutable())->format('Y-m-d H:i:s')]);
        return true;
    }

    // ---- Reacciones -------------------------------------------------------------------------

    /**
     * Reacciones de un conjunto de mensajes: comentario_id => lista de [clave, emoji, n, mia, nombres].
     *
     * @param array<int, string> $comentarioIds
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function reacciones(array $comentarioIds, string $lado, string $personaId): array
    {
        if ($comentarioIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($comentarioIds), '?'));
        try {
            $st = $this->pdo->prepare("SELECT r.comentario_id, r.persona_tipo, r.persona_id, r.clave, COALESCE(c.nombre, e.nombre, '') AS nombre
                FROM portal_mensaje_reacciones r
                LEFT JOIN portal_contactos c ON r.persona_tipo = 'contacto' AND c.id = r.persona_id
                LEFT JOIN portal_equipo e ON r.persona_tipo = 'equipo' AND e.id = r.persona_id
                WHERE r.comentario_id IN ({$in}) ORDER BY r.created_at");
            $st->execute(array_values($comentarioIds));
        } catch (\Throwable) {
            return [];
        }
        $por = [];
        foreach ($st->fetchAll() as $f) {
            if (!isset(self::REACCIONES[$f['clave']])) {
                continue;
            }
            $x = &$por[$f['comentario_id']][$f['clave']];
            $x ??= ['clave' => $f['clave'], 'emoji' => self::REACCIONES[$f['clave']][0], 'titulo' => self::REACCIONES[$f['clave']][1], 'n' => 0, 'mia' => false, 'nombres' => []];
            $x['n']++;
            $mia = $f['persona_tipo'] === $lado && $f['persona_id'] === $personaId;
            $x['mia'] = $x['mia'] || $mia;
            $x['nombres'][] = $mia ? 'Tú' : strtok((string) $f['nombre'], ' ');
            unset($x);
        }
        return array_map('array_values', $por);
    }

    /** ¿Es este mensaje de esa conversación? (para no reaccionar a mensajes ajenos). */
    public function esDe(string $comentarioId, string $tipo, string $id): bool
    {
        $st = $this->pdo->prepare('SELECT 1 FROM portal_comentarios WHERE id = ? AND entidad_tipo = ? AND entidad_id = ?');
        $st->execute([$comentarioId, $tipo, $id]);
        return $st->fetchColumn() !== false;
    }

    /** Pone o quita una reacción. @return bool true si quedó puesta; false si se quitó o la clave no existe */
    public function alternarReaccion(string $comentarioId, string $clave, string $lado, string $personaId): bool
    {
        if (!isset(self::REACCIONES[$clave])) {
            return false;
        }
        $st = $this->pdo->prepare('DELETE FROM portal_mensaje_reacciones WHERE comentario_id = ? AND persona_tipo = ? AND persona_id = ? AND clave = ?');
        $st->execute([$comentarioId, $lado, $personaId, $clave]);
        if ($st->rowCount() > 0) {
            return false;
        }
        $this->pdo->prepare('INSERT INTO portal_mensaje_reacciones (comentario_id, persona_tipo, persona_id, clave, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$comentarioId, $lado, $personaId, $clave, (new \DateTimeImmutable())->format('Y-m-d H:i:s')]);
        return true;
    }

    // ---- Filtros de la bandeja --------------------------------------------------------------

    /**
     * Aplica pestaña, proyecto (o cliente) y tipo a las conversaciones.
     *
     * @param array<int, array<string, mixed>> $todas
     * @param string $campo 'proyecto_id' (portal del cliente) o 'cliente_id' (panel del equipo)
     * @return array<int, array<string, mixed>>
     */
    public static function filtrar(array $todas, string $pestana, string $campo, string $valor, string $tipo): array
    {
        return array_values(array_filter($todas, static function (array $x) use ($pestana, $campo, $valor, $tipo): bool {
            if ($valor !== '' && (string) $x[$campo] !== $valor) {
                return false;
            }
            if ($tipo !== '' && $x['tipo'] !== $tipo) {
                return false;
            }
            return match ($pestana) {
                'archivadas' => $x['archivada'],
                'destacadas' => $x['destacada'],
                default      => !$x['archivada'],
            };
        }));
    }

    /**
     * Opciones de los dos desplegables, que se complementan: los proyectos (o clientes) que quedan con el tipo elegido
     * y los tipos que quedan con el proyecto (o cliente) elegido, cada uno con cuántas conversaciones tiene.
     *
     * @param array<int, array<string, mixed>> $todas
     * @return array{grupos: array<string, array{nombre: string, n: int}>, tipos: array<string, int>}
     */
    public static function opciones(array $todas, string $campo, string $valor, string $tipo): array
    {
        $nombre = $campo === 'cliente_id' ? 'cliente_nombre' : 'proyecto_nombre';
        $grupos = [];
        $tipos = [];
        foreach ($todas as $x) {
            if ($tipo === '' || $x['tipo'] === $tipo) {
                $g = (string) $x[$campo];
                $grupos[$g] ??= ['nombre' => (string) ($x[$nombre] ?: 'Sin proyecto'), 'n' => 0];
                $grupos[$g]['n']++;
            }
            if ($valor === '' || (string) $x[$campo] === $valor) {
                $tipos[$x['tipo']] = ($tipos[$x['tipo']] ?? 0) + 1;
            }
        }
        uasort($grupos, fn(array $a, array $b): int => strcmp(mb_strtolower($a['nombre']), mb_strtolower($b['nombre'])));
        return ['grupos' => $grupos, 'tipos' => $tipos];
    }

    /** Cuántas conversaciones tienen mensajes nuevos de la otra parte (para el menú). */
    public static function sinLeer(array $conversaciones): int
    {
        return count(array_filter($conversaciones, fn(array $c): bool => $c['sin_leer'] > 0));
    }

    /**
     * Agrega a cada conversación su último mensaje (sólo a las que se van a mostrar).
     *
     * @param array<int, array<string, mixed>> $conversaciones
     * @return array<int, array<string, mixed>>
     */
    public function conUltimo(array $conversaciones): array
    {
        if ($conversaciones === []) {
            return [];
        }
        $cond = [];
        $args = [];
        foreach ($conversaciones as $c) {
            $cond[] = '(entidad_tipo = ? AND entidad_id = ?)';
            array_push($args, $c['tipo'], $c['id']);
        }
        $st = $this->pdo->prepare('SELECT entidad_tipo, entidad_id, autor_tipo, autor_id, autor_nombre, cuerpo, created_at, id FROM portal_comentarios WHERE ' . implode(' OR ', $cond) . ' ORDER BY created_at, id');
        $st->execute($args);
        $ult = [];
        foreach ($st->fetchAll() as $m) {
            $ult[$m['entidad_tipo'] . ':' . $m['entidad_id']] = $m;   // el último gana
        }
        foreach ($conversaciones as &$c) {
            $c['ultimo_msg'] = $ult[$c['clave']] ?? null;
        }
        return $conversaciones;
    }

    /** Mensajes de una conversación, del más antiguo al más nuevo. */
    public function hilo(string $tipo, string $id): array
    {
        return (new ComentarioService($this->pdo))->listar($tipo, $id);
    }

    /**
     * A dónde volver después de responder: la bandeja (con la conversación abierta) si el
     * formulario lo pidió y la dirección es de la bandeja; si no, la de siempre.
     */
    public static function destino(string $bandeja, string $porDefecto): string
    {
        $v = rawurldecode((string) ($_POST['volver'] ?? ''));
        if ($v === $bandeja || preg_match('#^' . preg_quote($bandeja, '#') . '\?c=(tarea|contenido|solicitud):[0-9a-f-]{36}(&[a-z]+=[a-z0-9_%.-]+)*$#i', $v) === 1) {
            return $v;
        }
        return $porDefecto;
    }

    /**
     * A dónde volver tras destacar o reaccionar: la misma bandeja, con la misma conversación y los mismos filtros.
     * Sólo se aceptan los parámetros de la bandeja, con valores simples.
     *
     * @return array<string, string>
     */
    public static function consultaDeVuelta(): array
    {
        parse_str((string) parse_url(rawurldecode((string) ($_POST['volver'] ?? '')), PHP_URL_QUERY), $q);
        $out = [];
        foreach (['c' => '#^(tarea|contenido|solicitud):[0-9a-f-]{36}$#i', 't' => '#^(archivadas|destacadas)$#', 'proyecto' => '#^[0-9a-f-]{0,36}$#i',
                  'cliente' => '#^[0-9a-f-]{0,36}$#i', 'tipo' => '#^(tarea|contenido|solicitud)$#', 'p' => '#^\d{1,4}$#'] as $k => $re) {
            if (isset($q[$k]) && is_string($q[$k]) && $q[$k] !== '' && preg_match($re, $q[$k]) === 1) {
                $out[$k] = $q[$k];
            }
        }
        return $out;
    }

    /**
     * Título, estado, proyecto y si está terminada / visible, de cada cosa con mensajes.
     *
     * @param array<int, array<string, mixed>> $filas
     * @return array<string, array<string, mixed>>
     */
    private function cosas(array $filas, string $lado): array
    {
        $ids = [];
        foreach ($filas as $f) {
            $ids[$f['entidad_tipo']][] = (string) $f['entidad_id'];
        }
        $in = fn(array $l): string => implode(',', array_fill(0, count($l), '?'));
        $out = [];

        if (!empty($ids['tarea'])) {
            $st = $this->pdo->prepare("SELECT t.id, t.titulo, t.estado, t.proyecto_id, p.cliente_id, p.nombre AS proyecto_nombre, c.nombre AS cliente_nombre,
                    (t.visible_cliente = 1 OR t.responsable_tipo = 'cliente') AS visible
                FROM portal_tareas t JOIN portal_proyectos p ON p.id = t.proyecto_id JOIN portal_clientes c ON c.id = p.cliente_id
                WHERE t.id IN (" . $in($ids['tarea']) . ')');
            $st->execute($ids['tarea']);
            foreach ($st->fetchAll() as $r) {
                $out['tarea:' . $r['id']] = [
                    'tipo' => 'tarea', 'id' => (string) $r['id'], 'titulo' => (string) $r['titulo'],
                    'estado' => Idioma::lista(Fmt::ESTADOS)[$r['estado']] ?? ['—', 'muted'], 'terminada' => $r['estado'] === 'hecha',
                    'visible' => (bool) $r['visible'], 'proyecto_id' => (string) $r['proyecto_id'], 'cliente_id' => (string) $r['cliente_id'],
                    'proyecto_nombre' => (string) $r['proyecto_nombre'], 'cliente_nombre' => (string) $r['cliente_nombre'],
                ];
            }
        }
        if (!empty($ids['contenido'])) {
            $st = $this->pdo->prepare('SELECT x.id, x.titulo, x.estado, e.proyecto_id, e.cliente_id, e.estado AS entrega_estado, e.titulo AS entrega_titulo,
                    p.nombre AS proyecto_nombre, c.nombre AS cliente_nombre
                FROM portal_contenidos x JOIN portal_entregas e ON e.id = x.entrega_id JOIN portal_proyectos p ON p.id = e.proyecto_id JOIN portal_clientes c ON c.id = e.cliente_id
                WHERE x.id IN (' . $in($ids['contenido']) . ')');
            $st->execute($ids['contenido']);
            foreach ($st->fetchAll() as $r) {
                $out['contenido:' . $r['id']] = [
                    'tipo' => 'contenido', 'id' => (string) $r['id'], 'titulo' => (string) $r['titulo'],
                    'estado' => Idioma::lista(TiposContenido::ESTADOS_CONTENIDO)[$r['estado']] ?? ['—', 'muted'], 'terminada' => $r['estado'] === 'aprobado',
                    'visible' => $r['entrega_estado'] !== 'borrador', 'proyecto_id' => (string) $r['proyecto_id'], 'cliente_id' => (string) $r['cliente_id'],
                    'proyecto_nombre' => (string) $r['proyecto_nombre'], 'cliente_nombre' => (string) $r['cliente_nombre'],
                ];
            }
        }
        if (!empty($ids['solicitud'])) {
            $st = $this->pdo->prepare('SELECT s.id, s.titulo, s.estado, s.proyecto_id, s.cliente_id, t.estado AS tarea_estado, p.nombre AS proyecto_nombre, c.nombre AS cliente_nombre
                FROM portal_solicitudes s LEFT JOIN portal_tareas t ON t.id = s.tarea_id LEFT JOIN portal_proyectos p ON p.id = s.proyecto_id JOIN portal_clientes c ON c.id = s.cliente_id
                WHERE s.id IN (' . $in($ids['solicitud']) . ')');
            $st->execute($ids['solicitud']);
            foreach ($st->fetchAll() as $r) {
                $terminada = in_array($r['estado'], ['respondida', 'rechazada', 'no_aprobada', 'agendada'], true)
                    || ($r['estado'] === 'en_curso' && $r['tarea_estado'] === 'hecha');
                $out['solicitud:' . $r['id']] = [
                    'tipo' => 'solicitud', 'id' => (string) $r['id'], 'titulo' => (string) $r['titulo'],
                    'estado' => $lado === 'contacto' ? NovedadesService::estadoSolicitud($r)
                        : ($r['estado'] === 'en_curso' && isset(Fmt::ESTADOS[$r['tarea_estado'] ?? '']) ? Idioma::lista(Fmt::ESTADOS)[$r['tarea_estado']] : (Idioma::lista(SolicitudService::ESTADOS)[$r['estado']] ?? ['—', 'muted'])),
                    'terminada' => $terminada,
                    'visible' => true, 'proyecto_id' => (string) $r['proyecto_id'], 'cliente_id' => (string) $r['cliente_id'],
                    'proyecto_nombre' => (string) ($r['proyecto_nombre'] ?? ''), 'cliente_nombre' => (string) $r['cliente_nombre'],
                ];
            }
        }
        return $out;
    }
}

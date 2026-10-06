<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * Reuniones: datos de agenda (con link de Meet), transcripción pegada,
 * resumen / acuerdos / análisis interno y la lista de tareas *propuestas*
 * que el equipo revisa antes de crearlas y mostrarlas al cliente.
 *
 * Reglas de visibilidad hacia el cliente:
 *  - la reunión se ve si `publicada = 1`;
 *  - resumen, acuerdos y grabación sólo si además `resumen_publicado = 1`;
 *  - la transcripción y el análisis interno NUNCA se muestran al cliente.
 */
class ReunionService
{
    public const TABLE  = 'portal_reuniones';
    public const PROP   = 'portal_reunion_propuestas';
    public const ZONA   = 'America/Santiago';

    public function __construct(private readonly \PDO $pdo) {}

    private static function ahora(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }

    /** @return array<array<string, mixed>> */
    public function listAll(): array
    {
        $stmt = $this->pdo->query(
            'SELECT r.*, p.nombre AS proyecto_nombre, p.cliente_id AS cliente_id, c.nombre AS cliente_nombre,
                    (SELECT COUNT(*) FROM ' . self::PROP . ' q WHERE q.reunion_id = r.id AND q.estado = \'propuesta\') AS n_propuestas
             FROM ' . self::TABLE . ' r
             JOIN portal_proyectos p ON p.id = r.proyecto_id
             JOIN portal_clientes c ON c.id = p.cliente_id
             ORDER BY r.fecha DESC'
        );
        return $stmt ? $stmt->fetchAll() : [];
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.*, p.nombre AS proyecto_nombre, p.cliente_id AS cliente_id, cl.nombre AS cliente_nombre
             FROM ' . self::TABLE . ' r
             JOIN portal_proyectos p ON p.id = r.proyecto_id
             JOIN portal_clientes cl ON cl.id = p.cliente_id
             WHERE r.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /** Reunión visible para un cliente (o null). */
    public function findDelCliente(string $id, string $clienteId): ?array
    {
        $r = $this->find($id);
        return $r !== null && $r['cliente_id'] === $clienteId && (int) $r['publicada'] === 1 ? $r : null;
    }

    // ---------------------------------------------------------------------
    // Normalización
    // ---------------------------------------------------------------------

    /** 'YYYY-MM-DDTHH:MM' / 'YYYY-MM-DD HH:MM' / 'YYYY-MM-DD' → formato guardado ('' si es inválida). */
    public static function normalizarFecha(string $s): string
    {
        $s = trim($s);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2}))?/', $s, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return '';
        }
        if (isset($m[4])) {
            if ((int) $m[4] > 23 || (int) $m[5] > 59) {
                return '';
            }
            return "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}";
        }
        return "{$m[1]}-{$m[2]}-{$m[3]}";
    }

    /** @param array<string, mixed> $p @return array<string, mixed> */
    /** Días después de los cuales una reunión pasada se archiva (sale de la lista principal). */
    public const DIAS_ARCHIVO = 20;

    /**
     * 'proxima', 'pasada' o 'archivada'. Una reunión ya pasó si terminó (fecha + duración) o si ya tiene
     * resumen o análisis. Las pasadas de más de 20 días se archivan solas. La fecha va en hora de la agencia.
     *
     * @param array<string, mixed> $r
     */
    public static function estado(array $r, ?\DateTimeImmutable $ahora = null): string
    {
        $zona = new \DateTimeZone(Zona::agencia());
        $ahora ??= new \DateTimeImmutable('now', $zona);
        $fecha = (string) ($r['fecha'] ?? '');
        $ini = \DateTimeImmutable::createFromFormat(str_contains($fecha, ':') ? '!Y-m-d H:i' : '!Y-m-d', substr($fecha, 0, str_contains($fecha, ':') ? 16 : 10), $zona);
        if ($ini === false) {
            return 'pasada';
        }
        $fin = str_contains($fecha, ':') ? $ini->modify('+' . max(5, (int) ($r['duracion_min'] ?? 60)) . ' minutes') : $ini->modify('+1 day');
        $conResultado = trim((string) ($r['resumen'] ?? '')) !== '' || trim((string) ($r['analisis'] ?? '')) !== '';
        if ($fin > $ahora && !$conResultado) {
            return 'proxima';
        }
        return $ini < $ahora->modify('-' . self::DIAS_ARCHIVO . ' days') ? 'archivada' : 'pasada';
    }

    public function normalizar(array $p): array
    {
        $enlace = static fn(string $k): string => TiposContenido::enlaceSeguro((string) ($p[$k] ?? ''));
        return [
            'proyecto_id'       => (string) ($p['proyecto_id'] ?? ''),
            'titulo'            => mb_substr(trim((string) ($p['titulo'] ?? '')), 0, 255),
            'fecha'             => self::normalizarFecha((string) ($p['fecha'] ?? '')),
            'duracion_min'      => max(5, min(600, (int) ($p['duracion_min'] ?? 60) ?: 60)),
            'enlace_meet'       => mb_substr($enlace('enlace_meet'), 0, 500),
            'enlace_grabacion'  => mb_substr($enlace('enlace_grabacion'), 0, 500),
            'resumen'           => mb_substr(trim((string) ($p['resumen'] ?? '')), 0, 20000),
            'acuerdos'          => mb_substr(trim((string) ($p['acuerdos'] ?? '')), 0, 8000),
            'analisis'          => mb_substr(trim((string) ($p['analisis'] ?? '')), 0, 20000),
            'transcripcion'     => mb_substr(trim((string) ($p['transcripcion'] ?? '')), 0, 200000),
            'publicada'         => !empty($p['publicada']) ? 1 : 0,
            'resumen_publicado' => !empty($p['resumen_publicado']) ? 1 : 0,
            'prox_fecha'        => self::normalizarFecha((string) ($p['prox_fecha'] ?? '')),
            'prox_titulo'       => mb_substr(trim((string) ($p['prox_titulo'] ?? '')), 0, 255),
        ];
    }

    // ---------------------------------------------------------------------
    // CRUD
    // ---------------------------------------------------------------------

    /** @param array<string, mixed> $payload */
    public function create(array $payload): string
    {
        $d   = $this->normalizar($payload);
        $id  = typedock_uuid7();
        $now = self::ahora();
        $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . '
             (id, proyecto_id, titulo, fecha, duracion_min, enlace_meet, enlace_grabacion, resumen, acuerdos, analisis,
              transcripcion, publicada, resumen_publicado, prox_fecha, prox_titulo, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $id, $d['proyecto_id'], $d['titulo'], $d['fecha'], $d['duracion_min'], $d['enlace_meet'], $d['enlace_grabacion'],
            $d['resumen'], $d['acuerdos'], $d['analisis'], $d['transcripcion'], $d['publicada'], $d['resumen_publicado'],
            $d['prox_fecha'], $d['prox_titulo'], $now, $now,
        ]);
        return $id;
    }

    /** @param array<string, mixed> $payload */
    public function update(string $id, array $payload): void
    {
        $d = $this->normalizar($payload);
        $this->pdo->prepare(
            'UPDATE ' . self::TABLE . ' SET proyecto_id = ?, titulo = ?, fecha = ?, duracion_min = ?, enlace_meet = ?, enlace_grabacion = ?,
             resumen = ?, acuerdos = ?, analisis = ?, transcripcion = ?, publicada = ?, resumen_publicado = ?,
             prox_fecha = ?, prox_titulo = ?, updated_at = ? WHERE id = ?'
        )->execute([
            $d['proyecto_id'], $d['titulo'], $d['fecha'], $d['duracion_min'], $d['enlace_meet'], $d['enlace_grabacion'],
            $d['resumen'], $d['acuerdos'], $d['analisis'], $d['transcripcion'], $d['publicada'], $d['resumen_publicado'],
            $d['prox_fecha'], $d['prox_titulo'], self::ahora(), $id,
        ]);
    }

    public function delete(string $id): void
    {
        $this->pdo->prepare('DELETE FROM ' . self::PROP . ' WHERE reunion_id = ?')->execute([$id]);
        $this->pdo->prepare('UPDATE portal_tareas SET reunion_origen_id = NULL WHERE reunion_origen_id = ?')->execute([$id]);
        $this->pdo->prepare('UPDATE ' . self::TABLE . ' SET prox_reunion_id = NULL WHERE prox_reunion_id = ?')->execute([$id]);
        $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE id = ?')->execute([$id]);
    }

    // ---------------------------------------------------------------------
    // Propuestas de tareas
    // ---------------------------------------------------------------------

    /** @return array<array<string, mixed>> */
    public function propuestas(string $reunionId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . self::PROP . ' WHERE reunion_id = ? ORDER BY orden, created_at, id');
        $stmt->execute([$reunionId]);
        return $stmt->fetchAll();
    }

    public function propuesta(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . self::PROP . ' WHERE id = ?');
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        return $r !== false ? $r : null;
    }

    /** @param array<string, mixed> $p */
    public function agregarPropuesta(string $reunionId, array $p, string $origen = 'manual'): ?string
    {
        $titulo = Fmt::mayusculaInicial(mb_substr(trim((string) ($p['titulo'] ?? '')), 0, 255));
        if ($titulo === '') {
            return null;
        }
        $asignado = ($p['asignado'] ?? 'equipo') === 'cliente' ? 'cliente' : 'equipo';
        $vence = self::normalizarFecha((string) ($p['fecha_vencimiento'] ?? ''));
        $vence = $vence !== '' ? substr($vence, 0, 10) : null;
        $max = $this->pdo->prepare('SELECT COALESCE(MAX(orden), 0) FROM ' . self::PROP . ' WHERE reunion_id = ?');
        $max->execute([$reunionId]);
        $id = typedock_uuid7();
        $this->pdo->prepare(
            'INSERT INTO ' . self::PROP . ' (id, reunion_id, titulo, descripcion, asignado, fecha_vencimiento, visible_cliente, estado, orden, origen, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, \'propuesta\', ?, ?, ?)'
        )->execute([
            $id, $reunionId, $titulo, mb_substr(trim((string) ($p['descripcion'] ?? '')), 0, 4000), $asignado, $vence,
            ($asignado === 'cliente' || !empty($p['visible_cliente'])) ? 1 : 0, (int) $max->fetchColumn() + 1,
            $origen === 'ia' ? 'ia' : 'manual', self::ahora(),
        ]);
        return $id;
    }

    /** Actualiza los campos editables de una propuesta que aún no se ha convertido en tarea. @param array<string, mixed> $p */
    public function actualizarPropuesta(string $id, array $p): void
    {
        $asignado = ($p['asignado'] ?? 'equipo') === 'cliente' ? 'cliente' : 'equipo';
        $vence = self::normalizarFecha((string) ($p['fecha_vencimiento'] ?? ''));
        $titulo = Fmt::mayusculaInicial(mb_substr(trim((string) ($p['titulo'] ?? '')), 0, 255));
        if ($titulo === '') {
            return;
        }
        $this->pdo->prepare(
            'UPDATE ' . self::PROP . ' SET titulo = ?, descripcion = ?, asignado = ?, fecha_vencimiento = ?, visible_cliente = ?
             WHERE id = ? AND estado = \'propuesta\''
        )->execute([
            $titulo, mb_substr(trim((string) ($p['descripcion'] ?? '')), 0, 4000), $asignado, $vence !== '' ? substr($vence, 0, 10) : null,
            ($asignado === 'cliente' || !empty($p['visible_cliente'])) ? 1 : 0, $id,
        ]);
    }

    public function borrarPropuesta(string $id): void
    {
        $this->pdo->prepare('DELETE FROM ' . self::PROP . ' WHERE id = ? AND estado = \'propuesta\'')->execute([$id]);
    }

    /** Descarta las propuestas pendientes (antes de regenerar con IA). */
    public function limpiarPropuestasPendientes(string $reunionId): void
    {
        $this->pdo->prepare('DELETE FROM ' . self::PROP . ' WHERE reunion_id = ? AND estado = \'propuesta\'')->execute([$reunionId]);
    }

    /**
     * Convierte en tareas reales las propuestas indicadas.
     * Devuelve cuántas se crearon y cuántas de ellas quedan visibles para el cliente.
     *
     * @param array<string> $ids
     * @return array{creadas: int, visibles: int}
     */
    public function aprobarPropuestas(string $reunionId, array $ids): array
    {
        $reunion = $this->find($reunionId);
        if ($reunion === null) {
            return ['creadas' => 0, 'visibles' => 0];
        }
        $tareas = new TareaService($this->pdo);
        $creadas = 0;
        $visibles = 0;
        foreach ($this->propuestas($reunionId) as $q) {
            if ($q['estado'] !== 'propuesta' || !in_array($q['id'], $ids, true)) {
                continue;
            }
            $tid = $tareas->create([
                'proyecto_id'       => $reunion['proyecto_id'],
                'reunion_origen_id' => $reunionId,
                'titulo'            => $q['titulo'],
                'descripcion'       => (string) $q['descripcion'],
                'fecha_vencimiento' => $q['fecha_vencimiento'],
                'asignado'          => $q['asignado'],
                'visible_cliente'   => (int) $q['visible_cliente'],
                'tipo'              => 'tarea',
                'estado'            => 'pendiente',
            ]);
            $this->pdo->prepare('UPDATE ' . self::PROP . ' SET estado = \'aprobada\', tarea_id = ? WHERE id = ?')->execute([$tid, $q['id']]);
            $creadas++;
            if ($q['asignado'] === 'cliente' || (int) $q['visible_cliente'] === 1) {
                $visibles++;
            }
        }
        return ['creadas' => $creadas, 'visibles' => $visibles];
    }

    /** Tareas que salieron de esta reunión y el cliente puede ver. @return array<array<string, mixed>> */
    public function tareasVisibles(string $reunionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.id, t.titulo, t.estado, t.fecha_vencimiento, t.responsable_tipo FROM portal_tareas t
             WHERE t.reunion_origen_id = ? AND (t.visible_cliente = 1 OR t.responsable_tipo = \'cliente\')
             ORDER BY t.created_at, t.id'
        );
        $stmt->execute([$reunionId]);
        return $stmt->fetchAll();
    }

    // ---------------------------------------------------------------------
    // Próxima reunión
    // ---------------------------------------------------------------------

    /** Crea la reunión siguiente en el mismo proyecto a partir de la propuesta guardada. Devuelve su id. */
    public function agendarProxima(string $reunionId, bool $publicar): ?string
    {
        $r = $this->find($reunionId);
        if ($r === null || (string) $r['prox_reunion_id'] !== '' || (string) $r['prox_fecha'] === '') {
            return null;
        }
        $titulo = trim((string) $r['prox_titulo']) !== '' ? (string) $r['prox_titulo'] : 'Próxima reunión · ' . $r['proyecto_nombre'];
        $id = $this->create([
            'proyecto_id' => $r['proyecto_id'],
            'titulo'      => $titulo,
            'fecha'       => $r['prox_fecha'],
            'duracion_min' => $r['duracion_min'],
            'publicada'   => $publicar ? 1 : 0,
            'resumen_publicado' => 0,
        ]);
        $this->pdo->prepare('UPDATE ' . self::TABLE . ' SET prox_reunion_id = ? WHERE id = ?')->execute([$id, $reunionId]);
        return $id;
    }

    // ---------------------------------------------------------------------
    // Calendario
    // ---------------------------------------------------------------------

    /** Fecha guardada (hora de Santiago) → DateTimeImmutable en UTC, o null si no tiene hora válida. */
    public static function aUtc(string $fecha): ?\DateTimeImmutable
    {
        if (self::normalizarFecha($fecha) === '' ) {
            return null;
        }
        $hora = str_contains($fecha, ':') ? $fecha : $fecha . ' 09:00';
        try {
            return (new \DateTimeImmutable(substr(str_replace('T', ' ', $hora), 0, 16), new \DateTimeZone(Zona::agencia())))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Evento .ics con el link de Meet incluido.
     *
     * Para descargar: METHOD:PUBLISH. Para una invitación por correo: REQUEST (nueva o cambiada,
     * con SEQUENCE creciente) o CANCEL, con organizador y la persona invitada, como lo esperan
     * Gmail y Outlook para mostrar «Agregar al calendario».
     *
     * @param array{metodo?: string, organizador?: string, nombre_org?: string, para?: string, nombre_para?: string} $inv
     */
    public function ics(array $r, string $urlPortal = '', array $inv = []): string
    {
        $ini = self::aUtc((string) $r['fecha']);
        if ($ini === null) {
            return '';
        }
        $metodo = in_array($inv['metodo'] ?? '', ['REQUEST', 'CANCEL'], true) ? $inv['metodo'] : 'PUBLISH';
        $fin = $ini->modify('+' . max(5, (int) $r['duracion_min']) . ' minutes');
        $esc = static fn(string $t): string => str_replace(["\\", ';', ',', "\r", "\n"], ['\\\\', '\\;', '\\,', '', '\\n'], $t);
        $desc = ($r['enlace_meet'] ? 'Unirse: ' . $r['enlace_meet'] : '') . ($urlPortal !== '' ? ($r['enlace_meet'] ? "\n" : '') . 'Detalle: ' . $urlPortal : '');
        $lineas = [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Portal de Clientes//ES', 'CALSCALE:GREGORIAN', 'METHOD:' . $metodo,
            'BEGIN:VEVENT',
            'UID:' . $r['id'] . '@portal',
            'DTSTAMP:' . gmdate('Ymd\THis\Z'),
            'DTSTART:' . $ini->format('Ymd\THis\Z'),
            'DTEND:' . $fin->format('Ymd\THis\Z'),
            'SUMMARY:' . $esc((string) $r['titulo']),
            'SEQUENCE:' . (int) ($r['ics_seq'] ?? 0),
            'STATUS:' . ($metodo === 'CANCEL' ? 'CANCELLED' : 'CONFIRMED'),
        ];
        if ($desc !== '') {
            $lineas[] = 'DESCRIPTION:' . $esc($desc);
        }
        if ((string) $r['enlace_meet'] !== '') {
            $lineas[] = 'LOCATION:' . $esc((string) $r['enlace_meet']);
            $lineas[] = 'URL:' . $r['enlace_meet'];
        }
        if ($metodo !== 'PUBLISH' && ($inv['organizador'] ?? '') !== '') {
            $lineas[] = 'ORGANIZER;CN="' . str_replace('"', '', (string) ($inv['nombre_org'] ?? '')) . '":mailto:' . $inv['organizador'];
            if (($inv['para'] ?? '') !== '') {
                $lineas[] = 'ATTENDEE;CN="' . str_replace('"', '', (string) ($inv['nombre_para'] ?? '')) . '";ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=FALSE:mailto:' . $inv['para'];
            }
        }
        if ($metodo === 'REQUEST') {
            $lineas[] = 'BEGIN:VALARM';
            $lineas[] = 'ACTION:DISPLAY';
            $lineas[] = 'DESCRIPTION:' . $esc((string) $r['titulo']);
            $lineas[] = 'TRIGGER:-PT10M';
            $lineas[] = 'END:VALARM';
        }
        $lineas[] = 'END:VEVENT';
        $lineas[] = 'END:VCALENDAR';
        // Líneas de más de 75 octetos se pliegan (RFC 5545).
        $plegar = static function (string $l): string {
            $out = '';
            while (strlen($l) > 75) {
                $corte = 75;
                while ($corte > 0 && (ord($l[$corte]) & 0xC0) === 0x80) {
                    $corte--;   // no partir un carácter UTF-8
                }
                $out .= substr($l, 0, $corte) . "\r\n ";
                $l = substr($l, $corte);
            }
            return $out . $l;
        };
        return implode("\r\n", array_map($plegar, $lineas)) . "\r\n";
    }

    /** Enlace a Google Calendar con el evento ya rellenado (para crear la reunión con Meet en un clic). */
    public function enlaceGoogleCalendar(array $r): string
    {
        $ini = self::aUtc((string) $r['fecha']);
        if ($ini === null) {
            return '';
        }
        $fin = $ini->modify('+' . max(5, (int) $r['duracion_min']) . ' minutes');
        return 'https://calendar.google.com/calendar/render?' . http_build_query([
            'action'  => 'TEMPLATE',
            'text'    => (string) $r['titulo'],
            'dates'   => $ini->format('Ymd\THis\Z') . '/' . $fin->format('Ymd\THis\Z'),
            'details' => trim('Proyecto: ' . ($r['proyecto_nombre'] ?? '') . ($r['enlace_meet'] ? "\nUnirse: " . $r['enlace_meet'] : '')),
            'location' => (string) ($r['enlace_meet'] ?? ''),
        ], '', '&', PHP_QUERY_RFC3986);
    }
}

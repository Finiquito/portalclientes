<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * Solicitudes del cliente: un pedido, un presupuesto, una reunión o un problema.
 *
 * Flujo:
 *  - El cliente la crea desde su portal (con proyecto y urgencia). Queda «nueva» en la bandeja.
 *  - El equipo la acepta (pedido/problema → tarea, reunión → reunión agendada), la cotiza
 *    (presupuesto → «cotizada», y el cliente la aprueba o no), la responde o la rechaza.
 *
 * Urgencias: para el cliente todo es urgente, así que «urgente» pide un motivo y cada cliente
 * puede tener sólo N urgencias abiertas a la vez (Portal · Ajustes, por defecto 1). Un problema
 * («la web se cayó») es siempre urgente y no cuenta para ese tope.
 */
class SolicitudService
{
    public const TABLE = 'portal_solicitudes';

    /** tipo => [nombre, para qué sirve, ícono] */
    public const TIPOS = [
        'pedido'      => ['Un pedido', 'Algo nuevo que necesitas que hagamos.', 'i-tasks'],
        'presupuesto' => ['Un presupuesto', 'Te cotizamos algo antes de empezar.', 'i-file'],
        'reunion'     => ['Una reunión', 'Propón horarios y te confirmamos uno.', 'i-calendar'],
        'problema'    => ['Reportar un problema', 'Algo dejó de funcionar o tiene un error.', 'i-alert'],
    ];

    public const URGENCIAS = [
        'urgente'   => 'Urgente',
        'semana'    => 'Esta semana',
        'sin_apuro' => 'Sin apuro',
    ];

    /** estado => [etiqueta para el equipo, tono .chip-*] */
    public const ESTADOS = [
        'nueva'       => ['Por revisar', 'brand'],
        'en_curso'    => ['Convertida en tarea', 'info'],
        'cotizada'    => ['Cotización enviada', 'warn'],
        'aprobada'    => ['Presupuesto aprobado', 'ok'],
        'no_aprobada' => ['Presupuesto no aprobado', 'muted'],
        'agendada'    => ['Reunión agendada', 'ok'],
        'respondida'  => ['Respondida', 'muted'],
        'rechazada'   => ['No tomada', 'muted'],
    ];

    /** Lo que ve el cliente en cada estado. */
    public const ESTADOS_CLIENTE = [
        'nueva'       => ['Recibida', 'brand'],
        'en_curso'    => ['En curso', 'info'],
        'cotizada'    => ['Cotización lista', 'warn'],
        'aprobada'    => ['Aprobada', 'ok'],
        'no_aprobada' => ['No aprobada', 'muted'],
        'agendada'    => ['Reunión confirmada', 'ok'],
        'respondida'  => ['Respondida', 'ok'],
        'rechazada'   => ['No la tomamos', 'muted'],
    ];

    /** Estados en que el equipo tiene algo que hacer. */
    public const POR_ATENDER = ['nueva', 'aprobada'];

    public const MODALIDADES = ['video' => 'Videollamada', 'presencial' => 'Presencial', 'telefono' => 'Llamada telefónica'];

    public function __construct(private readonly \PDO $pdo, private readonly ?\DateTimeImmutable $ahora = null) {}

    private function ahora(): \DateTimeImmutable
    {
        return $this->ahora ?? new \DateTimeImmutable('now', new \DateTimeZone(Fmt::ZONA));
    }

    private function marca(): string
    {
        return $this->ahora()->format('Y-m-d H:i:s');
    }

    /** @param array<int, mixed> $p */
    private function uno(string $sql, array $p): ?array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($p);
        $r = $st->fetch();
        return $r !== false ? $r : null;
    }

    /** @param array<int, mixed> $p */
    private function todos(string $sql, array $p = []): array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($p);
        return $st->fetchAll();
    }

    private const SELECT = 'SELECT s.*, p.nombre AS proyecto_nombre, c.nombre AS cliente_nombre, c.empresa AS cliente_empresa,
            t.estado AS tarea_estado, t.titulo AS tarea_titulo, r.fecha AS reunion_fecha,
            (SELECT COUNT(*) FROM portal_comentarios k WHERE k.entidad_tipo = \'solicitud\' AND k.entidad_id = s.id) AS n_comentarios
        FROM portal_solicitudes s
        JOIN portal_proyectos p ON p.id = s.proyecto_id
        JOIN portal_clientes c ON c.id = s.cliente_id
        LEFT JOIN portal_tareas t ON t.id = s.tarea_id
        LEFT JOIN portal_reuniones r ON r.id = s.reunion_id';

    // ---------------------------------------------------------------------
    // Lectura
    // ---------------------------------------------------------------------

    public function find(string $id): ?array
    {
        return $this->uno(self::SELECT . ' WHERE s.id = ?', [$id]);
    }

    public function delCliente(string $clienteId): array
    {
        return $this->todos(self::SELECT . ' WHERE s.cliente_id = ? ORDER BY s.created_at DESC, s.id DESC', [$clienteId]);
    }

    public function findDelCliente(string $id, string $clienteId): ?array
    {
        return $this->uno(self::SELECT . ' WHERE s.id = ? AND s.cliente_id = ?', [$id, $clienteId]);
    }

    /** Todas, las urgentes y más antiguas primero entre las por atender. */
    public function listAll(): array
    {
        $filas = $this->todos(self::SELECT . ' ORDER BY s.created_at DESC, s.id DESC');
        usort($filas, function (array $a, array $b): int {
            $pa = in_array($a['estado'], self::POR_ATENDER, true) ? 0 : 1;
            $pb = in_array($b['estado'], self::POR_ATENDER, true) ? 0 : 1;
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }
            if ($pa === 0) {
                $ua = $a['urgencia'] === 'urgente' ? 0 : ($a['urgencia'] === 'semana' ? 1 : 2);
                $ub = $b['urgencia'] === 'urgente' ? 0 : ($b['urgencia'] === 'semana' ? 1 : 2);
                return [$ua, $a['created_at']] <=> [$ub, $b['created_at']];
            }
            return strcmp((string) $b['created_at'], (string) $a['created_at']);
        });
        return $filas;
    }

    public function porAtender(): int
    {
        return (int) ($this->uno("SELECT COUNT(*) AS n FROM portal_solicitudes WHERE estado IN ('nueva', 'aprobada')", [])['n'] ?? 0);
    }

    /** @return array<int, string> horarios propuestos ('Y-m-d H:i') */
    public static function horarios(?string $json): array
    {
        $l = json_decode((string) $json, true);
        return is_array($l) ? array_values(array_filter($l, 'is_string')) : [];
    }

    // ---------------------------------------------------------------------
    // Urgencias y fechas
    // ---------------------------------------------------------------------

    public function maxUrgentes(): int
    {
        return max(0, (int) (new AjustesService($this->pdo))->get('global', 'portal', 'urgentes_max', '1'));
    }

    /** Urgencias del cliente que siguen abiertas (los problemas no cuentan). */
    public function urgentesAbiertas(string $clienteId): int
    {
        return (int) ($this->uno(
            "SELECT COUNT(*) AS n FROM portal_solicitudes s LEFT JOIN portal_tareas t ON t.id = s.tarea_id
             WHERE s.cliente_id = ? AND s.urgencia = 'urgente' AND s.tipo <> 'problema'
               AND (s.estado IN ('nueva', 'cotizada', 'aprobada') OR (s.estado = 'en_curso' AND (t.id IS NULL OR t.estado <> 'hecha')))",
            [$clienteId]
        )['n'] ?? 0);
    }

    public function puedeUrgente(string $clienteId): bool
    {
        $max = $this->maxUrgentes();
        return $max === 0 || $this->urgentesAbiertas($clienteId) < $max;
    }

    /** Fecha límite sugerida para la tarea: urgente → próximo día hábil; esta semana → viernes; sin apuro → ninguna. */
    public function fechaSugerida(string $urgencia, string $tipo = 'pedido'): ?string
    {
        $hoy = $this->ahora()->setTime(0, 0);
        if ($tipo === 'problema') {
            return $hoy->format('Y-m-d');
        }
        if ($urgencia === 'urgente') {
            $d = $hoy->modify('+1 day');
            while ((int) $d->format('N') >= 6) {
                $d = $d->modify('+1 day');
            }
            return $d->format('Y-m-d');
        }
        if ($urgencia === 'semana') {
            $n = (int) $hoy->format('N');
            return ($n <= 4 ? $hoy->modify('friday this week') : $hoy->modify('next friday'))->format('Y-m-d');
        }
        return null;
    }

    // ---------------------------------------------------------------------
    // Escritura
    // ---------------------------------------------------------------------

    /**
     * El cliente crea una solicitud. Devuelve ['id' => …] o ['error' => mensaje para el cliente].
     *
     * @param array<string, mixed> $contacto
     * @param array<string, mixed> $p
     * @return array{id?: string, error?: string}
     */
    public function crear(array $contacto, array $p): array
    {
        $clienteId = (string) $contacto['cliente_id'];
        $tipo = isset(self::TIPOS[$p['tipo'] ?? '']) ? (string) $p['tipo'] : '';
        if ($tipo === '') {
            return ['error' => 'Elige qué necesitas.'];
        }
        $proyectoId = (string) ($p['proyecto_id'] ?? '');
        if ($this->uno('SELECT id FROM portal_proyectos WHERE id = ? AND cliente_id = ?', [$proyectoId, $clienteId]) === null) {
            return ['error' => 'Elige el proyecto.'];
        }
        $titulo = mb_substr(trim((string) ($p['titulo'] ?? '')), 0, 255);
        if ($titulo === '') {
            return ['error' => $tipo === 'reunion' ? 'Cuéntanos de qué quieres hablar.' : 'Ponle un título corto a tu solicitud.'];
        }
        $detalle = mb_substr(trim(str_replace("\r\n", "\n", (string) ($p['detalle'] ?? ''))), 0, 8000);

        $urgencia = isset(self::URGENCIAS[$p['urgencia'] ?? '']) ? (string) $p['urgencia'] : 'semana';
        $motivo = mb_substr(trim((string) ($p['motivo_urgencia'] ?? '')), 0, 500);
        if ($tipo === 'problema') {
            $urgencia = 'urgente';
        } elseif ($urgencia === 'urgente') {
            if (!$this->puedeUrgente($clienteId)) {
                return ['error' => 'Ya tienes una solicitud urgente abierta. Mientras la resolvemos, elige «Esta semana» o «Sin apuro», o escríbenos en la urgente para cambiar prioridades.'];
            }
            if ($motivo === '') {
                return ['error' => 'Cuéntanos por qué es urgente: nos ayuda a reordenar el trabajo.'];
            }
        }
        if ($urgencia !== 'urgente') {
            $motivo = '';
        }

        $horarios = [];
        $modalidad = null;
        if ($tipo === 'reunion') {
            foreach ((array) ($p['horarios'] ?? []) as $h) {
                $h = str_replace('T', ' ', trim((string) $h));
                $d = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', substr($h, 0, 16), new \DateTimeZone(Fmt::ZONA));
                if ($d !== false && $d > $this->ahora() && !in_array($d->format('Y-m-d H:i'), $horarios, true)) {
                    $horarios[] = $d->format('Y-m-d H:i');
                }
            }
            $horarios = array_slice($horarios, 0, 3);
            if ($horarios === []) {
                return ['error' => 'Propón al menos un horario (en el futuro).'];
            }
            $modalidad = isset(self::MODALIDADES[$p['modalidad'] ?? '']) ? (string) $p['modalidad'] : 'video';
        }

        $id = typedock_uuid7();
        $now = $this->marca();
        $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . ' (id, cliente_id, proyecto_id, contacto_id, contacto_nombre, tipo, titulo, detalle,
             urgencia, motivo_urgencia, horarios, modalidad, estado, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $id, $clienteId, $proyectoId, (string) $contacto['id'], (string) $contacto['nombre'], $tipo, $titulo, $detalle,
            $urgencia, $motivo !== '' ? $motivo : null, $horarios !== [] ? json_encode($horarios) : null, $modalidad, 'nueva', $now, $now,
        ]);
        return ['id' => $id];
    }

    private function cambiar(string $id, array $campos): void
    {
        $campos['updated_at'] = $this->marca();
        $sets = implode(', ', array_map(static fn(string $k): string => $k . ' = ?', array_keys($campos)));
        $this->pdo->prepare('UPDATE ' . self::TABLE . " SET {$sets} WHERE id = ?")->execute([...array_values($campos), $id]);
    }

    /**
     * Acepta un pedido / problema / presupuesto aprobado: crea la tarea del equipo (visible para el
     * cliente) y le pasa los archivos que adjuntó.
     *
     * @param array<string, mixed> $d titulo, descripcion, fecha_vencimiento, responsable_usuario_id
     */
    public function aceptar(string $id, array $d, string $firma): ?string
    {
        $s = $this->find($id);
        if ($s === null || $s['tipo'] === 'reunion' || !in_array($s['estado'], ['nueva', 'aprobada'], true)) {
            return null;
        }
        $desc = trim((string) ($d['descripcion'] ?? ''));
        $tareaId = (new TareaService($this->pdo))->create([
            'proyecto_id'            => $s['proyecto_id'],
            'titulo'                 => trim((string) ($d['titulo'] ?? '')) ?: $s['titulo'],
            'descripcion'            => $desc !== '' ? $desc : (string) $s['detalle'],
            'fecha_vencimiento'      => (string) ($d['fecha_vencimiento'] ?? ''),
            'estado'                 => 'pendiente',
            'tipo'                   => 'tarea',
            'asignado'               => 'equipo',
            'responsable_usuario_id' => (string) ($d['responsable_usuario_id'] ?? ''),
            'visible_cliente'        => '1',
        ]);
        $this->pdo->prepare("UPDATE portal_archivos SET entidad_tipo = 'tarea', entidad_id = ? WHERE entidad_tipo = 'solicitud' AND entidad_id = ?")
            ->execute([$tareaId, $id]);
        $this->cambiar($id, ['estado' => 'en_curso', 'tarea_id' => $tareaId, 'atendida_por' => $firma, 'atendida_en' => $this->marca()]);
        return $tareaId;
    }

    /** Agenda la reunión pedida (publicada para el cliente). */
    public function agendar(string $id, string $fecha, int $duracion, string $enlace, string $titulo, string $firma): ?string
    {
        $s = $this->find($id);
        if ($s === null || $s['tipo'] !== 'reunion' || $s['estado'] !== 'nueva' || trim($fecha) === '') {
            return null;
        }
        $reunionId = (new ReunionService($this->pdo))->create([
            'proyecto_id'  => $s['proyecto_id'],
            'titulo'       => trim($titulo) ?: $s['titulo'],
            'fecha'        => $fecha,
            'duracion_min' => $duracion,
            'enlace_meet'  => $enlace,
            'publicada'    => '1',
        ]);
        $this->cambiar($id, ['estado' => 'agendada', 'reunion_id' => $reunionId, 'atendida_por' => $firma, 'atendida_en' => $this->marca()]);
        return $reunionId;
    }

    public function cotizar(string $id, string $monto, string $validez, string $mensaje, string $firma): bool
    {
        $s = $this->find($id);
        $monto = mb_substr(trim($monto), 0, 80);
        if ($s === null || $s['tipo'] !== 'presupuesto' || !in_array($s['estado'], ['nueva', 'cotizada'], true) || $monto === '') {
            return false;
        }
        $v = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($validez));
        $this->cambiar($id, [
            'estado' => 'cotizada', 'monto' => $monto, 'validez' => $v !== false ? $v->format('Y-m-d') : null,
            'respuesta' => mb_substr(trim($mensaje), 0, 8000), 'atendida_por' => $firma, 'atendida_en' => $this->marca(),
        ]);
        return true;
    }

    /** El cliente aprueba (o no) la cotización. */
    public function decidir(string $id, bool $aprueba): bool
    {
        $s = $this->find($id);
        if ($s === null || $s['estado'] !== 'cotizada') {
            return false;
        }
        $this->cambiar($id, ['estado' => $aprueba ? 'aprobada' : 'no_aprobada', 'decision_en' => $this->marca()]);
        return true;
    }

    /** Responder sin crear nada (una duda resuelta) o rechazar (no la tomamos), siempre con un mensaje. */
    public function cerrar(string $id, string $estado, string $mensaje, string $firma): bool
    {
        $s = $this->find($id);
        $mensaje = mb_substr(trim($mensaje), 0, 8000);
        if ($s === null || !in_array($estado, ['respondida', 'rechazada'], true) || $mensaje === ''
            || !in_array($s['estado'], ['nueva', 'aprobada', 'cotizada'], true)) {
            return false;
        }
        $this->cambiar($id, ['estado' => $estado, 'respuesta' => $mensaje, 'atendida_por' => $firma, 'atendida_en' => $this->marca()]);
        return true;
    }

    /** Al borrar un proyecto: sus solicitudes y sus comentarios (los archivos los limpia ArchivoService). */
    public function borrarDeProyecto(string $proyectoId): void
    {
        $this->pdo->prepare("DELETE FROM portal_comentarios WHERE entidad_tipo = 'solicitud'
            AND entidad_id IN (SELECT id FROM portal_solicitudes WHERE proyecto_id = ?)")->execute([$proyectoId]);
        $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE proyecto_id = ?')->execute([$proyectoId]);
    }

    public function delete(string $id): void
    {
        (new ComentarioService($this->pdo))->borrarDeEntidad('solicitud', $id);
        (new ArchivoService($this->pdo))->borrarDeEntidad('solicitud', $id);
        $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE id = ?')->execute([$id]);
    }
}

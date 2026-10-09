<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

class TareaService
{
    public const TABLE = 'portal_tareas';

    public const ESTADOS = ['pendiente', 'en_progreso', 'entregada', 'cambios', 'hecha'];
    public const TIPOS   = ['tarea', 'archivo', 'revision'];

    public function __construct(private readonly \PDO $pdo) {}

    /** @return array<array<string, mixed>> */
    public function listAll(): array
    {
        $stmt = $this->pdo->query(
            'SELECT t.*, p.nombre AS proyecto_nombre, p.cliente_id AS cliente_id, cl.nombre AS cliente_nombre,
                    COALESCE(eq.nombre, u.name) AS responsable_nombre, ct.nombre AS contacto_nombre,
                    (SELECT COUNT(*) FROM portal_comentarios c WHERE c.entidad_tipo = \'tarea\' AND c.entidad_id = t.id) AS n_comentarios,
                    (SELECT COUNT(*) FROM portal_archivos a WHERE a.entidad_tipo = \'tarea\' AND a.entidad_id = t.id) AS n_archivos
             FROM ' . self::TABLE . ' t
             JOIN portal_proyectos p ON p.id = t.proyecto_id
             JOIN portal_clientes cl ON cl.id = p.cliente_id
             LEFT JOIN users u ON u.id = t.responsable_usuario_id AND t.responsable_tipo = \'equipo\'
             LEFT JOIN portal_equipo eq ON eq.id = t.responsable_usuario_id AND t.responsable_tipo = \'equipo\'
             LEFT JOIN portal_contactos ct ON ct.id = t.responsable_contacto_id AND t.responsable_tipo = \'cliente\'
             ORDER BY (CASE WHEN t.estado = \'entregada\' THEN 0 ELSE 1 END), (t.fecha_vencimiento IS NULL), t.fecha_vencimiento'
        );
        return $stmt ? $stmt->fetchAll() : [];
    }

    /**
     * Archiva o desarchiva tareas. Archivar sólo las saca de las listas del equipo
     * (bandeja, tareas, proyecto); el cliente las sigue viendo en «Listas».
     *
     * @param array<int, string> $ids
     */
    public function archivar(array $ids, bool $archivar = true): int
    {
        $st = $this->pdo->prepare('UPDATE ' . self::TABLE . ' SET archivada = ?, updated_at = ? WHERE id = ?');
        $n = 0;
        foreach (array_unique($ids) as $id) {
            $st->execute([$archivar ? 1 : 0, (new \DateTimeImmutable())->format('Y-m-d H:i:s'), (string) $id]);
            $n += $st->rowCount();
        }
        return $n;
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.*, p.nombre AS proyecto_nombre, p.cliente_id AS cliente_id
             FROM ' . self::TABLE . ' t JOIN portal_proyectos p ON p.id = t.proyecto_id WHERE t.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /** @return array<array<string, mixed>> */
    public function fasesDe(string $proyectoId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, nombre FROM portal_fases WHERE proyecto_id = ? ORDER BY orden, nombre');
        $stmt->execute([$proyectoId]);
        return $stmt->fetchAll();
    }

    /** @return array<array<string, mixed>> todas las fases, para el <select> del formulario */
    public function todasLasFases(): array
    {
        $stmt = $this->pdo->query('SELECT id, proyecto_id, nombre FROM portal_fases ORDER BY proyecto_id, orden, nombre');
        return $stmt ? $stmt->fetchAll() : [];
    }

    /** @return array<array<string, mixed>> tareas que pueden ir antes que otra (las no archivadas, por proyecto) */
    public function paraDependencia(): array
    {
        Schema::asegurar($this->pdo);
        $stmt = $this->pdo->query('SELECT id, proyecto_id, titulo, estado FROM ' . self::TABLE . ' WHERE archivada = 0 OR archivada IS NULL ORDER BY proyecto_id, COALESCE(fecha_vencimiento, fecha_inicio, created_at), titulo');
        return $stmt ? $stmt->fetchAll() : [];
    }

    /** @return array<array<string, mixed>> contactos con su cliente, para asignar tareas */
    public function contactosAsignables(): array
    {
        $stmt = $this->pdo->query(
            'SELECT ct.id, ct.nombre, ct.cliente_id, cl.nombre AS cliente_nombre
             FROM portal_contactos ct JOIN portal_clientes cl ON cl.id = ct.cliente_id ORDER BY cl.nombre, ct.nombre'
        );
        return $stmt ? $stmt->fetchAll() : [];
    }

    /**
     * Limpia y valida lo que viene del formulario. Nunca confía en ids sueltos:
     * la fase y el contacto tienen que pertenecer al proyecto/cliente elegido.
     *
     * @param array<string, mixed> $p
     * @return array<string, mixed>
     */
    public function normalizar(array $p): array
    {
        $proyectoId = (string) ($p['proyecto_id'] ?? '');
        $asignado   = ($p['asignado'] ?? 'equipo') === 'cliente' ? 'cliente' : 'equipo';
        $tipo       = in_array($p['tipo'] ?? '', self::TIPOS, true) ? (string) $p['tipo'] : 'tarea';
        $estado     = in_array($p['estado'] ?? '', self::ESTADOS, true) ? (string) $p['estado'] : 'pendiente';

        $faseId = $this->nullIfEmpty((string) ($p['fase_id'] ?? ''));
        if ($faseId !== null && !$this->existe('SELECT id FROM portal_fases WHERE id = ? AND proyecto_id = ?', [$faseId, $proyectoId])) {
            $faseId = null;
        }

        $usuarioId  = null;
        $contactoId = null;
        if ($asignado === 'equipo') {
            // Si el equipo es una sola persona, lo del equipo es suyo.
            $usuarioId = $this->nullIfEmpty((string) ($p['responsable_usuario_id'] ?? '')) ?? (new EquipoService($this->pdo))->unico();
        } else {
            $contactoId = $this->nullIfEmpty((string) ($p['responsable_contacto_id'] ?? ''));
            if ($contactoId !== null && !$this->existe(
                'SELECT ct.id FROM portal_contactos ct JOIN portal_proyectos pr ON pr.cliente_id = ct.cliente_id
                 WHERE ct.id = ? AND pr.id = ?', [$contactoId, $proyectoId]
            )) {
                $contactoId = null;
            }
        }

        // Dependencia: la tarea parte cuando termina otra del mismo proyecto y dura N días hábiles.
        $dependeDe = $this->nullIfEmpty((string) ($p['depende_de'] ?? ''));
        $duracion = (int) ($p['duracion_dias'] ?? 0);
        if ($dependeDe !== null && !(new Cronograma($this->pdo))->dependenciaValida((string) ($p['id'] ?? ''), $dependeDe, $proyectoId)) {
            $dependeDe = null;
        }

        return [
            'depende_de'        => $dependeDe,
            'duracion_dias'     => $dependeDe !== null ? max(1, min(250, $duracion ?: 1)) : ($duracion > 0 ? min(250, $duracion) : null),
            'proyecto_id'       => $proyectoId,
            'fase_id'           => $faseId,
            'reunion_origen_id' => $this->nullIfEmpty((string) ($p['reunion_origen_id'] ?? '')),
            'titulo'            => Fmt::mayusculaInicial(mb_substr(trim((string) ($p['titulo'] ?? '')), 0, 255)),
            'descripcion'       => (string) ($p['descripcion'] ?? ''),
            'fecha_inicio'      => $this->nullIfEmpty((string) ($p['fecha_inicio'] ?? '')),
            'fecha_vencimiento' => $this->nullIfEmpty((string) ($p['fecha_vencimiento'] ?? '')),
            'estado'            => $estado,
            'tipo'              => $tipo,
            'responsable_tipo'  => $asignado,
            'responsable_usuario_id'  => $usuarioId,
            'responsable_contacto_id' => $contactoId,
            // Lo asignado al cliente siempre es visible para él.
            'visible_cliente'   => $asignado === 'cliente' || !empty($p['visible_cliente']) ? 1 : 0,
        ];
    }

    /** @param array<string, mixed> $payload */
    public function create(array $payload): string
    {
        Schema::asegurar($this->pdo);
        $id  = typedock_uuid7();
        $d   = $this->normalizar(['id' => $id] + $payload);
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        [$d['fecha_inicio'], $d['fecha_vencimiento']] = $this->fechasConDependencia($d);

        $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . '
             (id, proyecto_id, fase_id, reunion_origen_id, titulo, descripcion, fecha_inicio, fecha_vencimiento,
              estado, tipo, visible_cliente, completada_en, responsable_tipo, responsable_usuario_id,
              responsable_contacto_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $id, $d['proyecto_id'], $d['fase_id'], $d['reunion_origen_id'], $d['titulo'], $d['descripcion'],
            $d['fecha_inicio'], $d['fecha_vencimiento'], $d['estado'], $d['tipo'], $d['visible_cliente'],
            $d['estado'] === 'hecha' ? $now : null,
            $d['responsable_tipo'], $d['responsable_usuario_id'], $d['responsable_contacto_id'], $now, $now,
        ]);
        $this->guardarDependencia($id, $d);

        return $id;
    }

    /** @param array<string, mixed> $payload */
    public function update(string $id, array $payload): void
    {
        Schema::asegurar($this->pdo);
        $d   = $this->normalizar(['id' => $id] + $payload);
        $old = $this->find($id);
        [$d['fecha_inicio'], $d['fecha_vencimiento']] = $this->fechasConDependencia($d);
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $completada = null;
        if ($d['estado'] === 'hecha') {
            $completada = ($old['completada_en'] ?? null) ?: $now;
        }

        $this->pdo->prepare(
            'UPDATE ' . self::TABLE . ' SET
                proyecto_id = ?, fase_id = ?, reunion_origen_id = ?, titulo = ?, descripcion = ?,
                fecha_inicio = ?, fecha_vencimiento = ?, estado = ?, tipo = ?, visible_cliente = ?, completada_en = ?,
                responsable_tipo = ?, responsable_usuario_id = ?, responsable_contacto_id = ?, updated_at = ?
             WHERE id = ?'
        )->execute([
            $d['proyecto_id'], $d['fase_id'], $d['reunion_origen_id'], $d['titulo'], $d['descripcion'],
            $d['fecha_inicio'], $d['fecha_vencimiento'], $d['estado'], $d['tipo'], $d['visible_cliente'], $completada,
            $d['responsable_tipo'], $d['responsable_usuario_id'], $d['responsable_contacto_id'], $now, $id,
        ]);
        $this->guardarDependencia($id, $d);
        if ($d['estado'] === 'hecha' && ($old['estado'] ?? '') !== 'hecha') {
            (new Cronograma($this->pdo))->fijar($id);
        }
    }

    /**
     * Con dependencia, las fechas no se escriben a mano: si la anterior ya terminó se fijan desde
     * su término; si no, quedan vacías (la línea de tiempo las estima).
     *
     * @param array<string, mixed> $d
     * @return array{0: ?string, 1: ?string}
     */
    private function fechasConDependencia(array $d): array
    {
        if ($d['depende_de'] === null) {
            return [$d['fecha_inicio'], $d['fecha_vencimiento']];
        }
        $st = $this->pdo->prepare("SELECT completada_en FROM portal_tareas WHERE id = ? AND estado = 'hecha'");
        $st->execute([$d['depende_de']]);
        $fin = $st->fetchColumn();
        if (!is_string($fin) || $fin === '') {
            return [null, null];
        }
        $ini = Cronograma::siguienteHabil(substr($fin, 0, 10));
        return [$ini, Cronograma::finTras($ini, (int) $d['duracion_dias'])];
    }

    /** @param array<string, mixed> $d */
    private function guardarDependencia(string $id, array $d): void
    {
        try {
            $this->pdo->prepare('UPDATE ' . self::TABLE . ' SET depende_de = ?, duracion_dias = ? WHERE id = ?')
                ->execute([$d['depende_de'], $d['duracion_dias'], $id]);
        } catch (\Throwable) {
            // columnas aún no creadas
        }
    }

    /** Fechas desde la línea de tiempo (arrastrar). Con dependencia sólo cambia la duración. */
    public function moverFechas(string $id, string $ini, string $fin): bool
    {
        $t = $this->find($id);
        if ($t === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ini) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fin)) {
            return false;
        }
        if ($fin < $ini) {
            [$ini, $fin] = [$fin, $ini];
        }
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        if (!empty($t['depende_de'])) {
            $this->pdo->prepare('UPDATE ' . self::TABLE . ' SET duracion_dias = ?, updated_at = ? WHERE id = ?')
                ->execute([Cronograma::habilesEntre($ini, $fin), $now, $id]);
            if ($t['fecha_inicio']) {   // ya fijada: también corre el vencimiento
                $this->pdo->prepare('UPDATE ' . self::TABLE . ' SET fecha_vencimiento = ? WHERE id = ?')
                    ->execute([Cronograma::finTras((string) $t['fecha_inicio'], Cronograma::habilesEntre($ini, $fin)), $id]);
            }
            return true;
        }
        $this->pdo->prepare('UPDATE ' . self::TABLE . ' SET fecha_inicio = ?, fecha_vencimiento = ?, updated_at = ? WHERE id = ?')
            ->execute([$ini, $fin, $now, $id]);
        return true;
    }

    public function cambiarEstado(string $id, string $estado): void
    {
        if (!in_array($estado, self::ESTADOS, true)) {
            return;
        }
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->pdo->prepare('UPDATE ' . self::TABLE . ' SET estado = ?, completada_en = ?, updated_at = ? WHERE id = ?')
            ->execute([$estado, $estado === 'hecha' ? $now : null, $now, $id]);
        if ($estado === 'hecha') {
            (new Cronograma($this->pdo))->fijar($id);   // las que dependen de ésta ya tienen fecha
        }
    }

    public function delete(string $id): void
    {
        $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE id = ?')->execute([$id]);
    }

    /** @param array<int, mixed> $params */
    private function existe(string $sql, array $params): bool
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn() !== false;
    }

    private function nullIfEmpty(string $value): ?string
    {
        $value = trim($value);
        return $value === '' ? null : $value;
    }
}

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
                    u.name AS responsable_nombre, ct.nombre AS contacto_nombre,
                    (SELECT COUNT(*) FROM portal_comentarios c WHERE c.entidad_tipo = \'tarea\' AND c.entidad_id = t.id) AS n_comentarios,
                    (SELECT COUNT(*) FROM portal_archivos a WHERE a.entidad_tipo = \'tarea\' AND a.entidad_id = t.id) AS n_archivos
             FROM ' . self::TABLE . ' t
             JOIN portal_proyectos p ON p.id = t.proyecto_id
             JOIN portal_clientes cl ON cl.id = p.cliente_id
             LEFT JOIN users u ON u.id = t.responsable_usuario_id AND t.responsable_tipo = \'equipo\'
             LEFT JOIN portal_contactos ct ON ct.id = t.responsable_contacto_id AND t.responsable_tipo = \'cliente\'
             ORDER BY (CASE WHEN t.estado = \'entregada\' THEN 0 ELSE 1 END), (t.fecha_vencimiento IS NULL), t.fecha_vencimiento'
        );
        return $stmt ? $stmt->fetchAll() : [];
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
            $usuarioId = $this->nullIfEmpty((string) ($p['responsable_usuario_id'] ?? ''));
        } else {
            $contactoId = $this->nullIfEmpty((string) ($p['responsable_contacto_id'] ?? ''));
            if ($contactoId !== null && !$this->existe(
                'SELECT ct.id FROM portal_contactos ct JOIN portal_proyectos pr ON pr.cliente_id = ct.cliente_id
                 WHERE ct.id = ? AND pr.id = ?', [$contactoId, $proyectoId]
            )) {
                $contactoId = null;
            }
        }

        return [
            'proyecto_id'       => $proyectoId,
            'fase_id'           => $faseId,
            'reunion_origen_id' => $this->nullIfEmpty((string) ($p['reunion_origen_id'] ?? '')),
            'titulo'            => mb_substr(trim((string) ($p['titulo'] ?? '')), 0, 255),
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
        $d   = $this->normalizar($payload);
        $id  = typedock_uuid7();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

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

        return $id;
    }

    /** @param array<string, mixed> $payload */
    public function update(string $id, array $payload): void
    {
        $d   = $this->normalizar($payload);
        $old = $this->find($id);
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
    }

    public function cambiarEstado(string $id, string $estado): void
    {
        if (!in_array($estado, self::ESTADOS, true)) {
            return;
        }
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->pdo->prepare('UPDATE ' . self::TABLE . ' SET estado = ?, completada_en = ?, updated_at = ? WHERE id = ?')
            ->execute([$estado, $estado === 'hecha' ? $now : null, $now, $id]);
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

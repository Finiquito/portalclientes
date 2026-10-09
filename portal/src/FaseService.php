<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

class FaseService
{
    public const TABLE = 'portal_fases';

    public function __construct(private readonly \PDO $pdo) {}

    /** @return array<array<string, mixed>> */
    public function listAll(): array
    {
        $stmt = $this->pdo->query(
            'SELECT f.*, p.nombre AS proyecto_nombre
             FROM ' . self::TABLE . ' f
             JOIN portal_proyectos p ON p.id = f.proyecto_id
             ORDER BY p.nombre, f.orden'
        );
        return $stmt ? $stmt->fetchAll() : [];
    }

    /** @return array<array<string, mixed>> */
    public function listByProyecto(string $proyectoId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . self::TABLE . ' WHERE proyecto_id = ? ORDER BY orden'
        );
        $stmt->execute([$proyectoId]);
        return $stmt->fetchAll();
    }

    /**
     * Fases con su rango de fechas: las propias o, si no tiene, las de sus tareas.
     * Sirve para sugerir la fase de una reunión o entrega según su fecha.
     * @return array<array{id: string, proyecto_id: string, nombre: string, ini: ?string, fin: ?string}>
     */
    public function conRangos(): array
    {
        $stmt = $this->pdo->query(
            'SELECT f.id, f.proyecto_id, f.nombre, f.orden, f.fecha_inicio, f.fecha_fin,
                    MIN(COALESCE(t.fecha_inicio, t.fecha_vencimiento)) AS t_ini, MAX(COALESCE(t.fecha_vencimiento, t.fecha_inicio)) AS t_fin
             FROM ' . self::TABLE . ' f LEFT JOIN portal_tareas t ON t.fase_id = f.id
             GROUP BY f.id, f.proyecto_id, f.nombre, f.orden, f.fecha_inicio, f.fecha_fin
             ORDER BY f.proyecto_id, f.orden, f.nombre'
        );
        $corta = static fn($v): ?string => $v !== null && $v !== '' ? substr((string) $v, 0, 10) : null;
        return array_map(static fn(array $f): array => [
            'id' => (string) $f['id'], 'proyecto_id' => (string) $f['proyecto_id'], 'nombre' => (string) $f['nombre'],
            'ini' => $corta($f['fecha_inicio']) ?? $corta($f['t_ini']), 'fin' => $corta($f['fecha_fin']) ?? $corta($f['t_fin']),
        ], $stmt ? $stmt->fetchAll() : []);
    }

    /** Devuelve $faseId si es una fase de ese proyecto; si no, null. */
    public function deProyecto(?string $faseId, string $proyectoId): ?string
    {
        $faseId = trim((string) $faseId);
        if ($faseId === '' || $proyectoId === '') {
            return null;
        }
        $st = $this->pdo->prepare('SELECT 1 FROM ' . self::TABLE . ' WHERE id = ? AND proyecto_id = ?');
        $st->execute([$faseId, $proyectoId]);
        return $st->fetchColumn() !== false ? $faseId : null;
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /** @param array<string, mixed> $payload */
    public function create(array $payload): string
    {
        $id  = typedock_uuid7();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . '
             (id, proyecto_id, nombre, orden, fecha_inicio, fecha_fin, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $id,
            (string) ($payload['proyecto_id'] ?? ''),
            (string) ($payload['nombre'] ?? ''),
            (int) ($payload['orden'] ?? 0),
            $this->nullIfEmpty($payload['fecha_inicio'] ?? ''),
            $this->nullIfEmpty($payload['fecha_fin'] ?? ''),
            $now,
            $now,
        ]);

        return $id;
    }

    /** @param array<string, mixed> $payload */
    public function update(string $id, array $payload): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->pdo->prepare(
            'UPDATE ' . self::TABLE . ' SET
                proyecto_id = ?, nombre = ?, orden = ?, fecha_inicio = ?, fecha_fin = ?, updated_at = ?
             WHERE id = ?'
        )->execute([
            (string) ($payload['proyecto_id'] ?? ''),
            (string) ($payload['nombre'] ?? ''),
            (int) ($payload['orden'] ?? 0),
            $this->nullIfEmpty($payload['fecha_inicio'] ?? ''),
            $this->nullIfEmpty($payload['fecha_fin'] ?? ''),
            $now,
            $id,
        ]);
    }

    public function delete(string $id): void
    {
        Schema::asegurar($this->pdo);
        foreach (['portal_reuniones', 'portal_entregas', 'portal_hitos'] as $t) {   // quedan en el proyecto, sin fase
            $this->pdo->prepare("UPDATE {$t} SET fase_id = NULL WHERE fase_id = ?")->execute([$id]);
        }
        $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE id = ?')->execute([$id]);
    }

    private function nullIfEmpty(string $value): ?string
    {
        $value = trim($value);
        return $value === '' ? null : $value;
    }
}

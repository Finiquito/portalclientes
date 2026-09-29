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
        $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE id = ?')->execute([$id]);
    }

    private function nullIfEmpty(string $value): ?string
    {
        $value = trim($value);
        return $value === '' ? null : $value;
    }
}

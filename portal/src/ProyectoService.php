<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

class ProyectoService
{
    public const TABLE = 'portal_proyectos';

    public function __construct(private readonly \PDO $pdo) {}

    /** @return array<array<string, mixed>> */
    public function listAll(): array
    {
        $stmt = $this->pdo->query(
            'SELECT p.*, c.nombre AS cliente_nombre, c.pais AS cliente_pais
             FROM ' . self::TABLE . ' p
             JOIN portal_clientes c ON c.id = p.cliente_id
             ORDER BY p.nombre'
        );
        return $stmt ? $stmt->fetchAll() : [];
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
            'INSERT INTO ' . self::TABLE . ' (id, cliente_id, nombre, descripcion, estado, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $id,
            (string) ($payload['cliente_id'] ?? ''),
            (string) ($payload['nombre'] ?? ''),
            (string) ($payload['descripcion'] ?? ''),
            (string) ($payload['estado'] ?? 'activo'),
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
            'UPDATE ' . self::TABLE . ' SET cliente_id = ?, nombre = ?, descripcion = ?, estado = ?, updated_at = ? WHERE id = ?'
        )->execute([
            (string) ($payload['cliente_id'] ?? ''),
            (string) ($payload['nombre'] ?? ''),
            (string) ($payload['descripcion'] ?? ''),
            (string) ($payload['estado'] ?? 'activo'),
            $now,
            $id,
        ]);
    }

    public function delete(string $id): void
    {
        $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE id = ?')->execute([$id]);
    }
}

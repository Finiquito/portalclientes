<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

class ContactoService
{
    public const TABLE = 'portal_contactos';

    public function __construct(private readonly \PDO $pdo) {}

    /** @return array<array<string, mixed>> */
    public function listAll(): array
    {
        $stmt = $this->pdo->query(
            'SELECT ct.*, cl.nombre AS cliente_nombre
             FROM ' . self::TABLE . ' ct
             JOIN portal_clientes cl ON cl.id = ct.cliente_id
             ORDER BY cl.nombre, ct.nombre'
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

    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE email = ? LIMIT 1');
        $stmt->execute([trim(strtolower($email))]);
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
             (id, cliente_id, nombre, email, password_hash, rol, created_at, updated_at)
             VALUES (?, ?, ?, ?, \'\', ?, ?, ?)'
        )->execute([
            $id,
            (string) ($payload['cliente_id'] ?? ''),
            (string) ($payload['nombre'] ?? ''),
            trim(strtolower((string) ($payload['email'] ?? ''))),
            (string) ($payload['rol'] ?? 'viewer'),
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
            'UPDATE ' . self::TABLE . ' SET cliente_id = ?, nombre = ?, email = ?, rol = ?, updated_at = ? WHERE id = ?'
        )->execute([
            (string) ($payload['cliente_id'] ?? ''),
            (string) ($payload['nombre'] ?? ''),
            trim(strtolower((string) ($payload['email'] ?? ''))),
            (string) ($payload['rol'] ?? 'viewer'),
            $now,
            $id,
        ]);
    }

    public function delete(string $id): void
    {
        $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE id = ?')->execute([$id]);
    }
}

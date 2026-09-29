<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * Persistencia de portal_clientes. Sigue el mismo patrón que
 * plugins/form/src/FormService.php: PDO directo, nada de ORM.
 */
class ClienteService
{
    public const TABLE = 'portal_clientes';

    public function __construct(private readonly \PDO $pdo) {}

    /** @return array<array<string, mixed>> */
    public function listAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM ' . self::TABLE . ' ORDER BY nombre');
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
            'INSERT INTO ' . self::TABLE . ' (id, nombre, empresa, email, pais, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $id,
            (string) ($payload['nombre'] ?? ''),
            (string) ($payload['empresa'] ?? ''),
            (string) ($payload['email'] ?? ''),
            HorarioHabil::paisValido((string) ($payload['pais'] ?? '')),
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
            'UPDATE ' . self::TABLE . ' SET nombre = ?, empresa = ?, email = ?, pais = ?, updated_at = ? WHERE id = ?'
        )->execute([
            (string) ($payload['nombre'] ?? ''),
            (string) ($payload['empresa'] ?? ''),
            (string) ($payload['email'] ?? ''),
            HorarioHabil::paisValido((string) ($payload['pais'] ?? '')),
            $now,
            $id,
        ]);
    }

    public function delete(string $id): void
    {
        $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE id = ?')->execute([$id]);
    }
}

<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/** Bitácora de acciones. Alimenta "Novedades" del cliente y la vista del admin. */
class ActividadService
{
    public const TABLE = 'portal_actividad';

    public function __construct(private readonly \PDO $pdo) {}

    public function registrar(
        string $clienteId,
        ?string $proyectoId,
        string $actorTipo,
        string $actorNombre,
        string $accion,
        ?string $entidadTipo = null,
        ?string $entidadId = null,
        ?string $titulo = null,
        ?string $detalle = null
    ): void {
        $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . '
             (id, cliente_id, proyecto_id, actor_tipo, actor_nombre, accion, entidad_tipo, entidad_id, titulo, detalle, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            typedock_uuid7(), $clienteId, $proyectoId, $actorTipo, $actorNombre, $accion,
            $entidadTipo, $entidadId, $titulo !== null ? mb_substr($titulo, 0, 255) : null,
            $detalle !== null ? mb_substr($detalle, 0, 500) : null,
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    /** @return array<array<string, mixed>> */
    public function deCliente(string $clienteId, int $limite = 8): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . self::TABLE . ' WHERE cliente_id = ? ORDER BY created_at DESC, id DESC LIMIT ' . (int) $limite
        );
        $stmt->execute([$clienteId]);
        return $stmt->fetchAll();
    }

    /** @return array<array<string, mixed>> */
    public function recientes(int $limite = 60): array
    {
        $stmt = $this->pdo->query(
            'SELECT a.*, c.nombre AS cliente_nombre
             FROM ' . self::TABLE . ' a JOIN portal_clientes c ON c.id = a.cliente_id
             ORDER BY a.created_at DESC, a.id DESC LIMIT ' . (int) $limite
        );
        return $stmt ? $stmt->fetchAll() : [];
    }

    /** Acciones de clientes posteriores a una fecha (badge "nuevo" del admin). */
    public function contarDeClientesDesde(string $desde): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE actor_tipo = \'contacto\' AND created_at > ?'
        );
        $stmt->execute([$desde !== '' ? $desde : '1970-01-01 00:00:00']);
        return (int) $stmt->fetchColumn();
    }
}

<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * Qué puede ver un usuario de agencia. Regla única para todo el front /equipo:
 * nunca se confía en un id de la URL sin pasarlo por aquí.
 *
 *  - rol 'coordinador': todos los clientes y proyectos.
 *  - rol 'equipo': clientes asignados completos + proyectos asignados sueltos.
 */
final class EquipoAcceso
{
    /** @var array<int, string>|null ids de proyectos visibles (null = todos) */
    private ?array $proyectos = null;
    private bool $cargado = false;

    /** @param array<string, mixed> $usuario */
    public function __construct(private readonly \PDO $pdo, private readonly array $usuario) {}

    public function todo(): bool
    {
        return ($this->usuario['rol'] ?? '') === 'coordinador';
    }

    /** @return array<int, string>|null ids de proyectos visibles; null = sin restricción. */
    public function proyectoIds(): ?array
    {
        if ($this->todo()) {
            return null;
        }
        if (!$this->cargado) {
            $stmt = $this->pdo->prepare(
                'SELECT p.id FROM portal_proyectos p
                 JOIN portal_equipo_asignaciones a ON a.cliente_id = p.cliente_id
                   AND (a.proyecto_id IS NULL OR a.proyecto_id = p.id)
                 WHERE a.usuario_id = ?'
            );
            $stmt->execute([(string) $this->usuario['id']]);
            $this->proyectos = array_values(array_unique(array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN))));
            $this->cargado = true;
        }
        return $this->proyectos;
    }

    /** @return array<int, string>|null ids de clientes visibles; null = todos. */
    public function clienteIds(): ?array
    {
        if ($this->todo()) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT DISTINCT cliente_id FROM portal_equipo_asignaciones WHERE usuario_id = ?');
        $stmt->execute([(string) $this->usuario['id']]);
        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function puedeVerProyecto(string $proyectoId): bool
    {
        $ids = $this->proyectoIds();
        if ($ids === null) {
            $stmt = $this->pdo->prepare('SELECT 1 FROM portal_proyectos WHERE id = ?');
            $stmt->execute([$proyectoId]);
            return $stmt->fetchColumn() !== false;
        }
        return in_array($proyectoId, $ids, true);
    }

    public function puedeVerCliente(string $clienteId): bool
    {
        $ids = $this->clienteIds();
        if ($ids === null) {
            $stmt = $this->pdo->prepare('SELECT 1 FROM portal_clientes WHERE id = ?');
            $stmt->execute([$clienteId]);
            return $stmt->fetchColumn() !== false;
        }
        return in_array($clienteId, $ids, true);
    }

    /**
     * Condición SQL para filtrar por una columna de proyecto (ej. "t.proyecto_id").
     *
     * @return array{0: string, 1: array<int, string>} [sql, parámetros]
     */
    public function filtroProyecto(string $columna): array
    {
        $ids = $this->proyectoIds();
        if ($ids === null) {
            return ['1 = 1', []];
        }
        if ($ids === []) {
            return ['1 = 0', []];
        }
        return [$columna . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids];
    }

    /** @return array{0: string, 1: array<int, string>} */
    public function filtroCliente(string $columna): array
    {
        $ids = $this->clienteIds();
        if ($ids === null) {
            return ['1 = 1', []];
        }
        if ($ids === []) {
            return ['1 = 0', []];
        }
        return [$columna . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids];
    }
}

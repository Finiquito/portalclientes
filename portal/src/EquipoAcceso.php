<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * Qué puede ver un usuario de agencia. Regla única para todo el front /equipo:
 * nunca se confía en un id de la URL sin pasarlo por aquí.
 *
 *  - rol 'coordinador' (Coordinación general): todos los clientes y proyectos; administra todo.
 *  - rol 'cuentas' (Coordinación de cuentas): los clientes de los que es responsable (completos)
 *    más lo asignado; administra sólo los suyos (cliente, proyectos, equipo del cliente).
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

    public function esCuentas(): bool
    {
        return ($this->usuario['rol'] ?? '') === 'cuentas';
    }

    /** ¿Puede crear clientes? Coordinación general y de cuentas (el cliente queda a su cargo). */
    public function puedeCrearClientes(): bool
    {
        return $this->todo() || $this->esCuentas();
    }

    /** ¿Administra este cliente? (editarlo, su portal, sus proyectos, su equipo). */
    public function gestionaCliente(string $clienteId): bool
    {
        if ($this->todo()) {
            return true;
        }
        if (!$this->esCuentas() || $clienteId === '') {
            return false;
        }
        return in_array($clienteId, $this->clientesACargo(), true);
    }

    public function gestionaProyecto(string $proyectoId): bool
    {
        $st = $this->pdo->prepare('SELECT cliente_id FROM portal_proyectos WHERE id = ?');
        $st->execute([$proyectoId]);
        $c = $st->fetchColumn();
        return $c !== false && $this->gestionaCliente((string) $c);
    }

    /** @var array<int, string>|null */
    private ?array $aCargo = null;

    /** @return array<int, string> clientes de los que es responsable */
    public function clientesACargo(): array
    {
        if ($this->aCargo === null) {
            try {
                $st = $this->pdo->prepare('SELECT id FROM portal_clientes WHERE responsable_id = ?');
                $st->execute([(string) ($this->usuario['id'] ?? '')]);
                $this->aCargo = array_map('strval', $st->fetchAll(\PDO::FETCH_COLUMN));
            } catch (\Throwable) {
                $this->aCargo = [];
            }
        }
        return $this->aCargo;
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
            $ids = array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
            if ($this->clientesACargo() !== []) {   // sus cuentas, completas
                $ph = implode(',', array_fill(0, count($this->clientesACargo()), '?'));
                $st2 = $this->pdo->prepare("SELECT id FROM portal_proyectos WHERE cliente_id IN ({$ph})");
                $st2->execute($this->clientesACargo());
                array_push($ids, ...array_map('strval', $st2->fetchAll(\PDO::FETCH_COLUMN)));
            }
            $this->proyectos = array_values(array_unique($ids));
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
        return array_values(array_unique([...array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN)), ...$this->clientesACargo()]));
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

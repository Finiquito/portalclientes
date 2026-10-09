<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/** Entregas: el paquete de contenidos que el cliente revisa de una sola vez. */
class EntregaService
{
    public const TABLE = 'portal_entregas';

    public function __construct(private readonly \PDO $pdo) {}

    private static function ahora(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }

    /** Conteo de contenidos por estado, para tarjetas y barras de progreso. */
    private const SELECT = "SELECT e.*, p.nombre AS proyecto_nombre, c.nombre AS cliente_nombre, c.empresa AS cliente_empresa,
        (SELECT COUNT(*) FROM portal_contenidos x WHERE x.entrega_id = e.id) AS n_total,
        (SELECT COUNT(*) FROM portal_contenidos x WHERE x.entrega_id = e.id AND x.estado = 'aprobado') AS n_aprobados,
        (SELECT COUNT(*) FROM portal_contenidos x WHERE x.entrega_id = e.id AND x.estado = 'cambios') AS n_cambios,
        (SELECT COUNT(*) FROM portal_contenidos x WHERE x.entrega_id = e.id AND x.estado = 'pendiente') AS n_pendientes
        FROM portal_entregas e
        JOIN portal_proyectos p ON p.id = e.proyecto_id
        JOIN portal_clientes c ON c.id = e.cliente_id";

    /** @return array<array<string, mixed>> */
    public function listAll(): array
    {
        return $this->pdo->query(self::SELECT . ' ORDER BY e.created_at DESC, e.id DESC')->fetchAll();
    }

    /** Entregas que el cliente ya puede ver (todo menos borradores). */
    public function delCliente(string $clienteId): array
    {
        $stmt = $this->pdo->prepare(self::SELECT . " WHERE e.cliente_id = ? AND e.estado <> 'borrador' ORDER BY e.created_at DESC, e.id DESC");
        $stmt->execute([$clienteId]);
        return $stmt->fetchAll();
    }

    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE e.id = ?');
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        return $r !== false ? $r : null;
    }

    /** La ve el cliente sólo si es suya y no es borrador. */
    public function findDelCliente(string $id, string $clienteId): ?array
    {
        $e = $this->find($id);
        return $e !== null && $e['cliente_id'] === $clienteId && $e['estado'] !== 'borrador' ? $e : null;
    }

    /** Contenidos por revisar en las entregas abiertas del cliente (globito del menú). */
    public function pendientesDelCliente(string $clienteId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM portal_contenidos c JOIN portal_entregas e ON e.id = c.entrega_id
             WHERE e.cliente_id = ? AND e.estado = 'publicada' AND c.estado = 'pendiente'"
        );
        $stmt->execute([$clienteId]);
        return (int) $stmt->fetchColumn();
    }

    /** @param array<string, mixed> $d */
    public function create(array $d): string
    {
        $proy = (new ProyectoService($this->pdo))->find((string) ($d['proyecto_id'] ?? ''));
        if ($proy === null) {
            throw new \InvalidArgumentException('Proyecto no válido');
        }
        $id = typedock_uuid7();
        $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . ' (id, cliente_id, proyecto_id, titulo, mensaje, fecha_limite, estado, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $id, $proy['cliente_id'], $proy['id'],
            mb_substr(trim((string) ($d['titulo'] ?? '')) ?: 'Nueva entrega', 0, 255),
            mb_substr(trim((string) ($d['mensaje'] ?? '')), 0, 4000),
            self::fecha((string) ($d['fecha_limite'] ?? '')),
            'borrador', self::ahora(), self::ahora(),
        ]);
        $this->guardarFase($id, $d, (string) $proy['id']);
        return $id;
    }

    /** Fase de la entrega (línea de tiempo), sólo si viene del formulario (marca _linea). @param array<string, mixed> $d */
    private function guardarFase(string $id, array $d, string $proyectoId): void
    {
        if (!isset($d['_linea'])) {
            return;
        }
        Schema::asegurar($this->pdo);
        $this->pdo->prepare('UPDATE ' . self::TABLE . ' SET fase_id = ? WHERE id = ?')
            ->execute([(new FaseService($this->pdo))->deProyecto((string) ($d['fase_id'] ?? ''), $proyectoId), $id]);
    }

    /** @param array<string, mixed> $d */
    public function update(string $id, array $d): void
    {
        $this->pdo->prepare('UPDATE ' . self::TABLE . ' SET titulo = ?, mensaje = ?, fecha_limite = ?, updated_at = ? WHERE id = ?')
            ->execute([
                mb_substr(trim((string) ($d['titulo'] ?? '')) ?: 'Entrega', 0, 255),
                mb_substr(trim((string) ($d['mensaje'] ?? '')), 0, 4000),
                self::fecha((string) ($d['fecha_limite'] ?? '')),
                self::ahora(), $id,
            ]);
        if (isset($d['_linea'])) {
            $st = $this->pdo->prepare('SELECT proyecto_id FROM ' . self::TABLE . ' WHERE id = ?');
            $st->execute([$id]);
            $this->guardarFase($id, $d, (string) $st->fetchColumn());
        }
    }

    private static function fecha(string $f): ?string
    {
        $f = trim($f);
        return preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2})?$/', $f) === 1 ? str_replace('T', ' ', $f) : null;
    }

    public function publicar(string $id): void
    {
        $this->pdo->prepare('UPDATE ' . self::TABLE . " SET estado = 'publicada', publicada_en = ?, respondida_en = NULL, respondida_por = NULL, updated_at = ? WHERE id = ?")
            ->execute([self::ahora(), self::ahora(), $id]);
    }

    public function volverABorrador(string $id): void
    {
        $this->pdo->prepare('UPDATE ' . self::TABLE . " SET estado = 'borrador', updated_at = ? WHERE id = ?")->execute([self::ahora(), $id]);
    }

    /** El cliente terminó su revisión. Devuelve el estado final. */
    public function responder(string $id, string $porNombre): string
    {
        $e = $this->find($id);
        if ($e === null) {
            return 'borrador';
        }
        $estado = (int) $e['n_total'] > 0 && (int) $e['n_aprobados'] === (int) $e['n_total'] ? 'aprobada' : 'respondida';
        $this->pdo->prepare('UPDATE ' . self::TABLE . ' SET estado = ?, respondida_en = ?, respondida_por = ?, updated_at = ? WHERE id = ?')
            ->execute([$estado, self::ahora(), mb_substr($porNombre, 0, 255), self::ahora(), $id]);
        return $estado;
    }

    /** Vuelve a abrir la revisión (por ejemplo al subir una versión nueva). */
    public function reabrir(string $id): void
    {
        $this->pdo->prepare('UPDATE ' . self::TABLE . " SET estado = 'publicada', updated_at = ? WHERE id = ? AND estado IN ('respondida', 'aprobada')")
            ->execute([self::ahora(), $id]);
    }

    /** Borra todo lo que cuelga de la entrega (archivos físicos incluidos). */
    public function delete(string $id): void
    {
        $contenidos = new ContenidoService($this->pdo);
        foreach ($contenidos->listar($id) as $c) {
            $contenidos->borrar((string) $c['id']);
        }
        $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE id = ?')->execute([$id]);
    }

    /** Antes de borrar un proyecto: limpia entregas, contenidos, versiones, reacciones, comentarios y binarios. */
    public function borrarDeProyecto(string $proyectoId): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM ' . self::TABLE . ' WHERE proyecto_id = ?');
        $stmt->execute([$proyectoId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            $this->delete((string) $id);
        }
    }

    /** Antes de borrar un cliente: sus entregas (los binarios ya los quita borrarFisicosDeCliente). */
    public function borrarDeCliente(string $clienteId): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM ' . self::TABLE . ' WHERE cliente_id = ?');
        $stmt->execute([$clienteId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            $this->delete((string) $id);
        }
    }
}

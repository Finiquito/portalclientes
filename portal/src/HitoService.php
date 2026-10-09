<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * Hitos (metas con fecha) de la línea de tiempo de un proyecto: «Entrega de logos», «Lanzamiento».
 * Un hito ligado a una fase se cumple solo cuando la fase termina; también se puede marcar a mano.
 */
final class HitoService
{
    public const TABLE = 'portal_hitos';

    public function __construct(private readonly \PDO $pdo) {}

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        Schema::asegurar($this->pdo);
        $st = $this->pdo->prepare('SELECT h.*, p.cliente_id, p.nombre AS proyecto_nombre FROM ' . self::TABLE . ' h JOIN portal_proyectos p ON p.id = h.proyecto_id WHERE h.id = ?');
        $st->execute([$id]);
        $r = $st->fetch();
        return $r !== false ? $r : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function delProyecto(string $proyectoId): array
    {
        Schema::asegurar($this->pdo);
        $st = $this->pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE proyecto_id = ? ORDER BY fecha, nombre');
        $st->execute([$proyectoId]);
        return $st->fetchAll();
    }

    /** @param array<string, mixed> $p @return array<string, mixed>|null null si falta nombre o fecha */
    private function normalizar(array $p): ?array
    {
        $nombre = Fmt::mayusculaInicial(mb_substr(trim((string) ($p['nombre'] ?? '')), 0, 255));
        $fecha = substr(trim((string) ($p['fecha'] ?? '')), 0, 10);
        if ($nombre === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) !== 1) {
            return null;
        }
        $proyectoId = (string) ($p['proyecto_id'] ?? '');
        $faseId = trim((string) ($p['fase_id'] ?? ''));
        if ($faseId !== '') {
            $st = $this->pdo->prepare('SELECT 1 FROM portal_fases WHERE id = ? AND proyecto_id = ?');
            $st->execute([$faseId, $proyectoId]);
            if ($st->fetchColumn() === false) {
                $faseId = '';
            }
        }
        return ['proyecto_id' => $proyectoId, 'fase_id' => $faseId !== '' ? $faseId : null, 'nombre' => $nombre, 'fecha' => $fecha,
            'visible_cliente' => !empty($p['visible_cliente']) ? 1 : 0];
    }

    /** @param array<string, mixed> $p */
    public function create(array $p): ?string
    {
        Schema::asegurar($this->pdo);
        $d = $this->normalizar($p);
        if ($d === null || $d['proyecto_id'] === '') {
            return null;
        }
        $id = typedock_uuid7();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->pdo->prepare('INSERT INTO ' . self::TABLE . ' (id, proyecto_id, fase_id, nombre, fecha, visible_cliente, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$id, $d['proyecto_id'], $d['fase_id'], $d['nombre'], $d['fecha'], $d['visible_cliente'], $now, $now]);
        return $id;
    }

    /** @param array<string, mixed> $p */
    public function update(string $id, array $p): bool
    {
        $h = $this->find($id);
        $d = $h !== null ? $this->normalizar(['proyecto_id' => $h['proyecto_id']] + $p) : null;
        if ($d === null) {
            return false;
        }
        $cumplido = !empty($p['cumplido']) ? ($h['cumplido_en'] ?: (new \DateTimeImmutable())->format('Y-m-d H:i:s')) : null;
        $this->pdo->prepare('UPDATE ' . self::TABLE . ' SET fase_id = ?, nombre = ?, fecha = ?, visible_cliente = ?, cumplido_en = ?, updated_at = ? WHERE id = ?')
            ->execute([$d['fase_id'], $d['nombre'], $d['fecha'], $d['visible_cliente'], $cumplido, (new \DateTimeImmutable())->format('Y-m-d H:i:s'), $id]);
        return true;
    }

    public function mover(string $id, string $fecha): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) !== 1) {
            return false;
        }
        $st = $this->pdo->prepare('UPDATE ' . self::TABLE . ' SET fecha = ?, updated_at = ? WHERE id = ?');
        $st->execute([$fecha, (new \DateTimeImmutable())->format('Y-m-d H:i:s'), $id]);
        return $st->rowCount() > 0;
    }

    public function delete(string $id): void
    {
        $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE id = ?')->execute([$id]);
    }
}

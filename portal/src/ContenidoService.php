<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/** Contenidos de una entrega, con sus versiones, decisiones y reacciones. */
class ContenidoService
{
    public const TABLE = 'portal_contenidos';

    public function __construct(private readonly \PDO $pdo) {}

    private static function ahora(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }

    /** Contenido + datos de su versión vigente. */
    private const SELECT = 'SELECT c.*, e.estado AS entrega_estado, e.titulo AS entrega_titulo,
        v.id AS version_id, v.copy AS copy, v.enlace AS enlace, v.nota AS nota, v.decision AS decision,
        v.decidido_por_nombre AS decidido_por_nombre, v.decidido_en AS decidido_en
        FROM portal_contenidos c
        JOIN portal_entregas e ON e.id = c.entrega_id
        LEFT JOIN portal_versiones v ON v.contenido_id = c.id AND v.numero = c.version_actual';

    /** @return array<array<string, mixed>> */
    public function listar(string $entregaId): array
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE c.entrega_id = ? ORDER BY c.orden, c.created_at, c.id');
        $stmt->execute([$entregaId]);
        return $stmt->fetchAll();
    }

    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE c.id = ?');
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        return $r !== false ? $r : null;
    }

    /** Sólo si es del cliente y su entrega ya no es borrador. */
    public function findDelCliente(string $id, string $clienteId): ?array
    {
        $c = $this->find($id);
        return $c !== null && $c['cliente_id'] === $clienteId && $c['entrega_estado'] !== 'borrador' ? $c : null;
    }

    /** @return array<array<string, mixed>> versiones de la más antigua a la más nueva */
    public function versiones(string $contenidoId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM portal_versiones WHERE contenido_id = ? ORDER BY numero');
        $stmt->execute([$contenidoId]);
        return $stmt->fetchAll();
    }

    public function version(string $versionId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM portal_versiones WHERE id = ?');
        $stmt->execute([$versionId]);
        $r = $stmt->fetch();
        return $r !== false ? $r : null;
    }

    /**
     * Archivos de una versión en el orden en que se subieron (el orden importa en un carrusel).
     * @return array<array<string, mixed>>
     */
    public function archivos(string $versionId): array
    {
        $a = (new ArchivoService($this->pdo))->deEntidad('version', $versionId);
        usort($a, fn($x, $y) => [(int) $x['orden'], (string) $x['id']] <=> [(int) $y['orden'], (string) $y['id']]);
        return $a;
    }

    /**
     * Crea el contenido con su versión 1. Devuelve [contenidoId, versionId].
     * @param array<string, mixed> $entrega
     * @param array<string, mixed> $d
     * @return array{0: string, 1: string}
     */
    public function crear(array $entrega, array $d): array
    {
        $tipo = (string) ($d['tipo'] ?? 'post');
        $tipo = TiposContenido::valido($tipo) ? $tipo : 'otro';
        $mx = $this->pdo->prepare('SELECT COALESCE(MAX(orden), 0) FROM portal_contenidos WHERE entrega_id = ?');
        $mx->execute([$entrega['id']]);
        $orden = (int) $mx->fetchColumn() + 1;

        $id = typedock_uuid7();
        $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . ' (id, entrega_id, cliente_id, proyecto_id, tipo, titulo, cuenta, fecha_publicacion, orden, estado, version_actual, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $id, $entrega['id'], $entrega['cliente_id'], $entrega['proyecto_id'], $tipo,
            mb_substr(trim((string) ($d['titulo'] ?? '')) ?: TiposContenido::nombre($tipo), 0, 255),
            mb_substr(trim((string) ($d['cuenta'] ?? '')), 0, 120),
            self::fecha((string) ($d['fecha_publicacion'] ?? '')),
            $orden, 'pendiente', 1, self::ahora(), self::ahora(),
        ]);
        $vid = $this->crearVersion($id, 1, $d);
        return [$id, $vid];
    }

    /** @param array<string, mixed> $d */
    private function crearVersion(string $contenidoId, int $numero, array $d): string
    {
        $vid = typedock_uuid7();
        $this->pdo->prepare(
            'INSERT INTO portal_versiones (id, contenido_id, numero, copy, enlace, nota, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $vid, $contenidoId, $numero,
            mb_substr(trim(str_replace("\r\n", "\n", (string) ($d['copy'] ?? ''))), 0, 4000),
            TiposContenido::enlaceSeguro((string) ($d['enlace'] ?? '')),
            mb_substr(trim((string) ($d['nota'] ?? '')), 0, 500),
            self::ahora(),
        ]);
        return $vid;
    }

    /**
     * Sube una versión nueva: el contenido vuelve a "por revisar" y la entrega se reabre.
     * @param array<string, mixed> $d
     */
    public function nuevaVersion(string $contenidoId, array $d): ?string
    {
        $c = $this->find($contenidoId);
        if ($c === null) {
            return null;
        }
        $n = (int) $c['version_actual'] + 1;
        $vid = $this->crearVersion($contenidoId, $n, $d);
        $this->pdo->prepare('UPDATE ' . self::TABLE . " SET version_actual = ?, estado = 'pendiente', updated_at = ? WHERE id = ?")
            ->execute([$n, self::ahora(), $contenidoId]);
        (new EntregaService($this->pdo))->reabrir((string) $c['entrega_id']);
        return $vid;
    }

    /** @param array<string, mixed> $d */
    public function actualizar(string $id, array $d): void
    {
        $c = $this->find($id);
        if ($c === null) {
            return;
        }
        $tipo = (string) ($d['tipo'] ?? $c['tipo']);
        $tipo = TiposContenido::valido($tipo) ? $tipo : (string) $c['tipo'];
        $this->pdo->prepare('UPDATE ' . self::TABLE . ' SET tipo = ?, titulo = ?, cuenta = ?, fecha_publicacion = ?, updated_at = ? WHERE id = ?')
            ->execute([
                $tipo,
                mb_substr(trim((string) ($d['titulo'] ?? '')) ?: (string) $c['titulo'], 0, 255),
                mb_substr(trim((string) ($d['cuenta'] ?? '')), 0, 120),
                self::fecha((string) ($d['fecha_publicacion'] ?? '')),
                self::ahora(), $id,
            ]);
        if ($c['version_id'] !== null && (isset($d['copy']) || isset($d['enlace']))) {
            $this->pdo->prepare('UPDATE portal_versiones SET copy = ?, enlace = ? WHERE id = ?')->execute([
                mb_substr(trim(str_replace("\r\n", "\n", (string) ($d['copy'] ?? ''))), 0, 4000),
                TiposContenido::enlaceSeguro((string) ($d['enlace'] ?? '')),
                $c['version_id'],
            ]);
        }
    }

    private static function fecha(string $f): ?string
    {
        $f = trim($f);
        return preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2})?$/', $f) === 1 ? str_replace('T', ' ', $f) : null;
    }

    public function borrar(string $id): void
    {
        $archivos = new ArchivoService($this->pdo);
        foreach ($this->versiones($id) as $v) {
            $archivos->borrarDeEntidad('version', (string) $v['id']);
            $this->pdo->prepare('DELETE FROM portal_reacciones WHERE version_id = ?')->execute([$v['id']]);
        }
        $this->pdo->prepare('DELETE FROM portal_versiones WHERE contenido_id = ?')->execute([$id]);
        (new ComentarioService($this->pdo))->borrarDeEntidad('contenido', $id);
        $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE id = ?')->execute([$id]);
    }

    /** Sube (-1) o baja (+1) un contenido dentro de su entrega, renumerando todo. */
    public function mover(string $id, int $dir): void
    {
        $c = $this->find($id);
        if ($c === null) {
            return;
        }
        $ids = array_map(fn($r) => (string) $r['id'], $this->listar((string) $c['entrega_id']));
        $i = array_search($id, $ids, true);
        $j = $i === false ? false : $i + ($dir < 0 ? -1 : 1);
        if ($i === false || $j < 0 || $j >= count($ids)) {
            return;
        }
        [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
        $upd = $this->pdo->prepare('UPDATE ' . self::TABLE . ' SET orden = ? WHERE id = ?');
        foreach ($ids as $n => $cid) {
            $upd->execute([$n + 1, $cid]);
        }
    }

    // ---- Revisión ---------------------------------------------------------

    /** Aprobar o pedir cambios sobre la versión vigente. */
    public function decidir(string $contenidoId, string $decision, string $contactoId, string $nombre): bool
    {
        $c = $this->find($contenidoId);
        if ($c === null || $c['version_id'] === null || !in_array($decision, ['aprobado', 'cambios'], true)) {
            return false;
        }
        $this->pdo->prepare('UPDATE portal_versiones SET decision = ?, decidido_por_id = ?, decidido_por_nombre = ?, decidido_en = ? WHERE id = ?')
            ->execute([$decision, $contactoId, mb_substr($nombre, 0, 255), self::ahora(), $c['version_id']]);
        $this->pdo->prepare('UPDATE ' . self::TABLE . ' SET estado = ?, updated_at = ? WHERE id = ?')
            ->execute([$decision, self::ahora(), $contenidoId]);
        return true;
    }

    /** Fija (o quita, con valor vacío) la reacción de un contacto sobre una versión. */
    public function reaccionar(string $versionId, string $contactoId, string $valor): void
    {
        $del = $this->pdo->prepare('DELETE FROM portal_reacciones WHERE version_id = ? AND contacto_id = ?');
        $del->execute([$versionId, $contactoId]);
        if (isset(TiposContenido::REACCIONES[$valor])) {
            $this->pdo->prepare('INSERT INTO portal_reacciones (id, version_id, contacto_id, valor, created_at) VALUES (?, ?, ?, ?, ?)')
                ->execute([typedock_uuid7(), $versionId, $contactoId, $valor, self::ahora()]);
        }
    }

    /**
     * Reacciones de una versión: conteo por valor y la del contacto dado.
     * @return array{conteo: array<string, int>, mia: string, total: int}
     */
    public function reacciones(string $versionId, string $contactoId = ''): array
    {
        $stmt = $this->pdo->prepare('SELECT contacto_id, valor FROM portal_reacciones WHERE version_id = ?');
        $stmt->execute([$versionId]);
        $conteo = array_fill_keys(array_keys(TiposContenido::REACCIONES), 0);
        $mia = '';
        foreach ($stmt->fetchAll() as $r) {
            if (isset($conteo[$r['valor']])) {
                $conteo[$r['valor']]++;
            }
            if ($contactoId !== '' && $r['contacto_id'] === $contactoId) {
                $mia = (string) $r['valor'];
            }
        }
        return ['conteo' => $conteo, 'mia' => $mia, 'total' => array_sum($conteo)];
    }
}

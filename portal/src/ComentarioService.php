<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * Comentarios polimórficos (entidad_tipo + entidad_id). Hoy se usan en
 * tareas; para piezas de contenido o reuniones basta con otra entidad_tipo.
 */
class ComentarioService
{
    public const TABLE = 'portal_comentarios';
    public const MAX_LARGO = 4000;

    public function __construct(private readonly \PDO $pdo) {}

    /** @return array<array<string, mixed>> */
    public function listar(string $entidadTipo, string $entidadId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . self::TABLE . ' WHERE entidad_tipo = ? AND entidad_id = ? ORDER BY created_at, id'
        );
        $stmt->execute([$entidadTipo, $entidadId]);
        return $stmt->fetchAll();
    }

    public function crear(
        string $clienteId,
        string $entidadTipo,
        string $entidadId,
        string $autorTipo,
        ?string $autorId,
        string $autorNombre,
        string $cuerpo,
        ?string $versionId = null,
        ?string $ubicacion = null
    ): ?string {
        $cuerpo = trim(str_replace("\r\n", "\n", $cuerpo));
        if ($cuerpo === '') {
            return null;
        }
        $cuerpo = mb_substr($cuerpo, 0, self::MAX_LARGO);
        $id = typedock_uuid7();

        $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . '
             (id, cliente_id, entidad_tipo, entidad_id, autor_tipo, autor_id, autor_nombre, cuerpo, created_at, version_id, ubicacion)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $id, $clienteId, $entidadTipo, $entidadId, $autorTipo, $autorId, $autorNombre, $cuerpo,
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            $versionId, $ubicacion !== null ? mb_substr($ubicacion, 0, 64) : null,
        ]);
        return $id;
    }

    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = ?');
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        return $r !== false ? $r : null;
    }

    public function borrar(string $id): void
    {
        $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE id = ?')->execute([$id]);
    }

    public function borrarDeProyecto(string $proyectoId): void
    {
        $this->pdo->prepare(
            'DELETE FROM ' . self::TABLE . ' WHERE entidad_tipo = \'tarea\'
             AND entidad_id IN (SELECT id FROM portal_tareas WHERE proyecto_id = ?)'
        )->execute([$proyectoId]);
    }

    /** Comentarios de contenidos (tipo 'contenido') de todo un proyecto. */
    public function borrarDeProyectoContenidos(string $proyectoId): void
    {
        $this->pdo->prepare(
            'DELETE FROM ' . self::TABLE . ' WHERE entidad_tipo = \'contenido\'
             AND entidad_id IN (SELECT id FROM portal_contenidos WHERE proyecto_id = ?)'
        )->execute([$proyectoId]);
    }

    public function borrarDeEntidad(string $entidadTipo, string $entidadId): void
    {
        $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE entidad_tipo = ? AND entidad_id = ?')
            ->execute([$entidadTipo, $entidadId]);
    }
}

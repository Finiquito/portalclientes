<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * Ajustes clave/valor por dueño ('cliente', 'contacto' o 'global').
 *
 * Es lo que permite que el portal se personalice sin tocar el esquema:
 * color y logo del cliente, frases de bienvenida, tema claro/oscuro de cada
 * contacto, etc. Un ajuste nuevo es sólo una clave nueva.
 */
class AjustesService
{
    public const TABLE  = 'portal_ajustes';

    public function __construct(private readonly \PDO $pdo) {}

    /** @return array<string, string> */
    public function todos(string $ownerTipo, string $ownerId): array
    {
        $stmt = $this->pdo->prepare('SELECT clave, valor FROM ' . self::TABLE . ' WHERE owner_tipo = ? AND owner_id = ?');
        $stmt->execute([$ownerTipo, $ownerId]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[$row['clave']] = (string) $row['valor'];
        }
        return $out;
    }

    public function get(string $ownerTipo, string $ownerId, string $clave, string $porDefecto = ''): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT valor FROM ' . self::TABLE . ' WHERE owner_tipo = ? AND owner_id = ? AND clave = ?'
        );
        $stmt->execute([$ownerTipo, $ownerId, $clave]);
        $v = $stmt->fetchColumn();
        return $v === false || $v === null ? $porDefecto : (string) $v;
    }

    public function set(string $ownerTipo, string $ownerId, string $clave, string $valor): void
    {
        $ahora = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt  = $this->pdo->prepare(
            'SELECT id FROM ' . self::TABLE . ' WHERE owner_tipo = ? AND owner_id = ? AND clave = ?'
        );
        $stmt->execute([$ownerTipo, $ownerId, $clave]);
        $id = $stmt->fetchColumn();

        if ($id !== false) {
            $this->pdo->prepare('UPDATE ' . self::TABLE . ' SET valor = ?, updated_at = ? WHERE id = ?')
                ->execute([$valor, $ahora, $id]);
            return;
        }
        $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . ' (id, owner_tipo, owner_id, clave, valor, updated_at) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([typedock_uuid7(), $ownerTipo, $ownerId, $clave, $valor, $ahora]);
    }

    /** @param array<string, string> $valores */
    public function setMuchos(string $ownerTipo, string $ownerId, array $valores): void
    {
        foreach ($valores as $clave => $valor) {
            $this->set($ownerTipo, $ownerId, (string) $clave, (string) $valor);
        }
    }

    public function borrar(string $ownerTipo, string $ownerId, string $clave): void
    {
        $this->pdo->prepare(
            'DELETE FROM ' . self::TABLE . ' WHERE owner_tipo = ? AND owner_id = ? AND clave = ?'
        )->execute([$ownerTipo, $ownerId, $clave]);
    }

    public function borrarDeDueno(string $ownerTipo, string $ownerId): void
    {
        $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE owner_tipo = ? AND owner_id = ?')
            ->execute([$ownerTipo, $ownerId]);
    }

    /** Al borrar un cliente: sus ajustes y los de todos sus contactos. */
    public function borrarDeCliente(string $clienteId): void
    {
        $this->borrarDeDueno('cliente', $clienteId);
        $this->pdo->prepare(
            'DELETE FROM ' . self::TABLE . ' WHERE owner_tipo = \'contacto\'
             AND owner_id IN (SELECT id FROM portal_contactos WHERE cliente_id = ?)'
        )->execute([$clienteId]);
    }

    // ---- Marca del cliente ------------------------------------------------

    /** Acepta sólo #rrggbb; cualquier otra cosa vuelve al color por defecto. */
    public static function colorValido(string $c, string $porDefecto = '#6d5df6'): string
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $c) === 1 ? strtolower($c) : $porDefecto;
    }

    /** Blanco o casi-negro según la luminancia, para que el texto sobre el color de marca se lea. */
    public static function colorTexto(string $hex): string
    {
        $r = hexdec(substr($hex, 1, 2)) / 255;
        $g = hexdec(substr($hex, 3, 2)) / 255;
        $b = hexdec(substr($hex, 5, 2)) / 255;
        $lin = static fn(float $c): float => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        $l = 0.2126 * $lin($r) + 0.7152 * $lin($g) + 0.0722 * $lin($b);
        return $l > 0.42 ? '#12131a' : '#ffffff';
    }

    /** @return string[] */
    public static function frasesDesdeTexto(string $texto): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $texto) ?: [] as $linea) {
            $linea = trim($linea);
            if ($linea !== '') {
                $out[] = mb_substr($linea, 0, 140);
            }
            if (count($out) >= 20) {
                break;
            }
        }
        return $out;
    }

    /** @return string[] */
    public function frasesDeCliente(string $clienteId): array
    {
        $json = $this->get('cliente', $clienteId, 'frases', '[]');
        $arr  = json_decode($json, true);
        return is_array($arr) ? array_values(array_filter($arr, 'is_string')) : [];
    }
}

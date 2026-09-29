<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * Login sin contraseña: un código de 6 dígitos por correo, válido 10 minutos,
 * de un solo uso. Sirve para contactos de cliente y para usuarios de agencia;
 * cada uno con sus propias tablas (ver ContactoAuthService y EquipoAuthService).
 *
 * Protecciones: un código nuevo invalida los anteriores, máximo 5 códigos
 * pedidos y 5 intentos fallidos cada 10 minutos por persona (sin esto, un
 * código de 6 dígitos se podía adivinar por fuerza bruta).
 */
abstract class CodigoAccesoService
{
    private const TTL_MINUTOS  = 10;
    private const MAX_CODIGOS  = 5;
    private const MAX_FALLOS   = 5;

    /** Tabla de códigos, tabla de intentos fallidos y columna con el id de la persona. */
    protected const TABLA_CODIGOS  = '';
    protected const TABLA_INTENTOS = '';
    protected const COLUMNA        = '';

    public function __construct(protected readonly \PDO $pdo) {}

    private function hace(int $minutos): string
    {
        return (new \DateTimeImmutable())->modify("-{$minutos} minutes")->format('Y-m-d H:i:s');
    }

    /** true si ya pidió demasiados códigos en la ventana (evita usar el portal para spamear correos). */
    public function demasiadosCodigos(string $personaId): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM ' . static::TABLA_CODIGOS . ' WHERE ' . static::COLUMNA . ' = ? AND created_at > ?');
        $stmt->execute([$personaId, $this->hace(self::TTL_MINUTOS)]);
        return (int) $stmt->fetchColumn() >= self::MAX_CODIGOS;
    }

    public function bloqueado(string $personaId): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM ' . static::TABLA_INTENTOS . ' WHERE ' . static::COLUMNA . ' = ? AND created_at > ?');
        $stmt->execute([$personaId, $this->hace(self::TTL_MINUTOS)]);
        return (int) $stmt->fetchColumn() >= self::MAX_FALLOS;
    }

    public function generarCodigo(string $personaId): string
    {
        $codigo = (string) random_int(100000, 999999);
        $ahora  = new \DateTimeImmutable();
        $expira = $ahora->modify('+' . self::TTL_MINUTOS . ' minutes');

        // Los códigos anteriores dejan de servir.
        $this->pdo->prepare('UPDATE ' . static::TABLA_CODIGOS . ' SET usado_en = ? WHERE ' . static::COLUMNA . ' = ? AND usado_en IS NULL')
            ->execute([$ahora->format('Y-m-d H:i:s'), $personaId]);

        $this->pdo->prepare(
            'INSERT INTO ' . static::TABLA_CODIGOS . ' (id, ' . static::COLUMNA . ', codigo, expira_en, created_at)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            typedock_uuid7(),
            $personaId,
            $codigo,
            $expira->format('Y-m-d H:i:s'),
            $ahora->format('Y-m-d H:i:s'),
        ]);

        return $codigo;
    }

    public function verificarCodigo(string $personaId, string $codigo): bool
    {
        if ($this->bloqueado($personaId)) {
            return false;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, expira_en FROM ' . static::TABLA_CODIGOS . '
             WHERE ' . static::COLUMNA . ' = ? AND codigo = ? AND usado_en IS NULL
             ORDER BY created_at DESC LIMIT 1'
        );
        $stmt->execute([$personaId, trim($codigo)]);
        $row = $stmt->fetch();

        if ($row === false || new \DateTimeImmutable($row['expira_en']) < new \DateTimeImmutable()) {
            $this->pdo->prepare('INSERT INTO ' . static::TABLA_INTENTOS . ' (id, ' . static::COLUMNA . ', created_at) VALUES (?, ?, ?)')
                ->execute([typedock_uuid7(), $personaId, (new \DateTimeImmutable())->format('Y-m-d H:i:s')]);
            return false;
        }

        $this->pdo->prepare('UPDATE ' . static::TABLA_CODIGOS . ' SET usado_en = ? WHERE id = ?')
            ->execute([(new \DateTimeImmutable())->format('Y-m-d H:i:s'), $row['id']]);
        $this->pdo->prepare('DELETE FROM ' . static::TABLA_INTENTOS . ' WHERE ' . static::COLUMNA . ' = ?')->execute([$personaId]);

        return true;
    }
}

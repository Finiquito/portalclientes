<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * Login de contactos de cliente sin contraseña: se les envía un código de
 * 6 dígitos por correo, válido 10 minutos, de un solo uso.
 *
 * Protecciones: un código nuevo invalida los anteriores, máximo 5 códigos
 * pedidos y 5 intentos fallidos cada 10 minutos por contacto (sin esto, un
 * código de 6 dígitos se podía adivinar por fuerza bruta).
 */
class ContactoAuthService
{
    private const TTL_MINUTOS  = 10;
    private const MAX_CODIGOS  = 5;
    private const MAX_FALLOS   = 5;

    public function __construct(private readonly \PDO $pdo) {}

    private function hace(int $minutos): string
    {
        return (new \DateTimeImmutable())->modify("-{$minutos} minutes")->format('Y-m-d H:i:s');
    }

    /** true si ya pidió demasiados códigos en la ventana (evita usar el portal para spamear correos). */
    public function demasiadosCodigos(string $contactoId): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM portal_contacto_codigos WHERE contacto_id = ? AND created_at > ?');
        $stmt->execute([$contactoId, $this->hace(self::TTL_MINUTOS)]);
        return (int) $stmt->fetchColumn() >= self::MAX_CODIGOS;
    }

    public function bloqueado(string $contactoId): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM portal_login_intentos WHERE contacto_id = ? AND created_at > ?');
        $stmt->execute([$contactoId, $this->hace(self::TTL_MINUTOS)]);
        return (int) $stmt->fetchColumn() >= self::MAX_FALLOS;
    }

    public function generarCodigo(string $contactoId): string
    {
        $codigo = (string) random_int(100000, 999999);
        $ahora  = new \DateTimeImmutable();
        $expira = $ahora->modify('+' . self::TTL_MINUTOS . ' minutes');

        // Los códigos anteriores dejan de servir.
        $this->pdo->prepare('UPDATE portal_contacto_codigos SET usado_en = ? WHERE contacto_id = ? AND usado_en IS NULL')
            ->execute([$ahora->format('Y-m-d H:i:s'), $contactoId]);

        $this->pdo->prepare(
            'INSERT INTO portal_contacto_codigos (id, contacto_id, codigo, expira_en, created_at)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            typedock_uuid7(),
            $contactoId,
            $codigo,
            $expira->format('Y-m-d H:i:s'),
            $ahora->format('Y-m-d H:i:s'),
        ]);

        return $codigo;
    }

    public function verificarCodigo(string $contactoId, string $codigo): bool
    {
        if ($this->bloqueado($contactoId)) {
            return false;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, expira_en FROM portal_contacto_codigos
             WHERE contacto_id = ? AND codigo = ? AND usado_en IS NULL
             ORDER BY created_at DESC LIMIT 1'
        );
        $stmt->execute([$contactoId, trim($codigo)]);
        $row = $stmt->fetch();

        if ($row === false || new \DateTimeImmutable($row['expira_en']) < new \DateTimeImmutable()) {
            $this->pdo->prepare('INSERT INTO portal_login_intentos (id, contacto_id, created_at) VALUES (?, ?, ?)')
                ->execute([typedock_uuid7(), $contactoId, (new \DateTimeImmutable())->format('Y-m-d H:i:s')]);
            return false;
        }

        $this->pdo->prepare('UPDATE portal_contacto_codigos SET usado_en = ? WHERE id = ?')
            ->execute([(new \DateTimeImmutable())->format('Y-m-d H:i:s'), $row['id']]);
        $this->pdo->prepare('DELETE FROM portal_login_intentos WHERE contacto_id = ?')->execute([$contactoId]);

        return true;
    }
}

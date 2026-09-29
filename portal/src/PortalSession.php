<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/** Sesión del portal público: CSRF y mensajes flash. */
final class PortalSession
{
    public const CONTACTO = 'portal_contacto_id';

    public static function iniciar(): void
    {
        typedock_session_start();
    }

    public static function csrf(): string
    {
        self::iniciar();
        if (empty($_SESSION['portal_csrf']) || !is_string($_SESSION['portal_csrf'])) {
            $_SESSION['portal_csrf'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['portal_csrf'];
    }

    public static function csrfValido(): bool
    {
        $enviado = (string) ($_POST['_csrf'] ?? '');
        return $enviado !== '' && hash_equals(self::csrf(), $enviado);
    }

    public static function flash(string $tipo, string $mensaje): void
    {
        self::iniciar();
        $_SESSION['portal_flash'] = ['tipo' => $tipo, 'mensaje' => $mensaje];
    }

    /** @return array{tipo: string, mensaje: string}|null */
    public static function tomarFlash(): ?array
    {
        self::iniciar();
        $f = $_SESSION['portal_flash'] ?? null;
        unset($_SESSION['portal_flash']);
        return is_array($f) ? $f : null;
    }
}

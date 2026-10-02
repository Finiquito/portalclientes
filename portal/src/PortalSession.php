<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/** Sesión del portal público: CSRF y mensajes flash. */
final class PortalSession
{
    public const CONTACTO = 'portal_contacto_id';

    /** «Ver como cliente»: quién del equipo está mirando y adónde vuelve al salir. */
    public const VISTA = 'portal_vista_previa';

    /** @return array{quien: string, volver: string}|null */
    public static function vistaPrevia(): ?array
    {
        self::iniciar();
        $v = $_SESSION[self::VISTA] ?? null;
        return is_array($v) ? $v : null;
    }

    /** Entra al portal como un contacto, en modo de sólo lectura. */
    public static function iniciarVistaPrevia(string $contactoId, string $quien, string $volver): void
    {
        self::iniciar();
        $_SESSION[self::CONTACTO] = $contactoId;
        $_SESSION[self::VISTA] = ['quien' => $quien, 'volver' => $volver];
    }

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

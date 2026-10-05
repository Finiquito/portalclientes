<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * Trabajo que no debe hacer esperar a quien navega (vaciar la cola de correos, avisos agrupados…).
 *
 * En el servidor web se ejecuta DESPUÉS de entregar la página: se cierra la respuesta
 * (fastcgi_finish_request / litespeed_finish_request) y recién ahí se trabaja. Así un SMTP lento
 * no deja la página colgada ni hace que el hosting corte la conexión.
 * En la consola (pruebas, cron por CLI) se ejecuta al tiro.
 */
final class Diferido
{
    /** @var array<int, callable> */
    private static array $trabajos = [];
    private static bool $registrado = false;

    public static function alTerminar(callable $f): void
    {
        if (PHP_SAPI === 'cli') {
            self::correr($f);
            return;
        }
        self::$trabajos[] = $f;
        if (!self::$registrado) {
            self::$registrado = true;
            register_shutdown_function([self::class, 'ejecutar']);
        }
    }

    /** @internal la llama PHP al terminar la request */
    public static function ejecutar(): void
    {
        if (self::$trabajos === []) {
            return;
        }
        // Que el navegador reciba la página ya; lo que sigue corre aparte.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
        ignore_user_abort(true);
        @set_time_limit(90);
        $lista = self::$trabajos;
        self::$trabajos = [];
        foreach ($lista as $f) {
            self::correr($f);
        }
    }

    private static function correr(callable $f): void
    {
        try {
            $f();
        } catch (\Throwable $e) {
            error_log('[portal] trabajo diferido: ' . $e->getMessage());   // nunca romper una página por un correo
        }
    }
}

<?php
declare(strict_types=1);

// Funciones globales que el núcleo de TypeDock expone a los plugins (versión de desarrollo).

if (!function_exists('typedock_uuid7')) {
    function typedock_uuid7(): string
    {
        $ms = (int) floor(microtime(true) * 1000);
        $b = random_bytes(16);
        $b[0] = chr(($ms >> 40) & 0xff);
        $b[1] = chr(($ms >> 32) & 0xff);
        $b[2] = chr(($ms >> 24) & 0xff);
        $b[3] = chr(($ms >> 16) & 0xff);
        $b[4] = chr(($ms >> 8) & 0xff);
        $b[5] = chr($ms & 0xff);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x70);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
    }
}

if (!function_exists('typedock_session_start')) {
    function typedock_session_start(): void
    {
        if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
            session_start();
        } elseif (PHP_SAPI === 'cli' && !isset($_SESSION)) {
            $_SESSION = [];
        }
    }
}

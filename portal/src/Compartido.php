<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * Configuración compartida de la beta: el SMTP y la clave de IA que pone el dueño de Prisma para todas
 * las agencias, guardados en archivos fuera del web-root y del repositorio:
 *
 *   <PORTAL_SECRETOS_DIR>/correo.json  {"host","puerto","seg","usuario","clave","desde","tope_diario"}
 *   <PORTAL_SECRETOS_DIR>/ia.json      {"proveedor","clave","dias_prueba","tope_diario"}
 *
 * El directorio llega por la constante o la variable de entorno PORTAL_SECRETOS_DIR. Sin eso no hay
 * respaldo compartido y el portal funciona igual que antes (cada agencia pone lo suyo).
 */
final class Compartido
{
    /** Sólo pruebas: fuerza el directorio. */
    public static ?string $dir = null;

    /** Valor de configuración del servidor: constante, variable de entorno o $_SERVER (SetEnv de Apache/LiteSpeed). */
    public static function entorno(string $nombre): string
    {
        if (defined($nombre)) {
            return trim((string) constant($nombre));
        }
        $v = getenv($nombre);
        if (is_string($v) && $v !== '') {
            return trim($v);
        }
        $v = $_SERVER[$nombre] ?? '';
        return is_string($v) ? trim($v) : '';
    }

    public static function directorio(): string
    {
        return rtrim(self::$dir ?? self::entorno('PORTAL_SECRETOS_DIR'), '/');
    }

    /** @return array<string, mixed>|null */
    private static function leer(string $archivo): ?array
    {
        $dir = self::directorio();
        if ($dir === '') {
            return null;
        }
        $ruta = $dir . '/' . $archivo;
        if (!is_file($ruta)) {
            return null;
        }
        $j = json_decode((string) @file_get_contents($ruta), true);
        return is_array($j) ? $j : null;
    }

    /** @return array{host: string, puerto: int, seg: string, usuario: string, clave: string, desde: string, tope: int}|null */
    public static function correo(): ?array
    {
        $j = self::leer('correo.json');
        if ($j === null) {
            return null;
        }
        $host = trim((string) ($j['host'] ?? ''));
        $user = trim((string) ($j['usuario'] ?? ''));
        $clave = (string) ($j['clave'] ?? '');
        $desde = trim((string) ($j['desde'] ?? ''));
        if ($host === '' || $user === '' || $clave === '' || !filter_var($desde, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        $seg = (string) ($j['seg'] ?? 'ssl');
        return [
            'host' => $host, 'puerto' => max(1, min(65535, (int) ($j['puerto'] ?? 465))),
            'seg' => in_array($seg, ['ssl', 'tls', 'ninguna'], true) ? $seg : 'ssl',
            'usuario' => $user, 'clave' => $clave, 'desde' => $desde,
            'tope' => max(1, (int) ($j['tope_diario'] ?? 100)),
        ];
    }

    /** @return array{proveedor: string, clave: string, dias: int, tope: int}|null */
    public static function ia(): ?array
    {
        $j = self::leer('ia.json');
        $clave = trim((string) ($j['clave'] ?? ''));
        if ($j === null || $clave === '') {
            return null;
        }
        return [
            'proveedor' => in_array($j['proveedor'] ?? 'anthropic', ['anthropic', 'openai', 'google'], true) ? (string) ($j['proveedor'] ?? 'anthropic') : 'anthropic',
            'clave' => $clave, 'dias' => max(1, (int) ($j['dias_prueba'] ?? 30)), 'tope' => max(1, (int) ($j['tope_diario'] ?? 60)),
        ];
    }

    /** Dirección de la página que explica quién responde por los datos del portal y qué cookies usa (el enlace del pie de ingreso). */
    public static function urlPrivacidad(): string
    {
        return self::entorno('PORTAL_URL_PRIVACIDAD') ?: 'https://usaprisma.com/privacidad/';
    }
}

<?php
declare(strict_types=1);

namespace Prisma;

final class Proc
{
    /**
     * Corre un comando sin shell. Lo sensible va por stdin, nunca por argumentos (se ven en `ps`).
     *
     * @param list<string> $cmd
     * @return array{0: int, 1: string, 2: string} código, stdout, stderr
     */
    public static function correr(array $cmd, ?string $stdin = null, ?string $cwd = null): array
    {
        $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
        if (!is_resource($p)) {
            return [127, '', 'no se pudo ejecutar ' . $cmd[0]];
        }
        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($p), $out, $err];
    }

    /** Corre lib/instancia.php dentro de una instancia (con el vendor/ de esa agencia). @return array<string, mixed> */
    public static function instancia(string $accion, array $datos): array
    {
        [$code, $out, $err] = self::correr([PHP_BINARY, dirname(__DIR__) . '/lib/instancia.php', $accion], json_encode($datos));
        $lineas = array_values(array_filter(array_map('trim', explode("\n", $out))));
        $j = $lineas === [] ? null : json_decode((string) end($lineas), true);
        if ($code !== 0 || !is_array($j) || empty($j['ok'])) {
            throw new \RuntimeException(is_array($j) && isset($j['error']) ? (string) $j['error'] : trim($err . "\n" . $out));
        }
        return $j;
    }
}

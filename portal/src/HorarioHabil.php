<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * Horario hábil del país del cliente: los correos del sistema sólo salen de lunes a viernes
 * entre una hora de inicio y una de término (por defecto 07:00–19:00, hora local del cliente).
 * Fuera de esa ventana quedan en cola hasta la próxima apertura.
 *
 * No considera feriados (cada país tiene los suyos); si hace falta, se pueden agregar después.
 */
final class HorarioHabil
{
    public const PAIS_DEFECTO = 'CL';
    public const INICIO = 7;
    public const FIN    = 19;

    /** código => [nombre, zona horaria IANA]. */
    public const PAISES = [
        'CL' => ['Chile', 'America/Santiago'],
        'AR' => ['Argentina', 'America/Argentina/Buenos_Aires'],
        'BO' => ['Bolivia', 'America/La_Paz'],
        'BR' => ['Brasil', 'America/Sao_Paulo'],
        'CO' => ['Colombia', 'America/Bogota'],
        'CR' => ['Costa Rica', 'America/Costa_Rica'],
        'DO' => ['República Dominicana', 'America/Santo_Domingo'],
        'EC' => ['Ecuador', 'America/Guayaquil'],
        'ES' => ['España', 'Europe/Madrid'],
        'US' => ['Estados Unidos (costa este)', 'America/New_York'],
        'FR' => ['Francia', 'Europe/Paris'],
        'GT' => ['Guatemala', 'America/Guatemala'],
        'MX' => ['México', 'America/Mexico_City'],
        'PA' => ['Panamá', 'America/Panama'],
        'PE' => ['Perú', 'America/Lima'],
        'PY' => ['Paraguay', 'America/Asuncion'],
        'UY' => ['Uruguay', 'America/Montevideo'],
        'VE' => ['Venezuela', 'America/Caracas'],
    ];

    public static function paisValido(string $codigo): string
    {
        $codigo = strtoupper(trim($codigo));
        return isset(self::PAISES[$codigo]) ? $codigo : self::PAIS_DEFECTO;
    }

    public static function zonaDe(string $codigo): string
    {
        return self::PAISES[self::paisValido($codigo)][1];
    }

    public static function nombreDe(string $codigo): string
    {
        return self::PAISES[self::paisValido($codigo)][0];
    }

    /** Ajusta un rango de horas a algo coherente (0–23, con inicio < fin). @return array{0:int,1:int} */
    public static function rango(int $inicio, int $fin): array
    {
        $inicio = max(0, min(22, $inicio));
        $fin    = max($inicio + 1, min(23, $fin));
        return [$inicio, $fin];
    }

    /**
     * Primer instante en que se puede enviar: el mismo $ahora si ya está dentro del horario hábil
     * del país; si no, la próxima apertura (hora de inicio de un día de lunes a viernes).
     */
    public static function proximoEnvio(\DateTimeImmutable $ahora, string $pais, int $inicio = self::INICIO, int $fin = self::FIN): \DateTimeImmutable
    {
        [$inicio, $fin] = self::rango($inicio, $fin);
        $local = $ahora->setTimezone(new \DateTimeZone(self::zonaDe($pais)));
        $habil = (int) $local->format('N') <= 5;
        $hora  = (int) $local->format('G');

        if ($habil && $hora >= $inicio && $hora < $fin) {
            return $ahora;
        }
        // Antes de abrir en un día hábil → hoy a la hora de inicio; en otro caso, el siguiente día hábil.
        $dia = $local;
        if (!($habil && $hora < $inicio)) {
            $dia = $local->modify('+1 day');
        }
        while ((int) $dia->format('N') > 5) {
            $dia = $dia->modify('+1 day');
        }
        return $dia->setTime($inicio, 0, 0);
    }

    public static function dentro(\DateTimeImmutable $ahora, string $pais, int $inicio = self::INICIO, int $fin = self::FIN): bool
    {
        return self::proximoEnvio($ahora, $pais, $inicio, $fin) == $ahora;
    }
}

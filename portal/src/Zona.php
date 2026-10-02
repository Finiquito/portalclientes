<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * Zona horaria de la agencia (Portal · Ajustes → «País de la agencia»).
 *
 * Las reuniones se guardan en esta hora y los «hoy» del sistema se calculan con ella.
 * Al cliente se le muestra todo en la hora de su país (portal_clientes.pais).
 * Se carga una vez por petición desde los ajustes (PortalPlugin::register).
 */
final class Zona
{
    private static string $pais = HorarioHabil::PAIS_DEFECTO;

    public static function configurar(string $pais): void
    {
        self::$pais = HorarioHabil::paisValido($pais);
    }

    public static function desdeAjustes(\PDO $pdo): void
    {
        try {
            self::configurar((new AjustesService($pdo))->get('global', 'portal', 'pais_agencia', HorarioHabil::PAIS_DEFECTO));
        } catch (\Throwable) {
            self::configurar(HorarioHabil::PAIS_DEFECTO);
        }
    }

    public static function pais(): string
    {
        return self::$pais;
    }

    /** Zona IANA de la agencia (ej. America/Santiago). */
    public static function agencia(): string
    {
        return HorarioHabil::zonaDe(self::$pais);
    }

    public static function nombre(): string
    {
        return HorarioHabil::nombreDe(self::$pais);
    }

    public static function hoy(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone(self::agencia())))->format('Y-m-d');
    }

    /** 'Y-m-d H:i' de la hora de la agencia a la de un país (sin hora, devuelve la fecha tal cual). */
    public static function aPais(?string $fecha, string $pais): string
    {
        $fecha = (string) $fecha;
        if (!str_contains($fecha, ':')) {
            return $fecha;
        }
        return SolicitudService::convertir($fecha, self::agencia(), HorarioHabil::zonaDe($pais));
    }

    /** 'Y-m-d H:i' de la hora de un país a la de la agencia. */
    public static function desdePais(?string $fecha, string $pais): string
    {
        $fecha = (string) $fecha;
        if (!str_contains($fecha, ':')) {
            return $fecha;
        }
        return SolicitudService::convertir($fecha, HorarioHabil::zonaDe($pais), self::agencia());
    }
}

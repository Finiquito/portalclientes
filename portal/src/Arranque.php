<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * Primer arranque de una agencia beta. `prisma crear` deja en config.php:
 *   PORTAL_PRIMER_COORDINADOR  correo de quien será Coordinación general (entra por /equipo con código al correo)
 *   PORTAL_AGENCIA_NOMBRE      nombre inicial de la agencia
 *   PORTAL_CRON_TOKEN          clave de la tarea programada de correos
 * y acá, la primera vez que el plugin arranca con las tablas listas, se materializan. Una sola vez
 * (marca «arranque_hecho»); sin esas variables no hace nada, así que una instalación normal no cambia.
 */
final class Arranque
{
    public static function asegurar(\PDO $pdo): void
    {
        $correo  = strtolower(Compartido::entorno('PORTAL_PRIMER_COORDINADOR'));
        $cron    = Compartido::entorno('PORTAL_CRON_TOKEN');
        $agencia = Compartido::entorno('PORTAL_AGENCIA_NOMBRE');
        if ($correo === '' && $cron === '' && $agencia === '') {
            return;
        }
        $a = new AjustesService($pdo);
        if ($a->get('global', 'portal', 'arranque_hecho') === '1') {
            return;
        }
        if ($cron !== '' && $a->get('global', 'portal', 'cron_token') === '') {
            $a->set('global', 'portal', 'cron_token', $cron);
        }
        if ($agencia !== '' && trim($a->get('global', 'portal', 'nombre_equipo')) === '') {
            $a->set('global', 'portal', 'nombre_equipo', mb_substr($agencia, 0, 120));
        }
        if (filter_var($correo, FILTER_VALIDATE_EMAIL) !== false) {
            $eq = new EquipoService($pdo);
            if ($eq->findByEmail($correo) === null) {
                $nombre = ucfirst((string) preg_replace('/[._\-+\d]+/', ' ', explode('@', $correo)[0]));
                $eq->create(['nombre' => trim($nombre) !== '' ? trim($nombre) : 'Coordinación', 'email' => $correo, 'rol' => 'coordinador', 'activo' => 1]);
            }
        }
        $a->set('global', 'portal', 'arranque_hecho', '1');
    }
}

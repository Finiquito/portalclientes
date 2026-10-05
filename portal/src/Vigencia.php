<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * ¿Sigue haciendo falta un aviso que espera en la cola o en el buzón?
 *
 * Algunos avisos sólo tienen sentido mientras algo siga pendiente: «tienes una tarea»,
 * «hay contenido para revisar», «vence mañana», la invitación a una reunión. Se guardan con
 * una marca de vigencia («tarea:<id>», «revision:<id>»…) y justo antes de enviarlos se
 * revisa; si ya no aplica (la tarea se completó, el contenido se aprobó, la reunión se borró),
 * el aviso se descarta. Una marca desconocida o vacía siempre se envía.
 */
final class Vigencia
{
    public static function tarea(string $id): string { return 'tarea:' . $id; }
    public static function revision(string $entregaId): string { return 'revision:' . $entregaId; }
    public static function contenido(string $id): string { return 'contenido:' . $id; }
    /** Con $version, el aviso vale sólo para esa versión de la invitación (si cambió la hora, sale la nueva). */
    public static function reunion(string $id, ?int $version = null): string { return 'reunion:' . $id . ($version !== null ? ':' . $version : ''); }

    public static function sigue(\PDO $pdo, ?string $marca): bool
    {
        if ($marca === null || !str_contains($marca, ':')) {
            return true;
        }
        [$tipo, $id] = explode(':', $marca, 2);
        try {
            return match ($tipo) {
                // Tarea todavía abierta (ni lista, ni entregada, ni archivada).
                'tarea' => self::uno($pdo, "SELECT 1 FROM portal_tareas WHERE id = ? AND estado IN ('pendiente', 'en_progreso') AND COALESCE(archivada, 0) = 0", $id),
                // Entrega publicada con algo que el cliente aún no revisa.
                'revision' => self::uno($pdo, "SELECT 1 FROM portal_entregas e WHERE e.id = ? AND e.estado = 'publicada'
                    AND EXISTS (SELECT 1 FROM portal_contenidos c WHERE c.entrega_id = e.id AND c.estado NOT IN ('aprobado', 'cambios'))", $id),
                // Contenido todavía por revisar, en una entrega publicada.
                'contenido' => self::uno($pdo, "SELECT 1 FROM portal_contenidos c JOIN portal_entregas e ON e.id = c.entrega_id
                    WHERE c.id = ? AND c.estado NOT IN ('aprobado', 'cambios') AND e.estado = 'publicada'", $id),
                // Reunión que existe y no ha terminado.
                'reunion' => self::reunionVigente($pdo, ...array_pad(explode(':', $id, 2), 2, null)),
                default => true,
            };
        } catch (\Throwable) {
            return true;   // ante la duda, se envía
        }
    }

    private static function uno(\PDO $pdo, string $sql, string $id): bool
    {
        $st = $pdo->prepare($sql);
        $st->execute([$id]);
        return $st->fetchColumn() !== false;
    }

    private static function reunionVigente(\PDO $pdo, string $id, ?string $version = null): bool
    {
        $st = $pdo->prepare('SELECT fecha, duracion_min, ics_seq FROM portal_reuniones WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch();
        if ($r === false || ($version !== null && (int) $r['ics_seq'] !== (int) $version)) {
            return false;
        }
        $ini = ReunionService::aUtc((string) $r['fecha']);
        $ahora = Notifier::$ahora ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return $ini === null || $ini->modify('+' . max(5, (int) $r['duracion_min']) . ' minutes') > $ahora;
    }
}

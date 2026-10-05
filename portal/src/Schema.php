<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * Agrega, de forma idempotente, las columnas nuevas de portal_tareas.
 *
 * Por qué no en una migración: ALTER TABLE ... ADD COLUMN falla si se
 * ejecuta dos veces, y no sabemos si el migrador de Core recuerda qué
 * archivos ya corrió. Acá primero se comprueba con un SELECT barato
 * (una vez por request) y sólo se altera si falta algo. La sintaxis
 * "ADD COLUMN x TIPO NOT NULL DEFAULT v" es la misma en MySQL, Postgres
 * y SQLite.
 */
final class Schema
{
    private static bool $listo = false;

    /** tabla => [columna => definición]. */
    private const COLUMNAS = [
        'portal_tareas' => [
            'tipo'            => "VARCHAR(16) NOT NULL DEFAULT 'tarea'",
            'completada_en'   => 'VARCHAR(32)',
            'visible_cliente' => 'SMALLINT NOT NULL DEFAULT 1',
            'archivada'       => 'SMALLINT NOT NULL DEFAULT 0',
        ],
        'portal_archivos' => [
            'orden' => 'INTEGER NOT NULL DEFAULT 0',
        ],
        'portal_reuniones' => [
            'enlace_meet'      => 'VARCHAR(500)',
            'enlace_grabacion' => 'VARCHAR(500)',
            'duracion_min'     => 'INTEGER NOT NULL DEFAULT 60',
            'transcripcion'    => 'TEXT',
            'acuerdos'         => 'TEXT',
            'analisis'         => 'TEXT',
            'publicada'        => 'SMALLINT NOT NULL DEFAULT 1',
            'resumen_publicado' => 'SMALLINT NOT NULL DEFAULT 1',
            'prox_fecha'       => 'VARCHAR(32)',
            'prox_titulo'      => 'VARCHAR(255)',
            'prox_reunion_id'  => 'VARCHAR(36)',
            'ia_generado_en'   => 'VARCHAR(32)',
            'ia_modelo'        => 'VARCHAR(64)',
            'ics_seq'          => 'INTEGER NOT NULL DEFAULT 0',   // versión de la invitación de calendario
        ],
        'portal_clientes' => [
            'pais' => "VARCHAR(2) NOT NULL DEFAULT 'CL'",
        ],
        'portal_contactos' => [
            'invitado_en'   => 'VARCHAR(32)',
            'primer_acceso' => 'VARCHAR(32)',
            'ultimo_acceso' => 'VARCHAR(32)',
        ],
        // Brief de cada pieza (grillas): lo que el cliente ve junto al contenido.
        'portal_contenidos' => [
            'etiqueta' => 'VARCHAR(80)',
            'pilar'    => 'VARCHAR(120)',
            'objetivo' => 'TEXT',
            'laminas'  => 'TEXT',
            'notas'    => 'TEXT',
        ],
        'portal_comentarios' => [
            'version_id' => 'VARCHAR(36)',
            'ubicacion'  => 'VARCHAR(64)',
        ],
    ];

    public static function asegurar(\PDO $pdo): void
    {
        if (self::$listo) {
            return;
        }
        self::$listo = true;

        foreach (self::COLUMNAS as $tabla => $columnas) {
            if (self::existe($pdo, $tabla, implode(', ', array_keys($columnas)))) {
                continue;
            }
            foreach ($columnas as $col => $def) {
                if (!self::existe($pdo, $tabla, $col)) {
                    $pdo->exec("ALTER TABLE {$tabla} ADD COLUMN {$col} {$def}");
                }
            }
        }

        self::tablaPropuestas($pdo);
        self::tablaCola($pdo);
        self::tablasEquipo($pdo);
        self::tablaSolicitudes($pdo);
        self::tablasAvisos($pdo);
        self::ampliarTextos($pdo);
    }

    /**
     * En MySQL/MariaDB un TEXT guarda como máximo 65.535 bytes: una transcripción de
     * reunión de ~1 hora (o un análisis largo, o un correo HTML grande) no cabe y el
     * guardado falla en modo estricto. Se pasan a MEDIUMTEXT (16 MB) una sola vez.
     * SQLite y Postgres no tienen ese límite: no se toca nada.
     */
    private const TEXTOS_LARGOS = [
        'portal_reuniones'    => ['transcripcion', 'resumen', 'analisis'],
        'portal_correos_cola' => ['texto', 'html'],
        'portal_tareas'       => ['descripcion'],
    ];

    private static function ampliarTextos(\PDO $pdo): void
    {
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            return;
        }
        try {
            $stmt = $pdo->query(
                "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND DATA_TYPE IN ('text', 'tinytext')
                   AND TABLE_NAME IN ('" . implode("','", array_keys(self::TEXTOS_LARGOS)) . "')"
            );
            $cortas = $stmt !== false ? $stmt->fetchAll(\PDO::FETCH_NUM) : [];
        } catch (\Throwable) {
            return;
        }
        foreach ($cortas as [$tabla, $col]) {
            if (in_array($col, self::TEXTOS_LARGOS[$tabla] ?? [], true)) {
                $pdo->exec("ALTER TABLE {$tabla} MODIFY {$col} MEDIUMTEXT");
            }
        }
    }

    /**
     * Tabla de propuestas de tareas de una reunión. También está en la migración 0005,
     * pero acá se garantiza por si el migrador no volvió a correr tras actualizar el plugin.
     */
    private static function tablaPropuestas(\PDO $pdo): void
    {
        if (self::existe($pdo, 'portal_reunion_propuestas', 'id, reunion_id, origen')) {
            return;
        }
        $cols = "id VARCHAR(36) PRIMARY KEY, reunion_id VARCHAR(36) NOT NULL, titulo VARCHAR(255) NOT NULL, descripcion TEXT,
                 asignado VARCHAR(16) NOT NULL DEFAULT 'equipo', fecha_vencimiento VARCHAR(32), visible_cliente SMALLINT NOT NULL DEFAULT 0,
                 estado VARCHAR(16) NOT NULL DEFAULT 'propuesta', tarea_id VARCHAR(36), orden INTEGER NOT NULL DEFAULT 0,
                 origen VARCHAR(8) NOT NULL DEFAULT 'manual', created_at VARCHAR(32)";
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS portal_reunion_propuestas ({$cols}, FOREIGN KEY (reunion_id) REFERENCES portal_reuniones(id) ON DELETE CASCADE)");
        } catch (\Throwable) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS portal_reunion_propuestas ({$cols})");
        }
    }

    /** Cola de correos pendientes de salir (horario hábil del cliente). También en la migración 0006. */
    private static function tablaCola(\PDO $pdo): void
    {
        if (self::existe($pdo, 'portal_correos_cola', 'id, destino, estado, enviar_desde')) {
            return;
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS portal_correos_cola (
            id VARCHAR(36) PRIMARY KEY, cliente_id VARCHAR(36), contacto_id VARCHAR(36),
            destino VARCHAR(255) NOT NULL, asunto VARCHAR(500) NOT NULL, texto TEXT, html TEXT,
            enviar_desde VARCHAR(19) NOT NULL, estado VARCHAR(12) NOT NULL DEFAULT 'pendiente',
            intentos INTEGER NOT NULL DEFAULT 0, error VARCHAR(500),
            created_at VARCHAR(32), enviado_en VARCHAR(32)
        )");
    }

    /** Usuarios de agencia (migración 0007): se ejecuta el mismo archivo si falta alguna tabla. */
    /** Solicitudes del cliente (pedido, presupuesto, reunión, problema). También en la migración 0008. */
    private static function tablaSolicitudes(\PDO $pdo): void
    {
        if (self::existe($pdo, 'portal_solicitudes', 'id, cliente_id, tipo, estado, urgencia, tarea_id')) {
            return;
        }
        $sql = (string) @file_get_contents(dirname(__DIR__) . '/migrations/0008_solicitudes.sql');
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? '';
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            try {
                $pdo->exec($stmt);
            } catch (\Throwable) {
                // sin la clave foránea (bases que no la aceptan)
                $pdo->exec((string) preg_replace('/,\s*FOREIGN KEY[^)]*\)[^)]*\)/', '', $stmt));
            }
        }
    }

    private static function tablasEquipo(\PDO $pdo): void
    {
        if (self::existe($pdo, 'portal_equipo', 'id, email, rol, activo')
            && self::existe($pdo, 'portal_equipo_asignaciones', 'usuario_id, cliente_id, proyecto_id')
            && self::existe($pdo, 'portal_equipo_codigos', 'usuario_id, codigo')
            && self::existe($pdo, 'portal_equipo_intentos', 'usuario_id')) {
            return;
        }
        $sql = (string) @file_get_contents(dirname(__DIR__) . '/migrations/0007_equipo_agencia.sql');
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? '';
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            $pdo->exec($stmt);
        }
    }

    /** Buzón de avisos agrupados, marcas de «ya avisado» y convocados de reuniones (migración 0009). */
    private static function tablasAvisos(\PDO $pdo): void
    {
        if (!self::existe($pdo, 'portal_avisos_buzon', 'id, usuario_id, clave, enviado_en')
            || !self::existe($pdo, 'portal_avisos_marcas', 'clave')
            || !self::existe($pdo, 'portal_reunion_asistentes', 'reunion_id, asistente_tipo, asistente_usuario_id, asistente_contacto_id')) {
            $sql = (string) @file_get_contents(dirname(__DIR__) . '/migrations/0009_avisos.sql');
            $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? '';
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                $pdo->exec($stmt);
            }
        }
        // Columnas que llegaron después: invitación de calendario en la cola y marca de vigencia
        // (ver Vigencia: si el aviso ya no hace falta, no sale).
        foreach ([['portal_correos_cola', 'ics', 'TEXT'], ['portal_correos_cola', 'vigencia', 'VARCHAR(80)'], ['portal_avisos_buzon', 'vigencia', 'VARCHAR(80)']] as [$t, $col, $def]) {
            if (self::existe($pdo, $t, 'id') && !self::existe($pdo, $t, $col)) {
                $pdo->exec("ALTER TABLE {$t} ADD COLUMN {$col} {$def}");
            }
        }
    }

    /** Sólo para pruebas. */
    public static function reiniciar(): void
    {
        self::$listo = false;
    }

    private static function existe(\PDO $pdo, string $tabla, string $columnas): bool
    {
        try {
            $r = $pdo->query("SELECT {$columnas} FROM {$tabla} WHERE 1 = 0");
            return $r !== false;
        } catch (\Throwable) {
            return false;
        }
    }
}

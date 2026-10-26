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
            'depende_de'      => 'VARCHAR(36)',   // tarea anterior (línea de tiempo)
            'duracion_dias'   => 'INTEGER',       // días hábiles, para las que dependen de otra
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
            'fase_id'          => 'VARCHAR(36)',                  // línea de tiempo: la etapa a la que pertenece
            'es_hito'          => 'SMALLINT NOT NULL DEFAULT 0',  // se ve como hito en la línea de tiempo
        ],
        'portal_entregas' => [
            'fase_id' => 'VARCHAR(36)',
        ],
        'portal_proyectos' => [
            'con_leads' => 'SMALLINT NOT NULL DEFAULT 0',   // «Contactos ganados» activo en este proyecto
            'mc_audiencia' => 'VARCHAR(32)',                // audiencia de Mailchimp con que se contrastan sus contactos
            'mc_audiencia_nombre' => 'VARCHAR(255)',
            'publico' => "VARCHAR(8) NOT NULL DEFAULT ''",   // a quién le habla la campaña: b2c, b2b o b2c,b2b
        ],
        // Resumen de Mailchimp de cada contacto (sólo lectura: estado, fechas, calificación, actividad reciente).
        'portal_leads' => [
            'mc_estado'      => 'VARCHAR(16)',
            'mc_datos'       => 'TEXT',
            'mc_revisado_en' => 'VARCHAR(19)',
        ],
        'portal_clientes' => [
            'pais' => "VARCHAR(2) NOT NULL DEFAULT 'CL'",
            'responsable_id' => 'VARCHAR(36)',   // quien lleva la cuenta (Coordinación de cuentas o general)
        ],
        'portal_contactos' => [
            'pais'          => 'VARCHAR(2)',      // si vive en otro país que su empresa (vacío = el del cliente)
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
            'actualizado_en' => 'VARCHAR(19)',   // última vez que el equipo le avisó al cliente de un cambio (versión nueva o actualización)
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

        self::tablasLeads($pdo);   // antes de las columnas: portal_leads recibe columnas nuevas
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

        self::tablaLeadLinea($pdo);
        self::tablasMensajes($pdo);
        self::tablaPropuestas($pdo);
        self::tablaCola($pdo);
        self::tablasEquipo($pdo);
        self::tablaSolicitudes($pdo);
        self::tablasAvisos($pdo);
        self::tablaHitos($pdo);
        self::tablaPlantillas($pdo);
        self::tablaVistos($pdo);
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
        foreach ([['portal_correos_cola', 'ics', 'TEXT'], ['portal_correos_cola', 'vigencia', 'VARCHAR(80)'], ['portal_avisos_buzon', 'vigencia', 'VARCHAR(80)'], ['portal_avisos_buzon', 'urgente', 'INTEGER NOT NULL DEFAULT 0'], ['portal_avisos_buzon', 'para', "VARCHAR(8) NOT NULL DEFAULT 'equipo'"]] as [$t, $col, $def]) {
            if (self::existe($pdo, $t, 'id') && !self::existe($pdo, $t, $col)) {
                $pdo->exec("ALTER TABLE {$t} ADD COLUMN {$col} {$def}");
            }
        }
    }

    /** Hitos de la línea de tiempo (migración 0010). */
    private static function tablaHitos(\PDO $pdo): void
    {
        if (self::existe($pdo, 'portal_hitos', 'id, proyecto_id, fecha, cumplido_en')) {
            return;
        }
        $sql = (string) @file_get_contents(dirname(__DIR__) . '/migrations/0010_cronograma.sql');
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? '';
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            $pdo->exec($stmt);
        }
    }

    private static function tablaPlantillas(\PDO $pdo): void
    {
        if (!self::existe($pdo, 'portal_plantillas', 'id, nombre, estructura')) {
            $sql = (string) @file_get_contents(dirname(__DIR__) . '/migrations/0011_plantillas.sql');
            $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? '';
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                $pdo->exec($stmt);
            }
        }
        PlantillaService::sembrar($pdo);   // la primera vez: las de ejemplo
    }

    /** Qué vio cada persona del cliente (migración 0013). */
    private static function tablaVistos(\PDO $pdo): void
    {
        if (self::existe($pdo, 'portal_vistos', 'contacto_id, entidad_tipo, entidad_id, visto_en')) {
            return;
        }
        $sql = (string) @file_get_contents(dirname(__DIR__) . '/migrations/0013_vistos.sql');
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? '';
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            $pdo->exec($stmt);
        }
    }

    /** Contactos ganados, sus fuentes y el registro de intentos (migración 0012). */
    private static function tablasLeads(\PDO $pdo): void
    {
        if (self::existe($pdo, 'portal_leads', 'id, cliente_id, proyecto_id, estado, anonimizado_en')
            && self::existe($pdo, 'portal_lead_fuentes', 'id, token_hash, activa')
            && self::existe($pdo, 'portal_lead_intentos', 'id, ip_hash')) {
            return;
        }
        $sql = (string) @file_get_contents(dirname(__DIR__) . '/migrations/0012_leads.sql');
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? '';
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            $pdo->exec($stmt);
        }
    }

    /**
     * Línea de tiempo de los contactos ganados (migración 0014). Al crearse por primera vez copia lo que ya
     * existía: la llegada de cada contacto, su estado actual (con quién y cuándo, si se sabe) y su nota única.
     * Las etapas por las que pasó antes no se guardaron nunca, así que no se pueden reconstruir.
     */
    /** Destacadas y reacciones de Mensajes (migración 0015). */
    private static function tablasMensajes(\PDO $pdo): void
    {
        if (self::existe($pdo, 'portal_mensaje_destacadas', 'persona_tipo, persona_id, entidad_tipo, entidad_id') && self::existe($pdo, 'portal_mensaje_reacciones', 'comentario_id, persona_tipo, persona_id, clave')) {
            return;
        }
        $sql = (string) @file_get_contents(dirname(__DIR__) . '/migrations/0015_mensajes_extras.sql');
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? '';
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            try {
                $pdo->exec($stmt);
            } catch (\Throwable) {
                // ya existe
            }
        }
    }

    private static function tablaLeadLinea(\PDO $pdo): void
    {
        if (!self::existe($pdo, 'portal_lead_eventos', 'id, lead_id, tipo, a_estado, ocurrio_en, registrado_en, autor_tipo, ajustada, legado')) {
            $sql = (string) @file_get_contents(dirname(__DIR__) . '/migrations/0014_lead_linea.sql');
            $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? '';
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                $pdo->exec($stmt);
            }
        }
        // La tabla puede haberla creado el migrador: lo anterior se copia sólo si está vacía (después siempre hay llegadas).
        $hay = $pdo->query('SELECT COUNT(*) FROM portal_lead_eventos');
        if ($hay === false || (int) $hay->fetchColumn() > 0) {
            return;
        }
        $ins = $pdo->prepare(
            'INSERT INTO portal_lead_eventos (id, lead_id, cliente_id, proyecto_id, tipo, de_estado, a_estado, texto, ocurrio_en, registrado_en, autor_tipo, autor, ajustada, legado)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 1)'
        );
        $filas = $pdo->query('SELECT id, cliente_id, proyecto_id, origen, estado, notas, estado_en, estado_por, creado_por, created_at, updated_at FROM portal_leads');
        foreach ($filas ? $filas->fetchAll() : [] as $l) {
            $llego = (string) ($l['created_at'] ?: date('Y-m-d H:i:s'));
            $base = [(string) $l['id'], (string) $l['cliente_id'], (string) $l['proyecto_id']];
            $manual = (string) $l['origen'] === 'manual';
            $ins->execute([typedock_uuid7(), ...$base, 'llegada', null, null, null, $llego, $llego, $manual ? '' : 'sistema', $manual ? (string) $l['creado_por'] : '']);
            if ((string) $l['estado'] !== 'nuevo') {
                $cuando = (string) ($l['estado_en'] ?: ($l['updated_at'] ?: $llego));
                $ins->execute([typedock_uuid7(), ...$base, 'estado', 'nuevo', (string) $l['estado'], null, $cuando, $cuando, '', (string) $l['estado_por']]);
            }
            if (trim((string) $l['notas']) !== '') {
                $cuando = (string) ($l['updated_at'] ?: $llego);
                $ins->execute([typedock_uuid7(), ...$base, 'nota', null, null, (string) $l['notas'], $cuando, $cuando, '', '']);
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

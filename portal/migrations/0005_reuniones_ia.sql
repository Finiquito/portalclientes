-- Reuniones con Meet, resumen revisable y tareas propuestas (a partir de una
-- transcripción pegada, con o sin IA). Las columnas nuevas de portal_reuniones
-- las agrega Schema.php de forma idempotente.
CREATE TABLE IF NOT EXISTS portal_reunion_propuestas (
    id VARCHAR(36) PRIMARY KEY,
    reunion_id VARCHAR(36) NOT NULL,
    titulo VARCHAR(255) NOT NULL,
    descripcion TEXT,
    asignado VARCHAR(16) NOT NULL DEFAULT 'equipo',
    fecha_vencimiento VARCHAR(32),
    visible_cliente SMALLINT NOT NULL DEFAULT 0,
    estado VARCHAR(16) NOT NULL DEFAULT 'propuesta',
    tarea_id VARCHAR(36),
    orden INTEGER NOT NULL DEFAULT 0,
    origen VARCHAR(8) NOT NULL DEFAULT 'manual',
    created_at VARCHAR(32),
    FOREIGN KEY (reunion_id) REFERENCES portal_reuniones(id) ON DELETE CASCADE
);

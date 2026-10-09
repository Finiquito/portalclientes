-- Línea de tiempo del proyecto.
--   portal_hitos: metas con fecha («Entrega de logos», «Lanzamiento»). Se cumplen solas cuando
--   termina su fase (si tienen) o se marcan a mano (cumplido_en).
--   portal_tareas.depende_de / duracion_dias (las agrega Schema.php): una tarea puede depender de
--   otra y durar N días hábiles; mientras la anterior no termine, sus fechas son estimadas.
-- Schema.php también garantiza esta tabla por si el migrador no vuelve a correr.

CREATE TABLE IF NOT EXISTS portal_hitos (
    id VARCHAR(36) PRIMARY KEY,
    proyecto_id VARCHAR(36) NOT NULL,
    fase_id VARCHAR(36),
    nombre VARCHAR(255) NOT NULL,
    fecha VARCHAR(10) NOT NULL,
    visible_cliente SMALLINT NOT NULL DEFAULT 1,
    cumplido_en VARCHAR(19),
    created_at VARCHAR(19),
    updated_at VARCHAR(19)
);

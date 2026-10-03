-- Avisos por persona del equipo.
--   portal_avisos_buzon: lo que espera para salir agrupado (cada 5 avisos o 3 horas) o en el
--   resumen de la mañana. enviado_en NULL = pendiente.
--   portal_avisos_marcas: lo que ya se avisó una vez (vence mañana, atrasada, recordatorio de
--   reunión, resumen del día) para no repetirlo.
--   portal_reunion_asistentes (de la migración 0001) guarda los convocados de cada reunión.
-- Schema.php también garantiza estas tablas por si el migrador no vuelve a correr.

CREATE TABLE IF NOT EXISTS portal_avisos_buzon (
    id VARCHAR(36) PRIMARY KEY,
    usuario_id VARCHAR(36) NOT NULL,
    cliente_id VARCHAR(36),
    proyecto_id VARCHAR(36),
    clave VARCHAR(120),
    asunto VARCHAR(500) NOT NULL,
    detalle TEXT,
    ruta VARCHAR(255),
    created_at VARCHAR(19) NOT NULL,
    enviado_en VARCHAR(19)
);

CREATE TABLE IF NOT EXISTS portal_avisos_marcas (
    clave VARCHAR(190) PRIMARY KEY,
    created_at VARCHAR(19)
);

CREATE TABLE IF NOT EXISTS portal_reunion_asistentes (
    id VARCHAR(36) PRIMARY KEY,
    reunion_id VARCHAR(36) NOT NULL,
    asistente_tipo VARCHAR(16) NOT NULL,
    asistente_usuario_id VARCHAR(36),
    asistente_contacto_id VARCHAR(36)
);

-- Qué vio cada persona del cliente y cuándo (solicitudes, tareas, revisiones, contenidos).
-- Sirve para marcar lo nuevo: si el equipo movió algo después de la última vez que lo abrió.
-- Schema.php también garantiza esta tabla.

CREATE TABLE IF NOT EXISTS portal_vistos (
    contacto_id VARCHAR(36) NOT NULL,
    entidad_tipo VARCHAR(16) NOT NULL,
    entidad_id VARCHAR(36) NOT NULL,
    visto_en VARCHAR(19) NOT NULL,
    PRIMARY KEY (contacto_id, entidad_tipo, entidad_id)
);

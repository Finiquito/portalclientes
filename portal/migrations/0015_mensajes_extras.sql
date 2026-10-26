-- Mensajes: conversaciones destacadas (por persona) y reacciones a los mensajes.
--   portal_mensaje_destacadas: una conversación (tarea, contenido o solicitud) que una persona marcó con la estrella.
--     persona_tipo: contacto (cliente) | equipo.  Una destacada sale de «Archivadas» y se ve en «Destacados».
--   portal_mensaje_reacciones: «clave» es una de MensajeService::REACCIONES (pulgar, aplauso, triste…);
--     se guarda la clave y no el emoji para no depender del juego de caracteres de la base.
-- Schema.php también garantiza estas tablas por si el migrador no vuelve a correr.

CREATE TABLE IF NOT EXISTS portal_mensaje_destacadas (
    persona_tipo VARCHAR(8) NOT NULL,
    persona_id VARCHAR(36) NOT NULL,
    entidad_tipo VARCHAR(16) NOT NULL,
    entidad_id VARCHAR(36) NOT NULL,
    created_at VARCHAR(19) NOT NULL,
    PRIMARY KEY (persona_tipo, persona_id, entidad_tipo, entidad_id)
);

CREATE TABLE IF NOT EXISTS portal_mensaje_reacciones (
    comentario_id VARCHAR(36) NOT NULL,
    persona_tipo VARCHAR(8) NOT NULL,
    persona_id VARCHAR(36) NOT NULL,
    clave VARCHAR(16) NOT NULL,
    created_at VARCHAR(19) NOT NULL,
    PRIMARY KEY (comentario_id, persona_tipo, persona_id, clave)
);

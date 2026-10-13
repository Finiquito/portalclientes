-- Contactos ganados (leads): los clientes de nuestro cliente que llegan por sus campañas.
-- No tienen nada que ver con portal_contactos (las personas del cliente que entran al portal).
--   portal_leads: cada contacto ganado, siempre de un proyecto con «Contactos ganados» activo
--                 (portal_proyectos.con_leads, lo agrega Schema.php).
--   portal_lead_fuentes: formularios o webhooks (Make, Zapier, la landing) con su token.
--                 Sólo se guarda el hash del token; se puede revocar o rotar.
--   portal_lead_intentos: registro corto (se purga) para el tope por IP y por fuente.
-- Schema.php también garantiza estas tablas por si el migrador no vuelve a correr.

CREATE TABLE IF NOT EXISTS portal_leads (
    id VARCHAR(36) PRIMARY KEY,
    cliente_id VARCHAR(36) NOT NULL,
    proyecto_id VARCHAR(36) NOT NULL,
    fuente_id VARCHAR(36),
    nombre VARCHAR(255),
    correo VARCHAR(255),
    telefono VARCHAR(64),
    empresa VARCHAR(255),
    origen VARCHAR(16) NOT NULL DEFAULT 'manual',
    campana VARCHAR(255),
    utm_source VARCHAR(120),
    utm_medium VARCHAR(120),
    utm_campaign VARCHAR(255),
    estado VARCHAR(16) NOT NULL DEFAULT 'nuevo',
    notas TEXT,
    proximo_paso VARCHAR(255),
    proximo_paso_fecha VARCHAR(10),
    estado_en VARCHAR(19),
    estado_por VARCHAR(255),
    creado_por VARCHAR(255),
    anonimizado_en VARCHAR(19),
    created_at VARCHAR(19),
    updated_at VARCHAR(19)
);

CREATE TABLE IF NOT EXISTS portal_lead_fuentes (
    id VARCHAR(36) PRIMARY KEY,
    cliente_id VARCHAR(36) NOT NULL,
    proyecto_id VARCHAR(36) NOT NULL,
    nombre VARCHAR(255) NOT NULL,
    token_hash VARCHAR(64) NOT NULL,
    url_gracias VARCHAR(500),
    activa SMALLINT NOT NULL DEFAULT 1,
    total INTEGER NOT NULL DEFAULT 0,
    ultimo_uso_en VARCHAR(19),
    revocada_en VARCHAR(19),
    created_at VARCHAR(19),
    updated_at VARCHAR(19)
);

CREATE TABLE IF NOT EXISTS portal_lead_intentos (
    id VARCHAR(36) PRIMARY KEY,
    fuente_id VARCHAR(36),
    ip_hash VARCHAR(64) NOT NULL,
    resultado VARCHAR(12) NOT NULL,
    created_at VARCHAR(19) NOT NULL
);

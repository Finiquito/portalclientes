-- Inscripciones de la lista de espera. La copia local es la fuente de verdad:
-- si Mailchimp falla, la inscripción no se pierde y se reintenta desde el admin.
CREATE TABLE IF NOT EXISTS invitaciones (
    id VARCHAR(36) PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    nombre VARCHAR(120),
    tamano VARCHAR(16),
    rubro VARCHAR(60),
    origen VARCHAR(20),
    ip_hash VARCHAR(64),
    mc_estado VARCHAR(12) NOT NULL DEFAULT 'pendiente',
    mc_error VARCHAR(500),
    created_at VARCHAR(32),
    updated_at VARCHAR(32),
    UNIQUE (email)
);

-- Ajustes clave/valor del plugin (la clave de Mailchimp va cifrada).
CREATE TABLE IF NOT EXISTS invitaciones_ajustes (
    clave VARCHAR(64) PRIMARY KEY,
    valor TEXT,
    updated_at VARCHAR(32)
);

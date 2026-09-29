-- Los contactos de cliente entran al portal con un código de un solo uso
-- enviado por correo (sin contraseña que mantener). El campo password_hash
-- de portal_contactos sigue existiendo (NOT NULL) pero se guarda vacío —
-- no se usa para nada, evitamos tocar el tipo de columna entre motores
-- (MySQL/Postgres/SQLite tienen sintaxis ALTER distinta).

CREATE TABLE IF NOT EXISTS portal_contacto_codigos (
    id VARCHAR(36) PRIMARY KEY,
    contacto_id VARCHAR(36) NOT NULL,
    codigo VARCHAR(6) NOT NULL,
    expira_en VARCHAR(32) NOT NULL,
    usado_en VARCHAR(32),
    created_at VARCHAR(32),
    FOREIGN KEY (contacto_id) REFERENCES portal_contactos(id) ON DELETE CASCADE
);

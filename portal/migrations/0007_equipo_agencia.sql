-- Usuarios de agencia (front /equipo). Los crea un admin de TypeDock en
-- Portal · Equipo y les asigna clientes o proyectos. Entran sin contraseña,
-- con un código por correo, igual que los contactos de cliente.
--   rol 'equipo'      = ve sólo lo asignado
--   rol 'coordinador' = ve todos los clientes
-- Asignación con proyecto_id NULL = el cliente completo (incluye proyectos futuros).
-- Schema.php también garantiza estas tablas por si el migrador no vuelve a correr.

CREATE TABLE IF NOT EXISTS portal_equipo (
    id VARCHAR(36) PRIMARY KEY,
    nombre VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    cargo VARCHAR(120),
    rol VARCHAR(16) NOT NULL DEFAULT 'equipo',
    activo SMALLINT NOT NULL DEFAULT 1,
    ultimo_acceso VARCHAR(32),
    created_at VARCHAR(32),
    updated_at VARCHAR(32),
    UNIQUE (email)
);

CREATE TABLE IF NOT EXISTS portal_equipo_asignaciones (
    id VARCHAR(36) PRIMARY KEY,
    usuario_id VARCHAR(36) NOT NULL,
    cliente_id VARCHAR(36) NOT NULL,
    proyecto_id VARCHAR(36),
    created_at VARCHAR(32),
    FOREIGN KEY (usuario_id) REFERENCES portal_equipo(id) ON DELETE CASCADE,
    FOREIGN KEY (cliente_id) REFERENCES portal_clientes(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS portal_equipo_codigos (
    id VARCHAR(36) PRIMARY KEY,
    usuario_id VARCHAR(36) NOT NULL,
    codigo VARCHAR(6) NOT NULL,
    expira_en VARCHAR(32) NOT NULL,
    usado_en VARCHAR(32),
    created_at VARCHAR(32),
    FOREIGN KEY (usuario_id) REFERENCES portal_equipo(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS portal_equipo_intentos (
    id VARCHAR(36) PRIMARY KEY,
    usuario_id VARCHAR(36) NOT NULL,
    created_at VARCHAR(32)
);

-- Portal de Clientes: esquema completo.
-- id VARCHAR(36) en todas las tablas para mantener el mismo estilo que
-- Core (users.id) y que el plugin Form (UUID como string).
--
-- Responsables/asistentes son polimórficos: pueden ser un usuario de Core
-- (equipo, tabla `users`) o un contacto de cliente (tabla `portal_contactos`).
-- Se resuelven con un par (tipo, id) en vez de dos FKs sueltas para dejar
-- claro cuál de las dos aplica.

CREATE TABLE IF NOT EXISTS portal_clientes (
    id VARCHAR(36) PRIMARY KEY,
    nombre VARCHAR(255) NOT NULL,
    empresa VARCHAR(255),
    email VARCHAR(255),
    logo_media_id VARCHAR(36),
    created_at VARCHAR(32),
    updated_at VARCHAR(32)
);

-- Personas del lado del cliente que pueden entrar al portal (login propio,
-- separado de los usuarios de Core, que son el equipo interno).
CREATE TABLE IF NOT EXISTS portal_contactos (
    id VARCHAR(36) PRIMARY KEY,
    cliente_id VARCHAR(36) NOT NULL,
    nombre VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    rol VARCHAR(32) NOT NULL DEFAULT 'viewer',
    created_at VARCHAR(32),
    updated_at VARCHAR(32),
    UNIQUE (email),
    FOREIGN KEY (cliente_id) REFERENCES portal_clientes(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS portal_proyectos (
    id VARCHAR(36) PRIMARY KEY,
    cliente_id VARCHAR(36) NOT NULL,
    nombre VARCHAR(255) NOT NULL,
    descripcion TEXT,
    estado VARCHAR(32) NOT NULL DEFAULT 'activo',
    created_at VARCHAR(32),
    updated_at VARCHAR(32),
    FOREIGN KEY (cliente_id) REFERENCES portal_clientes(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS portal_fases (
    id VARCHAR(36) PRIMARY KEY,
    proyecto_id VARCHAR(36) NOT NULL,
    nombre VARCHAR(255) NOT NULL,
    orden INT NOT NULL DEFAULT 0,
    fecha_inicio VARCHAR(32),
    fecha_fin VARCHAR(32),
    responsable_tipo VARCHAR(16),
    responsable_usuario_id VARCHAR(36),
    responsable_contacto_id VARCHAR(36),
    created_at VARCHAR(32),
    updated_at VARCHAR(32),
    FOREIGN KEY (proyecto_id) REFERENCES portal_proyectos(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS portal_reuniones (
    id VARCHAR(36) PRIMARY KEY,
    proyecto_id VARCHAR(36) NOT NULL,
    titulo VARCHAR(255) NOT NULL,
    fecha VARCHAR(32),
    resumen TEXT,
    created_at VARCHAR(32),
    updated_at VARCHAR(32),
    FOREIGN KEY (proyecto_id) REFERENCES portal_proyectos(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS portal_reunion_asistentes (
    id VARCHAR(36) PRIMARY KEY,
    reunion_id VARCHAR(36) NOT NULL,
    asistente_tipo VARCHAR(16) NOT NULL,
    asistente_usuario_id VARCHAR(36),
    asistente_contacto_id VARCHAR(36),
    FOREIGN KEY (reunion_id) REFERENCES portal_reuniones(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS portal_tareas (
    id VARCHAR(36) PRIMARY KEY,
    proyecto_id VARCHAR(36) NOT NULL,
    fase_id VARCHAR(36),
    reunion_origen_id VARCHAR(36),
    titulo VARCHAR(255) NOT NULL,
    descripcion TEXT,
    fecha_inicio VARCHAR(32),
    fecha_vencimiento VARCHAR(32),
    estado VARCHAR(32) NOT NULL DEFAULT 'pendiente',
    responsable_tipo VARCHAR(16),
    responsable_usuario_id VARCHAR(36),
    responsable_contacto_id VARCHAR(36),
    created_at VARCHAR(32),
    updated_at VARCHAR(32),
    FOREIGN KEY (proyecto_id) REFERENCES portal_proyectos(id) ON DELETE CASCADE,
    FOREIGN KEY (fase_id) REFERENCES portal_fases(id) ON DELETE SET NULL,
    FOREIGN KEY (reunion_origen_id) REFERENCES portal_reuniones(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS portal_piezas_contenido (
    id VARCHAR(36) PRIMARY KEY,
    proyecto_id VARCHAR(36) NOT NULL,
    titulo VARCHAR(255) NOT NULL,
    tipo VARCHAR(32),
    archivo_media_id VARCHAR(36),
    fecha_publicacion VARCHAR(32),
    estado VARCHAR(32) NOT NULL DEFAULT 'borrador',
    created_at VARCHAR(32),
    updated_at VARCHAR(32),
    FOREIGN KEY (proyecto_id) REFERENCES portal_proyectos(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS portal_aprobaciones (
    id VARCHAR(36) PRIMARY KEY,
    pieza_contenido_id VARCHAR(36) NOT NULL,
    contacto_id VARCHAR(36) NOT NULL,
    decision VARCHAR(16) NOT NULL,
    comentario TEXT,
    created_at VARCHAR(32),
    FOREIGN KEY (pieza_contenido_id) REFERENCES portal_piezas_contenido(id) ON DELETE CASCADE,
    FOREIGN KEY (contacto_id) REFERENCES portal_contactos(id) ON DELETE CASCADE
);

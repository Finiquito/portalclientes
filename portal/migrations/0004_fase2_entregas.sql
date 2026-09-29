-- Fase 2a: entregas de contenido para revisión del cliente.
--   entrega   = el paquete que se le manda ("Grilla octubre", "Identidad v1")
--   contenido = cada ítem, con un tipo que decide cómo se ve (post, reel, logo, brandbook…)
--   versión   = v1, v2… de un contenido; los archivos cuelgan de la versión
--               (portal_archivos.entidad_tipo = 'version')
--   reacción  = 😍 👍 😐 de cada contacto sobre una versión
-- Los comentarios usan portal_comentarios (entidad_tipo = 'contenido'); sus
-- columnas version_id y ubicacion las agrega Schema.php de forma idempotente.
-- (portal_piezas_contenido y portal_aprobaciones de la migración 0001 quedan
-- sin uso; se dejan para no tocar datos.)

CREATE TABLE IF NOT EXISTS portal_entregas (
    id VARCHAR(36) PRIMARY KEY,
    cliente_id VARCHAR(36) NOT NULL,
    proyecto_id VARCHAR(36) NOT NULL,
    titulo VARCHAR(255) NOT NULL,
    mensaje TEXT,
    fecha_limite VARCHAR(32),
    estado VARCHAR(16) NOT NULL DEFAULT 'borrador',
    publicada_en VARCHAR(32),
    respondida_en VARCHAR(32),
    respondida_por VARCHAR(255),
    created_at VARCHAR(32),
    updated_at VARCHAR(32),
    FOREIGN KEY (cliente_id) REFERENCES portal_clientes(id) ON DELETE CASCADE,
    FOREIGN KEY (proyecto_id) REFERENCES portal_proyectos(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS portal_contenidos (
    id VARCHAR(36) PRIMARY KEY,
    entrega_id VARCHAR(36) NOT NULL,
    cliente_id VARCHAR(36) NOT NULL,
    proyecto_id VARCHAR(36) NOT NULL,
    tipo VARCHAR(16) NOT NULL DEFAULT 'post',
    titulo VARCHAR(255) NOT NULL,
    cuenta VARCHAR(120),
    fecha_publicacion VARCHAR(32),
    orden INTEGER NOT NULL DEFAULT 0,
    estado VARCHAR(16) NOT NULL DEFAULT 'pendiente',
    version_actual INTEGER NOT NULL DEFAULT 1,
    created_at VARCHAR(32),
    updated_at VARCHAR(32),
    FOREIGN KEY (entrega_id) REFERENCES portal_entregas(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS portal_versiones (
    id VARCHAR(36) PRIMARY KEY,
    contenido_id VARCHAR(36) NOT NULL,
    numero INTEGER NOT NULL DEFAULT 1,
    copy TEXT,
    enlace VARCHAR(500),
    nota VARCHAR(500),
    decision VARCHAR(16),
    decidido_por_id VARCHAR(36),
    decidido_por_nombre VARCHAR(255),
    decidido_en VARCHAR(32),
    created_at VARCHAR(32),
    FOREIGN KEY (contenido_id) REFERENCES portal_contenidos(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS portal_reacciones (
    id VARCHAR(36) PRIMARY KEY,
    version_id VARCHAR(36) NOT NULL,
    contacto_id VARCHAR(36) NOT NULL,
    valor VARCHAR(16) NOT NULL,
    created_at VARCHAR(32),
    FOREIGN KEY (version_id) REFERENCES portal_versiones(id) ON DELETE CASCADE
);

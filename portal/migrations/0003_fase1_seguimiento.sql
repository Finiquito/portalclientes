-- Fase 1: comentarios, archivos, actividad, ajustes (personalización) y
-- protección del login. Todo con CREATE TABLE IF NOT EXISTS y tipos simples
-- para que corra igual en MySQL/MariaDB, Postgres y SQLite.
--
-- Las columnas nuevas de portal_tareas (tipo, completada_en, visible_cliente)
-- NO van acá: las agrega Schema.php de forma idempotente, porque un ALTER
-- TABLE ... ADD COLUMN repetido falla si el migrador vuelve a ejecutar el
-- archivo.

-- Comentarios polimórficos: sirven para tareas hoy y para piezas/reuniones
-- después (entidad_tipo + entidad_id). autor_nombre se guarda tal cual para
-- que el historial no cambie si después se edita o borra al contacto.
CREATE TABLE IF NOT EXISTS portal_comentarios (
    id VARCHAR(36) PRIMARY KEY,
    cliente_id VARCHAR(36) NOT NULL,
    entidad_tipo VARCHAR(16) NOT NULL,
    entidad_id VARCHAR(36) NOT NULL,
    autor_tipo VARCHAR(16) NOT NULL,
    autor_id VARCHAR(36),
    autor_nombre VARCHAR(255) NOT NULL,
    cuerpo TEXT NOT NULL,
    created_at VARCHAR(32),
    FOREIGN KEY (cliente_id) REFERENCES portal_clientes(id) ON DELETE CASCADE
);

-- Archivos subidos (por el equipo o por el cliente). El binario vive fuera
-- del web-root (typedock/storage/portal_uploads) y se sirve por controlador
-- validando sesión y cliente_id; acá sólo va el registro.
CREATE TABLE IF NOT EXISTS portal_archivos (
    id VARCHAR(36) PRIMARY KEY,
    cliente_id VARCHAR(36) NOT NULL,
    proyecto_id VARCHAR(36),
    entidad_tipo VARCHAR(16) NOT NULL,
    entidad_id VARCHAR(36) NOT NULL,
    nombre_original VARCHAR(255) NOT NULL,
    ruta VARCHAR(255) NOT NULL,
    miniatura VARCHAR(255),
    mime VARCHAR(128),
    tamano BIGINT NOT NULL DEFAULT 0,
    subido_por_tipo VARCHAR(16) NOT NULL,
    subido_por_id VARCHAR(36),
    subido_por_nombre VARCHAR(255) NOT NULL,
    created_at VARCHAR(32),
    FOREIGN KEY (cliente_id) REFERENCES portal_clientes(id) ON DELETE CASCADE
);

-- Bitácora simple: alimenta "Novedades" del cliente y la pantalla de
-- actividad del admin.
CREATE TABLE IF NOT EXISTS portal_actividad (
    id VARCHAR(36) PRIMARY KEY,
    cliente_id VARCHAR(36) NOT NULL,
    proyecto_id VARCHAR(36),
    actor_tipo VARCHAR(16) NOT NULL,
    actor_nombre VARCHAR(255) NOT NULL,
    accion VARCHAR(32) NOT NULL,
    entidad_tipo VARCHAR(16),
    entidad_id VARCHAR(36),
    titulo VARCHAR(255),
    detalle TEXT,
    created_at VARCHAR(32),
    FOREIGN KEY (cliente_id) REFERENCES portal_clientes(id) ON DELETE CASCADE
);

-- Ajustes clave/valor por dueño: 'cliente' (color, logo, título, frases de
-- bienvenida), 'contacto' (tema, apodo, frase propia, avisos) y 'global'
-- (correo de avisos, tamaño máximo). Agregar un ajuste nuevo no requiere
-- migración.
CREATE TABLE IF NOT EXISTS portal_ajustes (
    id VARCHAR(36) PRIMARY KEY,
    owner_tipo VARCHAR(16) NOT NULL,
    owner_id VARCHAR(36) NOT NULL,
    clave VARCHAR(64) NOT NULL,
    valor TEXT,
    updated_at VARCHAR(32),
    UNIQUE (owner_tipo, owner_id, clave)
);

-- Intentos fallidos de código: tope de 5 cada 10 min por contacto.
CREATE TABLE IF NOT EXISTS portal_login_intentos (
    id VARCHAR(36) PRIMARY KEY,
    contacto_id VARCHAR(36) NOT NULL,
    created_at VARCHAR(32)
);

-- Plantillas de proyecto: fases, tareas (con duración y de quién son) e hitos, en JSON.
-- Al aplicarlas con una fecha de inicio, la línea de tiempo se arma sola.
-- Schema.php también garantiza esta tabla y la primera vez deja tres de ejemplo (Web, Branding, Campaña digital).

CREATE TABLE IF NOT EXISTS portal_plantillas (
    id VARCHAR(36) PRIMARY KEY,
    nombre VARCHAR(255) NOT NULL,
    descripcion VARCHAR(500),
    estructura TEXT NOT NULL,
    orden INTEGER NOT NULL DEFAULT 0,
    created_at VARCHAR(19),
    updated_at VARCHAR(19)
);

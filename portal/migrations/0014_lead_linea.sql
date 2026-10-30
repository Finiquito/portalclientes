-- Línea de tiempo de cada contacto ganado: la llegada, cada cambio de estado, las notas con su dueño
-- (agencia o cliente) y los próximos pasos. Cada evento tiene dos fechas: cuándo ocurrió (se puede
-- corregir a mano; ordena la línea) y cuándo se registró (no cambia).
--   tipo:       llegada | estado | nota | proximo_paso
--   autor_tipo: agencia | cliente | sistema (llegadas por formulario, webhook o planilla)
--   ajustada:   1 si alguien cambió a mano la fecha en que ocurrió
--   legado:     1 si viene de las notas y estados anteriores a la línea de tiempo
-- Schema.php también garantiza la tabla y, si está vacía y ya hay contactos, copia lo que existía (una sola vez).
-- portal_proyectos.publico (B2C, B2B o ambos) lo agrega Schema.php.

CREATE TABLE IF NOT EXISTS portal_lead_eventos (
    id VARCHAR(36) PRIMARY KEY,
    lead_id VARCHAR(36) NOT NULL,
    cliente_id VARCHAR(36) NOT NULL,
    proyecto_id VARCHAR(36) NOT NULL,
    tipo VARCHAR(16) NOT NULL,
    de_estado VARCHAR(16),
    a_estado VARCHAR(16),
    texto TEXT,
    ocurrio_en VARCHAR(19) NOT NULL,
    registrado_en VARCHAR(19) NOT NULL,
    autor_tipo VARCHAR(8),
    autor VARCHAR(255),
    ajustada SMALLINT NOT NULL DEFAULT 0,
    legado SMALLINT NOT NULL DEFAULT 0
)

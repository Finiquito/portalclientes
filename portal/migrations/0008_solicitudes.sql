-- Solicitudes que el cliente hace desde su portal: un pedido, un presupuesto,
-- una reunión o un problema. Llegan a una bandeja del equipo, que las acepta
-- (se convierten en tarea o reunión), las cotiza, las responde o las rechaza.
--   tipo:     pedido | presupuesto | reunion | problema
--   urgencia: urgente | semana | sin_apuro
--   estado:   nueva | en_curso | cotizada | aprobada | no_aprobada | agendada | respondida | rechazada
-- Schema.php también garantiza esta tabla por si el migrador no vuelve a correr.

CREATE TABLE IF NOT EXISTS portal_solicitudes (
    id VARCHAR(36) PRIMARY KEY,
    cliente_id VARCHAR(36) NOT NULL,
    proyecto_id VARCHAR(36) NOT NULL,
    contacto_id VARCHAR(36),
    contacto_nombre VARCHAR(255),
    tipo VARCHAR(16) NOT NULL DEFAULT 'pedido',
    titulo VARCHAR(255) NOT NULL,
    detalle TEXT,
    urgencia VARCHAR(16) NOT NULL DEFAULT 'semana',
    motivo_urgencia VARCHAR(500),
    horarios TEXT,
    modalidad VARCHAR(16),
    estado VARCHAR(16) NOT NULL DEFAULT 'nueva',
    respuesta TEXT,
    monto VARCHAR(80),
    validez VARCHAR(32),
    tarea_id VARCHAR(36),
    reunion_id VARCHAR(36),
    atendida_por VARCHAR(255),
    atendida_en VARCHAR(32),
    decision_en VARCHAR(32),
    created_at VARCHAR(32),
    updated_at VARCHAR(32),
    FOREIGN KEY (cliente_id) REFERENCES portal_clientes(id) ON DELETE CASCADE
);

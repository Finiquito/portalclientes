-- Cola de correos que esperan el horario hábil del país del cliente.
-- El país del cliente (portal_clientes.pais) lo agrega Schema.php de forma idempotente.
CREATE TABLE IF NOT EXISTS portal_correos_cola (
    id VARCHAR(36) PRIMARY KEY,
    cliente_id VARCHAR(36),
    contacto_id VARCHAR(36),
    destino VARCHAR(255) NOT NULL,
    asunto VARCHAR(500) NOT NULL,
    texto TEXT,
    html TEXT,
    enviar_desde VARCHAR(19) NOT NULL,
    estado VARCHAR(12) NOT NULL DEFAULT 'pendiente',
    intentos INTEGER NOT NULL DEFAULT 0,
    error VARCHAR(500),
    created_at VARCHAR(32),
    enviado_en VARCHAR(32)
);

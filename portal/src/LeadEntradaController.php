<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

use TypeDock\Core\PluginContext;

/**
 * Entrada pública de contactos ganados (sin sesión).
 *
 *   POST /leads/f/{token}   formulario de una landing (o webhook con el token en la URL)
 *   POST /leads/f           webhook con el token en la cabecera X-Portal-Token (recomendado para Make)
 *
 * Acepta formulario (application/x-www-form-urlencoded o multipart) o JSON. Responde JSON
 * (201 / 422 / 404 / 413 / 429) cuando llega JSON o se pide; si no, lleva a la página de
 * gracias de la fuente o muestra una propia. A un bot se le responde como si todo hubiera
 * salido bien, sin guardar nada (no le enseñamos qué lo delató).
 */
class LeadEntradaController
{
    public const MAX_BYTES = 20000;

    public function __construct(protected readonly PluginContext $ctx) {}

    protected function pdo(): \PDO
    {
        return $this->ctx->db()->pdo();
    }

    protected function terminate(): void
    {
        exit;
    }

    /** Cuerpo crudo de la petición (en pruebas se reemplaza). */
    protected function cuerpo(): string
    {
        return (string) file_get_contents('php://input', false, null, 0, self::MAX_BYTES + 1);
    }

    private function esJson(): bool
    {
        return str_contains(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json');
    }

    private function quiereJson(): bool
    {
        return $this->esJson() || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    }

    /** @param array<string, mixed>|null $fuente */
    protected function responder(bool $ok, int $codigo, string $mensaje, ?array $fuente = null): void
    {
        if ($this->quiereJson()) {
            http_response_code($codigo);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode($ok ? ['ok' => true] : ['ok' => false, 'error' => $mensaje], JSON_UNESCAPED_UNICODE);
        } elseif ($ok && !empty($fuente['url_gracias'])) {
            header('Location: ' . $fuente['url_gracias'], true, 303);
        } else {
            http_response_code($ok ? 200 : $codigo);
            header('Cache-Control: no-store');
            $this->ctx->view('templates/public/lead_gracias.latte', ['ok' => $ok, 'mensaje' => $mensaje]);
        }
        $this->terminate();
    }

    public function recibir(string $token = ''): void
    {
        $token = $token !== '' ? $token : trim((string) ($_SERVER['HTTP_X_PORTAL_TOKEN'] ?? ''));
        $webhook = $this->esJson() || ($_SERVER['HTTP_X_PORTAL_TOKEN'] ?? '') !== '';
        $ip = LeadFuenteService::ipHash((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        $svcF = new LeadFuenteService($this->pdo());

        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > self::MAX_BYTES) {
            $this->responder(false, 413, 'El envío es demasiado grande.');
            return;
        }
        $fuente = $svcF->porToken($token);
        if ($fuente === null) {
            $svcF->anotar(null, $ip, 'token');
            $this->responder(false, 404, 'Este formulario ya no está recibiendo datos.');
            return;
        }

        if ($this->esJson()) {
            $crudo = $this->cuerpo();
            if (strlen($crudo) > self::MAX_BYTES) {
                $this->responder(false, 413, 'El envío es demasiado grande.', $fuente);
                return;
            }
            $datos = json_decode($crudo, true);
            if (!is_array($datos)) {
                $this->responder(false, 422, 'El cuerpo no es un JSON válido.', $fuente);
                return;
            }
        } else {
            $datos = $_POST;
        }

        if (LeadFuenteService::esBot($datos)) {
            $svcF->anotar((string) $fuente['id'], $ip, 'bot');
            $this->responder(true, 201, '¡Gracias! Recibimos tus datos.', $fuente);
            return;
        }
        if ($svcF->excedido((string) $fuente['id'], $webhook ? null : $ip)) {
            $svcF->anotar((string) $fuente['id'], $ip, 'tope');
            $this->responder(false, 429, 'Recibimos muchos envíos seguidos. Intenta de nuevo en un rato.', $fuente);
            return;
        }

        $leads = new LeadService($this->pdo());
        $proyecto = $leads->proyecto((string) $fuente['proyecto_id']);
        if ($proyecto === null) {
            $this->responder(false, 404, 'Este formulario ya no está recibiendo datos.');
            return;
        }
        $r = $leads->crear($proyecto, LeadFuenteService::mapear($datos), $webhook ? 'webhook' : 'formulario', (string) $fuente['nombre'], (string) $fuente['id']);
        if (isset($r['error'])) {
            $svcF->anotar((string) $fuente['id'], $ip, 'invalido');
            $this->responder(false, 422, $r['error'], $fuente);
            return;
        }
        $svcF->anotar((string) $fuente['id'], $ip, !empty($r['duplicado']) ? 'duplicado' : 'ok');
        $svcF->usada((string) $fuente['id']);
        if (empty($r['duplicado'])) {
            try {
                $leads->avisarCliente(new Notifier($this->ctx, $this->pdo()), (string) $proyecto['cliente_id'], (string) $proyecto['nombre']);
            } catch (\Throwable $e) {
                error_log('[portal] aviso de contacto nuevo: ' . $e->getMessage());
            }
        }
        $this->responder(true, 201, '¡Gracias! Recibimos tus datos.', $fuente);
    }
}

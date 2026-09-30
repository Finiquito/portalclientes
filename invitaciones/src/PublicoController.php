<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Invitaciones;

use TypeDock\Core\PluginContext;

/**
 * POST /invitacion — lo que envía el formulario del landing.
 *
 * Responde JSON si se pide (fetch), o redirige de vuelta con ?invitacion=ok|error (sin JS).
 * Anti-bots sin captcha: campo trampa oculto, tiempo mínimo de llenado y tope por IP.
 * A un bot se le responde «ok» sin guardar nada (no le enseñamos qué lo delató).
 */
class PublicoController
{
    public function __construct(protected readonly PluginContext $ctx) {}

    protected function pdo(): \PDO
    {
        return $this->ctx->db()->pdo();
    }

    protected function terminate(): void
    {
        exit;
    }

    private function quiereJson(): bool
    {
        return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    }

    /** @param array<string, mixed> $r */
    protected function responder(array $r, int $codigo = 200): void
    {
        if ($this->quiereJson()) {
            http_response_code($codigo);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode($r, JSON_UNESCAPED_UNICODE);
        } else {
            $volver = '/';
            $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
            $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
            if ($ref !== '' && $host !== '' && parse_url($ref, PHP_URL_HOST) === $host) {
                $volver = (string) (parse_url($ref, PHP_URL_PATH) ?: '/');
            }
            header('Location: ' . $volver . '?invitacion=' . (!empty($r['ok']) ? 'ok' : 'error') . '#invitacion', true, 303);
        }
        $this->terminate();
    }

    public function inscribir(): void
    {
        // Bots: campo trampa lleno o formulario enviado en menos de 2 segundos.
        $t = (int) ($_POST['t'] ?? 0);
        $ms = (int) floor(microtime(true) * 1000) - $t;
        if (trim((string) ($_POST['sitio_web'] ?? '')) !== '' || ($t > 0 && $ms < 2000)) {
            $this->responder(['ok' => true]);
            return;
        }

        $d = InvitacionService::normalizar($_POST);
        if (!InvitacionService::emailValido($d['email'])) {
            $this->responder(['ok' => false, 'error' => 'Revisa tu correo: parece que le falta algo.'], 422);
            return;
        }

        $servicio = new InvitacionService($this->pdo(), new Ajustes($this->pdo()));
        $ip = hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? '') . '|invitaciones');
        if ($servicio->limiteIp($ip)) {
            $this->responder(['ok' => false, 'error' => 'Recibimos varios envíos seguidos desde tu conexión. Intenta en un rato.'], 429);
            return;
        }

        try {
            $id = $servicio->guardar($d, $ip);
        } catch (\Throwable $e) {
            error_log('[invitaciones] no se pudo guardar: ' . $e->getMessage());
            $this->responder(['ok' => false, 'error' => 'No pudimos guardar tus datos. Intenta de nuevo en un rato.'], 500);
            return;
        }
        // Mailchimp es «mejor esfuerzo»: si falla, la copia local queda y se reintenta desde el admin.
        try {
            $servicio->sincronizar($id);
        } catch (\Throwable $e) {
            error_log('[invitaciones] mailchimp: ' . $e->getMessage());
        }
        $this->responder(['ok' => true]);
    }
}

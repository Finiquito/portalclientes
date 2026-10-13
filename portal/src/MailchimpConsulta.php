<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * Consulta en Mailchimp (API v3) a los contactos ganados que ya tenemos: sólo lectura.
 *
 * No trae a nadie nuevo ni escribe en Mailchimp. Por cada correo pregunta a la audiencia de la
 * campaña si está, su estado (suscrito, se dio de baja, rebotado…), fechas, calificación,
 * tasas de apertura y clics, etiquetas y su actividad reciente (correos recibidos, abiertos,
 * clics). La clave de la API del cliente se guarda cifrada en sus ajustes (Cifrado).
 */
final class MailchimpConsulta
{
    /** Para pruebas: reemplaza la llamada HTTP. fn(string $url): array{0:int,1:array} */
    public static ?\Closure $transporte = null;

    public const ESTADOS = [
        'subscribed'    => ['Suscrito', 'ok'],
        'pending'       => ['Sin confirmar', 'info'],
        'unsubscribed'  => ['Se dio de baja', 'warn'],
        'cleaned'       => ['Correo rebotado', 'danger'],
        'transactional' => ['Sólo correos transaccionales', 'gris'],
        'archived'      => ['Archivado en Mailchimp', 'gris'],
        'no_esta'       => ['No está en Mailchimp', 'gris'],
        'error'         => ['No se pudo revisar', 'gris'],
    ];

    /** Qué significa cada evento de la actividad. */
    public const EVENTOS = [
        'sent' => 'Recibió', 'open' => 'Abrió', 'click' => 'Hizo clic en', 'bounce' => 'Rebotó', 'unsub' => 'Se dio de baja desde',
        'abuse' => 'Marcó como spam', 'signup' => 'Se inscribió', 'conversation' => 'Respondió', 'marketing_permission' => 'Cambió permisos',
    ];

    public function __construct(private readonly string $clave) {}

    public static function claveValida(string $clave): bool
    {
        return preg_match('/^[0-9a-f]{32}-us\d{1,2}$/', trim($clave)) === 1;
    }

    // ---- Clave por cliente ------------------------------------------------------------

    public static function deCliente(\PDO $pdo, string $clienteId): ?self
    {
        $g = (new AjustesService($pdo))->get('cliente', $clienteId, 'mc_clave');
        $clave = $g !== '' ? (new Cifrado($pdo))->descifrar($g) : '';
        return self::claveValida($clave) ? new self($clave) : null;
    }

    public static function guardarClave(\PDO $pdo, string $clienteId, string $clave): void
    {
        (new AjustesService($pdo))->set('cliente', $clienteId, 'mc_clave', (new Cifrado($pdo))->cifrar(trim($clave)));
    }

    public static function quitarClave(\PDO $pdo, string $clienteId): void
    {
        (new AjustesService($pdo))->borrar('cliente', $clienteId, 'mc_clave');
        $pdo->prepare('UPDATE portal_proyectos SET mc_audiencia = NULL, mc_audiencia_nombre = NULL WHERE cliente_id = ?')->execute([$clienteId]);
    }

    // ---- Consultas -----------------------------------------------------------------

    /**
     * Audiencias de la cuenta (para elegir la de cada campaña).
     *
     * @return array{ok: bool, error?: string, audiencias?: array<int, array{id: string, nombre: string, miembros: int}>}
     */
    public function audiencias(): array
    {
        [$cod, $r] = $this->get('/lists?count=100&fields=lists.id,lists.name,lists.stats.member_count');
        if ($cod !== 200) {
            return ['ok' => false, 'error' => self::error($cod, $r)];
        }
        $out = [];
        foreach ((array) ($r['lists'] ?? []) as $l) {
            $out[] = ['id' => (string) ($l['id'] ?? ''), 'nombre' => (string) ($l['name'] ?? ''), 'miembros' => (int) ($l['stats']['member_count'] ?? 0)];
        }
        return ['ok' => true, 'audiencias' => $out];
    }

    /**
     * Resumen de una persona en la audiencia. Con $actividad, también sus últimos eventos.
     *
     * @return array{estado: string, datos: array<string, mixed>}
     */
    public function persona(string $audiencia, string $correo, bool $actividad = true): array
    {
        if (preg_match('/^[0-9a-z]{1,32}$/i', $audiencia) !== 1) {
            return ['estado' => 'error', 'datos' => ['error' => 'Falta elegir la audiencia de Mailchimp.']];
        }
        $hash = md5(mb_strtolower(trim($correo)));
        [$cod, $m] = $this->get("/lists/{$audiencia}/members/{$hash}?fields=status,timestamp_signup,timestamp_opt,last_changed,member_rating,stats.avg_open_rate,stats.avg_click_rate,tags,unsubscribe_reason,source");
        if ($cod === 404) {
            return ['estado' => 'no_esta', 'datos' => []];
        }
        if ($cod !== 200) {
            return ['estado' => 'error', 'datos' => ['error' => self::error($cod, $m)]];
        }
        $estado = (string) ($m['status'] ?? '');
        $d = [
            'alta'      => self::fecha((string) ($m['timestamp_opt'] ?? '') ?: (string) ($m['timestamp_signup'] ?? '')),
            'cambio'    => self::fecha((string) ($m['last_changed'] ?? '')),
            'rating'    => max(0, min(5, (int) ($m['member_rating'] ?? 0))),
            'aperturas' => isset($m['stats']['avg_open_rate']) ? (int) round((float) $m['stats']['avg_open_rate'] * 100) : null,
            'clics'     => isset($m['stats']['avg_click_rate']) ? (int) round((float) $m['stats']['avg_click_rate'] * 100) : null,
            'etiquetas' => array_slice(array_values(array_filter(array_map(fn($t) => mb_substr((string) ($t['name'] ?? ''), 0, 60), (array) ($m['tags'] ?? [])))), 0, 10),
            'motivo'    => mb_substr(trim((string) ($m['unsubscribe_reason'] ?? '')), 0, 200),
            'origen'    => mb_substr((string) ($m['source'] ?? ''), 0, 60),
        ];
        if ($actividad) {
            [$d['actividad'], $d['conteo']] = $this->actividad($audiencia, $hash);
        }
        return ['estado' => isset(self::ESTADOS[$estado]) ? $estado : 'error', 'datos' => $d];
    }

    /**
     * Últimos eventos (correos recibidos, abiertos, clics, baja…) y cuántos correos distintos
     * recibió, abrió y en cuántos hizo clic. Usa el «activity feed» y, si la cuenta no lo
     * entrega, la actividad clásica (sólo los últimos 50 eventos: el conteo queda como «al menos»).
     * Mailchimp sólo guarda la historia reciente.
     *
     * @return array{0: array<int, array{tipo: string, fecha: string, titulo: string}>, 1: array{enviados: int, abiertos: int, clics: int, completo: bool}|null}
     */
    private function actividad(string $audiencia, string $hash): array
    {
        $completo = true;
        [$cod, $r] = $this->get("/lists/{$audiencia}/members/{$hash}/activity-feed?count=1000"
            . '&fields=total_items,activity.activity_type,activity.created_at_timestamp,activity.campaign_id,activity.campaign_title');
        if ($cod !== 200) {
            $completo = false;
            [$cod, $r] = $this->get("/lists/{$audiencia}/members/{$hash}/activity?count=50");
        }
        if ($cod !== 200) {
            return [[], null];
        }
        $eventos = (array) ($r['activity'] ?? []);
        if (isset($r['total_items']) && (int) $r['total_items'] > count($eventos)) {
            $completo = false;
        }
        $out = [];
        $porTipo = ['sent' => [], 'open' => [], 'click' => [], 'unsub' => []];
        foreach ($eventos as $a) {
            $tipo = (string) ($a['activity_type'] ?? $a['action'] ?? '');
            if ($tipo === '') {
                continue;
            }
            $titulo = (string) ($a['campaign_title'] ?? $a['title'] ?? '');
            if (isset($porTipo[$tipo])) {
                // Un correo se cuenta una vez aunque lo abra varias veces.
                $porTipo[$tipo][(string) ($a['campaign_id'] ?? '') ?: $titulo] = true;
            }
            if (count($out) < 10) {
                $out[] = [
                    'tipo'   => mb_substr($tipo, 0, 30),
                    'fecha'  => self::fecha((string) ($a['created_at_timestamp'] ?? $a['timestamp'] ?? '')),
                    'titulo' => mb_substr($titulo, 0, 150),
                ];
            }
        }
        // Si abrió, hizo clic o se dio de baja desde un correo cuyo envío ya no aparece, igual lo recibió.
        $enviados = $porTipo['sent'] + $porTipo['open'] + $porTipo['click'] + $porTipo['unsub'];
        $conteo = ['enviados' => count($enviados), 'abiertos' => count($porTipo['open'] + $porTipo['click']), 'clics' => count($porTipo['click']), 'completo' => $completo];
        return [$out, $conteo];
    }

    /** Fecha de Mailchimp (ISO, UTC) → «Y-m-d H:i:s» en la hora del servidor, como el resto del portal. */
    private static function fecha(string $f): string
    {
        if (trim($f) === '') {
            return '';
        }
        try {
            return (new \DateTimeImmutable($f))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return '';
        }
    }

    private static function error(int $cod, array $r): string
    {
        $det = trim((string) ($r['detail'] ?? $r['title'] ?? ''));
        return match (true) {
            $cod === 0   => 'No se pudo conectar con Mailchimp.',
            $cod === 401 => 'La clave de Mailchimp no es válida o fue revocada.',
            $cod === 403 => 'La clave de Mailchimp no tiene acceso a eso.',
            $cod === 404 => 'No se encontró la audiencia en Mailchimp.',
            $cod === 429 => 'Mailchimp pidió esperar un poco (muchas consultas seguidas).',
            default      => 'Mailchimp respondió ' . $cod . ($det !== '' ? ': ' . mb_substr($det, 0, 150) : '.'),
        };
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function get(string $ruta): array
    {
        preg_match('/-(us\d{1,2})$/', $this->clave, $m);
        $url = 'https://' . ($m[1] ?? 'us1') . '.api.mailchimp.com/3.0' . $ruta;
        if (self::$transporte !== null) {
            return (self::$transporte)($url);
        }
        if (!function_exists('curl_init')) {
            return [0, []];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET        => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => 'portal:' . $this->clave,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT        => 8,
        ]);
        $cuerpo = curl_exec($ch);
        $cod = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($cuerpo === false) {
            return [0, []];
        }
        $d = json_decode((string) $cuerpo, true);
        return [$cod, is_array($d) ? $d : []];
    }
}

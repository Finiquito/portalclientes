<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Invitaciones;

/**
 * Cliente mínimo de la API de Mailchimp (v3), sin dependencias.
 * La región (us1…us21) va al final de la clave: «abc123…-us21».
 */
final class Mailchimp
{
    /** Para pruebas: reemplaza la llamada HTTP. fn(string $metodo, string $url, ?array $json): array{0:int,1:array} */
    public static ?\Closure $transporte = null;

    public function __construct(private readonly string $clave, private readonly string $audiencia) {}

    public static function region(string $clave): ?string
    {
        return preg_match('/-(us\d{1,2})$/', trim($clave), $m) === 1 ? $m[1] : null;
    }

    public static function claveValida(string $clave): bool
    {
        return preg_match('/^[0-9a-f]{32}-us\d{1,2}$/', trim($clave)) === 1;
    }

    /**
     * Crea o actualiza la persona en la audiencia (upsert por correo).
     *
     * @param array<string, string> $campos merge fields (TAG => valor)
     * @param array<int, string>    $etiquetas
     * @return array{0: bool, 1: string} [ok, detalle]
     */
    public function inscribir(string $email, array $campos, array $etiquetas, bool $dobleOptIn): array
    {
        $hash = md5(strtolower(trim($email)));
        $cuerpo = [
            'email_address' => $email,
            'status_if_new' => $dobleOptIn ? 'pending' : 'subscribed',
        ];
        $campos = array_filter($campos, static fn($v) => $v !== '');
        if ($campos !== []) {
            $cuerpo['merge_fields'] = $campos;
        }
        [$cod, $res] = $this->llamar('PUT', "/lists/{$this->audiencia}/members/{$hash}", $cuerpo);

        // Si la audiencia no tiene alguno de los campos, se reintenta sin ellos (la inscripción vale más).
        $aviso = '';
        if ($cod === 400 && $campos !== [] && str_contains(strtolower(json_encode($res) ?: ''), 'merge')) {
            unset($cuerpo['merge_fields']);
            [$cod, $res] = $this->llamar('PUT', "/lists/{$this->audiencia}/members/{$hash}", $cuerpo);
            $aviso = ' (sin campos extra: revisa que existan en la audiencia: ' . implode(', ', array_keys($campos)) . ')';
        }
        if ($cod < 200 || $cod >= 300) {
            return [false, self::error($cod, $res)];
        }
        if ($etiquetas !== []) {
            $this->llamar('POST', "/lists/{$this->audiencia}/members/{$hash}/tags", [
                'tags' => array_map(static fn($t) => ['name' => $t, 'status' => 'active'], $etiquetas),
            ]);
        }
        return [true, (string) ($res['status'] ?? 'ok') . $aviso];
    }

    /**
     * Revisa la conexión y devuelve el nombre de la audiencia y sus campos.
     *
     * @return array{ok: bool, error?: string, audiencia?: string, campos?: array<int, array{tag: string, nombre: string, tipo: string, opciones: array<int, string>}>}
     */
    public function probar(): array
    {
        [$cod, $res] = $this->llamar('GET', "/lists/{$this->audiencia}?fields=name,stats.member_count", null);
        if ($cod !== 200) {
            return ['ok' => false, 'error' => self::error($cod, $res)];
        }
        [$c2, $r2] = $this->llamar('GET', "/lists/{$this->audiencia}/merge-fields?count=50&fields=merge_fields.tag,merge_fields.name,merge_fields.type,merge_fields.options", null);
        $campos = [];
        foreach ((array) ($r2['merge_fields'] ?? []) as $f) {
            $campos[] = [
                'tag' => (string) ($f['tag'] ?? ''), 'nombre' => (string) ($f['name'] ?? ''), 'tipo' => (string) ($f['type'] ?? ''),
                'opciones' => array_map('strval', (array) ($f['options']['choices'] ?? [])),
            ];
        }
        return ['ok' => true, 'audiencia' => (string) ($res['name'] ?? ''), 'miembros' => (int) ($res['stats']['member_count'] ?? 0), 'campos' => $campos];
    }

    private static function error(int $cod, array $res): string
    {
        $det = trim((string) ($res['detail'] ?? $res['title'] ?? ''));
        return match (true) {
            $cod === 0   => 'No se pudo conectar con Mailchimp' . ($det !== '' ? ': ' . $det : '.'),
            $cod === 401 => 'La clave de Mailchimp no es válida o fue revocada.',
            $cod === 404 => 'No se encontró la audiencia. Revisa el ID de la audiencia.',
            $cod === 400 && str_contains(strtolower($det), 'fake') => 'Mailchimp rechazó el correo por parecer falso.',
            $cod === 400 && str_contains(strtolower($det), 'compliance') => 'Ese correo se dio de baja antes; Mailchimp no permite volver a inscribirlo desde aquí.',
            default      => 'Mailchimp respondió ' . $cod . ($det !== '' ? ': ' . mb_substr($det, 0, 200) : '.'),
        };
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function llamar(string $metodo, string $ruta, ?array $json): array
    {
        $region = self::region($this->clave) ?? 'us1';
        $url = "https://{$region}.api.mailchimp.com/3.0" . $ruta;
        if (self::$transporte !== null) {
            return (self::$transporte)($metodo, $url, $json);
        }
        if (!function_exists('curl_init')) {
            return [0, ['detail' => 'falta la extensión cURL de PHP']];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $metodo,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => 'prisma:' . $this->clave,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
        ]);
        if ($json !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_UNICODE));
        }
        $cuerpo = curl_exec($ch);
        $cod = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($cuerpo === false) {
            return [0, ['detail' => $err]];
        }
        $d = json_decode((string) $cuerpo, true);
        return [$cod, is_array($d) ? $d : []];
    }
}

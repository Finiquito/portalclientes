<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * Fuentes de contactos ganados: cada formulario de landing o webhook (Make, Zapier…) tiene
 * su token. El token se muestra una sola vez; en la base queda sólo su hash (SHA-256), y se
 * puede revocar o cambiar por uno nuevo en cualquier momento.
 *
 * También recibe lo que llega por el endpoint público: reconoce los nombres de campo más
 * comunes (Make, Meta, formularios en inglés o español), frena el spam (campo trampa, tiempo
 * mínimo de llenado, tope por IP y por fuente) y deja un registro corto de intentos.
 */
class LeadFuenteService
{
    public const TABLE    = 'portal_lead_fuentes';
    public const INTENTOS = 'portal_lead_intentos';

    /** Envíos de formulario por IP en una hora (los webhooks no cuentan: Make comparte IPs). */
    public const MAX_IP_HORA = 10;
    /** Contactos por fuente en 24 horas: más que esto huele a abuso. */
    public const MAX_FUENTE_DIA = 500;
    /** Milisegundos mínimos entre que se abre el formulario y se envía. */
    public const MIN_MS = 2000;

    /** Nombre de campo que llega => campo nuestro. */
    private const ALIAS = [
        'nombre' => 'nombre', 'name' => 'nombre', 'full_name' => 'nombre', 'fullname' => 'nombre', 'nombre_completo' => 'nombre', 'your_name' => 'nombre',
        'correo' => 'correo', 'email' => 'correo', 'e_mail' => 'correo', 'mail' => 'correo', 'email_address' => 'correo', 'your_email' => 'correo',
        'correo_electronico' => 'correo', 'direccion_de_correo_electronico' => 'correo', 'direccion_de_correo' => 'correo', 'email_adresse' => 'correo',
        'telefono' => 'telefono', 'phone' => 'telefono', 'phone_number' => 'telefono', 'tel' => 'telefono', 'celular' => 'telefono', 'movil' => 'telefono', 'whatsapp' => 'telefono',
        'numero_de_telefono' => 'telefono', 'telefono_movil' => 'telefono', 'numero_de_celular' => 'telefono',
        'empresa' => 'empresa', 'company' => 'empresa', 'company_name' => 'empresa', 'compania' => 'empresa',
        'campana' => 'campana', 'campaign' => 'campana', 'campaign_name' => 'campana', 'origen' => 'campana', 'nombre_de_la_campana' => 'campana',
        'utm_source' => 'utm_source', 'utm_medium' => 'utm_medium', 'utm_campaign' => 'utm_campaign',
        'mensaje' => 'mensaje', 'message' => 'mensaje', 'comentario' => 'mensaje', 'comentarios' => 'mensaje', 'consulta' => 'mensaje',
    ];

    public function __construct(private readonly \PDO $pdo) {}

    private static function ahora(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Token nuevo: «pl_» + 40 caracteres hexadecimales. */
    public static function nuevoToken(): string
    {
        return 'pl_' . bin2hex(random_bytes(20));
    }

    public static function tokenValido(string $t): bool
    {
        return preg_match('/^pl_[0-9a-f]{40}$/', $t) === 1;
    }

    /** Sólo http(s); lo demás se descarta. */
    public static function urlGracias(string $u): ?string
    {
        $u = trim($u);
        if ($u === '' || mb_strlen($u) > 500 || filter_var($u, FILTER_VALIDATE_URL) === false || !preg_match('#^https?://#i', $u)) {
            return null;
        }
        return $u;
    }

    // ---- Fuentes ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $proyecto fila de LeadService::proyecto()
     * @return array{id: string, token: string}
     */
    public function crear(array $proyecto, string $nombre, string $urlGracias = ''): array
    {
        $id = typedock_uuid7();
        $token = self::nuevoToken();
        $now = self::ahora();
        $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . ' (id, cliente_id, proyecto_id, nombre, token_hash, url_gracias, activa, total, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 1, 0, ?, ?)'
        )->execute([$id, (string) $proyecto['cliente_id'], (string) $proyecto['id'], mb_substr(trim($nombre) ?: 'Formulario', 0, 255), self::hash($token), self::urlGracias($urlGracias), $now, $now]);
        return ['id' => $id, 'token' => $token];
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = ?');
        $st->execute([$id]);
        $f = $st->fetch();
        return $f !== false ? $f : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function delProyecto(string $proyectoId): array
    {
        $st = $this->pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE proyecto_id = ? ORDER BY activa DESC, created_at');
        $st->execute([$proyectoId]);
        return $st->fetchAll();
    }

    /** La fuente activa de ese token, sólo si su proyecto sigue con el módulo activo. */
    public function porToken(string $token): ?array
    {
        if (!self::tokenValido($token)) {
            return null;
        }
        $st = $this->pdo->prepare(
            'SELECT f.* FROM ' . self::TABLE . ' f JOIN portal_proyectos p ON p.id = f.proyecto_id
             WHERE f.token_hash = ? AND f.activa = 1 AND p.con_leads = 1'
        );
        $st->execute([self::hash($token)]);
        $f = $st->fetch();
        return $f !== false ? $f : null;
    }

    public function revocar(string $id): void
    {
        $now = self::ahora();
        $this->pdo->prepare('UPDATE ' . self::TABLE . ' SET activa = 0, revocada_en = ?, updated_at = ? WHERE id = ?')->execute([$now, $now, $id]);
    }

    /** Token nuevo para la misma fuente (el anterior deja de servir al tiro). */
    public function rotar(string $id): string
    {
        $token = self::nuevoToken();
        $now = self::ahora();
        $this->pdo->prepare('UPDATE ' . self::TABLE . ' SET token_hash = ?, activa = 1, revocada_en = NULL, updated_at = ? WHERE id = ?')
            ->execute([self::hash($token), $now, $id]);
        return $token;
    }

    public function editar(string $id, string $nombre, string $urlGracias): void
    {
        $this->pdo->prepare('UPDATE ' . self::TABLE . ' SET nombre = ?, url_gracias = ?, updated_at = ? WHERE id = ?')
            ->execute([mb_substr(trim($nombre) ?: 'Formulario', 0, 255), self::urlGracias($urlGracias), self::ahora(), $id]);
    }

    public function usada(string $id): void
    {
        $this->pdo->prepare('UPDATE ' . self::TABLE . ' SET total = total + 1, ultimo_uso_en = ? WHERE id = ?')->execute([self::ahora(), $id]);
    }

    // ---- Lo que llega ----------------------------------------------------------------

    /** Clave de campo comparable: minúsculas, sin tildes, espacios y guiones como «_». */
    private static function clave(string $k): string
    {
        $k = mb_strtolower(trim($k));
        $k = strtr($k, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u']);
        return (string) preg_replace('/[^a-z0-9]+/', '_', $k);
    }

    /**
     * Pasa lo recibido a nuestros campos. Sólo se toman valores simples de primer nivel;
     * lo que no reconoce se ignora (no guardamos datos que no pedimos).
     *
     * @param array<string, mixed> $in
     * @return array<string, string>
     */
    public static function mapear(array $in): array
    {
        $out = [];
        $nombre = $apellido = '';
        foreach ($in as $k => $v) {
            if (!is_scalar($v)) {
                continue;
            }
            $k = self::clave((string) $k);
            $v = trim((string) $v);
            if ($v === '') {
                continue;
            }
            if (in_array($k, ['first_name', 'nombres', 'primer_nombre'], true)) {
                $nombre = $v;
                continue;
            }
            if (in_array($k, ['last_name', 'apellido', 'apellidos'], true)) {
                $apellido = $v;
                continue;
            }
            $campo = self::ALIAS[$k] ?? null;
            if ($campo !== null && !isset($out[$campo])) {
                $out[$campo] = $v;
            }
        }
        if (!isset($out['nombre']) && ($nombre !== '' || $apellido !== '')) {
            $out['nombre'] = trim($nombre . ' ' . $apellido);
        }
        if (isset($out['mensaje'])) {
            $out['notas'] = 'Mensaje: ' . mb_substr($out['mensaje'], 0, 2000);
            unset($out['mensaje']);
        }
        return $out;
    }

    /**
     * ¿Parece un bot? Campo trampa «sitio_web» lleno, o el formulario se envió demasiado rápido
     * («t» es el momento en que se abrió, en milisegundos; sin JS no viene y no se exige).
     *
     * @param array<string, mixed> $in
     */
    public static function esBot(array $in, ?int $ahoraMs = null): bool
    {
        if (trim((string) ($in['sitio_web'] ?? '')) !== '') {
            return true;
        }
        $t = (int) ($in['t'] ?? 0);
        $ahoraMs ??= (int) floor(microtime(true) * 1000);
        return $t > 0 && $ahoraMs - $t < self::MIN_MS;
    }

    public static function ipHash(string $ip): string
    {
        return hash('sha256', $ip . '|portal-leads');
    }

    /** ¿Ya se pasó el tope? $ipHash null = no se cuenta por IP (webhooks). */
    public function excedido(string $fuenteId, ?string $ipHash): bool
    {
        $hora = (new \DateTimeImmutable())->modify('-1 hour')->format('Y-m-d H:i:s');
        $dia  = (new \DateTimeImmutable())->modify('-1 day')->format('Y-m-d H:i:s');
        if ($ipHash !== null) {
            $st = $this->pdo->prepare('SELECT COUNT(*) FROM ' . self::INTENTOS . " WHERE ip_hash = ? AND created_at > ? AND resultado IN ('ok', 'duplicado', 'invalido')");
            $st->execute([$ipHash, $hora]);
            if ((int) $st->fetchColumn() >= self::MAX_IP_HORA) {
                return true;
            }
        }
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM ' . self::INTENTOS . " WHERE fuente_id = ? AND created_at > ? AND resultado IN ('ok', 'duplicado')");
        $st->execute([$fuenteId, $dia]);
        return (int) $st->fetchColumn() >= self::MAX_FUENTE_DIA;
    }

    /** Anota el intento y, de paso, borra los de hace más de dos días. */
    public function anotar(?string $fuenteId, string $ipHash, string $resultado): void
    {
        $now = new \DateTimeImmutable();
        $this->pdo->prepare('INSERT INTO ' . self::INTENTOS . ' (id, fuente_id, ip_hash, resultado, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([typedock_uuid7(), $fuenteId, $ipHash, mb_substr($resultado, 0, 12), $now->format('Y-m-d H:i:s')]);
        if (random_int(1, 20) === 1) {
            $this->pdo->prepare('DELETE FROM ' . self::INTENTOS . ' WHERE created_at < ?')->execute([$now->modify('-2 days')->format('Y-m-d H:i:s')]);
        }
    }

    /** Fragmento HTML de ejemplo para pegar en la landing (con trampa, tiempo y UTM). */
    public static function snippet(string $endpoint): string
    {
        return <<<HTML
<form action="{$endpoint}" method="post">
  <input name="nombre" placeholder="Nombre" required>
  <input name="correo" type="email" placeholder="Correo">
  <input name="telefono" type="tel" placeholder="Teléfono">
  <textarea name="mensaje" placeholder="¿En qué te ayudamos?"></textarea>
  <!-- No borrar: protección contra spam y datos de la campaña -->
  <input name="sitio_web" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px">
  <input type="hidden" name="t"><input type="hidden" name="utm_source"><input type="hidden" name="utm_medium"><input type="hidden" name="utm_campaign">
  <script>(function(e,q){e.t.value=Date.now();['utm_source','utm_medium','utm_campaign'].forEach(function(k){e[k].value=q.get(k)||''})})(document.currentScript.closest('form').elements,new URLSearchParams(location.search))</script>
  <button type="submit">Enviar</button>
</form>
HTML;
    }
}

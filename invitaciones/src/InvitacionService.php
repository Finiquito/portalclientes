<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Invitaciones;

/** Guarda inscripciones (copia local primero) y las sincroniza con Mailchimp. */
final class InvitacionService
{
    /** Valores del formulario → texto que se guarda en Mailchimp (campo TAMANO). */
    public const TAMANOS = [
        '1'    => 'Independiente',
        '2-5'  => '2 a 5',
        '6-15' => '6 a 15',
        '+15'  => 'Más de 15',
    ];
    public const RUBROS = ['Diseño gráfico', 'Publicidad', 'Marketing digital y redes', 'Desarrollo web', 'Audiovisual', 'Otro'];

    private const MAX_POR_IP_HORA = 6;

    public function __construct(private readonly \PDO $pdo, private readonly Ajustes $ajustes) {}

    private static function ahora(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }

    public static function emailValido(string $e): bool
    {
        return mb_strlen($e) <= 254 && filter_var($e, FILTER_VALIDATE_EMAIL) !== false && preg_match('/\.[a-z]{2,}$/i', $e) === 1;
    }

    /**
     * Normaliza lo que llega del formulario.
     *
     * @param array<string, mixed> $p
     * @return array{email: string, nombre: string, tamano: string, rubro: string, origen: string}
     */
    public static function normalizar(array $p): array
    {
        $txt = static fn(string $k, int $max): string => mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags((string) ($p[$k] ?? ''))) ?? ''), 0, $max);
        $tam = (string) ($p['tamano'] ?? '');
        $rub = $txt('rubro', 60);
        return [
            'email'  => strtolower($txt('email', 254)),
            'nombre' => $txt('nombre', 120),
            'tamano' => isset(self::TAMANOS[$tam]) ? $tam : '',
            'rubro'  => in_array($rub, self::RUBROS, true) ? $rub : '',
            'origen' => in_array($p['origen'] ?? '', ['portada', 'formulario'], true) ? (string) $p['origen'] : 'otro',
        ];
    }

    /** ¿Demasiados envíos desde la misma IP en la última hora? */
    public function limiteIp(string $ipHash): bool
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM invitaciones WHERE ip_hash = ? AND updated_at > ?');
        $st->execute([$ipHash, (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s')]);
        return (int) $st->fetchColumn() >= self::MAX_POR_IP_HORA;
    }

    /**
     * Guarda (o actualiza, si el correo ya estaba) y devuelve el id. Un segundo envío con más
     * datos (p. ej. primero sólo el correo, después el formulario completo) completa la fila.
     *
     * @param array{email: string, nombre: string, tamano: string, rubro: string, origen: string} $d
     */
    public function guardar(array $d, string $ipHash): string
    {
        $st = $this->pdo->prepare('SELECT * FROM invitaciones WHERE email = ?');
        $st->execute([$d['email']]);
        $f = $st->fetch();
        if ($f !== false) {
            $this->pdo->prepare(
                "UPDATE invitaciones SET nombre = ?, tamano = ?, rubro = ?, ip_hash = ?, mc_estado = 'pendiente', updated_at = ? WHERE id = ?"
            )->execute([
                $d['nombre'] !== '' ? $d['nombre'] : $f['nombre'],
                $d['tamano'] !== '' ? $d['tamano'] : $f['tamano'],
                $d['rubro'] !== '' ? $d['rubro'] : $f['rubro'],
                $ipHash, self::ahora(), $f['id'],
            ]);
            return (string) $f['id'];
        }
        $id = function_exists('typedock_uuid7') ? typedock_uuid7() : bin2hex(random_bytes(16));
        $this->pdo->prepare(
            'INSERT INTO invitaciones (id, email, nombre, tamano, rubro, origen, ip_hash, mc_estado, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$id, $d['email'], $d['nombre'], $d['tamano'], $d['rubro'], $d['origen'], $ipHash, 'pendiente', self::ahora(), self::ahora()]);
        return $id;
    }

    public function find(string $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM invitaciones WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch();
        return $r !== false ? $r : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function lista(int $max = 500): array
    {
        return $this->pdo->query('SELECT * FROM invitaciones ORDER BY created_at DESC LIMIT ' . max(1, $max))->fetchAll();
    }

    /** @return array<string, int> */
    public function resumen(): array
    {
        $out = ['total' => 0, 'ok' => 0, 'error' => 0, 'pendiente' => 0];
        foreach ($this->pdo->query('SELECT mc_estado, COUNT(*) AS n FROM invitaciones GROUP BY mc_estado')->fetchAll() as $r) {
            $out[(string) $r['mc_estado']] = (int) $r['n'];
            $out['total'] += (int) $r['n'];
        }
        return $out;
    }

    public function configurado(): bool
    {
        return Mailchimp::claveValida($this->ajustes->clave()) && $this->ajustes->get('mc_audiencia') !== '';
    }

    /** Envía una inscripción a Mailchimp y deja el resultado en la fila. */
    public function sincronizar(string $id): bool
    {
        $f = $this->find($id);
        if ($f === null) {
            return false;
        }
        if (!$this->configurado()) {
            $this->marcar($id, 'pendiente', 'Mailchimp no está configurado todavía.');
            return false;
        }
        $campos = [];
        $tagNombre = trim($this->ajustes->get('mc_campo_nombre', 'FNAME'));
        $tagTamano = trim($this->ajustes->get('mc_campo_tamano', 'TAMANO'));
        $tagRubro  = trim($this->ajustes->get('mc_campo_rubro', ''));
        if ($tagNombre !== '' && $f['nombre']) {
            $campos[$tagNombre] = (string) $f['nombre'];
        }
        if ($tagTamano !== '' && $f['tamano']) {
            $campos[$tagTamano] = self::TAMANOS[$f['tamano']] ?? (string) $f['tamano'];
        }
        if ($tagRubro !== '' && $f['rubro']) {
            $campos[$tagRubro] = (string) $f['rubro'];
        }
        $etiquetas = array_values(array_filter(array_map('trim', explode(',', $this->ajustes->get('mc_etiquetas', 'invitacion-prisma')))));

        $mc = new Mailchimp($this->ajustes->clave(), $this->ajustes->get('mc_audiencia'));
        try {
            [$ok, $detalle] = $mc->inscribir((string) $f['email'], $campos, $etiquetas, $this->ajustes->get('mc_doble', '1') === '1');
        } catch (\Throwable $e) {
            [$ok, $detalle] = [false, 'Error inesperado: ' . $e->getMessage()];
        }
        $this->marcar($id, $ok ? 'ok' : 'error', $ok ? (str_contains($detalle, '(') ? $detalle : null) : $detalle);
        return $ok;
    }

    private function marcar(string $id, string $estado, ?string $error): void
    {
        $this->pdo->prepare('UPDATE invitaciones SET mc_estado = ?, mc_error = ? WHERE id = ?')
            ->execute([$estado, $error !== null ? mb_substr($error, 0, 500) : null, $id]);
    }

    /** Reintenta todas las que quedaron pendientes o con error. */
    public function reintentar(int $max = 100): array
    {
        $st = $this->pdo->query("SELECT id FROM invitaciones WHERE mc_estado <> 'ok' ORDER BY created_at LIMIT " . max(1, $max));
        $ok = 0;
        $mal = 0;
        foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            $this->sincronizar((string) $id) ? $ok++ : $mal++;
        }
        return [$ok, $mal];
    }

    public function borrar(string $id): void
    {
        $this->pdo->prepare('DELETE FROM invitaciones WHERE id = ?')->execute([$id]);
    }
}

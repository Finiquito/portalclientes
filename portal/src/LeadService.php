<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * Contactos ganados (leads): los clientes de nuestro cliente que llegan por sus campañas.
 *
 * No es un CRM: guarda lo justo (nombre, correo, teléfono, empresa, de dónde vino), el estado
 * del seguimiento, notas y un próximo paso. Cada lead pertenece a un proyecto con «Contactos
 * ganados» activo (portal_proyectos.con_leads) y, por él, a un cliente: toda consulta va
 * acotada por cliente o por proyecto para que nunca se crucen datos entre clientes.
 */
class LeadService
{
    public const TABLE = 'portal_leads';

    public const ESTADOS = [
        'nuevo'      => 'Nuevo',
        'contactado' => 'Contactado',
        'calificado' => 'Calificado',
        'ganado'     => 'Ganado',
        'perdido'    => 'Perdido',
    ];

    /** Color del chip de cada estado (clases del portal y del admin). */
    public const CHIPS = [
        'nuevo'      => 'chip-brand',
        'contactado' => 'chip-info',
        'calificado' => 'chip-warn',
        'ganado'     => 'chip-ok',
        'perdido'    => 'chip-muted',
    ];

    /** Lo mismo en el admin y el panel (clases de portal-admin.css). */
    public const CHIPS_ADMIN = [
        'nuevo'      => 'brand',
        'contactado' => 'info',
        'calificado' => 'warn',
        'ganado'     => 'ok',
        'perdido'    => 'gris',
    ];

    public const ORIGENES = [
        'manual'     => 'Ingreso manual',
        'formulario' => 'Formulario',
        'webhook'    => 'Webhook',
        'csv_meta'   => 'Meta Lead Ads',
        'csv_google' => 'Google Ads',
    ];

    /** Un mismo correo (o teléfono) en el mismo proyecto dentro de este plazo no crea otro lead. */
    public const DIAS_DUPLICADO = 30;

    /** Largo máximo de cada campo de texto (lo demás se corta). */
    private const LARGOS = [
        'nombre' => 255, 'correo' => 255, 'telefono' => 64, 'empresa' => 255, 'campana' => 255,
        'utm_source' => 120, 'utm_medium' => 120, 'utm_campaign' => 255, 'proximo_paso' => 255, 'notas' => 5000,
    ];

    public function __construct(private readonly \PDO $pdo) {}

    private static function ahora(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone(Zona::agencia())))->format('Y-m-d H:i:s');
    }

    // ---- Proyectos con contactos ganados ------------------------------------------

    /** @return array<int, array<string, mixed>> proyectos del cliente con el módulo activo */
    public function proyectosDe(string $clienteId): array
    {
        $st = $this->pdo->prepare("SELECT id, nombre, estado, cliente_id FROM portal_proyectos WHERE cliente_id = ? AND con_leads = 1 ORDER BY (estado <> 'activo'), nombre");
        $st->execute([$clienteId]);
        return $st->fetchAll();
    }

    public function clienteTieneLeads(string $clienteId): bool
    {
        return $this->proyectosDe($clienteId) !== [];
    }

    /** @return array<string, mixed>|null el proyecto, sólo si tiene el módulo activo */
    public function proyecto(string $proyectoId): ?array
    {
        $st = $this->pdo->prepare('SELECT p.id, p.nombre, p.cliente_id, c.nombre AS cliente_nombre FROM portal_proyectos p JOIN portal_clientes c ON c.id = p.cliente_id WHERE p.id = ? AND p.con_leads = 1');
        $st->execute([$proyectoId]);
        $f = $st->fetch();
        return $f !== false ? $f : null;
    }

    /** @return array<int, array<string, mixed>> todos los proyectos con el módulo activo (para el panel) */
    public function proyectosConLeads(): array
    {
        $st = $this->pdo->query("SELECT p.id, p.nombre, p.estado, p.cliente_id, c.nombre AS cliente_nombre FROM portal_proyectos p JOIN portal_clientes c ON c.id = p.cliente_id WHERE p.con_leads = 1 ORDER BY c.nombre, p.nombre");
        return $st ? $st->fetchAll() : [];
    }

    // ---- Lectura -------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT l.*, p.nombre AS proyecto_nombre, c.nombre AS cliente_nombre
             FROM ' . self::TABLE . ' l JOIN portal_proyectos p ON p.id = l.proyecto_id JOIN portal_clientes c ON c.id = l.cliente_id
             WHERE l.id = ?'
        );
        $st->execute([$id]);
        $f = $st->fetch();
        return $f !== false ? $f : null;
    }

    /** El lead sólo si es de ese cliente (el portal nunca abre uno ajeno). */
    public function delCliente(string $id, string $clienteId): ?array
    {
        $l = $this->find($id);
        return $l !== null && (string) $l['cliente_id'] === $clienteId ? $l : null;
    }

    /**
     * Leads filtrados. $f: cliente_id, proyecto_id, estado, q (busca en nombre, correo,
     * teléfono, empresa y campaña), campana, desde, hasta (Y-m-d de llegada).
     *
     * @param array<string, string> $f
     * @return array<int, array<string, mixed>> los más nuevos primero
     */
    public function lista(array $f = []): array
    {
        $where = ['p.con_leads = 1'];
        $args  = [];
        foreach (['cliente_id' => 'l.cliente_id', 'proyecto_id' => 'l.proyecto_id', 'estado' => 'l.estado', 'campana' => 'l.campana'] as $k => $col) {
            if (($f[$k] ?? '') !== '') {
                $where[] = "{$col} = ?";
                $args[]  = $f[$k];
            }
        }
        if (($f['desde'] ?? '') !== '') {
            $where[] = 'l.created_at >= ?';
            $args[]  = substr($f['desde'], 0, 10) . ' 00:00:00';
        }
        if (($f['hasta'] ?? '') !== '') {
            $where[] = 'l.created_at <= ?';
            $args[]  = substr($f['hasta'], 0, 10) . ' 23:59:59';
        }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            // «!» como escape: la barra invertida se interpreta distinto en MySQL y SQLite.
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($q)) . '%';
            $where[] = "(LOWER(l.nombre) LIKE ? ESCAPE '!' OR LOWER(l.correo) LIKE ? ESCAPE '!' OR l.telefono LIKE ? ESCAPE '!' OR LOWER(l.empresa) LIKE ? ESCAPE '!' OR LOWER(l.campana) LIKE ? ESCAPE '!')";
            array_push($args, $like, $like, $like, $like, $like);
        }
        $st = $this->pdo->prepare(
            'SELECT l.*, p.nombre AS proyecto_nombre, c.nombre AS cliente_nombre
             FROM ' . self::TABLE . ' l JOIN portal_proyectos p ON p.id = l.proyecto_id JOIN portal_clientes c ON c.id = l.cliente_id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY l.created_at DESC, l.id DESC'
        );
        $st->execute($args);
        return $st->fetchAll();
    }

    /** @return array<int, string> campañas distintas que ya llegaron (para el filtro) */
    public static function campanas(array $leads): array
    {
        $c = array_values(array_unique(array_filter(array_map(fn(array $l): string => (string) ($l['campana'] ?? ''), $leads))));
        sort($c, SORT_NATURAL | SORT_FLAG_CASE);
        return $c;
    }

    /** @return array<string, int> cuántos hay en cada estado */
    public static function conteo(array $leads): array
    {
        $n = array_fill_keys(array_keys(self::ESTADOS), 0);
        foreach ($leads as $l) {
            if (isset($n[$l['estado']])) {
                $n[$l['estado']]++;
            }
        }
        return $n;
    }

    /**
     * Vista consolidada para la agencia: por proyecto y, dentro, por campaña (el texto que trae
     * cada lead, «Sin campaña» si no trae), con el conteo por estado.
     *
     * @param array<int, array<string, mixed>> $leads
     * @return array<int, array{proyecto_id: string, proyecto: string, cliente: string, total: int, estados: array<string, int>, campanas: array<int, array{campana: string, total: int, estados: array<string, int>}>}>
     */
    public static function porCampana(array $leads): array
    {
        $g = [];
        foreach ($leads as $l) {
            $pid = (string) $l['proyecto_id'];
            $g[$pid] ??= ['proyecto_id' => $pid, 'proyecto' => (string) $l['proyecto_nombre'], 'cliente' => (string) $l['cliente_nombre'], 'leads' => [], 'camp' => []];
            $g[$pid]['leads'][] = $l;
            $g[$pid]['camp'][(string) ($l['campana'] ?? '')][] = $l;
        }
        $out = [];
        foreach ($g as $p) {
            $camps = [];
            foreach ($p['camp'] as $nombre => $ls) {
                $camps[] = ['campana' => (string) $nombre, 'total' => count($ls), 'estados' => self::conteo($ls)];
            }
            usort($camps, fn($a, $b) => [$b['total'], $a['campana']] <=> [$a['total'], $b['campana']]);
            $out[] = ['proyecto_id' => $p['proyecto_id'], 'proyecto' => $p['proyecto'], 'cliente' => $p['cliente'], 'total' => count($p['leads']), 'estados' => self::conteo($p['leads']), 'campanas' => $camps];
        }
        usort($out, fn($a, $b) => [$a['cliente'], $a['proyecto']] <=> [$b['cliente'], $b['proyecto']]);
        return $out;
    }

    public function nuevosDelCliente(string $clienteId): int
    {
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM " . self::TABLE . " l JOIN portal_proyectos p ON p.id = l.proyecto_id WHERE l.cliente_id = ? AND l.estado = 'nuevo' AND p.con_leads = 1");
        $st->execute([$clienteId]);
        return (int) $st->fetchColumn();
    }

    // ---- Escritura ----------------------------------------------------------------

    /** Correo en minúsculas y sin espacios; vacío si no es válido. */
    public static function correo(string $c): string
    {
        $c = mb_strtolower(trim($c));
        return filter_var($c, FILTER_VALIDATE_EMAIL) !== false ? $c : '';
    }

    /** Teléfono con sólo dígitos y un + inicial (para comparar duplicados y llamar). */
    public static function telefono(string $t): string
    {
        $t = trim($t);
        $mas = str_starts_with($t, '+');
        $d = preg_replace('/\D+/', '', $t) ?? '';
        return $d === '' ? '' : ($mas ? '+' : '') . $d;
    }

    /**
     * Limpia lo que llega (formulario, webhook, CSV o a mano): sólo los campos permitidos,
     * cortados a su largo y sin caracteres de control.
     *
     * @param array<string, mixed> $d
     * @return array<string, string>
     */
    public static function limpiar(array $d): array
    {
        $out = [];
        foreach (self::LARGOS as $k => $max) {
            $v = is_scalar($d[$k] ?? null) ? (string) $d[$k] : '';
            $v = (string) preg_replace($k === 'notas' ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u', '', $v);
            $out[$k] = mb_substr(trim($v), 0, $max);
        }
        $out['correo']   = self::correo($out['correo']);
        $out['telefono'] = self::telefono($out['telefono']);
        return $out;
    }

    /**
     * Crea un lead en un proyecto con el módulo activo.
     *
     * @param array<string, mixed> $proyecto fila de proyecto() (id, cliente_id)
     * @param array<string, mixed> $datos
     * @return array{id?: string, duplicado?: bool, error?: string}
     */
    public function crear(array $proyecto, array $datos, string $origen, string $creadoPor, ?string $fuenteId = null): array
    {
        $d = self::limpiar($datos);
        if (($datos['correo'] ?? '') !== '' && $d['correo'] === '') {
            return ['error' => 'El correo no es válido.'];
        }
        if ($d['correo'] === '' && $d['telefono'] === '') {
            return ['error' => 'Falta un correo o un teléfono para poder contactarlo.'];
        }
        if ($d['nombre'] === '') {
            $d['nombre'] = $d['correo'] !== '' ? strstr($d['correo'], '@', true) : $d['telefono'];
        }
        if (!isset(self::ORIGENES[$origen])) {
            $origen = 'manual';
        }

        $previo = $this->duplicado((string) $proyecto['id'], $d['correo'], $d['telefono']);
        $now = self::ahora();
        if ($previo !== null) {
            $this->pdo->prepare('UPDATE ' . self::TABLE . ' SET updated_at = ? WHERE id = ?')->execute([$now, $previo]);
            return ['id' => $previo, 'duplicado' => true];
        }

        $estado = (string) ($datos['estado'] ?? 'nuevo');
        $id = typedock_uuid7();
        $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . ' (id, cliente_id, proyecto_id, fuente_id, nombre, correo, telefono, empresa, origen, campana,
                utm_source, utm_medium, utm_campaign, estado, notas, proximo_paso, proximo_paso_fecha, estado_en, estado_por, creado_por, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $id, (string) $proyecto['cliente_id'], (string) $proyecto['id'], $fuenteId,
            $d['nombre'], $d['correo'] ?: null, $d['telefono'] ?: null, $d['empresa'] ?: null, $origen,
            ($d['campana'] ?: $d['utm_campaign']) ?: null, $d['utm_source'] ?: null, $d['utm_medium'] ?: null, $d['utm_campaign'] ?: null,
            isset(self::ESTADOS[$estado]) ? $estado : 'nuevo', $d['notas'] ?: null, $d['proximo_paso'] ?: null,
            self::fecha((string) ($datos['proximo_paso_fecha'] ?? '')), $now, mb_substr($creadoPor, 0, 255), mb_substr($creadoPor, 0, 255), $now, $now,
        ]);
        return ['id' => $id];
    }

    /** Id de un lead del mismo proyecto con ese correo o teléfono en los últimos DIAS_DUPLICADO días. */
    public function duplicado(string $proyectoId, string $correo, string $telefono): ?string
    {
        $desde = (new \DateTimeImmutable('now', new \DateTimeZone(Zona::agencia())))->modify('-' . self::DIAS_DUPLICADO . ' days')->format('Y-m-d H:i:s');
        $cond = [];
        $args = [$proyectoId, $desde];
        if ($correo !== '') {
            $cond[] = 'correo = ?';
            $args[] = $correo;
        }
        if ($telefono !== '') {
            $cond[] = 'telefono = ?';
            $args[] = $telefono;
        }
        if ($cond === []) {
            return null;
        }
        $st = $this->pdo->prepare('SELECT id FROM ' . self::TABLE . ' WHERE proyecto_id = ? AND created_at >= ? AND anonimizado_en IS NULL AND (' . implode(' OR ', $cond) . ') ORDER BY created_at DESC');
        $st->execute($args);
        $id = $st->fetchColumn();
        return $id !== false ? (string) $id : null;
    }

    private static function fecha(string $f): ?string
    {
        $f = substr(trim($f), 0, 10);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) === 1 ? $f : null;
    }

    /**
     * Guarda el seguimiento (estado, notas, próximo paso) y, si vienen, los datos de contacto.
     * Si cambia el estado, queda quién y cuándo.
     *
     * @param array<string, mixed> $lead
     * @param array<string, mixed> $datos
     * @return string|null error
     */
    public function actualizar(array $lead, array $datos, string $quien): ?string
    {
        if (!empty($lead['anonimizado_en'])) {
            return 'Este contacto fue anonimizado: ya no se puede editar.';
        }
        $d = self::limpiar($datos + $lead);
        $estado = (string) ($datos['estado'] ?? $lead['estado']);
        if (!isset(self::ESTADOS[$estado])) {
            return 'Ese estado no existe.';
        }
        if (array_key_exists('correo', $datos) && trim((string) $datos['correo']) !== '' && $d['correo'] === '') {
            return 'El correo no es válido.';
        }
        if ($d['correo'] === '' && $d['telefono'] === '') {
            return 'Deja al menos un correo o un teléfono.';
        }
        $now = self::ahora();
        $cambia = $estado !== (string) $lead['estado'];
        $this->pdo->prepare(
            'UPDATE ' . self::TABLE . ' SET nombre = ?, correo = ?, telefono = ?, empresa = ?, estado = ?, notas = ?, proximo_paso = ?, proximo_paso_fecha = ?,
                estado_en = ?, estado_por = ?, updated_at = ? WHERE id = ?'
        )->execute([
            $d['nombre'] !== '' ? $d['nombre'] : (string) $lead['nombre'], $d['correo'] ?: null, $d['telefono'] ?: null, $d['empresa'] ?: null,
            $estado, $d['notas'] ?: null, $d['proximo_paso'] ?: null,
            array_key_exists('proximo_paso_fecha', $datos) ? self::fecha((string) $datos['proximo_paso_fecha']) : ($lead['proximo_paso_fecha'] ?? null),
            $cambia ? $now : $lead['estado_en'], $cambia ? mb_substr($quien, 0, 255) : $lead['estado_por'], $now, (string) $lead['id'],
        ]);
        return null;
    }

    /**
     * Borra los datos personales y deja el registro (estado, origen, campaña y fechas) para que los
     * conteos sigan cuadrando.
     */
    public function anonimizar(string $id): void
    {
        $this->pdo->prepare(
            "UPDATE " . self::TABLE . " SET nombre = 'Contacto anonimizado', correo = NULL, telefono = NULL, empresa = NULL, notas = NULL,
                proximo_paso = NULL, proximo_paso_fecha = NULL, anonimizado_en = ?, updated_at = ? WHERE id = ?"
        )->execute([self::ahora(), self::ahora(), $id]);
    }

    public function eliminar(string $id): void
    {
        $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE id = ?')->execute([$id]);
    }

    /** Al borrar un proyecto o un cliente: sus leads y fuentes se van con él. */
    public function borrarDeProyecto(string $proyectoId): void
    {
        $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE proyecto_id = ?')->execute([$proyectoId]);
        $this->pdo->prepare('DELETE FROM portal_lead_fuentes WHERE proyecto_id = ?')->execute([$proyectoId]);
    }

    public function borrarDeCliente(string $clienteId): void
    {
        $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE cliente_id = ?')->execute([$clienteId]);
        $this->pdo->prepare('DELETE FROM portal_lead_fuentes WHERE cliente_id = ?')->execute([$clienteId]);
    }
}

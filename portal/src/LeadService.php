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
        'csv'        => 'Planilla',
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
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
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
            'SELECT l.*, p.nombre AS proyecto_nombre, p.mc_audiencia, p.mc_audiencia_nombre, c.nombre AS cliente_nombre
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

        // Una planilla se puede volver a subir: ahí cuenta cualquier fecha, no sólo los últimos días.
        $previo = $this->duplicado((string) $proyecto['id'], $d['correo'], $d['telefono'], str_starts_with($origen, 'csv') ? null : self::DIAS_DUPLICADO);
        $now = self::ahora();
        if ($previo !== null) {
            $this->pdo->prepare('UPDATE ' . self::TABLE . ' SET updated_at = ? WHERE id = ?')->execute([$now, $previo]);
            return ['id' => $previo, 'duplicado' => true];
        }

        $estado = (string) ($datos['estado'] ?? 'nuevo');
        $llego = self::fechaHora((string) ($datos['creado_en'] ?? '')) ?? $now;   // de una planilla: cuándo llegó de verdad
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
            self::fecha((string) ($datos['proximo_paso_fecha'] ?? '')), null, null, mb_substr($creadoPor, 0, 255), $llego, $now,
        ]);
        return ['id' => $id];
    }

    /** Id de un lead del mismo proyecto con ese correo o teléfono en los últimos $dias días (null = cualquier fecha). */
    public function duplicado(string $proyectoId, string $correo, string $telefono, ?int $dias = self::DIAS_DUPLICADO): ?string
    {
        $desde = $dias === null ? '0000-00-00 00:00:00' : (new \DateTimeImmutable())->modify('-' . $dias . ' days')->format('Y-m-d H:i:s');
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

    /** Celda segura para Excel: lo que empieza con = + - @ (o tab/retorno) se lee como texto, no como fórmula. */
    public static function celda(?string $v): string
    {
        $v = (string) $v;
        return $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false ? "'" . $v : $v;
    }

    /**
     * Planilla de contactos (UTF-8 con BOM y «;», como la abre Excel en español).
     *
     * @param array<int, array<string, mixed>> $leads
     */
    public static function csv(array $leads, bool $conCliente = false): string
    {
        $fh = fopen('php://temp', 'r+');
        $cab = ['Nombre', 'Correo', 'Teléfono', 'Empresa', 'Proyecto', 'Campaña', 'Origen', 'Estado', 'Próximo paso', 'Fecha próximo paso', 'Notas', 'Llegó', 'utm_source', 'utm_medium', 'utm_campaign'];
        if ($conCliente) {
            array_unshift($cab, 'Cliente');
        }
        fputcsv($fh, $cab, ';', '"', '');
        foreach ($leads as $l) {
            $fila = [
                $l['nombre'], $l['correo'], $l['telefono'], $l['empresa'], $l['proyecto_nombre'] ?? '', $l['campana'],
                self::ORIGENES[$l['origen']] ?? $l['origen'], self::ESTADOS[$l['estado']] ?? $l['estado'], $l['proximo_paso'], $l['proximo_paso_fecha'],
                $l['notas'], $l['created_at'], $l['utm_source'], $l['utm_medium'], $l['utm_campaign'],
            ];
            if ($conCliente) {
                array_unshift($fila, $l['cliente_nombre'] ?? '');
            }
            fputcsv($fh, array_map(fn($v) => self::celda(is_scalar($v) ? (string) $v : ''), $fila), ';', '"', '');
        }
        rewind($fh);
        $out = "\xEF\xBB\xBF" . stream_get_contents($fh);
        fclose($fh);
        return $out;
    }

    /** Fecha y hora de una planilla (ISO de Meta, «2027-03-01 10:00», «01/03/2027»…), en la hora del servidor; nunca futura. */
    public static function fechaHora(string $f): ?string
    {
        $f = trim($f);
        if ($f === '') {
            return null;
        }
        if (preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{4})(.*)$#', $f, $m) === 1) {
            $f = sprintf('%04d-%02d-%02d%s', $m[3], $m[2], $m[1], $m[4]);
        }
        try {
            $d = (new \DateTimeImmutable($f))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        } catch (\Throwable) {
            return null;
        }
        return $d > new \DateTimeImmutable() || (int) $d->format('Y') < 2000 ? null : $d->format('Y-m-d H:i:s');
    }

    /**
     * Revisa las filas de una planilla antes de importar: cuáles entran, cuáles ya estaban
     * (en la campaña o repetidas en el mismo archivo) y cuáles no sirven.
     *
     * @param array<int, array<string, string>> $filas de LeadImportador
     * @return array{nuevos: int, duplicados: int, errores: array<int, string>, muestra: array<int, array<string, string>>}
     */
    public function revisar(string $proyectoId, array $filas): array
    {
        $vistos = [];
        $out = ['nuevos' => 0, 'duplicados' => 0, 'errores' => [], 'muestra' => []];
        foreach ($filas as $f) {
            $d = self::limpiar($f);
            $estado = 'nuevo';
            if (($f['correo'] ?? '') !== '' && $d['correo'] === '') {
                $estado = 'error';
                $out['errores'][] = 'Fila ' . ($f['_fila'] ?? '?') . ': el correo «' . mb_substr((string) $f['correo'], 0, 60) . '» no es válido.';
            } elseif ($d['correo'] === '' && $d['telefono'] === '') {
                $estado = 'error';
                $out['errores'][] = 'Fila ' . ($f['_fila'] ?? '?') . ': no tiene correo ni teléfono.';
            } else {
                $claves = array_filter(['c:' . $d['correo'], 't:' . $d['telefono']], fn($k) => strlen($k) > 2);
                if (array_intersect($claves, array_keys($vistos)) !== [] || $this->duplicado($proyectoId, $d['correo'], $d['telefono'], null) !== null) {
                    $estado = 'duplicado';
                }
                foreach ($claves as $k) {
                    $vistos[$k] = true;
                }
            }
            if ($estado === 'nuevo') {
                $out['nuevos']++;
            } elseif ($estado === 'duplicado') {
                $out['duplicados']++;
            }
            if (count($out['muestra']) < 15) {
                $out['muestra'][] = ['nombre' => $d['nombre'], 'correo' => $d['correo'], 'telefono' => $d['telefono'], 'campana' => $d['campana'] ?: $d['utm_campaign'], 'estado' => $estado];
            }
        }
        return $out;
    }

    /**
     * Importa las filas (las repetidas y las con error se saltan solas).
     *
     * @param array<string, mixed> $proyecto
     * @param array<int, array<string, string>> $filas
     * @param array<int, string> $creado fecha de llegada de cada fila (o vacío)
     * @return array{nuevos: int, duplicados: int, errores: int}
     */
    public function importar(array $proyecto, array $filas, array $creado, string $origen, string $quien): array
    {
        $n = ['nuevos' => 0, 'duplicados' => 0, 'errores' => 0];
        foreach ($filas as $i => $f) {
            $r = $this->crear($proyecto, $f + ['creado_en' => (string) ($creado[$i] ?? '')], $origen, $quien);
            $n[isset($r['error']) ? 'errores' : (!empty($r['duplicado']) ? 'duplicados' : 'nuevos')]++;
        }
        return $n;
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
        if ($d['correo'] !== (string) ($lead['correo'] ?? '')) {
            // Otro correo: lo que dijo Mailchimp del anterior ya no vale.
            $this->pdo->prepare('UPDATE ' . self::TABLE . ' SET mc_estado = NULL, mc_datos = NULL, mc_revisado_en = NULL WHERE id = ?')->execute([(string) $lead['id']]);
        }
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
     * Avisa a los colaboradores del cliente que llegaron contactos nuevos. El correo no lleva
     * datos de la persona (sólo cuántos hay y el enlace al portal) y se agrupa: si llegan varios
     * seguidos, sale uno con el total. Cada contacto puede apagarlo en sus Ajustes.
     *
     * @return int a cuántas personas se les avisó
     */
    public function avisarCliente(Notifier $n, string $clienteId, string $proyectoNombre): int
    {
        $total = $this->nuevosDelCliente($clienteId);
        if ($total === 0) {
            return 0;
        }
        $st = $this->pdo->prepare("SELECT id, nombre, email FROM portal_contactos WHERE cliente_id = ? AND rol = 'aprobador' AND email IS NOT NULL AND email <> ''");
        $st->execute([$clienteId]);
        $aj = new AjustesService($this->pdo);
        $asunto = $total === 1 ? 'Llegó un contacto nuevo · ' . $proyectoNombre : "Tienes {$total} contactos nuevos por atender";
        $cuerpo = $total === 1
            ? "Llegó un contacto nuevo por tu campaña «{$proyectoNombre}». Revísalo en el portal y anota el próximo paso."
            : "Tienes {$total} contactos nuevos por atender en tus campañas. Revísalos en el portal y anota el próximo paso de cada uno.";
        $avisados = 0;
        foreach ($st->fetchAll() as $c) {
            $pref = $aj->todos('contacto', (string) $c['id']);
            if (($pref['avisos_email'] ?? '1') === '0' || ($pref['avisos_leads'] ?? '1') === '0') {
                continue;
            }
            [$html, $texto] = $n->componer($asunto, $cuerpo, ['etiqueta' => 'Contactos ganados', 'boton' => 'Ver contactos'], 'Hola ' . $n->primerNombre((string) $c['nombre']) . ',',
                $n->absoluta('/portal/contactos-ganados'), $clienteId, ['Puedes desactivar este aviso en Ajustes dentro del portal.']);
            $n->agrupado($c, $clienteId, Vigencia::leads($clienteId), $asunto, $html, $texto);
            $avisados++;
        }
        return $avisados;
    }

    // ---- Mailchimp (sólo lectura) ----------------------------------------------------

    /** Horas que vale una consulta antes de volver a preguntar sola. */
    public const MC_HORAS = 24;

    /** El cliente de Mailchimp del proyecto, si tiene clave y audiencia elegida. */
    public function mailchimp(string $proyectoId): ?array
    {
        $st = $this->pdo->prepare('SELECT cliente_id, mc_audiencia, mc_audiencia_nombre FROM portal_proyectos WHERE id = ? AND con_leads = 1');
        $st->execute([$proyectoId]);
        $p = $st->fetch();
        if ($p === false || (string) ($p['mc_audiencia'] ?? '') === '') {
            return null;
        }
        $mc = MailchimpConsulta::deCliente($this->pdo, (string) $p['cliente_id']);
        return $mc === null ? null : ['consulta' => $mc, 'audiencia' => (string) $p['mc_audiencia'], 'nombre' => (string) $p['mc_audiencia_nombre']];
    }

    /** ¿Hay que volver a preguntar? (nunca se revisó, o hace más de MC_HORAS) */
    public static function mcVencido(array $lead): bool
    {
        $r = (string) ($lead['mc_revisado_en'] ?? '');
        return $r === '' || $r < (new \DateTimeImmutable())->modify('-' . self::MC_HORAS . ' hours')->format('Y-m-d H:i:s');
    }

    /**
     * Pregunta a Mailchimp por este contacto y guarda el resumen.
     *
     * @return string|null error (null si se pudo revisar)
     */
    public function revisarMailchimp(array $lead, bool $actividad = true): ?string
    {
        if (!empty($lead['anonimizado_en']) || (string) ($lead['correo'] ?? '') === '') {
            return 'Sin correo no se puede revisar en Mailchimp.';
        }
        $mc = $this->mailchimp((string) $lead['proyecto_id']);
        if ($mc === null) {
            return 'Esta campaña no está conectada a Mailchimp.';
        }
        $r = $mc['consulta']->persona($mc['audiencia'], (string) $lead['correo'], $actividad);
        if ($r['estado'] === 'error') {
            return (string) ($r['datos']['error'] ?? 'No se pudo revisar en Mailchimp.');
        }
        // Sin actividad nueva (revisión en lote), se conserva la que ya teníamos.
        if (!$actividad) {
            $antes = json_decode((string) ($lead['mc_datos'] ?? ''), true);
            if (is_array($antes) && isset($antes['actividad'])) {
                $r['datos']['actividad'] = $antes['actividad'];
            }
        }
        $this->pdo->prepare('UPDATE ' . self::TABLE . ' SET mc_estado = ?, mc_datos = ?, mc_revisado_en = ? WHERE id = ?')
            ->execute([$r['estado'], json_encode($r['datos'], JSON_UNESCAPED_UNICODE), self::ahora(), (string) $lead['id']]);
        return null;
    }

    /**
     * Revisa en lote los contactos de una campaña que nunca se revisaron o están vencidos
     * (sin la actividad, que se trae al abrir cada ficha).
     *
     * @return array{revisados: int, pendientes: int, error: ?string}
     */
    public function revisarProyecto(string $proyectoId, int $max = 40): array
    {
        $st = $this->pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE proyecto_id = ? AND correo IS NOT NULL AND anonimizado_en IS NULL ORDER BY mc_revisado_en, created_at DESC');
        $st->execute([$proyectoId]);
        $porRevisar = array_values(array_filter($st->fetchAll(), [self::class, 'mcVencido']));
        $n = 0;
        $error = null;
        foreach (array_slice($porRevisar, 0, $max) as $l) {
            $error = $this->revisarMailchimp($l, false);
            if ($error !== null) {
                break;
            }
            $n++;
        }
        return ['revisados' => $n, 'pendientes' => count($porRevisar) - $n, 'error' => $error];
    }

    /** Resumen guardado, listo para mostrar. */
    public static function mc(array $lead): ?array
    {
        $estado = (string) ($lead['mc_estado'] ?? '');
        if ($estado === '' || !isset(MailchimpConsulta::ESTADOS[$estado])) {
            return null;
        }
        $d = json_decode((string) ($lead['mc_datos'] ?? ''), true);
        return ['estado' => $estado, 'etiqueta' => MailchimpConsulta::ESTADOS[$estado], 'revisado' => (string) $lead['mc_revisado_en']] + (is_array($d) ? $d : []);
    }

    /**
     * Borra los datos personales y deja el registro (estado, origen, campaña y fechas) para que los
     * conteos sigan cuadrando.
     */
    public function anonimizar(string $id): void
    {
        $this->pdo->prepare(
            "UPDATE " . self::TABLE . " SET nombre = 'Contacto anonimizado', correo = NULL, telefono = NULL, empresa = NULL, notas = NULL,
                proximo_paso = NULL, proximo_paso_fecha = NULL, mc_estado = NULL, mc_datos = NULL, mc_revisado_en = NULL, anonimizado_en = ?, updated_at = ? WHERE id = ?"
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

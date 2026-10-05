<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

use TypeDock\Core\PluginContext;

/**
 * Convocados de una reunión (personas del equipo y contactos del cliente) y sus invitaciones.
 *
 *  - Al convocar a alguien le llega una invitación de calendario (.ics adjunto cuando el correo sale
 *    por el SMTP del portal, más enlaces para Google Calendar y para descargar el .ics).
 *  - Si cambia la fecha, la hora, la duración, el título o el Meet, a todos les llega la invitación
 *    actualizada (misma reunión, versión siguiente: el calendario la reemplaza).
 *  - Al quitar a alguien o borrar la reunión, le llega la cancelación.
 *  - El día anterior, un recordatorio (Avisos::recordatorios).
 *
 * A los contactos sólo se les invita si la reunión es visible para el cliente; les llega en su hora
 * y dentro de su horario hábil, salvo que la reunión sea dentro de las próximas 36 horas.
 */
final class Convocados
{
    public const TABLA = 'portal_reunion_asistentes';

    private Notifier $n;

    /** true: al cliente le llega ya, sin esperar su horario hábil (p. ej. cuando él pidió la reunión). */
    public bool $inmediato = false;

    public function __construct(private readonly PluginContext $ctx, private readonly \PDO $pdo, ?Notifier $n = null)
    {
        $this->n = $n ?? new Notifier($ctx, $pdo);
    }

    /** @return array{equipo: array<int, string>, contacto: array<int, string>} */
    public function ids(string $reunionId): array
    {
        Schema::asegurar($this->pdo);
        $st = $this->pdo->prepare('SELECT asistente_tipo, asistente_usuario_id, asistente_contacto_id FROM ' . self::TABLA . ' WHERE reunion_id = ?');
        $st->execute([$reunionId]);
        $out = ['equipo' => [], 'contacto' => []];
        foreach ($st->fetchAll() as $f) {
            if ($f['asistente_tipo'] === 'equipo' && $f['asistente_usuario_id']) {
                $out['equipo'][] = (string) $f['asistente_usuario_id'];
            } elseif ($f['asistente_tipo'] === 'contacto' && $f['asistente_contacto_id']) {
                $out['contacto'][] = (string) $f['asistente_contacto_id'];
            }
        }
        return $out;
    }

    /** @return array<int, array<string, mixed>> [tipo, id, nombre, email, cargo] */
    public function personas(string $reunionId): array
    {
        Schema::asegurar($this->pdo);
        $st = $this->pdo->prepare(
            "SELECT 'equipo' AS tipo, e.id, e.nombre, e.email, e.cargo FROM " . self::TABLA . " a JOIN portal_equipo e ON e.id = a.asistente_usuario_id
             WHERE a.reunion_id = ? AND a.asistente_tipo = 'equipo' AND e.activo = 1
             UNION ALL
             SELECT 'contacto' AS tipo, c.id, c.nombre, c.email, c.rol AS cargo FROM " . self::TABLA . " a JOIN portal_contactos c ON c.id = a.asistente_contacto_id
             WHERE a.reunion_id = ? AND a.asistente_tipo = 'contacto'"
        );
        $st->execute([$reunionId, $reunionId]);
        return $st->fetchAll();
    }

    /**
     * Deja los convocados como vienen del formulario. Sólo acepta personas activas del equipo y
     * contactos del cliente de la reunión.
     *
     * @param array<int, string> $equipoIds
     * @param array<int, string> $contactoIds
     * @return array{nuevos: array<int, array{tipo: string, id: string}>, quitados: array<int, array{tipo: string, id: string}>}
     */
    public function guardar(string $reunionId, string $clienteId, array $equipoIds, array $contactoIds): array
    {
        Schema::asegurar($this->pdo);
        $validos = function (string $sql, array $ids, array $extra = []): array {
            $ids = array_values(array_unique(array_filter(array_map('strval', $ids))));
            if ($ids === []) {
                return [];
            }
            $st = $this->pdo->prepare(sprintf($sql, implode(',', array_fill(0, count($ids), '?'))));
            $st->execute(array_merge($ids, $extra));
            return array_map('strval', $st->fetchAll(\PDO::FETCH_COLUMN));
        };
        $quiere = [
            'equipo'   => $validos('SELECT id FROM portal_equipo WHERE id IN (%s) AND activo = 1', $equipoIds),
            'contacto' => $validos('SELECT id FROM portal_contactos WHERE id IN (%s) AND cliente_id = ?', $contactoIds, [$clienteId]),
        ];
        $hay = $this->ids($reunionId);
        $nuevos = $quitados = [];
        foreach (['equipo', 'contacto'] as $tipo) {
            foreach (array_diff($quiere[$tipo], $hay[$tipo]) as $id) {
                $this->pdo->prepare('INSERT INTO ' . self::TABLA . ' (id, reunion_id, asistente_tipo, asistente_usuario_id, asistente_contacto_id) VALUES (?, ?, ?, ?, ?)')
                    ->execute([typedock_uuid7(), $reunionId, $tipo, $tipo === 'equipo' ? $id : null, $tipo === 'contacto' ? $id : null]);
                $nuevos[] = ['tipo' => $tipo, 'id' => $id];
            }
            foreach (array_diff($hay[$tipo], $quiere[$tipo]) as $id) {
                $col = $tipo === 'equipo' ? 'asistente_usuario_id' : 'asistente_contacto_id';
                $this->pdo->prepare('DELETE FROM ' . self::TABLA . " WHERE reunion_id = ? AND asistente_tipo = ? AND {$col} = ?")->execute([$reunionId, $tipo, $id]);
                $quitados[] = ['tipo' => $tipo, 'id' => $id];
            }
        }
        return ['nuevos' => $nuevos, 'quitados' => $quitados];
    }

    /** Suma un convocado sin tocar a los demás (p. ej. quien pidió la reunión). */
    public function agregar(string $reunionId, string $tipo, string $id): void
    {
        $hay = $this->ids($reunionId);
        if (!in_array($id, $hay[$tipo] ?? [], true)) {
            $this->pdo->prepare('INSERT INTO ' . self::TABLA . ' (id, reunion_id, asistente_tipo, asistente_usuario_id, asistente_contacto_id) VALUES (?, ?, ?, ?, ?)')
                ->execute([typedock_uuid7(), $reunionId, $tipo, $tipo === 'equipo' ? $id : null, $tipo === 'contacto' ? $id : null]);
        }
    }

    /** ¿Cambió algo que va en la invitación? @param array<string, mixed> $a @param array<string, mixed> $b */
    public static function cambioLaInvitacion(array $a, array $b): bool
    {
        foreach (['fecha', 'duracion_min', 'titulo', 'enlace_meet'] as $k) {
            if ((string) ($a[$k] ?? '') !== (string) ($b[$k] ?? '')) {
                return true;
            }
        }
        return false;
    }

    /** Sólo se invita a reuniones con hora que todavía no terminan. @param array<string, mixed> $r */
    public function invitable(array $r): bool
    {
        $ini = ReunionService::aUtc((string) $r['fecha']);
        if ($ini === null || !str_contains((string) $r['fecha'], ':')) {
            return false;
        }
        $ahora = Notifier::$ahora ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return $ini->modify('+' . max(5, (int) ($r['duracion_min'] ?? 60)) . ' minutes') > $ahora;
    }

    /**
     * Después de guardar una reunión: invita a los nuevos, manda la versión nueva a todos si cambió
     * algo de la invitación y la cancelación a los que se quitaron.
     *
     * @param array<string, mixed>|null $antes null si la reunión es nueva
     * @param array<string, mixed> $ahora
     * @param array{nuevos: array<int, array{tipo: string, id: string}>, quitados: array<int, array{tipo: string, id: string}>} $cambios
     * @return int invitaciones enviadas
     */
    public function sincronizar(?array $antes, array $ahora, array $cambios): int
    {
        $rid = (string) $ahora['id'];
        $cambio = $antes !== null && self::cambioLaInvitacion($antes, $ahora);
        // Si recién se hizo visible al cliente, sus contactos convocados reciben la invitación.
        $recienVisible = $antes !== null && (int) $antes['publicada'] === 0 && (int) $ahora['publicada'] === 1;
        if ($cambio) {
            $this->pdo->prepare('UPDATE ' . ReunionService::TABLE . ' SET ics_seq = ics_seq + 1 WHERE id = ?')->execute([$rid]);
            $ahora['ics_seq'] = (int) ($ahora['ics_seq'] ?? 0) + 1;
        }
        $enviadas = 0;
        foreach ($cambios['quitados'] as $q) {
            $p = $this->persona($q['tipo'], $q['id']);
            if ($p !== null && $this->invitable($antes ?? $ahora)) {
                $enviadas += $this->enviar($antes ?? $ahora, $p, 'cancelada', (int) ($ahora['ics_seq'] ?? 0) + 1);
            }
        }
        if (!$this->invitable($ahora)) {
            return $enviadas;
        }
        $nuevos = array_map(fn($x) => $x['tipo'] . ':' . $x['id'], $cambios['nuevos']);
        foreach ($this->personas($rid) as $p) {
            $esNuevo = in_array($p['tipo'] . ':' . $p['id'], $nuevos, true);
            $contactoRecien = $recienVisible && $p['tipo'] === 'contacto';
            if ($esNuevo || $contactoRecien) {
                $enviadas += $this->enviar($ahora, $p, 'nueva');
            } elseif ($cambio) {
                $enviadas += $this->enviar($ahora, $p, 'cambio');
            }
        }
        if ($enviadas > 0) {
            $av = new Avisos($this->ctx, $this->pdo, $this->n);
            $this->pdo->prepare('DELETE FROM ' . Avisos::MARCAS . ' WHERE clave = ?')->execute(['inv:' . $rid]);
            $av->marcar('inv:' . $rid);
        }
        return $enviadas;
    }

    /** Antes de borrar una reunión: cancelación a todos los convocados. @param array<string, mixed> $r */
    public function cancelarTodo(array $r): int
    {
        if (!$this->invitable($r)) {
            return 0;
        }
        $n = 0;
        foreach ($this->personas((string) $r['id']) as $p) {
            $n += $this->enviar($r, $p, 'cancelada', (int) ($r['ics_seq'] ?? 0) + 1);
        }
        return $n;
    }

    /** Recordatorio del día anterior. @param array<string, mixed> $r */
    public function recordar(array $r): int
    {
        $r = (new ReunionService($this->pdo))->find((string) $r['id']) ?? $r;
        $n = 0;
        foreach ($this->personas((string) $r['id']) as $p) {
            $n += $this->enviar($r, $p, 'recordatorio');
        }
        return $n;
    }

    /** @return array<string, mixed>|null */
    private function persona(string $tipo, string $id): ?array
    {
        $st = $this->pdo->prepare($tipo === 'equipo'
            ? "SELECT 'equipo' AS tipo, id, nombre, email, cargo FROM portal_equipo WHERE id = ?"
            : "SELECT 'contacto' AS tipo, id, nombre, email, rol AS cargo FROM portal_contactos WHERE id = ?");
        $st->execute([$id]);
        $p = $st->fetch();
        return $p !== false ? $p : null;
    }

    // ---- Enlaces --------------------------------------------------------------------

    private function clave(): string
    {
        $aj = new AjustesService($this->pdo);
        $k = $aj->get('global', 'portal', 'ics_clave');
        if (strlen($k) < 32) {
            $k = bin2hex(random_bytes(24));
            $aj->set('global', 'portal', 'ics_clave', $k);
        }
        return $k;
    }

    public function token(string $reunionId): string
    {
        return substr(hash_hmac('sha256', 'ics|' . $reunionId, $this->clave()), 0, 32);
    }

    public function tokenValido(string $reunionId, string $t): bool
    {
        return $t !== '' && hash_equals($this->token($reunionId), $t);
    }

    /** Descarga del .ics sin iniciar sesión (enlace firmado), para Outlook, Apple y otros. */
    public function urlIcs(string $reunionId): string
    {
        return $this->n->absoluta('/portal/reuniones/' . $reunionId . '/invitacion.ics?t=' . $this->token($reunionId));
    }

    // ---- Correo -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $r reunión (con proyecto_nombre, cliente_id, cliente_nombre)
     * @param array<string, mixed> $p persona convocada (tipo, id, nombre, email)
     * @return int 1 si se envió (o quedó en cola), 0 si no
     */
    private function enviar(array $r, array $p, string $tipo, ?int $seq = null): int
    {
        $esCliente = $p['tipo'] === 'contacto';
        if ($esCliente && (int) ($r['publicada'] ?? 0) !== 1) {
            return 0;   // el cliente no la ve: no se le invita
        }
        if (!filter_var((string) $p['email'], FILTER_VALIDATE_EMAIL)) {
            return 0;
        }
        $svc = new ReunionService($this->pdo);
        $r = ($svc->find((string) $r['id']) ?? []) + $r;   // con el nombre del cliente y del proyecto
        if ($seq !== null) {
            $r['ics_seq'] = $seq;
        }
        $cid = (string) $r['cliente_id'];
        $fmt = new Fmt();
        $pais = $esCliente ? $this->n->paisDe($cid) : Zona::pais();
        $fechaLocal = $esCliente ? Zona::aPais((string) $r['fecha'], $pais) : (string) $r['fecha'];
        $cuando = $fmt->fechaLarga($fechaLocal) . ' a las ' . $fmt->hora($fechaLocal);
        $zona = 'hora de ' . HorarioHabil::nombreDe($pais);
        $url = $this->n->absoluta(($esCliente ? '/portal' : '/equipo') . '/reuniones/' . $r['id']);

        [$asunto, $etiqueta, $titulo, $resaltado, $intro] = match ($tipo) {
            'cambio'       => ['Cambió la reunión: ' . $r['titulo'] . ' — ' . $fmt->fechaCorta($fechaLocal) . ' ' . $fmt->hora($fechaLocal), 'Reunión', 'La reunión cambió', 'cambió',
                               'Actualizamos los datos de la reunión. Si ya la tenías en tu calendario, se reemplaza sola con la invitación adjunta.'],
            'cancelada'    => ['Se canceló la reunión: ' . $r['titulo'], 'Reunión', 'Se canceló la reunión', 'canceló',
                               'Esta reunión ya no va. Si la tenías en tu calendario, la invitación adjunta la quita.'],
            'recordatorio' => ['Mañana: ' . $r['titulo'] . ' a las ' . $fmt->hora($fechaLocal), 'Recordatorio', 'Mañana tienes reunión', 'Mañana',
                               ''],
            default        => ['Invitación: ' . $r['titulo'] . ' — ' . $fmt->fechaCorta($fechaLocal) . ' ' . $fmt->hora($fechaLocal), 'Reunión', 'Te invitamos a una reunión', 'reunión',
                               'Agrégala a tu calendario con un clic.'],
        };

        $nombres = array_map(fn($x) => (string) $x['nombre'], $this->personas((string) $r['id']));
        $datos = [
            ['Cuándo', $cuando . ' (' . $zona . ')'],
            ['Duración', (int) $r['duracion_min'] . ' minutos'],
            ['Proyecto', trim(($esCliente ? '' : $r['cliente_nombre'] . ' · ') . $r['proyecto_nombre'])],
        ];
        if ($nombres !== []) {
            $datos[] = ['Convocados', implode(', ', $nombres)];
        }
        $bloques = [['tarjetas' => [['titulo' => (string) $r['titulo']]]]];
        if ($intro !== '') {
            $bloques[] = ['p' => $intro];
        }
        $bloques[] = ['datos' => $datos];
        if ($tipo !== 'cancelada') {
            $enlaces = [];
            if ((string) $r['enlace_meet'] !== '') {
                $enlaces[] = ['texto' => 'Unirse con Meet', 'url' => (string) $r['enlace_meet']];
            }
            $enlaces[] = ['texto' => 'Agregar a Google Calendar', 'url' => $svc->enlaceGoogleCalendar($r)];
            $enlaces[] = ['texto' => 'Outlook o Apple (.ics)', 'url' => $this->urlIcs((string) $r['id'])];
            $bloques[] = ['enlaces' => $enlaces];
        }

        $ics = $tipo === 'recordatorio' ? null : $svc->ics($r, $url, [
            'metodo' => $tipo === 'cancelada' ? 'CANCEL' : 'REQUEST',
            'organizador' => $this->n->correoOrganizador(), 'nombre_org' => (new MarcaService($this->pdo))->nombreEquipo(),
            'para' => (string) $p['email'], 'nombre_para' => (string) $p['nombre'],
        ]);
        $op = ['etiqueta' => $etiqueta, 'titulo' => $titulo, 'resaltado' => $resaltado, 'bloques' => $bloques,
            'boton' => $tipo === 'cancelada' ? 'Ver mis reuniones' : 'Ver la reunión', 'preheader' => $cuando . ' (' . $zona . ')'];

        if ($esCliente) {
            if ($tipo === 'recordatorio' && (new AjustesService($this->pdo))->get('contacto', (string) $p['id'], 'avisos_email', '1') === '0') {
                return 0;
            }
            [$html, $texto] = $this->n->componer($asunto, '', $op, 'Hola ' . $this->n->primerNombre((string) $p['nombre']) . ',', $url, $cid,
                ['Te llega porque te convocaron a esta reunión.']);
            $ini = ReunionService::aUtc((string) $r['fecha']);
            $pronto = $ini !== null && $ini <= (Notifier::$ahora ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+36 hours');
            // La cancelación sale siempre; la invitación y el recordatorio, sólo si la reunión sigue en pie.
            $this->n->aContacto($p, $cid, $asunto, $html, $texto, $this->inmediato || $pronto || $tipo === 'cancelada', $ics,
                $tipo === 'cancelada' ? null : Vigencia::reunion((string) $r['id'], $tipo === 'recordatorio' ? null : (int) ($r['ics_seq'] ?? 0)));
            return 1;
        }

        // Equipo: la invitación y la cancelación llegan solas (llevan el calendario); el recordatorio respeta el agrupado.
        $u = (new EquipoService($this->pdo))->find((string) $p['id']);
        if ($u === null || (int) $u['activo'] !== 1) {
            return 0;
        }
        if ($tipo === 'recordatorio') {
            $pref = Avisos::preferencias(new AjustesService($this->pdo), (string) $u['id']);
            if ($pref['que'] === 'nada') {
                return 0;
            }
            if ($pref['como'] === 'agrupado') {
                (new Avisos($this->ctx, $this->pdo, $this->n))->guardar((string) $u['id'], $cid, (string) $r['proyecto_id'], 'reuniones/' . $r['id'],
                    $asunto, $cuando, 'reuniones/' . $r['id'], Vigencia::reunion((string) $r['id']));
                return 1;
            }
        }
        [, , $contexto] = $this->n->contextoAviso(['proyecto_id' => (string) $r['proyecto_id']]);
        [$html, $texto] = $this->n->componer($asunto, '', $op + ['boton' => 'Abrir en el panel'], 'Hola ' . $this->n->primerNombre((string) $u['nombre']) . ',',
            $url, $cid, ['Te llega porque te convocaron a esta reunión.'], $contexto);
        return $this->n->enviarCorreo((string) $u['email'], $asunto, $html, $texto, true, $ics) ? 1 : 0;
    }
}

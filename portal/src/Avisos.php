<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

use TypeDock\Core\PluginContext;

/**
 * Avisos por persona del equipo.
 *
 * Preferencias de cada persona («Mis ajustes» del panel):
 *   qué      mio (por defecto) | todo | nada
 *            «mio»: lo que es suyo (es responsable) y, de lo que no tiene responsable, lo de
 *            los clientes o proyectos que tiene asignados. Si nadie tiene asignado ese cliente,
 *            le llega a Coordinación para que no se pierda.
 *   cómo     siempre agrupado: no hay avisos sueltos. Se juntan en un buzón y salen en un solo correo
 *            a horas fijas (HORAS_ENVIO: 10:00, 13:00 y 16:00, hora de la agencia), o antes si se juntan
 *            JUNTOS (10). Una urgencia adelanta el envío a la próxima pasada del cron (máx. 15 minutos).
 *            Sólo de lunes a viernes dentro del horario hábil.
 *   resumen  1 (por defecto) | 0: resumen del día de lunes a viernes a las 8:30 (hora de la
 *            agencia), con lo que llegó en la noche. Si no hay nada, no se manda.
 *
 * Los contactos del cliente reciben sus avisos con la misma lógica (horas fijas en su zona, o antes si se
 * juntan JUNTOS_CLIENTE = 6) y pueden pedir su propio resumen de la mañana (desactivado por defecto).
 * Antes de salir, cada aviso se revisa (Vigencia): si ya se resolvió, se descarta.
 * Quedan fuera del agrupado: los códigos de acceso y las invitaciones, cancelaciones y recordatorios de reunión
 * (llevan el calendario y salen una vez).
 *
 * Todo lo programado (agrupados, resúmenes, vencimientos, recordatorios) lo corre correr():
 * la tarea programada del hosting cada 15 minutos y, de respaldo, las visitas al portal.
 */
final class Avisos
{
    public const BUZON  = 'portal_avisos_buzon';
    public const MARCAS = 'portal_avisos_marcas';
    public const JUNTOS = 10;
    public const JUNTOS_CLIENTE = 6;
    /** Horas fijas de envío (hora local de quien recibe). */
    public const HORAS_ENVIO = ['10:00', '13:00', '16:00'];
    public const HORA_RESUMEN  = '08:30';
    /** Si la tarea programada no corrió en la mañana, después de esta hora el resumen ya no se manda. */
    private const RESUMEN_HASTA = '12:00';

    private Notifier $n;

    public function __construct(private readonly PluginContext $ctx, private readonly \PDO $pdo, ?Notifier $n = null)
    {
        $this->n = $n ?? new Notifier($ctx, $pdo);
    }

    // ---- Preferencias ------------------------------------------------------------

    /** @return array{que: string, resumen: bool} */
    public static function preferencias(AjustesService $aj, string $usuarioId): array
    {
        $t = $aj->todos('equipo', $usuarioId);
        // Antes había un solo sí/no («avisos»): un «no» se respeta como «nada».
        $que = $t['avisos_que'] ?? (($t['avisos'] ?? '1') === '0' ? 'nada' : 'mio');
        return [
            'que'     => in_array($que, ['mio', 'todo', 'nada'], true) ? $que : 'mio',
            'resumen' => ($t['resumen_diario'] ?? '1') !== '0',
        ];
    }

    public static function resumenContacto(AjustesService $aj, string $contactoId): bool
    {
        return $aj->get('contacto', $contactoId, 'resumen_diario', '0') === '1';
    }

    /**
     * ¿Le toca este aviso a la persona?
     *
     * @param bool $asignado     tiene asignado el cliente o el proyecto
     * @param bool $hayAsignados alguien del equipo (no Coordinación) lo tiene asignado
     */
    public static function leToca(string $que, string $usuarioId, ?string $responsable, bool $asignado, bool $hayAsignados, bool $coordinacion): bool
    {
        return match ($que) {
            'nada'  => false,
            'todo'  => true,
            default => $responsable !== null
                ? $responsable === $usuarioId
                : ($asignado || ($coordinacion && !$hayAsignados)),
        };
    }

    /** Línea del pie: por qué le llega y dónde cambiarlo. @param array<string, mixed> $u */
    public static function pieMotivo(array $u): string
    {
        $por = match (true) {
            !empty($u['es_responsable']) => 'Te llega porque es tuyo.',
            ($u['que'] ?? '') === 'todo' => 'Te llega porque pediste todo lo de tus clientes.',
            default                      => 'Te llega porque tienes asignado este cliente.',
        };
        return $por . ' Puedes cambiar qué te llega y cómo en «Mis ajustes» del panel.';
    }

    /**
     * Personas del equipo a las que les toca un aviso, con sus preferencias.
     *
     * @param array<string, mixed> $op responsable, solo_responsable, actor
     * @return array<int, array<string, mixed>>
     */
    public function destinatarios(?string $proyectoId, ?string $clienteId, array $op = []): array
    {
        $eq = new EquipoService($this->pdo);
        $resp = (string) ($op['responsable'] ?? '');
        $responsable = $resp !== '' && ($r = $eq->find($resp)) !== null && (int) $r['activo'] === 1 ? $resp : null;
        $actor = (string) ($op['actor'] ?? '');

        if (!empty($op['solo_responsable']) && $responsable !== null) {
            $filas = [$eq->find($responsable) + ['asignado' => 1]];
        } elseif ($proyectoId === null && $clienteId === null) {
            $filas = $responsable !== null ? [$eq->find($responsable) + ['asignado' => 1]] : [];
        } else {
            Schema::asegurar($this->pdo);
            // Asignado = tiene el cliente o el proyecto asignado, o lleva la cuenta de ese cliente.
            if ($proyectoId !== null) {
                $on = 'a.cliente_id = p.cliente_id AND (a.proyecto_id IS NULL OR a.proyecto_id = p.id)';
                $from = 'portal_equipo e JOIN portal_proyectos p ON p.id = ? JOIN portal_clientes c ON c.id = p.cliente_id
                         LEFT JOIN portal_equipo_asignaciones a ON a.usuario_id = e.id AND ' . $on;
                $params = [$proyectoId];
            } else {
                $from = 'portal_equipo e JOIN portal_clientes c ON c.id = ? LEFT JOIN portal_equipo_asignaciones a ON a.usuario_id = e.id AND a.cliente_id = c.id';
                $params = [$clienteId];
            }
            $asig = "MAX(CASE WHEN a.id IS NOT NULL OR c.responsable_id = e.id THEN 1 ELSE 0 END)";
            $st = $this->pdo->prepare(
                "SELECT e.id, e.nombre, e.email, e.rol, e.activo, {$asig} AS asignado
                 FROM {$from} WHERE e.activo = 1 GROUP BY e.id, e.nombre, e.email, e.rol, e.activo
                 HAVING e.rol = 'coordinador' OR {$asig} = 1"
            );
            $st->execute($params);
            $filas = $st->fetchAll();
        }

        $hayAsignados = false;
        foreach ($filas as $f) {
            if ((int) $f['asignado'] === 1 && $f['rol'] !== 'coordinador') {
                $hayAsignados = true;
            }
        }
        $aj = new AjustesService($this->pdo);
        $out = [];
        foreach ($filas as $f) {
            $id = (string) $f['id'];
            if ($id === $actor || (int) ($f['activo'] ?? 1) !== 1) {
                continue;
            }
            $pref = self::preferencias($aj, $id);
            if (!self::leToca($pref['que'], $id, $responsable, (int) $f['asignado'] === 1, $hayAsignados, $f['rol'] === 'coordinador')) {
                continue;
            }
            $out[] = $f + $pref + ['es_responsable' => $responsable === $id];
        }
        return $out;
    }

    // ---- Buzón (agrupados) ------------------------------------------------------

    private function ahora(): \DateTimeImmutable
    {
        return (Notifier::$ahora ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC'));
    }

    private function local(?\DateTimeImmutable $t = null, ?string $zona = null): \DateTimeImmutable
    {
        return ($t ?? $this->ahora())->setTimezone(new \DateTimeZone($zona ?? Zona::agencia()));
    }

    /** @param ?string $vigencia marca de Vigencia: si deja de aplicar antes de salir, el aviso se descarta */
    public function guardar(string $usuarioId, ?string $clienteId, ?string $proyectoId, string $clave, string $asunto, string $detalle, ?string $ruta, ?string $vigencia = null, bool $urgente = false, string $para = 'equipo', bool $reemplaza = false): void
    {
        Schema::asegurar($this->pdo);
        if ($reemplaza) {
            // Un aviso que se va actualizando (p. ej. «tienes 3 contactos nuevos»): el nuevo reemplaza al que seguía esperando.
            $this->pdo->prepare('DELETE FROM ' . self::BUZON . ' WHERE usuario_id = ? AND clave = ? AND enviado_en IS NULL')->execute([$usuarioId, mb_substr($clave, 0, 120)]);
        }
        $this->pdo->prepare(
            'INSERT INTO ' . self::BUZON . ' (id, usuario_id, cliente_id, proyecto_id, clave, asunto, detalle, ruta, vigencia, urgente, para, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([typedock_uuid7(), $usuarioId, $clienteId, $proyectoId, mb_substr($clave, 0, 120), mb_substr($asunto, 0, 500),
            $detalle !== '' ? mb_substr($detalle, 0, 1000) : null, $ruta !== null ? mb_substr($ruta, 0, 255) : null, $vigencia, $urgente ? 1 : 0,
            $para === 'cliente' ? 'cliente' : 'equipo', $this->ahora()->format('Y-m-d H:i:s')]);
    }

    /** Saca del buzón lo que ya no hace falta avisar (la tarea se completó, la reunión se borró…). */
    public function depurar(?string $usuarioId = null): int
    {
        Schema::asegurar($this->pdo);
        $st = $this->pdo->prepare('SELECT id, vigencia FROM ' . self::BUZON . ' WHERE enviado_en IS NULL AND vigencia IS NOT NULL' . ($usuarioId !== null ? ' AND usuario_id = ?' : ''));
        $st->execute($usuarioId !== null ? [$usuarioId] : []);
        $viejos = array_values(array_filter($st->fetchAll(), fn($f) => !Vigencia::sigue($this->pdo, (string) $f['vigencia'])));
        $this->marcarEnviados($viejos);   // quedan como despachados: no salen
        return count($viejos);
    }

    /** @return array<int, array<string, mixed>> pendientes de una persona, ordenados por cliente y proyecto */
    public function pendientes(string $usuarioId): array
    {
        $this->depurar($usuarioId);
        $st = $this->pdo->prepare(
            'SELECT b.*, p.nombre AS proyecto_nombre, c.nombre AS cliente_nombre
             FROM ' . self::BUZON . ' b
             LEFT JOIN portal_proyectos p ON p.id = b.proyecto_id
             LEFT JOIN portal_clientes c ON c.id = COALESCE(b.cliente_id, p.cliente_id)
             WHERE b.usuario_id = ? AND b.enviado_en IS NULL
             ORDER BY c.nombre, p.nombre, b.created_at, b.id'
        );
        $st->execute([$usuarioId]);
        return $st->fetchAll();
    }

    /** @param array<int, array<string, mixed>> $items */
    private function marcarEnviados(array $items): void
    {
        $st = $this->pdo->prepare('UPDATE ' . self::BUZON . ' SET enviado_en = ? WHERE id = ? AND enviado_en IS NULL');
        foreach ($items as $i) {
            $st->execute([$this->ahora()->format('Y-m-d H:i:s'), $i['id']]);
        }
    }

    /**
     * Pasa los pendientes a bloques del correo: una sección por proyecto (con su color) y una tarjeta por
     * asunto; varias novedades de lo mismo (misma clave) se juntan en una.
     *
     * @param array<int, array<string, mixed>> $items
     * @param string  $base '/equipo' (las rutas son del panel) o '' (ya vienen completas, como las del portal del cliente)
     * @param ?int    $max  tarjetas a mostrar como mucho; el resto se resume en una línea («y N más»)
     * @param ?string $zona zona horaria para la hora de cada tarjeta; null = sin hora (correo al cliente)
     * @return array<int, array<string, mixed>>
     */
    public function bloquesBuzon(array $items, string $base = '/equipo', ?int $max = null, ?string $zona = '')
    {
        $colores = Fmt::coloresTodos($this->pdo);
        $zona = $zona === '' ? Zona::agencia() : $zona;
        $grupos = [];
        $vistas = [];
        $extra = 0;
        foreach ($items as $i) {
            $k = (string) ($i['clave'] ?: $i['id']);
            $g = (string) ($i['proyecto_id'] ?: $i['cliente_id'] ?: '-');
            if (!isset($vistas[$g . '|' . $k])) {
                if ($max !== null && count($vistas) >= $max) {
                    $extra++;
                    continue;
                }
                $vistas[$g . '|' . $k] = true;
            }
            $quien = $base === '' ? (string) ($i['proyecto_nombre'] ?? '') : trim(($i['cliente_nombre'] ?? '') . (($i['proyecto_nombre'] ?? '') !== '' ? ' · ' . $i['proyecto_nombre'] : ''));
            $grupos[$g]['titulo'] = $quien !== '' ? $quien : ($base === '' ? '' : 'General');
            $grupos[$g]['color'] = $colores[(string) $i['proyecto_id']] ?? '';
            $previo = $grupos[$g]['items'][$k] ?? null;
            $grupos[$g]['items'][$k] = $i + ['n' => ($previo['n'] ?? 0) + 1];
        }
        $bloques = [];
        foreach ($grupos as $g) {
            if ($g['titulo'] !== '') {
                $bloques[] = ['seccion' => ['titulo' => $g['titulo'], 'color' => $g['color']]];
            }
            $tarjetas = [];
            foreach ($g['items'] as $i) {
                $mas = $i['n'] > 1 ? ($i['n'] - 1 === 1 ? ' · y 1 novedad más' : ' · y ' . ($i['n'] - 1) . ' novedades más') : '';
                $ruta = (string) $i['ruta'];
                $tarjetas[] = [
                    'titulo'  => (string) $i['asunto'],
                    'detalle' => trim((string) ($i['detalle'] ?? '')) . $mas,
                    'chip'    => $zona === null ? '' : $this->local(new \DateTimeImmutable((string) $i['created_at'], new \DateTimeZone('UTC')), $zona)->format('H:i'),
                    'url'     => $this->n->absoluta($base === '' ? ($ruta !== '' ? $ruta : '/portal') : $base . ($ruta !== '' ? '/' . ltrim($ruta, '/') : '')),
                ];
            }
            $bloques[] = ['tarjetas' => $tarjetas];
        }
        if ($extra > 0) {
            $bloques[] = ['p' => 'Y ' . ($extra === 1 ? '1 novedad más' : $extra . ' novedades más') . ': las ves al abrir ' . ($base === '' ? 'tu portal' : 'el panel') . '.'];
        }
        return $bloques;
    }

    /** «10:00, 13:00 y 16:00» */
    public static function horasTexto(): string
    {
        $h = self::HORAS_ENVIO;
        $ultima = array_pop($h);
        return $h === [] ? (string) $ultima : implode(', ', $h) . ' y ' . $ultima;
    }

    /** Correo agrupado con lo pendiente de una persona del equipo. @param array<string, mixed> $u */
    public function enviarAgrupado(array $u): bool
    {
        $items = $this->pendientes((string) $u['id']);
        if ($items === []) {
            return false;
        }
        $this->marcarEnviados($items);
        $n = count($items);
        $proyectos = count(array_unique(array_map(fn($i) => (string) ($i['proyecto_id'] ?: $i['cliente_id']), $items)));
        $asunto = $n === 1 ? (string) $items[0]['asunto']
            : $n . ' novedades' . ($proyectos > 1 ? ' de ' . $proyectos . ' proyectos' : ' en ' . ($items[0]['proyecto_nombre'] ?: $items[0]['cliente_nombre']));
        [$html, $texto] = $this->n->componer($asunto, '', [
            'etiqueta' => 'Novedades', 'titulo' => 'Tus novedades', 'resaltado' => 'novedades', 'boton' => 'Abrir el panel',
            'bloques' => $this->bloquesBuzon($items, '/equipo', self::JUNTOS), 'compacto' => true,
            'preheader' => implode(' · ', array_slice(array_map(fn($i) => (string) $i['asunto'], $items), 0, 3)),
        ], 'Hola ' . $this->n->primerNombre((string) $u['nombre']) . ',', $this->n->absoluta('/equipo'), null, [
            'Te llegan agrupadas: a las ' . self::horasTexto() . ' (hora de ' . Zona::nombre() . '), o antes si se juntan ' . self::JUNTOS . '.',
            'Puedes cambiar qué te llega en «Mis ajustes» del panel.',
        ]);
        return $this->n->enviarCorreo((string) $u['email'], $asunto, $html, $texto);
    }

    /** Correo agrupado con lo pendiente de un contacto del cliente. @param array<string, mixed> $c contacto: id, nombre, email, cliente_id */
    public function enviarAgrupadoCliente(array $c): bool
    {
        $items = $this->pendientes((string) $c['id']);
        if ($items === []) {
            return false;
        }
        $this->marcarEnviados($items);
        if ((new AjustesService($this->pdo))->get('contacto', (string) $c['id'], 'avisos_email', '1') === '0' || trim((string) $c['email']) === '') {
            return false;
        }
        $n = count($items);
        $asunto = $n === 1 ? (string) $items[0]['asunto'] : $n . ' novedades en tu portal';
        [$html, $texto] = $this->n->componer($asunto, '', [
            'etiqueta' => 'Novedades', 'titulo' => 'Novedades en tu portal', 'resaltado' => 'novedades', 'boton' => 'Abrir mi portal',
            'bloques' => $this->bloquesBuzon($items, '', self::JUNTOS_CLIENTE, null), 'compacto' => true,
            'preheader' => implode(' · ', array_slice(array_map(fn($i) => (string) $i['asunto'], $items), 0, 3)),
        ], 'Hola ' . $this->n->primerNombre((string) $c['nombre']) . ',', $this->n->absoluta('/portal'), (string) $c['cliente_id'] ?: null, [
            'Te llegan agrupadas: a las ' . self::horasTexto() . ' (tu hora), o antes si se juntan ' . self::JUNTOS_CLIENTE . '.',
            'Puedes desactivar estos avisos en Ajustes dentro del portal.',
        ]);
        return $this->n->enviarCorreo((string) $c['email'], $asunto, $html, $texto);
    }

    private function enHorarioAgencia(): bool
    {
        [$respetar, $ini, $fin] = $this->n->horario();
        return !$respetar || HorarioHabil::dentro($this->ahora(), Zona::pais(), $ini, $fin);
    }

    private function diaHabil(\DateTimeImmutable $local): bool
    {
        return (int) $local->format('N') <= 5;
    }

    /**
     * Última hora fija de envío que ya pasó hoy en esa zona (en UTC), o null si no hay: fin de semana,
     * antes de la primera hora, o horas que caen fuera del horario hábil.
     */
    private function ultimaHoraDeEnvio(string $zona): ?\DateTimeImmutable
    {
        $l = $this->local(null, $zona);
        if (!$this->diaHabil($l)) {
            return null;
        }
        [$respetar, $ini, $fin] = $this->n->horario();
        $mejor = null;
        foreach (self::HORAS_ENVIO as $h) {
            [$hh, $mm] = array_map('intval', explode(':', $h));
            if ($respetar && ($hh < $ini || $hh >= $fin)) {
                continue;
            }
            $t = $l->setTime($hh, $mm);
            if ($t <= $l) {
                $mejor = $t;
            }
        }
        return $mejor?->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * ¿Toca enviarle ahora lo que tiene pendiente? Sí si se juntó el tope, si hay una urgencia, o si
     * ya pasó una hora fija desde que llegó algo (lo que llegó después espera a la siguiente).
     */
    private function tocaEnviar(string $destinoId, int $pendientes, int $urgentes, int $tope, ?\DateTimeImmutable $corte): bool
    {
        if ($pendientes >= $tope || $urgentes > 0) {
            return true;
        }
        if ($corte === null) {
            return false;
        }
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM ' . self::BUZON . ' WHERE usuario_id = ? AND enviado_en IS NULL AND created_at <= ?');
        $st->execute([$destinoId, $corte->format('Y-m-d H:i:s')]);
        return (int) $st->fetchColumn() > 0;
    }

    /** @return array<int, array<string, mixed>> destinatarios con avisos pendientes: usuario_id, n, urg */
    private function conPendientes(string $para): array
    {
        $st = $this->pdo->prepare(
            'SELECT usuario_id, COUNT(*) AS n, SUM(CASE WHEN urgente = 1 THEN 1 ELSE 0 END) AS urg FROM ' . self::BUZON
            . " WHERE enviado_en IS NULL AND COALESCE(para, 'equipo') = ? GROUP BY usuario_id"
        );
        $st->execute([$para]);
        return $st->fetchAll();
    }

    /** Envía los agrupados del equipo y del cliente que ya les toca. @return int correos enviados */
    public function barrer(): int
    {
        Schema::asegurar($this->pdo);
        $this->depurar();
        return $this->barrerEquipo() + $this->barrerClientes();
    }

    private function barrerEquipo(): int
    {
        if (!$this->enHorarioAgencia()) {
            return 0;   // de noche y fines de semana se junta; sale en el resumen o a la primera hora del día
        }
        $corte = $this->ultimaHoraDeEnvio(Zona::agencia());
        $eq = new EquipoService($this->pdo);
        $enviados = 0;
        foreach ($this->conPendientes('equipo') as $f) {
            $u = $eq->find((string) $f['usuario_id']);
            if ($u === null || (int) $u['activo'] !== 1) {
                $this->marcarEnviados($this->pendientes((string) $f['usuario_id']));   // ya no está: se descarta
                continue;
            }
            if ($this->tocaEnviar((string) $f['usuario_id'], (int) $f['n'], (int) $f['urg'], self::JUNTOS, $corte)) {
                $enviados += $this->enviarAgrupado($u) ? 1 : 0;
            }
        }
        return $enviados;
    }

    private function barrerClientes(): int
    {
        [$respetar, $ini, $fin] = $this->n->horario();
        $enviados = 0;
        foreach ($this->conPendientes('cliente') as $f) {
            $st = $this->pdo->prepare('SELECT id, nombre, email, cliente_id FROM portal_contactos WHERE id = ?');
            $st->execute([(string) $f['usuario_id']]);
            $c = $st->fetch();
            if ($c === false) {
                $this->marcarEnviados($this->pendientes((string) $f['usuario_id']));
                continue;
            }
            $pais = Zona::paisPersona($this->pdo, (string) $c['cliente_id'], (string) $c['id']);
            if ($respetar && !HorarioHabil::dentro($this->ahora(), $pais, $ini, $fin)) {
                continue;
            }
            if ($this->tocaEnviar((string) $c['id'], (int) $f['n'], 0, self::JUNTOS_CLIENTE, $this->ultimaHoraDeEnvio(HorarioHabil::zonaDe($pais)))) {
                $enviados += $this->enviarAgrupadoCliente($c) ? 1 : 0;
            }
        }
        return $enviados;
    }

    // ---- Marcas («ya avisado») -----------------------------------------------------

    /** Deja la marca; false si ya estaba (otra request o una pasada anterior ya lo hizo). */
    public function marcar(string $clave): bool
    {
        Schema::asegurar($this->pdo);
        try {
            $this->pdo->prepare('INSERT INTO ' . self::MARCAS . ' (clave, created_at) VALUES (?, ?)')
                ->execute([mb_substr($clave, 0, 190), $this->ahora()->format('Y-m-d H:i:s')]);
            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    public function marcado(string $clave, ?string $desde = null): bool
    {
        Schema::asegurar($this->pdo);
        $st = $this->pdo->prepare('SELECT created_at FROM ' . self::MARCAS . ' WHERE clave = ?');
        $st->execute([$clave]);
        $c = $st->fetchColumn();
        return $c !== false && ($desde === null || (string) $c >= $desde);
    }

    // ---- Resumen de la mañana ---------------------------------------------------------

    private function enVentanaResumen(\DateTimeImmutable $local): bool
    {
        $h = $local->format('H:i');
        return $this->diaHabil($local) && $h >= self::HORA_RESUMEN && $h < self::RESUMEN_HASTA;
    }

    /** @return int resúmenes enviados */
    public function resumenes(): int
    {
        Schema::asegurar($this->pdo);
        $enviados = 0;
        $aj = new AjustesService($this->pdo);

        $l = $this->local();
        if ($this->enVentanaResumen($l)) {
            foreach ($this->pdo->query('SELECT * FROM portal_equipo WHERE activo = 1 ORDER BY nombre')->fetchAll() as $u) {
                if (!self::preferencias($aj, (string) $u['id'])['resumen'] || !$this->marcar('resumen:e:' . $u['id'] . ':' . $l->format('Y-m-d'))) {
                    continue;
                }
                $enviados += $this->resumenEquipo($u) ? 1 : 0;
            }
        }

        // Contactos que lo pidieron: a las 8:30 de su país.
        $st = $this->pdo->query(
            "SELECT ct.*, COALESCE(NULLIF(ct.pais, ''), c.pais) AS pais FROM portal_contactos ct JOIN portal_clientes c ON c.id = ct.cliente_id
             JOIN portal_ajustes a ON a.owner_tipo = 'contacto' AND a.owner_id = ct.id AND a.clave = 'resumen_diario' AND a.valor = '1'"
        );
        foreach ($st ? $st->fetchAll() : [] as $c) {
            $lc = $this->local(null, HorarioHabil::zonaDe((string) $c['pais']));
            if (!$this->enVentanaResumen($lc) || !$this->marcar('resumen:c:' . $c['id'] . ':' . $lc->format('Y-m-d'))) {
                continue;
            }
            $enviados += $this->resumenCliente($c, $lc) ? 1 : 0;
        }
        return $enviados;
    }

    /**
     * Resumen del día de una persona del equipo. No se manda si no hay nada.
     *
     * @param array<string, mixed> $u
     */
    public function resumenEquipo(array $u): bool
    {
        $uid = (string) $u['id'];
        $pref = self::preferencias(new AjustesService($this->pdo), $uid);
        $todo = $pref['que'] === 'todo';
        $acc = new EquipoAcceso($this->pdo, $u);
        [$wP, $pP] = $acc->filtroProyecto('p.id');
        $hoy = $this->local()->format('Y-m-d');
        $colores = Fmt::coloresTodos($this->pdo);
        $fmt = new Fmt();
        $panel = fn(string $r): string => $this->n->absoluta('/equipo/' . $r);

        // Reuniones de hoy: en las que está convocada (o todas las visibles si pidió «todo»).
        $st = $this->pdo->prepare(
            "SELECT r.*, p.nombre AS proyecto_nombre, c.nombre AS cliente_nombre FROM portal_reuniones r
             JOIN portal_proyectos p ON p.id = r.proyecto_id JOIN portal_clientes c ON c.id = p.cliente_id
             WHERE SUBSTR(r.fecha, 1, 10) = ? AND {$wP}" . ($todo ? '' : " AND EXISTS (SELECT 1 FROM portal_reunion_asistentes x
                WHERE x.reunion_id = r.id AND x.asistente_tipo = 'equipo' AND x.asistente_usuario_id = ?)") . ' ORDER BY r.fecha'
        );
        $st->execute(array_merge([$hoy], $pP, $todo ? [] : [$uid]));
        $reuniones = $st->fetchAll();

        // Tareas del equipo: suyas (o todas las visibles si pidió «todo»).
        $alcance = $todo ? "t.responsable_tipo = 'equipo'" : "t.responsable_tipo = 'equipo' AND t.responsable_usuario_id = ?";
        $tareas = function (string $cond, array $extra) use ($alcance, $wP, $pP, $todo, $uid): array {
            $st = $this->pdo->prepare(
                "SELECT t.*, p.nombre AS proyecto_nombre, c.nombre AS cliente_nombre FROM portal_tareas t
                 JOIN portal_proyectos p ON p.id = t.proyecto_id JOIN portal_clientes c ON c.id = p.cliente_id
                 WHERE {$alcance} AND COALESCE(t.archivada, 0) = 0 AND {$cond} AND {$wP}
                 ORDER BY t.fecha_vencimiento, c.nombre, p.nombre LIMIT 12"
            );
            $st->execute(array_merge($todo ? [] : [$uid], $extra, $pP));
            return $st->fetchAll();
        };
        $abiertas = "t.estado IN ('pendiente', 'en_progreso') AND t.fecha_vencimiento IS NOT NULL";
        $vencenHoy = $tareas("{$abiertas} AND SUBSTR(t.fecha_vencimiento, 1, 10) = ?", [$hoy]);
        $atrasadas = $tareas("{$abiertas} AND SUBSTR(t.fecha_vencimiento, 1, 10) < ?", [$hoy]);
        $esperan = $tareas("t.estado IN ('entregada', 'cambios')", []);
        $noche = $this->pendientes($uid);

        if ($reuniones === [] && $vencenHoy === [] && $atrasadas === [] && $esperan === [] && $noche === []) {
            return false;
        }

        $tarjetaTarea = fn(array $t, string $chip = ''): array => [
            'titulo' => (string) $t['titulo'], 'url' => $panel('tareas/' . $t['id']), 'chip' => $chip,
            'detalle' => $t['cliente_nombre'] . ' · ' . $t['proyecto_nombre'],
        ];
        $bloques = [];
        if ($reuniones !== []) {
            $bloques[] = ['seccion' => ['titulo' => 'Reuniones de hoy']];
            $bloques[] = ['tarjetas' => array_map(fn($r) => [
                'titulo' => (string) $r['titulo'], 'chip' => $fmt->hora((string) $r['fecha']),
                'detalle' => $r['cliente_nombre'] . ' · ' . $r['proyecto_nombre'] . ($r['enlace_meet'] ? ' · con Meet' : ''),
                'url' => $r['enlace_meet'] ?: $panel('reuniones/' . $r['id']),
            ], $reuniones)];
        }
        if ($vencenHoy !== []) {
            $bloques[] = ['seccion' => ['titulo' => 'Vencen hoy']];
            $bloques[] = ['tarjetas' => array_map(fn($t) => $tarjetaTarea($t), $vencenHoy)];
        }
        if ($atrasadas !== []) {
            $bloques[] = ['seccion' => ['titulo' => 'Atrasadas', 'color' => '#dc2626']];
            $bloques[] = ['tarjetas' => array_map(fn($t) => $tarjetaTarea($t, 'desde el ' . $fmt->fechaCorta((string) $t['fecha_vencimiento'])), $atrasadas)];
        }
        if ($esperan !== []) {
            $bloques[] = ['seccion' => ['titulo' => 'Esperan tu respuesta']];
            $bloques[] = ['tarjetas' => array_map(fn($t) => $tarjetaTarea($t, $t['estado'] === 'cambios' ? 'Pidió cambios' : 'Entregado'), $esperan)];
        }
        if ($noche !== []) {
            $bloques[] = ['p' => 'Y esto llegó mientras no estabas:'];
            $bloques = array_merge($bloques, $this->bloquesBuzon($noche));
            $this->marcarEnviados($noche);
        }

        $partes = [];
        if ($reuniones !== []) {
            $partes[] = count($reuniones) . (count($reuniones) === 1 ? ' reunión' : ' reuniones');
        }
        $nT = count($vencenHoy) + count($atrasadas);
        if ($nT > 0) {
            $partes[] = $nT . ($nT === 1 ? ' tarea' : ' tareas') . ' por cerrar';
        }
        if ($esperan !== []) {
            $partes[] = count($esperan) . ' por responder';
        }
        if ($partes === []) {
            $partes[] = count($noche) . (count($noche) === 1 ? ' novedad' : ' novedades');
        }
        $asunto = 'Tu día: ' . implode(', ', $partes);
        [$html, $texto] = $this->n->componer($asunto, '', [
            'etiqueta' => 'Resumen del día', 'titulo' => 'Tu día de hoy', 'resaltado' => 'hoy', 'boton' => 'Abrir el panel',
            'bloques' => $bloques, 'preheader' => implode(' · ', $partes),
        ], 'Buenos días, ' . $this->n->primerNombre((string) $u['nombre']) . '.', $this->n->absoluta('/equipo'), null, [
            'Te llega de lunes a viernes a las ' . self::HORA_RESUMEN . ' (hora de ' . Zona::nombre() . '). Si un día no hay nada, no te escribimos.',
            'Puedes desactivarlo en «Mis ajustes» del panel.',
        ]);
        return $this->n->enviarCorreo((string) $u['email'], $asunto, $html, $texto);
    }

    /**
     * Resumen de la mañana de un contacto del cliente: lo que tiene pendiente y sus reuniones de hoy.
     *
     * @param array<string, mixed> $c contacto (con 'pais' del cliente)
     */
    public function resumenCliente(array $c, \DateTimeImmutable $local): bool
    {
        $cid = (string) $c['cliente_id'];
        $hoy = $local->format('Y-m-d');
        $fmt = new Fmt();
        $portal = fn(string $r): string => $this->n->absoluta('/portal' . $r);

        $st = $this->pdo->prepare(
            "SELECT t.*, p.nombre AS proyecto_nombre FROM portal_tareas t JOIN portal_proyectos p ON p.id = t.proyecto_id
             WHERE p.cliente_id = ? AND t.responsable_tipo = 'cliente' AND t.estado IN ('pendiente', 'en_progreso')
               AND COALESCE(t.archivada, 0) = 0 AND (t.responsable_contacto_id IS NULL OR t.responsable_contacto_id = ?)
             ORDER BY (t.fecha_vencimiento IS NULL), t.fecha_vencimiento LIMIT 10"
        );
        $st->execute([$cid, (string) $c['id']]);
        $tareas = $st->fetchAll();

        $st = $this->pdo->prepare(
            "SELECT e.id, e.titulo, (SELECT COUNT(*) FROM portal_contenidos x WHERE x.entrega_id = e.id AND x.estado NOT IN ('aprobado', 'cambios')) AS n
             FROM portal_entregas e WHERE e.cliente_id = ? AND e.estado = 'publicada' ORDER BY e.publicada_en"
        );
        $st->execute([$cid]);
        $revisiones = array_values(array_filter($st->fetchAll(), fn($e) => (int) $e['n'] > 0));

        // Reuniones de hoy (en su hora): las publicadas en las que está convocado, o todas si no hay convocados.
        $st = $this->pdo->prepare(
            "SELECT r.*, p.nombre AS proyecto_nombre,
                    (SELECT COUNT(*) FROM portal_reunion_asistentes x WHERE x.reunion_id = r.id AND x.asistente_tipo = 'contacto') AS n_conv,
                    (SELECT COUNT(*) FROM portal_reunion_asistentes x WHERE x.reunion_id = r.id AND x.asistente_contacto_id = ?) AS yo
             FROM portal_reuniones r JOIN portal_proyectos p ON p.id = r.proyecto_id
             WHERE p.cliente_id = ? AND r.publicada = 1 AND SUBSTR(r.fecha, 1, 10) BETWEEN ? AND ?"
        );
        $st->execute([(string) $c['id'], $cid, $local->modify('-1 day')->format('Y-m-d'), $local->modify('+1 day')->format('Y-m-d')]);
        $pais = (string) $c['pais'];
        $reuniones = array_values(array_filter($st->fetchAll(), fn($r) => substr(Zona::aPais((string) $r['fecha'], $pais), 0, 10) === $hoy
            && ((int) $r['n_conv'] === 0 || (int) $r['yo'] > 0)));

        if ($tareas === [] && $revisiones === [] && $reuniones === []) {
            return false;
        }
        $bloques = [];
        if ($reuniones !== []) {
            $bloques[] = ['seccion' => ['titulo' => 'Hoy tienes reunión']];
            $bloques[] = ['tarjetas' => array_map(fn($r) => [
                'titulo' => (string) $r['titulo'], 'chip' => $fmt->hora(Zona::aPais((string) $r['fecha'], $pais)),
                'detalle' => (string) $r['proyecto_nombre'] . ($r['enlace_meet'] ? ' · con Meet' : ''),
                'url' => $r['enlace_meet'] ?: $portal('/reuniones/' . $r['id']),
            ], $reuniones)];
        }
        if ($revisiones !== []) {
            $bloques[] = ['seccion' => ['titulo' => 'Para revisar']];
            $bloques[] = ['tarjetas' => array_map(fn($e) => [
                'titulo' => (string) $e['titulo'], 'url' => $portal('/entregas/' . $e['id']),
                'detalle' => (int) $e['n'] === 1 ? '1 pieza esperando tu revisión' : $e['n'] . ' piezas esperando tu revisión',
            ], $revisiones)];
        }
        if ($tareas !== []) {
            $bloques[] = ['seccion' => ['titulo' => 'Lo que te toca']];
            $bloques[] = ['tarjetas' => array_map(fn($t) => [
                'titulo' => (string) $t['titulo'], 'url' => $portal('/tareas/' . $t['id']), 'detalle' => (string) $t['proyecto_nombre'],
                'chip' => $t['fecha_vencimiento'] ? 'para el ' . $fmt->fechaCorta((string) $t['fecha_vencimiento']) : '',
            ], $tareas)];
        }
        $n = count($tareas) + count($revisiones);
        $asunto = 'Tu día: ' . implode(', ', array_filter([
            $reuniones !== [] ? (count($reuniones) === 1 ? '1 reunión' : count($reuniones) . ' reuniones') : '',
            $n > 0 ? ($n === 1 ? '1 pendiente' : $n . ' pendientes') : '',
        ]));
        [$html, $texto] = $this->n->componer($asunto, '', [
            'etiqueta' => 'Resumen del día', 'titulo' => 'Lo de hoy en tu portal', 'resaltado' => 'hoy', 'boton' => 'Abrir mi portal', 'bloques' => $bloques,
        ], 'Buenos días, ' . $this->n->primerNombre((string) $c['nombre']) . '.', $portal(''), $cid, [
            'Lo pediste en Ajustes, dentro del portal: ahí mismo lo puedes desactivar.',
        ]);
        return $this->n->enviarCorreo((string) $c['email'], $asunto, $html, $texto);
    }

    // ---- Vencimientos y recordatorios ----------------------------------------------------

    /** Tareas del equipo que vencen mañana o que se acaban de atrasar: un aviso por cada una. */
    public function vencimientos(): int
    {
        Schema::asegurar($this->pdo);
        $l = $this->local();
        $hoy = $l->format('Y-m-d');
        $manana = $l->modify('+1 day')->format('Y-m-d');
        $st = $this->pdo->prepare(
            "SELECT t.id, t.titulo, t.proyecto_id, t.responsable_usuario_id, t.fecha_vencimiento FROM portal_tareas t
             WHERE t.responsable_tipo = 'equipo' AND t.estado IN ('pendiente', 'en_progreso') AND COALESCE(t.archivada, 0) = 0
               AND t.fecha_vencimiento IS NOT NULL AND SUBSTR(t.fecha_vencimiento, 1, 10) BETWEEN ? AND ?"
        );
        // Las atrasadas de hace más de 3 días ya no avisan (al instalar, no llega una avalancha).
        $st->execute([$l->modify('-3 days')->format('Y-m-d'), $manana]);
        $n = 0;
        foreach ($st->fetchAll() as $t) {
            $f = substr((string) $t['fecha_vencimiento'], 0, 10);
            if ($f === $manana) {
                [$clave, $asunto, $titulo] = ['venc:' . $t['id'] . ':' . $f, 'Vence mañana: «' . $t['titulo'] . '»', 'Vence mañana'];
            } elseif ($f < $hoy) {
                [$clave, $asunto, $titulo] = ['atr:' . $t['id'] . ':' . $f, 'Se atrasó: «' . $t['titulo'] . '»', 'Esta tarea se atrasó'];
            } else {
                continue;
            }
            if (!$this->marcar($clave)) {
                continue;
            }
            $resp = (string) ($t['responsable_usuario_id'] ?? '');
            $this->n->alEquipo($asunto, '', 'tareas/' . $t['id'], [
                'proyecto_id' => (string) $t['proyecto_id'], 'responsable' => $resp !== '' ? $resp : null, 'solo_responsable' => $resp !== '',
                'etiqueta' => 'Tarea', 'titulo' => $titulo, 'resaltado' => $f === $manana ? 'mañana' : 'atrasó',
                'bloques' => [['tarjetas' => [['titulo' => (string) $t['titulo'], 'detalle' => 'Fecha: ' . (new Fmt())->fechaLarga($f)]]]],
                'detalle' => 'Fecha: ' . (new Fmt())->fechaLarga($f), 'clave' => 'tareas/' . $t['id'], 'vigencia' => Vigencia::tarea((string) $t['id']),
            ]);
            $n++;
        }
        return $n;
    }

    /** Recordatorio el día anterior a los convocados de cada reunión. */
    public function recordatorios(): int
    {
        Schema::asegurar($this->pdo);
        $manana = $this->local()->modify('+1 day')->format('Y-m-d');
        $st = $this->pdo->prepare(
            "SELECT r.*, p.nombre AS proyecto_nombre, p.cliente_id FROM portal_reuniones r JOIN portal_proyectos p ON p.id = r.proyecto_id
             WHERE SUBSTR(r.fecha, 1, 10) = ? AND LENGTH(r.fecha) > 10"
        );
        $st->execute([$manana]);
        $conv = new Convocados($this->ctx, $this->pdo, $this->n);
        $hace = $this->ahora()->modify('-18 hours')->format('Y-m-d H:i:s');
        $n = 0;
        foreach ($st->fetchAll() as $r) {
            // Si la invitación salió hace poco, el recordatorio sobra.
            if ($this->marcado('inv:' . $r['id'], $hace) || !$this->marcar('rec:' . $r['id'] . ':' . $r['fecha'])) {
                continue;
            }
            $n += $conv->recordar($r);
        }
        return $n;
    }

    // ---- Todo junto -----------------------------------------------------------------------------

    /** Lo programado, en orden: lo que vence, recordatorios, resúmenes de la mañana y agrupados. @return array<string, int> */
    public function correr(): array
    {
        $r = [];
        foreach (['vencimientos', 'recordatorios', 'resumenes', 'barrer'] as $paso) {
            try {
                $r[$paso] = $this->{$paso}();
            } catch (\Throwable $e) {
                error_log('[portal] avisos ' . $paso . ': ' . $e->getMessage());
                $r[$paso] = 0;
            }
        }
        return $r;
    }

    /** Respaldo sin tarea programada: en las visitas, como mucho cada 5 minutos (si el cron anda, no hace nada). */
    public function correrSiToca(): void
    {
        $aj = new AjustesService($this->pdo);
        $ahora = $this->ahora();
        if ($aj->get('global', 'portal', 'cron_ultimo') > $ahora->modify('-30 minutes')->format('Y-m-d H:i:s')) {
            return;
        }
        $ultimo = $aj->get('global', 'portal', 'avisos_ultimo');
        if ($ultimo !== '' && $ultimo > $ahora->modify('-5 minutes')->format('Y-m-d H:i:s')) {
            return;
        }
        $aj->set('global', 'portal', 'avisos_ultimo', $ahora->format('Y-m-d H:i:s'));
        $this->correr();
    }
}

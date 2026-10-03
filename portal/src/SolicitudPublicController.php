<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * Solicitudes desde el portal del cliente: pedir algo nuevo, un presupuesto, una reunión
 * o reportar un problema. Cualquier contacto del cliente puede hacerlas.
 *
 * Cada solicitud avisa al equipo asignado al proyecto (y al correo de avisos). Las urgentes
 * y los problemas llevan la marca de urgencia en el asunto.
 */
class SolicitudPublicController extends PortalPublicController
{
    private function solicitudes(): SolicitudService
    {
        return new SolicitudService($this->pdo());
    }

    /** @return array<int, array<string, mixed>> proyectos del cliente (activos primero) */
    private function proyectosDe(string $clienteId): array
    {
        return $this->fetchAll(
            "SELECT id, nombre, estado FROM portal_proyectos WHERE cliente_id = ? ORDER BY (estado <> 'activo'), nombre",
            [$clienteId]
        );
    }

    public function lista(): void
    {
        $c = $this->requerirContacto();
        $this->marcarPaso($c, 'solicitudes');
        $todas = $this->solicitudes()->delCliente((string) $c['cliente_id']);
        $abiertas = array_values(array_filter($todas, fn(array $s): bool => in_array($s['estado'], ['nueva', 'cotizada', 'aprobada'], true)
            || ($s['estado'] === 'en_curso' && $s['tarea_estado'] !== 'hecha')));
        $cerradas = array_values(array_filter($todas, fn(array $s): bool => !in_array($s, $abiertas, true)));

        $this->ctx->view('templates/public/solicitudes.latte', $this->contexto($c, 'solicitudes') + [
            'abiertas' => $abiertas,
            'cerradas' => $cerradas,
            'tipos'    => SolicitudService::TIPOS,
            'estadosS' => SolicitudService::ESTADOS_CLIENTE,
            'urgencias' => SolicitudService::URGENCIAS,
            'multiples' => count($this->proyectosDe((string) $c['cliente_id'])) > 1,
        ]);
    }

    /**
     * Si el servidor manda «Permissions-Policy: microphone=()», Chrome bloquea el dictado sin preguntar.
     * En la página del formulario se cambia sólo esa parte a microphone=(self) (el resto de la política
     * se respeta). Si la cabecera la pone Apache (.htaccess), PHP no la alcanza: ver README.
     */
    protected function permitirMicrofono(): void
    {
        $actual = '';
        foreach (headers_list() as $h) {
            if (stripos($h, 'permissions-policy:') === 0) {
                $actual = trim(substr($h, strlen('permissions-policy:')));
            }
        }
        try {
            $r = \Flight::response();
            foreach ($r->headers() as $k => $v) {
                if (strcasecmp((string) $k, 'Permissions-Policy') === 0) {
                    $actual = is_array($v) ? (string) end($v) : (string) $v;
                }
            }
        } catch (\Throwable) {
            $r = null;
        }
        $nueva = $actual === '' ? 'microphone=(self)'
            : (preg_match('/microphone=\([^)]*\)/i', $actual) === 1
                ? (string) preg_replace('/microphone=\([^)]*\)/i', 'microphone=(self)', $actual)
                : $actual . ', microphone=(self)');
        if (!headers_sent()) {
            header('Permissions-Policy: ' . $nueva, true);
        }
        if ($r !== null) {
            $r->header('Permissions-Policy', $nueva);
        }
    }

    public function nueva(): void
    {
        $c = $this->requerirContacto();
        $this->permitirMicrofono();
        $clienteId = (string) $c['cliente_id'];
        $proyectos = $this->proyectosDe($clienteId);
        if ($proyectos === []) {
            PortalSession::flash('error', 'Todavía no tienes proyectos con nosotros. Escríbenos y lo armamos.');
            $this->redirectTo('portal');
            return;
        }
        $svc = $this->solicitudes();
        PortalSession::iniciar();
        $previo = $_SESSION['portal_solicitud_previa'] ?? [];
        unset($_SESSION['portal_solicitud_previa']);
        $tipo = (string) ($_GET['tipo'] ?? ($previo['tipo'] ?? ''));

        $this->ctx->view('templates/public/solicitud_nueva.latte', $this->contexto($c, 'solicitudes') + [
            'tipos'       => SolicitudService::TIPOS,
            'tipo'        => isset(SolicitudService::TIPOS[$tipo]) ? $tipo : '',
            'urgencias'   => SolicitudService::URGENCIAS,
            'modalidades' => SolicitudService::MODALIDADES,
            'proyectos'   => $proyectos,
            'proyectoSel' => (string) ($_GET['proyecto'] ?? ($previo['proyecto_id'] ?? '')),
            'previo'      => is_array($previo) ? $previo : [],
            'puedeUrgente' => $svc->puedeUrgente($clienteId),
            'maxUrgentes' => $svc->maxUrgentes(),
            'rangos'      => [
                'urgente' => $svc->rango('urgente'),
                'semana'  => $svc->rango('semana'),
            ],
            'paisCliente' => $svc->paisCliente($clienteId),
            'iaActiva'    => $this->ia()->activa(),
            'minHorario'  => (new \DateTimeImmutable('now', new \DateTimeZone(HorarioHabil::zonaDe($svc->paisCliente($clienteId)))))->modify('+1 hour')->format('Y-m-d\TH:00'),
        ]);
    }

    public function crear(): void
    {
        $c = $this->requerirContacto();
        if (ArchivoService::postExcedido()) {
            PortalSession::flash('error', 'Los archivos superan el máximo permitido (' . (int) round($this->archivos()->limiteBytes($this->maxMb()) / 1048576) . ' MB en total).');
            $this->redirectTo('portal/solicitudes/nueva');
            return;
        }
        $this->exigirCsrf('portal/solicitudes/nueva');

        $svc = $this->solicitudes();
        $r = $svc->crear($c, $_POST);
        if (isset($r['error'])) {
            PortalSession::iniciar();
            $_SESSION['portal_solicitud_previa'] = array_intersect_key($_POST, array_flip(['tipo', 'proyecto_id', 'titulo', 'detalle', 'urgencia', 'motivo_urgencia', 'modalidad', 'horarios']));
            PortalSession::flash('error', $r['error']);
            $this->redirectTo('portal/solicitudes/nueva', ['tipo' => (string) ($_POST['tipo'] ?? '')]);
            return;
        }
        $id = (string) $r['id'];
        $s = $svc->find($id);
        $clienteId = (string) $c['cliente_id'];

        // Adjuntos (opcionales): una captura del error, un brief, una referencia…
        $lista = ArchivoService::normalizar($_FILES['archivos'] ?? null);
        $errores = [];
        if ($lista !== [] && $s !== null) {
            $res = $this->archivos()->guardarVarios(
                array_slice($lista, 0, 10), $clienteId, ((string) $s['proyecto_id']) ?: null, 'solicitud', $id,
                ['tipo' => 'contacto', 'id' => (string) $c['id'], 'nombre' => (string) $c['nombre']],
                $this->maxMb()
            );
            $errores = $res['errores'];
        }

        if ($s !== null) {
            $this->actividad()->registrar($clienteId, ((string) $s['proyecto_id']) ?: null, 'contacto', (string) $c['nombre'], 'solicito', 'solicitud', $id, (string) $s['titulo'], SolicitudService::TIPOS[$s['tipo']][0]);
            $this->avisarSolicitud($s, (string) $c['nombre'], count($lista) - count($errores));
        }

        $gracias = match ($s['tipo'] ?? '') {
            'reunion'     => 'Recibimos tu propuesta de reunión. Te confirmamos el horario pronto.',
            'presupuesto' => 'Recibimos tu pedido de presupuesto. Te avisamos cuando esté listo.',
            'problema'    => 'Recibimos el reporte y avisamos al equipo de inmediato.',
            default       => 'Recibimos tu solicitud. Te avisamos cuando la tomemos.',
        };
        PortalSession::flash($errores === [] ? 'ok' : 'error', $errores === [] ? $gracias : $gracias . ' Pero algunos archivos no se subieron: ' . implode(' · ', $errores));
        $this->redirectTo('portal/solicitudes/' . $id);
    }

    public function ver(string $id): void
    {
        $c = $this->requerirContacto();
        $s = $this->solicitudes()->findDelCliente($id, (string) $c['cliente_id']);
        if ($s === null) {
            PortalSession::flash('error', 'No encontramos esa solicitud.');
            $this->redirectTo('portal/solicitudes');
            return;
        }
        $this->ctx->view('templates/public/solicitud.latte', $this->contexto($c, 'solicitudes') + [
            's'           => $s,
            'tipos'       => SolicitudService::TIPOS,
            'estadosS'    => SolicitudService::ESTADOS_CLIENTE,
            'urgencias'   => SolicitudService::URGENCIAS,
            'modalidades' => SolicitudService::MODALIDADES,
            'horarios'    => SolicitudService::horarios($s['horarios']),
            'paisCliente' => HorarioHabil::paisValido((string) $s['cliente_pais']),
            // La reunión se guarda en hora de la agencia: al cliente se le muestra en la suya.
            'reunionLocal' => $s['reunion_fecha'] ? SolicitudService::convertir((string) $s['reunion_fecha'], Zona::agencia(), HorarioHabil::zonaDe((string) $s['cliente_pais'])) : null,
            'archivosS'   => $this->archivos()->deEntidad('solicitud', $id),
            'comentarios' => $this->comentarios()->listar('solicitud', $id),
            'conversa'    => !in_array($s['estado'], ['en_curso', 'agendada'], true),
        ]);
    }

    public function comentar(string $id): void
    {
        $c = $this->requerirContacto();
        $vol = 'portal/solicitudes/' . $id;
        $this->exigirCsrf($vol);
        $s = $this->solicitudes()->findDelCliente($id, (string) $c['cliente_id']);
        if ($s === null) {
            $this->redirectTo('portal/solicitudes');
            return;
        }
        $texto = $this->tomarString('cuerpo');
        if ($texto === '') {
            PortalSession::flash('error', 'Escribe algo antes de enviar.');
            $this->redirectTo($vol . '#conversacion');
            return;
        }
        $this->comentarios()->crear((string) $c['cliente_id'], 'solicitud', $id, 'contacto', (string) $c['id'], (string) $c['nombre'], $texto);
        $this->actividad()->registrar((string) $c['cliente_id'], ((string) $s['proyecto_id']) ?: null, 'contacto', (string) $c['nombre'], 'comento', 'solicitud', $id, (string) $s['titulo'], mb_substr($texto, 0, 200));
        $this->notificador()->alEquipo("{$c['nombre']} comentó en la solicitud «{$s['titulo']}»", '', 'solicitudes/' . $id, [
            'etiqueta' => 'Del cliente', 'titulo' => "{$c['nombre']} comentó en una solicitud", 'resaltado' => (string) $c['nombre'],
            'proyecto_id' => (string) $s['proyecto_id'], 'cliente_id' => (string) $s['cliente_id'],
            'bloques' => [['tarjetas' => [['titulo' => (string) $s['titulo'], 'detalle' => SolicitudService::TIPOS[$s['tipo']][0]]]], ['cita' => mb_substr($texto, 0, 800)]],
            'preheader' => mb_substr($texto, 0, 110), 'boton' => 'Abrir la solicitud',
        ]);
        PortalSession::flash('ok', 'Comentario enviado.');
        $this->redirectTo($vol . '#conversacion');
    }

    protected function ia(): IaService
    {
        return new IaService($this->pdo());
    }

    /**
     * «Ordenar con IA» del formulario: recibe el texto dictado y devuelve una versión clara (JSON).
     * Tope por persona: 10 por hora, para que nadie gaste la cuenta de IA de la agencia.
     */
    public function ordenar(): void
    {
        $c = $this->currentContacto();
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        if ($c === null || !PortalSession::csrfValido() || PortalSession::vistaPrevia() !== null) {
            if (!headers_sent()) {
                http_response_code(403);
            }
            echo json_encode(['error' => 'Tu sesión expiró. Recarga la página.'], JSON_UNESCAPED_UNICODE);
            $this->terminate();
            return;
        }
        $ia = $this->ia();
        if (!$ia->activa()) {
            echo json_encode(['error' => 'Esta opción no está disponible.'], JSON_UNESCAPED_UNICODE);
            $this->terminate();
            return;
        }
        $hace = time() - 3600;
        $usos = array_values(array_filter((array) ($_SESSION['portal_ia_dictado'] ?? []), fn($t): bool => (int) $t > $hace));
        if (count($usos) >= 10) {
            echo json_encode(['error' => 'Ya ordenaste varios textos en la última hora. Puedes enviarlo tal como está.'], JSON_UNESCAPED_UNICODE);
            $this->terminate();
            return;
        }
        $usos[] = time();
        $_SESSION['portal_ia_dictado'] = $usos;
        $tipo = SolicitudService::TIPOS[(string) ($_POST['tipo'] ?? '')][0] ?? 'Un pedido';
        try {
            echo json_encode(['texto' => $ia->ordenar((string) ($_POST['texto'] ?? ''), $tipo, $this->tomarString('titulo', 255))], JSON_UNESCAPED_UNICODE);
        } catch (\RuntimeException $e) {
            echo json_encode(['error' => 'No pudimos ordenarlo ahora. Puedes enviarlo tal como está.'], JSON_UNESCAPED_UNICODE);
        }
        $this->terminate();
    }

    /** Aprobar o no la cotización. */
    public function decidir(string $id): void
    {
        $c = $this->requerirContacto();
        $vol = 'portal/solicitudes/' . $id;
        $this->exigirCsrf($vol);
        $s = $this->solicitudes()->findDelCliente($id, (string) $c['cliente_id']);
        $aprueba = ($_POST['decision'] ?? '') === 'aprobar';
        if ($s === null || !$this->solicitudes()->decidir($id, $aprueba)) {
            $this->redirectTo($vol);
            return;
        }
        $comentario = $this->tomarString('cuerpo');
        if ($comentario !== '') {
            $this->comentarios()->crear((string) $c['cliente_id'], 'solicitud', $id, 'contacto', (string) $c['id'], (string) $c['nombre'], $comentario);
        }
        $nombre = (string) $c['nombre'];
        $this->actividad()->registrar((string) $c['cliente_id'], ((string) $s['proyecto_id']) ?: null, 'contacto', $nombre, 'decidio', 'solicitud', $id, (string) $s['titulo'], $aprueba ? 'Aprobada' : 'No aprobada');
        $asunto = $aprueba ? "{$nombre} aprobó el presupuesto «{$s['titulo']}»" : "{$nombre} no aprobó el presupuesto «{$s['titulo']}»";
        $bloques = [['tarjetas' => [['titulo' => (string) $s['titulo'], 'detalle' => 'Presupuesto · ' . $s['monto']]]]];
        if ($comentario !== '') {
            $bloques[] = ['cita' => mb_substr($comentario, 0, 800)];
        }
        $bloques[] = ['p' => $aprueba ? 'Ya puedes convertirla en tarea desde la bandeja de solicitudes.' : 'Queda cerrada. Si quieres, escríbele para ajustar la propuesta.'];
        $this->notificador()->alEquipo($asunto, '', 'solicitudes/' . $id, [
            'etiqueta' => 'Del cliente', 'titulo' => $asunto, 'resaltado' => $nombre, 'proyecto_id' => (string) $s['proyecto_id'], 'cliente_id' => (string) $s['cliente_id'],
            'bloques' => $bloques, 'preheader' => $asunto, 'boton' => 'Abrir la solicitud',
        ]);
        PortalSession::flash('ok', $aprueba ? '¡Gracias! Avisamos al equipo para empezar.' : 'Listo, le avisamos al equipo.');
        $this->redirectTo($vol);
    }

    /** @param array<string, mixed> $s */
    private function avisarSolicitud(array $s, string $quien, int $nArchivos): void
    {
        $tipo = SolicitudService::TIPOS[$s['tipo']][0];
        $urgente = $s['urgencia'] === 'urgente';
        $asunto = ($urgente ? '[URGENTE] ' : '') . match ($s['tipo']) {
            'presupuesto' => "{$quien} pide un presupuesto: «{$s['titulo']}»",
            'reunion'     => "{$quien} propone una reunión: «{$s['titulo']}»",
            'problema'    => "{$quien} reporta un problema: «{$s['titulo']}»",
            default       => "{$quien} pide: «{$s['titulo']}»",
        };
        $detalle = $tipo . ' · ' . SolicitudService::URGENCIAS[$s['urgencia']] . ($s['proyecto_id'] === '' ? ' · Proyecto nuevo' : '');
        $bloques = [['tarjetas' => [['titulo' => (string) $s['titulo'], 'detalle' => $detalle]]]];
        if (trim((string) $s['detalle']) !== '') {
            $bloques[] = ['cita' => mb_substr((string) $s['detalle'], 0, 800)];
        }
        if ($urgente && $s['motivo_urgencia']) {
            $bloques[] = ['p' => 'Por qué es urgente: ' . $s['motivo_urgencia']];
        }
        if ($s['tipo'] === 'reunion') {
            $fmt = new Fmt();
            $hz = SolicitudService::horariosZona($s['horarios']);
            $otra = $hz['zona'] !== Zona::agencia();
            $hs = array_map(function (string $h) use ($fmt, $hz, $otra): string {
                $txt = $fmt->fechaCorta($h) . ' ' . substr($h, 11, 5);
                if ($otra) {
                    $ag = SolicitudService::convertir($h, $hz['zona'], Zona::agencia());
                    $txt .= ' (' . substr($ag, 11, 5) . ' en ' . HorarioHabil::nombreDe(SolicitudService::paisAgencia()) . ')';
                }
                return $txt;
            }, $hz['lista']);
            $bloques[] = ['p' => 'Horarios propuestos, en hora de ' . HorarioHabil::nombreDe($hz['pais']) . ': ' . implode(' · ', $hs) . '. ' . (SolicitudService::MODALIDADES[$s['modalidad']] ?? 'Videollamada') . '.'];
        }
        if ($nArchivos > 0) {
            $bloques[] = ['p' => $nArchivos === 1 ? 'Adjuntó 1 archivo.' : "Adjuntó {$nArchivos} archivos."];
        }
        $this->notificador()->alEquipo($asunto, '', 'solicitudes/' . $s['id'], [
            'etiqueta' => $urgente ? 'Urgente' : 'Solicitud nueva', 'titulo' => $asunto, 'resaltado' => $quien,
            'proyecto_id' => (string) $s['proyecto_id'], 'cliente_id' => (string) $s['cliente_id'], 'bloques' => $bloques,
            'preheader' => mb_substr(trim((string) $s['detalle']) ?: $asunto, 0, 110), 'boton' => 'Revisar la solicitud',
            // Urgente o «algo dejó de funcionar»: no espera al correo agrupado.
            'urgente' => $urgente || $s['tipo'] === 'problema', 'detalle' => $detalle,
        ]);
    }
}

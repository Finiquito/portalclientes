<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * Revisión de contenidos en el portal del cliente.
 *
 * Reglas:
 *  - Todo se filtra por el cliente_id del contacto; los borradores no existen para el cliente.
 *  - Cualquier contacto ve, comenta y reacciona. Sólo el rol "aprobador" aprueba,
 *    pide cambios y envía la revisión.
 *  - Aprobar / pedir cambios es un borrador hasta "Enviar revisión": el equipo recibe un
 *    solo aviso con el resumen, no un correo por contenido.
 *  - Las decisiones sólo se aceptan mientras la entrega está en revisión ("publicada").
 */
class EntregaPublicController extends PortalPublicController
{
    private function entregasSvc(): EntregaService
    {
        return new EntregaService($this->pdo());
    }

    private function contenidosSvc(): ContenidoService
    {
        return new ContenidoService($this->pdo());
    }

    /** Primera imagen de la versión vigente, para la portada de la grilla. */
    /** @return array{0: ?string, 1: int} primera imagen de la versión vigente y cuántas imágenes tiene */
    private function portada(array $c): array
    {
        if ($c['version_id'] === null) {
            return [null, 0];
        }
        $fmt = new Fmt();
        $imgs = array_values(array_filter($this->contenidosSvc()->archivos((string) $c['version_id']), fn($a) => $fmt->esImagen($a['mime'])));
        return [$imgs !== [] ? (string) $imgs[0]['id'] : null, count($imgs)];
    }

    public function entregas(): void
    {
        $c = $this->requerirContacto();
        $this->ctx->view('templates/public/entregas.latte', $this->contexto($c, 'revisiones') + [
            'entregas' => $this->entregasSvc()->delCliente((string) $c['cliente_id']),
            'estados'  => TiposContenido::ESTADOS_ENTREGA,
        ]);
    }

    public function entrega(string $id): void
    {
        $c = $this->requerirContacto();
        $e = $this->entregasSvc()->findDelCliente($id, (string) $c['cliente_id']);
        if ($e === null) {
            PortalSession::flash('error', 'No encontramos esa entrega.');
            $this->redirectTo('portal/entregas');
            return;
        }
        $lista = $this->contenidosSvc()->listar($id);
        $portadas = [];
        $reacc = [];
        $etiquetas = [];
        foreach ($lista as $x) {
            [$p, $nImg] = $this->portada($x);
            if ($p !== null) {
                $portadas[$x['id']] = $p;
            }
            $etiquetas[$x['id']] = TiposContenido::etiqueta($x, $nImg);
            if ($x['version_id'] !== null) {
                $reacc[$x['id']] = $this->contenidosSvc()->reacciones((string) $x['version_id'], (string) $c['id']);
            }
        }
        $total = (int) $e['n_total'];
        $decididos = (int) $e['n_aprobados'] + (int) $e['n_cambios'];

        $this->ctx->view('templates/public/entrega.latte', $this->contexto($c, 'revisiones') + [
            'e'          => $e,
            'contenidos' => $lista,
            'portadas'   => $portadas,
            'etiquetas'  => $etiquetas,
            'reacc'      => $reacc,
            'tipos'      => TiposContenido::TIPOS,
            'estadosC'   => TiposContenido::ESTADOS_CONTENIDO,
            'estadosE'   => TiposContenido::ESTADOS_ENTREGA,
            'reaccionesDef' => TiposContenido::REACCIONES,
            'pct'        => $total > 0 ? (int) round($decididos * 100 / $total) : 0,
            'decididos'  => $decididos,
            'abierta'    => $e['estado'] === 'publicada',
            'puedeEnviar' => $this->esColaborador($c) && $e['estado'] === 'publicada' && $total > 0 && (int) $e['n_pendientes'] === 0,
        ]);
    }

    public function contenido(string $id): void
    {
        $c = $this->requerirContacto();
        $x = $this->contenidosSvc()->findDelCliente($id, (string) $c['cliente_id']);
        if ($x === null) {
            PortalSession::flash('error', 'No encontramos ese contenido.');
            $this->redirectTo('portal/entregas');
            return;
        }

        $versiones = $this->contenidosSvc()->versiones($id);
        $vigente = (int) $x['version_actual'];
        // Se puede mirar una versión anterior (?v=1), sólo lectura.
        $pedida = isset($_GET['v']) ? (int) $_GET['v'] : $vigente;
        $ver = null;
        foreach ($versiones as $v) {
            if ((int) $v['numero'] === $pedida) {
                $ver = $v;
            }
        }
        $ver ??= end($versiones) ?: null;
        $esVigente = $ver !== null && (int) $ver['numero'] === $vigente;

        $archivos = $ver !== null ? $this->contenidosSvc()->archivos((string) $ver['id']) : [];
        $fmt = new Fmt();
        $imagenes = array_values(array_filter($archivos, fn($a) => $fmt->esImagen($a['mime'])));
        $videos   = array_values(array_filter($archivos, fn($a) => TiposContenido::esVideo($a['mime'])));
        $docs     = array_values(array_filter($archivos, fn($a) => !$fmt->esImagen($a['mime']) && !TiposContenido::esVideo($a['mime'])));

        // Navegación entre contenidos de la misma entrega.
        $ids = array_map(fn($r) => (string) $r['id'], $this->contenidosSvc()->listar((string) $x['entrega_id']));
        $pos = array_search($id, $ids, true);
        $numeros = [];
        foreach ($versiones as $v) {
            $numeros[$v['id']] = (int) $v['numero'];
        }

        $abierta = $x['entrega_estado'] === 'publicada';
        $enlace = $ver !== null ? (string) $ver['enlace'] : '';
        $comentarios = (new ComentarioService($this->pdo()))->listar('contenido', $id);
        $posImagen = [];
        foreach ($imagenes as $k => $a) {
            $posImagen[(string) $a['id']] = $k + 1;
        }
        $pines = Ubicacion::pines($comentarios, $ver !== null ? (string) $ver['id'] : null, $posImagen);
        $pinesPdf = array_filter($pines, fn($p) => $p['tipo'] === 'pagina');

        $this->ctx->view('templates/public/contenido.latte', $this->contexto($c, 'revisiones') + [
            'x'          => $x,
            'ver'        => $ver,
            'versiones'  => $versiones,
            'esVigente'  => $esVigente,
            'visor'      => TiposContenido::visor((string) $x['tipo']),
            'etiqueta'   => TiposContenido::etiqueta($x, count($imagenes)),
            'laminas'    => TiposContenido::laminas($x['laminas'] ?? null),
            'imagenes'   => $imagenes,
            'videos'     => $videos,
            'docs'       => $docs,
            'hayPdf'     => array_filter($docs, fn($a) => $a['mime'] === 'application/pdf') !== [],
            'enlace'     => $enlace,
            'enlaceHost' => $enlace !== '' ? (string) parse_url($enlace, PHP_URL_HOST) : '',
            'embed'      => $enlace !== '' ? TiposContenido::embed($enlace) : null,
            'comentarios' => $comentarios,
            'pines'      => $pines,
            'posImagen'  => $posImagen,
            'pinesPdf'   => array_values(array_map(
                fn($cid, $p) => ['id' => $cid, 'n' => $p['n'], 'p' => $p['pagina'], 'x' => $p['x'], 'y' => $p['y']],
                array_keys($pinesPdf), $pinesPdf
            )),
            'numeros'    => $numeros,
            'reacc'      => $ver !== null ? $this->contenidosSvc()->reacciones((string) $ver['id'], (string) $c['id']) : ['conteo' => [], 'mia' => '', 'total' => 0],
            'reaccionesDef' => TiposContenido::REACCIONES,
            'estadosC'   => TiposContenido::ESTADOS_CONTENIDO,
            'prev'       => $pos !== false && $pos > 0 ? $ids[$pos - 1] : null,
            'next'       => $pos !== false && $pos < count($ids) - 1 ? $ids[$pos + 1] : null,
            'posicion'   => $pos !== false ? $pos + 1 : 1,
            'totalContenidos' => count($ids),
            'abierta'    => $abierta,
            'puedeDecidir' => $this->esColaborador($c) && $abierta && $esVigente,
        ]);
    }

    /** Aprobar o pedir cambios. Al decidir la última pieza pendiente, la revisión se envía sola. */
    public function decidir(string $id): void
    {
        $c   = $this->requerirContacto();
        $vol = 'portal/contenidos/' . $id;
        $this->exigirCsrf($vol);

        $x = $this->contenidosSvc()->findDelCliente($id, (string) $c['cliente_id']);
        if ($x === null) {
            $this->redirectTo('portal/entregas');
            return;
        }
        if (!$this->esColaborador($c) || $x['entrega_estado'] !== 'publicada') {
            PortalSession::flash('error', 'No puedes decidir sobre este contenido ahora.');
            $this->redirectTo($vol);
            return;
        }

        $nombre = (string) $c['nombre'];
        $cliente = (string) $c['cliente_id'];
        $accion = (string) ($_POST['accion'] ?? '');

        if ($accion === 'aprobar') {
            $this->contenidosSvc()->decidir($id, 'aprobado', (string) $c['id'], $nombre);
            $texto = $this->tomarString('cuerpo');
            if ($texto !== '') {
                (new ComentarioService($this->pdo()))->crear($cliente, 'contenido', $id, 'contacto', (string) $c['id'], $nombre, $texto, $x['version_id'], $this->ubicacion($x));
            }
            $this->actividad()->registrar($cliente, $x['proyecto_id'], 'contacto', $nombre, 'aprobo', 'contenido', $id, $x['titulo']);
            PortalSession::flash('ok', 'Aprobado.');
        } elseif ($accion === 'cambios') {
            $texto = $this->tomarString('cuerpo');
            if ($texto === '') {
                PortalSession::flash('error', 'Cuéntanos qué cambiarías: el comentario es obligatorio.');
                $this->redirectTo($vol);
                return;
            }
            $ub = $this->ubicacion($x);
            (new ComentarioService($this->pdo()))->crear($cliente, 'contenido', $id, 'contacto', (string) $c['id'], $nombre, $texto, $x['version_id'], $ub);
            $this->contenidosSvc()->decidir($id, 'cambios', (string) $c['id'], $nombre);
            $texto = ($ub !== null ? '[' . Ubicacion::etiqueta($ub) . '] ' : '') . $texto;
            $this->actividad()->registrar($cliente, $x['proyecto_id'], 'contacto', $nombre, 'pidio_cambios', 'contenido', $id, $x['titulo'], mb_substr($texto, 0, 200));
            PortalSession::flash('ok', 'Anotado.');
        }

        // Si era la última pieza por revisar, la revisión se envía sola al equipo.
        $e = $this->entregasSvc()->findDelCliente((string) $x['entrega_id'], $cliente);
        if ($e !== null && in_array($accion, ['aprobar', 'cambios'], true) && $e['estado'] === 'publicada'
            && (int) $e['n_total'] > 0 && (int) $e['n_pendientes'] === 0) {
            $estado = $this->enviarRevision($c, $e);
            PortalSession::flash('ok', $estado === 'aprobada'
                ? '¡Todo aprobado! Le avisamos al equipo. Gracias.'
                : 'Listo: enviamos tu revisión al equipo. Te avisaremos cuando subamos los cambios.');
            $this->redirectTo('portal/entregas/' . $x['entrega_id']);
            return;
        }

        // Si quedan, seguimos con el siguiente por revisar.
        $sig = $this->siguientePendiente((string) $x['entrega_id'], $id);
        $this->redirectTo($sig !== null ? 'portal/contenidos/' . $sig : 'portal/entregas/' . $x['entrega_id']);
    }

    /** Ubicación (página o pin) que manda el formulario, validada contra la versión vigente. */
    private function ubicacion(array $x): ?string
    {
        $ub = $this->tomarString('ubicacion');
        if ($ub === '' || $x['version_id'] === null) {
            return null;
        }
        $fmt = new Fmt();
        $ids = [];
        foreach ($this->contenidosSvc()->archivos((string) $x['version_id']) as $a) {
            if ($fmt->esImagen($a['mime'])) {
                $ids[] = (string) $a['id'];
            }
        }
        return Ubicacion::validar($ub, $ids);
    }

    private function siguientePendiente(string $entregaId, string $actual): ?string
    {
        $lista = $this->contenidosSvc()->listar($entregaId);
        $desde = false;
        foreach (array_merge($lista, $lista) as $r) {
            if ($r['id'] === $actual) {
                $desde = !$desde ? true : $desde;
                continue;
            }
            if ($desde && $r['estado'] === 'pendiente') {
                return (string) $r['id'];
            }
        }
        return null;
    }

    public function reaccionar(string $id): void
    {
        $c   = $this->requerirContacto();
        $vol = 'portal/contenidos/' . $id;
        $this->exigirCsrf($vol);

        $x = $this->contenidosSvc()->findDelCliente($id, (string) $c['cliente_id']);
        if ($x === null || $x['version_id'] === null) {
            $this->redirectTo('portal/entregas');
            return;
        }
        $valor = (string) ($_POST['valor'] ?? '');
        if (isset(TiposContenido::REACCIONES[$valor])) {
            $actual = $this->contenidosSvc()->reacciones((string) $x['version_id'], (string) $c['id'])['mia'];
            // Tocar la misma reacción otra vez la quita.
            $this->contenidosSvc()->reaccionar((string) $x['version_id'], (string) $c['id'], $actual === $valor ? '' : $valor);
        }
        $this->redirectTo($vol . '#reaccion');
    }

    public function comentar(string $id): void
    {
        $c   = $this->requerirContacto();
        $vol = 'portal/contenidos/' . $id;
        $this->exigirCsrf($vol);

        $x = $this->contenidosSvc()->findDelCliente($id, (string) $c['cliente_id']);
        if ($x === null) {
            $this->redirectTo('portal/entregas');
            return;
        }
        $texto = $this->tomarString('cuerpo');
        if ($texto === '') {
            PortalSession::flash('error', 'Escribe algo antes de enviar.');
            $this->redirectTo($vol . '#conversacion');
            return;
        }
        $ub = $this->ubicacion($x);
        (new ComentarioService($this->pdo()))->crear((string) $c['cliente_id'], 'contenido', $id, 'contacto', (string) $c['id'], (string) $c['nombre'], $texto, $x['version_id'], $ub);
        $pref = $ub !== null ? '[' . Ubicacion::etiqueta($ub) . '] ' : '';
        $this->actividad()->registrar((string) $c['cliente_id'], $x['proyecto_id'], 'contacto', (string) $c['nombre'], 'comento', 'contenido', $id, $x['titulo'], mb_substr($pref . $texto, 0, 200));

        // Durante la revisión activa no se manda correo por comentario: llega todo junto al enviar.
        if ($x['entrega_estado'] !== 'publicada') {
            $this->notificador()->alEquipo("{$c['nombre']} comentó en «{$x['titulo']}»", '', 'contenidos/' . $id, [
                'etiqueta' => 'Comentario', 'titulo' => "{$c['nombre']} comentó en «{$x['titulo']}»", 'resaltado' => (string) $c['nombre'], 'proyecto_id' => (string) $x['proyecto_id'],
                'bloques' => [['cita' => $pref . $texto]], 'preheader' => mb_substr($pref . $texto, 0, 110), 'boton' => 'Ver el contenido',
            ]);
        }
        PortalSession::flash('ok', 'Nota agregada.');
        $this->redirectTo($vol . '#conversacion');
    }

    /** El cliente terminó: un solo aviso al equipo con el resumen. */
    public function enviar(string $id): void
    {
        $c   = $this->requerirContacto();
        $vol = 'portal/entregas/' . $id;
        $this->exigirCsrf($vol);

        $e = $this->entregasSvc()->findDelCliente($id, (string) $c['cliente_id']);
        if ($e === null) {
            $this->redirectTo('portal/entregas');
            return;
        }
        if (!$this->esColaborador($c) || $e['estado'] !== 'publicada') {
            PortalSession::flash('error', 'No puedes enviar esta revisión ahora.');
            $this->redirectTo($vol);
            return;
        }
        if ((int) $e['n_pendientes'] > 0 || (int) $e['n_total'] === 0) {
            PortalSession::flash('error', 'Aún te faltan contenidos por revisar.');
            $this->redirectTo($vol);
            return;
        }

        $estado = $this->enviarRevision($c, $e);

        PortalSession::flash('ok', $estado === 'aprobada' ? '¡Todo aprobado! Gracias.' : 'Enviamos tu revisión al equipo. Te avisaremos cuando subamos los cambios.');
        $this->redirectTo($vol);
    }

    /**
     * Cierra la revisión de una entrega (aprobada o con cambios) y le avisa al equipo con un solo correo.
     * Se llama al apretar «Enviar revisión» o, sola, cuando el cliente decide la última pieza pendiente.
     *
     * @param array<string, mixed> $c contacto
     * @param array<string, mixed> $e entrega (con n_aprobados, n_cambios)
     */
    private function enviarRevision(array $c, array $e): string
    {
        $nombre = (string) $c['nombre'];
        $estado = $this->entregasSvc()->responder($e['id'], $nombre);
        $this->actividad()->registrar((string) $c['cliente_id'], $e['proyecto_id'], 'contacto', $nombre, 'respondio', 'entrega', $e['id'], $e['titulo'],
            $e['n_aprobados'] . ' aprobados · ' . $e['n_cambios'] . ' con cambios');

        $lineas = [];
        $cm = new ComentarioService($this->pdo());
        foreach ($this->contenidosSvc()->listar($e['id']) as $x) {
            if ($x['estado'] === 'cambios') {
                $ult = null;
                foreach ($cm->listar('contenido', (string) $x['id']) as $k) {
                    if ($k['autor_tipo'] === 'contacto') {
                        $ult = $k;
                    }
                }
                $lineas[] = '• ' . $x['titulo'] . ($ult ? ': ' . mb_substr((string) $ult['cuerpo'], 0, 300) : '');
            }
        }
        $bloques = [['datos' => [['Aprobados', (string) $e['n_aprobados']], ['Con cambios', (string) $e['n_cambios']]]]];
        if ($lineas) {
            $bloques[] = ['p' => 'Pidió cambios en:'];
            $bloques[] = ['lista' => array_map(fn($l) => ltrim(preg_replace('/^•\s*/u', '', $l) ?? $l), $lineas)];
        }
        $this->notificador()->alEquipo("{$nombre} respondió la revisión: {$e['titulo']}", '', 'entregas/' . $e['id'], [
            'etiqueta' => 'Revisión', 'titulo' => "{$nombre} respondió la revisión", 'resaltado' => 'respondió', 'proyecto_id' => (string) $e['proyecto_id'],
            'bloques' => array_merge([['p' => "Terminó de revisar «{$e['titulo']}»."]], $bloques), 'preheader' => "{$e['n_aprobados']} aprobados · {$e['n_cambios']} con cambios",
            'boton' => 'Abrir la entrega',
        ]);

        return $estado;
    }
}

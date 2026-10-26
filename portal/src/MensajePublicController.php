<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * «Mensajes» del portal: todas las conversaciones del cliente con el equipo (tareas,
 * contenidos y solicitudes), con lo nuevo marcado y respuesta sin salir de la bandeja.
 */
class MensajePublicController extends PortalPublicController
{
    private const URLS = ['tarea' => '/portal/tareas/', 'contenido' => '/portal/contenidos/', 'solicitud' => '/portal/solicitudes/'];

    public function lista(): void
    {
        $c = $this->requerirContacto();
        $svc = new MensajeService($this->pdo());
        if ($this->recordarLista('/portal/mensajes')) {
            return;
        }
        $todas = $svc->conversaciones('contacto', (string) $c['id'], (string) $c['cliente_id']);
        $pestana = in_array($_GET['t'] ?? '', ['archivadas', 'destacadas'], true) ? (string) $_GET['t'] : 'activas';
        $tipoF = isset(MensajeService::TIPOS[$_GET['tipo'] ?? '']) ? (string) $_GET['tipo'] : '';
        $proyF = (string) ($_GET['proyecto'] ?? '');
        if ($proyF !== '' && !in_array($proyF, array_column($todas, 'proyecto_id'), true)) {
            $proyF = '';
        }
        $grupo = MensajeService::filtrar($todas, $pestana, 'proyecto_id', $proyF, $tipoF);
        $cuenta = fn(string $t): int => count(MensajeService::filtrar($todas, $t, 'proyecto_id', $proyF, $tipoF));

        $pref = $this->ajustes()->todos('contacto', (string) $c['id']);
        $pag = Paginacion::desdeGet('/portal/mensajes', count($grupo), (int) ($pref['por_pagina'] ?? 0), ['recientes' => 'Más nuevos primero']);
        if (($por = Paginacion::elegido()) !== null && !PortalSession::vistaPrevia()) {
            $this->ajustes()->set('contacto', (string) $c['id'], 'por_pagina', (string) $por);
        }

        // Conversación abierta: sólo una de las que esta persona puede ver.
        $sel = null;
        $clave = (string) ($_GET['c'] ?? '');
        foreach ($todas as $x) {
            if ($x['clave'] === $clave) {
                $sel = $x;
                break;
            }
        }
        if ($sel !== null) {
            $this->marcarVisto($c, $sel['tipo'], $sel['id']);
            if ($sel['tipo'] === 'solicitud') {
                $tid = (string) ($this->fetchOne('SELECT tarea_id FROM portal_solicitudes WHERE id = ?', [$sel['id']])['tarea_id'] ?? '');
                if ($tid !== '') {
                    $this->marcarVisto($c, 'tarea', $tid);
                }
            }
            $sel['sin_leer'] = 0;
        }

        $hilo = $sel !== null ? $svc->hilo($sel['tipo'], $sel['id']) : [];
        // La conversación abierta nunca sale de la lista: si por la pestaña o los filtros no le tocaría estar, va fijada arriba.
        $lista = $svc->conUltimo($pag->cortar($grupo));
        if ($sel !== null && !in_array($sel['clave'], array_column($lista, 'clave'), true)) {
            array_unshift($lista, ['fuera' => true] + $svc->conUltimo([$sel])[0]);
        }
        $this->ctx->view('templates/public/mensajes.latte', $this->contexto($c, 'mensajes') + [
            'base'        => '/portal/mensajes',
            'pestana'     => $pestana,
            'nActivas'    => $cuenta('activas'),
            'nArchivadas' => $cuenta('archivadas'),
            'nDestacadas' => $cuenta('destacadas'),
            'filtros'     => ['campo' => 'proyecto', 'valor' => $proyF, 'tipo' => $tipoF, 'etiqueta' => 'Proyecto', 'todos' => 'Todos los proyectos'],
            'opciones'    => MensajeService::opciones($todas, 'proyecto_id', $proyF, $tipoF),
            'reacciones'  => $svc->reacciones(array_column($hilo, 'id'), 'contacto', (string) $c['id']),
            'puedeReaccionar' => !PortalSession::vistaPrevia(),
            'accionesBase' => '/portal/mensajes',
            'conv'        => $lista,
            'pag'         => $pag,
            'sel'         => $sel,
            'hilo'        => $hilo,
            'lado'        => 'contacto',
            'yoId'        => (string) $c['id'],
            'accion'      => $sel !== null && !PortalSession::vistaPrevia() ? self::URLS[$sel['tipo']] . $sel['id'] . '/comentarios' : null,
            'urlCosa'     => $sel !== null ? self::URLS[$sel['tipo']] . $sel['id'] : '',
            'csrfCampo'   => '_csrf',
            'equipo'      => false,
        ]);
    }

    /** Destaca (o quita la estrella de) una conversación: sale de Archivadas y queda en Destacados. */
    public function destacar(): void
    {
        $c = $this->requerirContacto();
        if (PortalSession::csrfValido() && PortalSession::vistaPrevia() === null) {
            $svc = new MensajeService($this->pdo());
            foreach ($svc->conversaciones('contacto', (string) $c['id'], (string) $c['cliente_id']) as $x) {
                if ($x['clave'] === (string) ($_POST['c'] ?? '')) {
                    $svc->alternarDestacada('contacto', (string) $c['id'], $x['tipo'], $x['id']);
                    break;
                }
            }
        }
        $this->redirectTo('/portal/mensajes', MensajeService::consultaDeVuelta());
    }

    /** Reacciona a un mensaje (o quita la reacción): sin correo y sin marcarlo como no leído. */
    public function reaccionar(): void
    {
        $c = $this->requerirContacto();
        if (PortalSession::csrfValido() && PortalSession::vistaPrevia() === null) {
            $svc = new MensajeService($this->pdo());
            foreach ($svc->conversaciones('contacto', (string) $c['id'], (string) $c['cliente_id']) as $x) {
                if ($x['clave'] === (string) ($_POST['c'] ?? '') && $svc->esDe((string) ($_POST['comentario'] ?? ''), $x['tipo'], $x['id'])) {
                    $svc->alternarReaccion((string) $_POST['comentario'], (string) ($_POST['clave'] ?? ''), 'contacto', (string) $c['id']);
                    break;
                }
            }
        }
        $this->redirectTo('/portal/mensajes', MensajeService::consultaDeVuelta());
    }
}

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
        $pestana = ($_GET['t'] ?? '') === 'archivadas' ? 'archivadas' : 'activas';
        $grupo = array_values(array_filter($todas, fn(array $x): bool => $x['archivada'] === ($pestana === 'archivadas')));

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

        $this->ctx->view('templates/public/mensajes.latte', $this->contexto($c, 'mensajes') + [
            'base'        => '/portal/mensajes',
            'pestana'     => $pestana,
            'nActivas'    => count(array_filter($todas, fn(array $x): bool => !$x['archivada'])),
            'nArchivadas' => count(array_filter($todas, fn(array $x): bool => $x['archivada'])),
            'conv'        => $svc->conUltimo($pag->cortar($grupo)),
            'pag'         => $pag,
            'sel'         => $sel,
            'hilo'        => $sel !== null ? $svc->hilo($sel['tipo'], $sel['id']) : [],
            'lado'        => 'contacto',
            'yoId'        => (string) $c['id'],
            'accion'      => $sel !== null && !PortalSession::vistaPrevia() ? self::URLS[$sel['tipo']] . $sel['id'] . '/comentarios' : null,
            'urlCosa'     => $sel !== null ? self::URLS[$sel['tipo']] . $sel['id'] : '',
            'csrfCampo'   => '_csrf',
            'equipo'      => false,
        ]);
    }
}

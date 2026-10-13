<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * Contactos ganados en el portal del cliente: la lista con filtros, el seguimiento de cada
 * uno (estado, notas, próximo paso) y el ingreso manual.
 *
 * Sólo para contactos colaboradores y sólo si algún proyecto del cliente tiene el módulo
 * activo. Todo se busca acotado al cliente de la sesión: un id ajeno da «no encontrado».
 */
class LeadPublicController extends PortalPublicController
{
    private function leads(): LeadService
    {
        return new LeadService($this->pdo());
    }

    /** El contacto, si puede ver contactos ganados; si no, lo devuelve a Inicio. */
    private function requerirAcceso(): array
    {
        $c = $this->requerirContacto();
        if (!$this->veLeads($c)) {
            PortalSession::flash('error', $this->esColaborador($c)
                ? 'Todavía no hay campañas con contactos ganados.'
                : 'Los contactos ganados los ven las personas con permiso para colaborar.');
            $this->redirectTo('portal');
            throw new \LogicException('redirectTo() debe terminar la request');
        }
        return $c;
    }

    public function lista(): void
    {
        $c = $this->requerirAcceso();
        $clienteId = (string) $c['cliente_id'];
        $svc = $this->leads();
        $proyectos = $svc->proyectosDe($clienteId);
        $f = [
            'proyecto_id' => (string) ($_GET['proyecto'] ?? ''),
            'estado'      => (string) ($_GET['estado'] ?? ''),
            'campana'     => (string) ($_GET['campana'] ?? ''),
            'q'           => mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100),
        ];
        if (!in_array($f['proyecto_id'], array_column($proyectos, 'id'), true)) {
            $f['proyecto_id'] = '';
        }
        if (!isset(LeadService::ESTADOS[$f['estado']])) {
            $f['estado'] = '';
        }
        // Base sin el filtro de estado: sirve para los conteos de las pestañas y las campañas.
        $base = $svc->lista(['cliente_id' => $clienteId, 'proyecto_id' => $f['proyecto_id']]);
        $leads = $svc->lista(['cliente_id' => $clienteId] + $f);

        $this->ctx->view('templates/public/leads.latte', $this->contexto($c, 'leads') + [
            'leads'     => $leads,
            'f'         => $f,
            'conteo'    => LeadService::conteo($base),
            'total'     => count($base),
            'campanas'  => LeadService::campanas($base),
            'proyectos' => $proyectos,
            'estados'   => LeadService::ESTADOS,
            'chips'     => LeadService::CHIPS,
            'origenes'  => LeadService::ORIGENES,
            'hoy'       => (new \DateTimeImmutable('now', new \DateTimeZone(Zona::agencia())))->format('Y-m-d'),
        ]);
    }

    public function nuevo(): void
    {
        $c = $this->requerirAcceso();
        PortalSession::iniciar();
        $previo = $_SESSION['portal_lead_previo'] ?? [];
        unset($_SESSION['portal_lead_previo']);
        $this->ctx->view('templates/public/lead_nuevo.latte', $this->contexto($c, 'leads') + [
            'proyectos'   => $this->leads()->proyectosDe((string) $c['cliente_id']),
            'proyectoSel' => (string) ($_GET['proyecto'] ?? ($previo['proyecto_id'] ?? '')),
            'previo'      => is_array($previo) ? $previo : [],
        ]);
    }

    public function crear(): void
    {
        $c = $this->requerirAcceso();
        $this->exigirCsrf('portal/contactos-ganados/nuevo');
        $svc = $this->leads();
        $p = $svc->proyecto((string) ($_POST['proyecto_id'] ?? ''));
        if ($p === null || (string) $p['cliente_id'] !== (string) $c['cliente_id']) {
            $r = ['error' => 'Elige la campaña a la que pertenece.'];
        } else {
            $r = $svc->crear($p, $_POST, 'manual', (string) $c['nombre']);
        }
        if (isset($r['error'])) {
            PortalSession::iniciar();
            $_SESSION['portal_lead_previo'] = array_intersect_key($_POST, array_flip(['proyecto_id', 'nombre', 'correo', 'telefono', 'empresa', 'campana', 'notas']));
            PortalSession::flash('error', $r['error']);
            $this->redirectTo('portal/contactos-ganados/nuevo');
            return;
        }
        PortalSession::flash('ok', !empty($r['duplicado'])
            ? 'Ese contacto ya estaba (mismo correo o teléfono en los últimos ' . LeadService::DIAS_DUPLICADO . ' días). Te llevamos a su ficha.'
            : 'Contacto agregado.');
        $this->redirectTo('portal/contactos-ganados/' . $r['id']);
    }

    public function ver(string $id): void
    {
        $c = $this->requerirAcceso();
        $l = $this->leads()->delCliente($id, (string) $c['cliente_id']);
        if ($l === null || $this->leads()->proyecto((string) $l['proyecto_id']) === null) {
            PortalSession::flash('error', 'No encontramos ese contacto.');
            $this->redirectTo('portal/contactos-ganados');
            return;
        }
        $this->ctx->view('templates/public/lead.latte', $this->contexto($c, 'leads') + [
            'l'        => $l,
            'estados'  => LeadService::ESTADOS,
            'chips'    => LeadService::CHIPS,
            'origenes' => LeadService::ORIGENES,
        ]);
    }

    public function guardar(string $id): void
    {
        $c = $this->requerirAcceso();
        $this->exigirCsrf('portal/contactos-ganados/' . $id);
        $svc = $this->leads();
        $l = $svc->delCliente($id, (string) $c['cliente_id']);
        if ($l === null || $svc->proyecto((string) $l['proyecto_id']) === null) {
            PortalSession::flash('error', 'No encontramos ese contacto.');
            $this->redirectTo('portal/contactos-ganados');
            return;
        }
        $datos = array_intersect_key($_POST, array_flip(['nombre', 'correo', 'telefono', 'empresa', 'estado', 'notas', 'proximo_paso', 'proximo_paso_fecha']));
        $error = $svc->actualizar($l, $datos, (string) $c['nombre']);
        PortalSession::flash($error === null ? 'ok' : 'error', $error ?? 'Guardado.');
        $this->redirectTo('portal/contactos-ganados/' . $id);
    }
}

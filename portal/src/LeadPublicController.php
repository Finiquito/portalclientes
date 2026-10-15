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

    /** Filtros de la lista (y de la exportación), sólo con valores válidos para este cliente. */
    private function filtros(array $proyectos): array
    {
        $f = [
            'proyecto_id' => (string) ($_GET['proyecto'] ?? ''),
            'estado'      => (string) ($_GET['estado'] ?? ''),
            'campana'     => mb_substr((string) ($_GET['campana'] ?? ''), 0, 255),
            'q'           => mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100),
        ];
        if (!in_array($f['proyecto_id'], array_column($proyectos, 'id'), true)) {
            $f['proyecto_id'] = '';
        }
        if (!isset(LeadService::ESTADOS[$f['estado']])) {
            $f['estado'] = '';
        }
        return $f;
    }

    public function lista(): void
    {
        $c = $this->requerirAcceso();
        $clienteId = (string) $c['cliente_id'];
        $svc = $this->leads();
        $proyectos = $svc->proyectosDe($clienteId);
        $f = $this->filtros($proyectos);
        // Base sin el filtro de estado: sirve para los conteos de las pestañas y las campañas.
        $base = $svc->lista(['cliente_id' => $clienteId, 'proyecto_id' => $f['proyecto_id']]);
        $leads = $svc->lista(['cliente_id' => $clienteId] + $f);

        // Orden: el selector o un clic en el encabezado de la tabla (otro clic lo da vuelta).
        $pag = Paginacion::delContacto($this->pdo(), (string) $c['id'], '/portal/contactos-ganados', count($leads), [
            'llego_desc'  => 'Más nuevos primero',
            'llego_asc'   => 'Más antiguos primero',
            'nombre_asc'  => 'Nombre (A a Z)',
            'nombre_desc' => 'Nombre (Z a A)',
            'estado_asc'  => 'Estado (nuevos primero)',
            'estado_desc' => 'Estado (perdidos primero)',
            'paso_asc'    => 'Próximo paso (el más cercano)',
            'paso_desc'   => 'Próximo paso (el más lejano)',
        ]);
        $orden = array_flip(array_keys(LeadService::ESTADOS));
        $nombre = fn(array $l): string => mb_strtolower((string) $l['nombre']);
        $paso = fn(array $l): string => (string) ($l['proximo_paso_fecha'] ?: '9999-12-31');
        $leads = $pag->ordenar($leads, [
            'llego_asc'   => fn($a, $b) => strcmp((string) $a['created_at'], (string) $b['created_at']),
            'nombre_asc'  => fn($a, $b) => strcmp($nombre($a), $nombre($b)),
            'nombre_desc' => fn($a, $b) => strcmp($nombre($b), $nombre($a)),
            'estado_asc'  => fn($a, $b) => [$orden[$a['estado']] ?? 9, $b['created_at']] <=> [$orden[$b['estado']] ?? 9, $a['created_at']],
            'estado_desc' => fn($a, $b) => [$orden[$b['estado']] ?? 9, $b['created_at']] <=> [$orden[$a['estado']] ?? 9, $a['created_at']],
            'paso_asc'    => fn($a, $b) => strcmp($paso($a), $paso($b)),
            'paso_desc'   => fn($a, $b) => strcmp($paso($b) === '9999-12-31' ? '' : $paso($b), $paso($a) === '9999-12-31' ? '' : $paso($a)),
        ]);

        $this->ctx->view('templates/public/leads.latte', $this->contexto($c, 'leads') + [
            'pag'       => $pag,
            'leads'     => $pag->cortar($leads),
            'f'         => $f,
            'conteo'    => LeadService::conteo($base),
            'total'     => count($base),
            'campanas'  => LeadService::campanas($base),
            'proyectos' => $proyectos,
            'estados'   => LeadService::ESTADOS,
            'chips'     => LeadService::CHIPS,
            'origenes'  => LeadService::ORIGENES,
            'mcEstados' => MailchimpConsulta::ESTADOS,
            'hoy'       => (new \DateTimeImmutable('now', new \DateTimeZone(Zona::agencia())))->format('Y-m-d'),
            'exportar'  => '/portal/contactos-ganados/exportar' . (($q = http_build_query(array_filter(['proyecto' => $f['proyecto_id'], 'estado' => $f['estado'], 'campana' => $f['campana'], 'q' => $f['q']]))) !== '' ? '?' . $q : ''),
        ]);
    }

    /** Descarga la lista (con los filtros que estén puestos) en CSV. */
    public function exportar(): void
    {
        $c = $this->requerirAcceso();
        $svc = $this->leads();
        $f = $this->filtros($svc->proyectosDe((string) $c['cliente_id']));
        $csv = LeadService::csv($svc->lista(['cliente_id' => (string) $c['cliente_id']] + $f));
        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="contactos-ganados-' . date('Y-m-d') . '.csv"');
            header('Cache-Control: no-store');
        }
        echo $csv;
        $this->terminate();
    }

    /** El lead del cliente, para anonimizar o eliminar (con CSRF y fuera de vista previa). */
    private function leadParaBorrar(array $c, string $id): ?array
    {
        $this->exigirCsrf('portal/contactos-ganados/' . $id);
        $l = $this->leads()->delCliente($id, (string) $c['cliente_id']);
        if ($l === null || $this->leads()->proyecto((string) $l['proyecto_id']) === null) {
            PortalSession::flash('error', 'No encontramos ese contacto.');
            $this->redirectTo('portal/contactos-ganados');
            return null;
        }
        return $l;
    }

    public function anonimizar(string $id): void
    {
        $c = $this->requerirAcceso();
        if ($this->leadParaBorrar($c, $id) === null) {
            return;
        }
        $this->leads()->anonimizar($id);
        PortalSession::flash('ok', 'Listo: se borraron sus datos personales. Queda sólo para los conteos.');
        $this->redirectTo('portal/contactos-ganados/' . $id);
    }

    public function eliminar(string $id): void
    {
        $c = $this->requerirAcceso();
        if ($this->leadParaBorrar($c, $id) === null) {
            return;
        }
        $this->leads()->eliminar($id);
        PortalSession::flash('ok', 'Contacto eliminado.');
        $this->redirectTo('portal/contactos-ganados');
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
        $svc = $this->leads();
        $conMc = $svc->mailchimp((string) $l['proyecto_id']) !== null;
        $mcError = null;
        if ($conMc && LeadService::mcVencido($l) && (string) $l['correo'] !== '' && empty($l['anonimizado_en'])) {
            $mcError = $svc->revisarMailchimp($l);
            $l = $svc->delCliente($id, (string) $c['cliente_id']) ?? $l;
        }
        $this->ctx->view('templates/public/lead.latte', $this->contexto($c, 'leads') + [
            'l'        => $l,
            'mc'       => LeadService::mc($l),
            'conMc'    => $conMc,
            'mcError'  => $mcError,
            'mcEventos' => MailchimpConsulta::EVENTOS,
            'estados'  => LeadService::ESTADOS,
            'chips'    => LeadService::CHIPS,
            'origenes' => LeadService::ORIGENES,
        ]);
    }

    /** «Revisar ahora» en Mailchimp, desde la ficha. */
    public function revisarMc(string $id): void
    {
        $c = $this->requerirAcceso();
        $this->exigirCsrf('portal/contactos-ganados/' . $id);
        $l = $this->leads()->delCliente($id, (string) $c['cliente_id']);
        if ($l === null || $this->leads()->proyecto((string) $l['proyecto_id']) === null) {
            PortalSession::flash('error', 'No encontramos ese contacto.');
            $this->redirectTo('portal/contactos-ganados');
            return;
        }
        $error = $this->leads()->revisarMailchimp($l);
        PortalSession::flash($error === null ? 'ok' : 'error', $error ?? 'Revisado en Mailchimp.');
        $this->redirectTo('portal/contactos-ganados/' . $id);
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

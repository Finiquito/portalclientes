<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

use TypeDock\Core\PluginContext;

/**
 * Contactos ganados del lado de la agencia (admin de TypeDock y panel /equipo):
 * el consolidado por campaña, la lista con filtros, la ficha de cada contacto y el
 * ingreso manual. Cada persona ve sólo los proyectos que tiene asignados.
 */
class LeadAdminController
{
    protected readonly Pantalla $ui;

    public function __construct(protected readonly PluginContext $ctx, ?Pantalla $ui = null)
    {
        $this->ui = $ui ?? new PantallaAdmin($ctx);
    }

    private function pdo(): \PDO
    {
        return $this->ctx->db()->pdo();
    }

    private function service(): LeadService
    {
        return new LeadService($this->pdo());
    }

    /** @return array<int, array<string, mixed>> proyectos con el módulo activo que ve esta persona */
    private function proyectos(): array
    {
        return $this->ui->filtrar($this->service()->proyectosConLeads(), 'id');
    }

    public function index(): void
    {
        $proyectos = $this->proyectos();
        $f = FiltrosLista::desdeGet($this->ui->url('leads'), ['estado' => '', 'proyecto' => '', 'q' => ''], [
            'estado'   => array_keys(LeadService::ESTADOS),
            'proyecto' => 'uuid',
            'q'        => 'texto',
        ]);
        $svc = $this->service();
        $base = $this->ui->filtrar($svc->lista(['proyecto_id' => $f->get('proyecto'), 'q' => $f->get('q')]), 'proyecto_id');
        $leads = $f->get('estado') === '' ? $base : array_values(array_filter($base, fn(array $l): bool => $l['estado'] === $f->get('estado')));

        $this->ui->view('leads/index.latte', [
            'leads'       => $leads,
            'porCampana'  => LeadService::porCampana($base),
            'conteo'      => LeadService::conteo($base),
            'total'       => count($base),
            'filtros'     => $f,
            'proyectos'   => $proyectos,
            'estados'     => LeadService::ESTADOS,
            'chips'       => LeadService::CHIPS_ADMIN,
            'origenes'    => LeadService::ORIGENES,
            'colorProy'   => Fmt::coloresTodos($this->pdo()),
            'fmt'         => new Fmt(),
            'hoy'         => Cronograma::hoy(),
            'flash_success' => $this->ui->flash('success'),
            'flash_error'   => $this->ui->flash('error'),
        ]);
    }

    public function create(): void
    {
        $proyectos = $this->proyectos();
        if ($proyectos === []) {
            $this->ui->redirect($this->ui->url('leads'), 'Primero activa «Con contactos ganados» en un proyecto.', 'error');
            return;
        }
        $this->ui->view('leads/edit.latte', [
            'l'         => null,
            'proyectos' => $proyectos,
            'pre'       => ['proyecto_id' => (string) ($_GET['proyecto'] ?? '')],
            'estados'   => LeadService::ESTADOS,
            'chips'     => LeadService::CHIPS_ADMIN,
            'origenes'  => LeadService::ORIGENES,
            'fmt'       => new Fmt(),
        ]);
    }

    public function store(): void
    {
        $svc = $this->service();
        $pid = (string) ($_POST['proyecto_id'] ?? '');
        $p = in_array($pid, array_column($this->proyectos(), 'id'), true) ? $svc->proyecto($pid) : null;
        if ($p === null) {
            $this->ui->redirect($this->ui->url('leads/nuevo'), 'Elige un proyecto con contactos ganados.', 'error');
            return;
        }
        $r = $svc->crear($p, $_POST, 'manual', $this->ui->firma());
        if (isset($r['error'])) {
            $this->ui->redirect($this->ui->url('leads/nuevo') . '?proyecto=' . rawurlencode($pid), $r['error'], 'error');
            return;
        }
        $this->ui->redirect($this->ui->url('leads/' . $r['id']), !empty($r['duplicado'])
            ? 'Ese contacto ya estaba (mismo correo o teléfono en los últimos ' . LeadService::DIAS_DUPLICADO . ' días).'
            : 'Contacto agregado. El cliente lo ve en su portal.');
    }

    /** El lead, si su proyecto sigue con el módulo activo y esta persona lo ve. */
    private function lead(string $id): ?array
    {
        $l = $this->service()->find($id);
        if ($l === null || $this->service()->proyecto((string) $l['proyecto_id']) === null) {
            return null;
        }
        return $this->ui->filtrar([$l], 'proyecto_id') !== [] ? $l : null;
    }

    public function ver(string $id): void
    {
        $l = $this->lead($id);
        if ($l === null) {
            $this->ui->redirect($this->ui->url('leads'), 'Contacto no encontrado.', 'error');
            return;
        }
        $this->ui->view('leads/edit.latte', [
            'l'         => $l,
            'proyectos' => [],
            'pre'       => [],
            'estados'   => LeadService::ESTADOS,
            'chips'     => LeadService::CHIPS_ADMIN,
            'origenes'  => LeadService::ORIGENES,
            'fmt'       => new Fmt(),
            'flash_success' => $this->ui->flash('success'),
            'flash_error'   => $this->ui->flash('error'),
        ]);
    }

    public function update(string $id): void
    {
        $l = $this->lead($id);
        if ($l === null) {
            $this->ui->redirect($this->ui->url('leads'), 'Contacto no encontrado.', 'error');
            return;
        }
        $datos = array_intersect_key($_POST, array_flip(['nombre', 'correo', 'telefono', 'empresa', 'estado', 'notas', 'proximo_paso', 'proximo_paso_fecha']));
        $error = $this->service()->actualizar($l, $datos, $this->ui->firma());
        $this->ui->redirect($this->ui->url('leads/' . $id), $error ?? 'Guardado.', $error === null ? 'success' : 'error');
    }
}

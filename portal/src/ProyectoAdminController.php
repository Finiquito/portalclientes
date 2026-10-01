<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

use TypeDock\Core\PluginContext;

class ProyectoAdminController
{
    protected readonly Pantalla $ui;

    public function __construct(private readonly PluginContext $ctx, ?Pantalla $ui = null)
    {
        $this->ui = $ui ?? new PantallaAdmin($ctx);
    }

    private function service(): ProyectoService
    {
        return new ProyectoService($this->ctx->db()->pdo());
    }

    private function clientes(): array
    {
        return $this->ui->filtrar((new ClienteService($this->ctx->db()->pdo()))->listAll(), 'id', 'cliente');
    }

    public function index(): void
    {
        $this->ui->view('proyectos/index.latte', [
            'proyectos'     => $this->ui->filtrar($this->service()->listAll(), 'id'),
            'flash_success' => $this->ui->flash('success'),
            'flash_error'   => $this->ui->flash('error'),
        ]);
    }

    public function create(): void
    {
        $clientes = $this->clientes();
        if ($clientes === []) {
            $this->ui->redirect($this->ui->url(), 'Crea un cliente primero.', 'error');
            return;
        }
        $this->ui->view('proyectos/edit.latte', [
            'proyecto' => null,
            'clientes' => $clientes,
        ]);
    }

    public function store(): void
    {
        $this->service()->create($_POST);
        $this->ui->redirect($this->ui->url('proyectos'), 'Proyecto creado.');
    }

    public function edit(string $id): void
    {
        $proyecto = $this->service()->find($id);
        if ($proyecto === null) {
            $this->ui->redirect($this->ui->url('proyectos'), 'Proyecto no encontrado.', 'error');
            return;
        }
        $this->ui->view('proyectos/edit.latte', [
            'proyecto' => $proyecto,
            'clientes' => $this->clientes(),
        ]);
    }

    public function update(string $id): void
    {
        $this->service()->update($id, $_POST);
        $this->ui->redirect($this->ui->url('proyectos'), 'Proyecto actualizado.');
    }

    public function destroy(string $id): void
    {
        // Limpieza de lo que las FK no alcanzan: comentarios y binarios de sus tareas.
        $pdo = $this->ctx->db()->pdo();
        (new EntregaService($pdo))->borrarDeProyecto($id);
        (new SolicitudService($pdo))->borrarDeProyecto($id);
        (new ComentarioService($pdo))->borrarDeProyecto($id);
        (new ArchivoService($pdo))->borrarFisicosDeProyecto($id);
        $this->service()->delete($id);
        $this->ui->redirect($this->ui->url('proyectos'), 'Proyecto eliminado.');
    }
}

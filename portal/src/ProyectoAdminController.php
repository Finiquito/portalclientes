<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

use TypeDock\Core\PluginContext;

class ProyectoAdminController
{
    public function __construct(private readonly PluginContext $ctx) {}

    private function service(): ProyectoService
    {
        return new ProyectoService($this->ctx->db()->pdo());
    }

    private function clientes(): array
    {
        return (new ClienteService($this->ctx->db()->pdo()))->listAll();
    }

    public function index(): void
    {
        $this->ctx->view('templates/admin/proyectos/index.latte', [
            'proyectos'     => $this->service()->listAll(),
            'flash_success' => $this->ctx->getFlash('success'),
            'flash_error'   => $this->ctx->getFlash('error'),
        ]);
    }

    public function create(): void
    {
        $clientes = $this->clientes();
        if ($clientes === []) {
            $this->ctx->redirect($this->ctx->adminUrl(), 'Crea un cliente primero.', 'error');
            return;
        }
        $this->ctx->view('templates/admin/proyectos/edit.latte', [
            'proyecto' => null,
            'clientes' => $clientes,
        ]);
    }

    public function store(): void
    {
        $this->service()->create($_POST);
        $this->ctx->redirect($this->ctx->adminUrl('proyectos'), 'Proyecto creado.');
    }

    public function edit(string $id): void
    {
        $proyecto = $this->service()->find($id);
        if ($proyecto === null) {
            $this->ctx->redirect($this->ctx->adminUrl('proyectos'), 'Proyecto no encontrado.', 'error');
            return;
        }
        $this->ctx->view('templates/admin/proyectos/edit.latte', [
            'proyecto' => $proyecto,
            'clientes' => $this->clientes(),
        ]);
    }

    public function update(string $id): void
    {
        $this->service()->update($id, $_POST);
        $this->ctx->redirect($this->ctx->adminUrl('proyectos'), 'Proyecto actualizado.');
    }

    public function destroy(string $id): void
    {
        // Limpieza de lo que las FK no alcanzan: comentarios y binarios de sus tareas.
        $pdo = $this->ctx->db()->pdo();
        (new EntregaService($pdo))->borrarDeProyecto($id);
        (new ComentarioService($pdo))->borrarDeProyecto($id);
        (new ArchivoService($pdo))->borrarFisicosDeProyecto($id);
        $this->service()->delete($id);
        $this->ctx->redirect($this->ctx->adminUrl('proyectos'), 'Proyecto eliminado.');
    }
}

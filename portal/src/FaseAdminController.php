<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

use TypeDock\Core\PluginContext;

class FaseAdminController
{
    public function __construct(private readonly PluginContext $ctx) {}

    private function service(): FaseService
    {
        return new FaseService($this->ctx->db()->pdo());
    }

    private function proyectos(): array
    {
        return (new ProyectoService($this->ctx->db()->pdo()))->listAll();
    }

    public function index(): void
    {
        $this->ctx->view('templates/admin/fases/index.latte', [
            'fases'         => $this->service()->listAll(),
            'flash_success' => $this->ctx->getFlash('success'),
            'flash_error'   => $this->ctx->getFlash('error'),
        ]);
    }

    public function create(): void
    {
        $proyectos = $this->proyectos();
        if ($proyectos === []) {
            $this->ctx->redirect($this->ctx->adminUrl('fases'), 'Crea un proyecto primero.', 'error');
            return;
        }
        $this->ctx->view('templates/admin/fases/edit.latte', [
            'fase'      => null,
            'proyectos' => $proyectos,
        ]);
    }

    public function store(): void
    {
        $this->service()->create($_POST);
        $this->ctx->redirect($this->ctx->adminUrl('fases'), 'Fase creada.');
    }

    public function edit(string $id): void
    {
        $fase = $this->service()->find($id);
        if ($fase === null) {
            $this->ctx->redirect($this->ctx->adminUrl('fases'), 'Fase no encontrada.', 'error');
            return;
        }
        $this->ctx->view('templates/admin/fases/edit.latte', [
            'fase'      => $fase,
            'proyectos' => $this->proyectos(),
        ]);
    }

    public function update(string $id): void
    {
        $this->service()->update($id, $_POST);
        $this->ctx->redirect($this->ctx->adminUrl('fases'), 'Fase actualizada.');
    }

    public function destroy(string $id): void
    {
        $this->service()->delete($id);
        $this->ctx->redirect($this->ctx->adminUrl('fases'), 'Fase eliminada.');
    }
}

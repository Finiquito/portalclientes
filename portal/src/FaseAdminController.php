<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

use TypeDock\Core\PluginContext;

class FaseAdminController
{
    protected readonly Pantalla $ui;

    public function __construct(private readonly PluginContext $ctx, ?Pantalla $ui = null)
    {
        $this->ui = $ui ?? new PantallaAdmin($ctx);
    }

    private function service(): FaseService
    {
        return new FaseService($this->ctx->db()->pdo());
    }

    private function proyectos(): array
    {
        return $this->ui->filtrar((new ProyectoService($this->ctx->db()->pdo()))->listAll(), 'id');
    }

    public function index(): void
    {
        $this->ui->view('fases/index.latte', [
            'fases'         => $this->ui->filtrar($this->service()->listAll(), 'proyecto_id'),
            'flash_success' => $this->ui->flash('success'),
            'flash_error'   => $this->ui->flash('error'),
        ]);
    }

    public function create(): void
    {
        $proyectos = $this->proyectos();
        if ($proyectos === []) {
            $this->ui->redirect($this->ui->url('fases'), 'Crea un proyecto primero.', 'error');
            return;
        }
        $this->ui->view('fases/edit.latte', [
            'fase'      => null,
            'proyectos' => $proyectos,
        ]);
    }

    public function store(): void
    {
        $this->service()->create($_POST);
        $this->ui->redirect($this->ui->url('fases'), 'Fase creada.');
    }

    public function edit(string $id): void
    {
        $fase = $this->service()->find($id);
        if ($fase === null) {
            $this->ui->redirect($this->ui->url('fases'), 'Fase no encontrada.', 'error');
            return;
        }
        $this->ui->view('fases/edit.latte', [
            'fase'      => $fase,
            'proyectos' => $this->proyectos(),
        ]);
    }

    public function update(string $id): void
    {
        $this->service()->update($id, $_POST);
        $this->ui->redirect($this->ui->url('fases'), 'Fase actualizada.');
    }

    public function destroy(string $id): void
    {
        $this->service()->delete($id);
        $this->ui->redirect($this->ui->url('fases'), 'Fase eliminada.');
    }
}

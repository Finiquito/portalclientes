<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

use TypeDock\Core\PluginContext;

/** Plantillas de proyecto: verlas, renombrarlas y borrarlas. Se crean desde la línea de tiempo de un proyecto. */
class PlantillaAdminController
{
    private Pantalla $ui;

    public function __construct(private readonly PluginContext $ctx, ?Pantalla $ui = null)
    {
        $this->ui = $ui ?? new PantallaAdmin($ctx);
    }

    private function service(): PlantillaService
    {
        return new PlantillaService($this->ctx->db()->pdo());
    }

    public function index(): void
    {
        $this->ui->view('plantillas/index.latte', [
            'plantillas'    => $this->service()->lista(),
            'flash_success' => $this->ui->flash('success'),
            'flash_error'   => $this->ui->flash('error'),
        ]);
    }

    public function renombrar(string $id): void
    {
        $this->service()->renombrar($id, (string) ($_POST['nombre'] ?? ''), (string) ($_POST['descripcion'] ?? ''));
        $this->ui->redirect($this->ui->url('plantillas'), 'Plantilla actualizada.');
    }

    public function borrar(string $id): void
    {
        $this->service()->borrar($id);
        $this->ui->redirect($this->ui->url('plantillas'), 'Plantilla eliminada. Los proyectos que se armaron con ella no cambian.');
    }
}

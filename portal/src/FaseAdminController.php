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
        return $this->ui->filtrar((new ProyectoService($this->ctx->db()->pdo()))->porMovimiento(), 'id');
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
            'enFase'    => $this->enFase($fase),
            'fmt'       => new Fmt(),
            'flash_success' => $this->ui->flash('success'),
            'flash_error'   => $this->ui->flash('error'),
        ]);
    }

    /**
     * Lo que cuelga de la fase: tareas, hitos, reuniones y entregas (resumen de la etapa).
     * @param array<string, mixed> $fase
     * @return array<string, list<array<string, mixed>>>
     */
    private function enFase(array $fase): array
    {
        Schema::asegurar($this->ctx->db()->pdo());
        $q = function (string $sql) use ($fase): array {
            $st = $this->ctx->db()->pdo()->prepare($sql);
            $st->execute([(string) $fase['id']]);
            return $st->fetchAll();
        };
        return [
            'tareas'    => $q('SELECT id, titulo, estado, fecha_inicio, fecha_vencimiento FROM portal_tareas WHERE fase_id = ? AND (archivada = 0 OR archivada IS NULL) ORDER BY COALESCE(fecha_inicio, fecha_vencimiento), titulo'),
            'hitos'     => $q('SELECT id, nombre, fecha, cumplido_en FROM portal_hitos WHERE fase_id = ? ORDER BY fecha'),
            'reuniones' => $q('SELECT id, titulo, fecha, es_hito FROM portal_reuniones WHERE fase_id = ? ORDER BY fecha'),
            'entregas'  => $q('SELECT id, titulo, fecha_limite, estado FROM portal_entregas WHERE fase_id = ? ORDER BY fecha_limite'),
        ];
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

<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

use TypeDock\Core\PluginContext;

class ContactoAdminController
{
    protected readonly Pantalla $ui;

    public function __construct(private readonly PluginContext $ctx, ?Pantalla $ui = null)
    {
        $this->ui = $ui ?? new PantallaAdmin($ctx);
    }

    private function service(): ContactoService
    {
        return new ContactoService($this->ctx->db()->pdo());
    }

    private function clientes(): array
    {
        return $this->ui->filtrar((new ClienteService($this->ctx->db()->pdo()))->listAll(), 'id', 'cliente');
    }

    public function index(): void
    {
        $this->ui->view('contactos/index.latte', [
            'contactos'     => $this->ui->filtrar($this->service()->listAll(), 'cliente_id', 'cliente'),
            'flash_success' => $this->ui->flash('success'),
            'flash_error'   => $this->ui->flash('error'),
        ]);
    }

    public function create(): void
    {
        $clientes = $this->clientes();
        if ($clientes === []) {
            $this->ui->redirect($this->ui->url('contactos'), 'Crea un cliente primero.', 'error');
            return;
        }
        $this->ui->view('contactos/edit.latte', [
            'contacto' => null,
            'clientes' => $clientes,
        ]);
    }

    public function store(): void
    {
        $existente = $this->service()->findByEmail((string) ($_POST['email'] ?? ''));
        if ($existente !== null) {
            $this->ui->redirect($this->ui->url('contactos'), 'Ya existe un contacto con ese email.', 'error');
            return;
        }
        $this->service()->create($_POST);
        $this->ui->redirect($this->ui->url('contactos'), 'Contacto creado.');
    }

    public function edit(string $id): void
    {
        $contacto = $this->service()->find($id);
        if ($contacto === null) {
            $this->ui->redirect($this->ui->url('contactos'), 'Contacto no encontrado.', 'error');
            return;
        }
        $this->ui->view('contactos/edit.latte', [
            'contacto' => $contacto,
            'clientes' => $this->clientes(),
        ]);
    }

    public function update(string $id): void
    {
        $this->service()->update($id, $_POST);
        $this->ui->redirect($this->ui->url('contactos'), 'Contacto actualizado.');
    }

    public function destroy(string $id): void
    {
        (new AjustesService($this->ctx->db()->pdo()))->borrarDeDueno('contacto', $id);
        $this->service()->delete($id);
        $this->ui->redirect($this->ui->url('contactos'), 'Contacto eliminado.');
    }
}

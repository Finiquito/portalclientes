<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

use TypeDock\Core\PluginContext;

class ContactoAdminController
{
    public function __construct(private readonly PluginContext $ctx) {}

    private function service(): ContactoService
    {
        return new ContactoService($this->ctx->db()->pdo());
    }

    private function clientes(): array
    {
        return (new ClienteService($this->ctx->db()->pdo()))->listAll();
    }

    public function index(): void
    {
        $this->ctx->view('templates/admin/contactos/index.latte', [
            'contactos'     => $this->service()->listAll(),
            'flash_success' => $this->ctx->getFlash('success'),
            'flash_error'   => $this->ctx->getFlash('error'),
        ]);
    }

    public function create(): void
    {
        $clientes = $this->clientes();
        if ($clientes === []) {
            $this->ctx->redirect($this->ctx->adminUrl('contactos'), 'Crea un cliente primero.', 'error');
            return;
        }
        $this->ctx->view('templates/admin/contactos/edit.latte', [
            'contacto' => null,
            'clientes' => $clientes,
        ]);
    }

    public function store(): void
    {
        $existente = $this->service()->findByEmail((string) ($_POST['email'] ?? ''));
        if ($existente !== null) {
            $this->ctx->redirect($this->ctx->adminUrl('contactos'), 'Ya existe un contacto con ese email.', 'error');
            return;
        }
        $this->service()->create($_POST);
        $this->ctx->redirect($this->ctx->adminUrl('contactos'), 'Contacto creado.');
    }

    public function edit(string $id): void
    {
        $contacto = $this->service()->find($id);
        if ($contacto === null) {
            $this->ctx->redirect($this->ctx->adminUrl('contactos'), 'Contacto no encontrado.', 'error');
            return;
        }
        $this->ctx->view('templates/admin/contactos/edit.latte', [
            'contacto' => $contacto,
            'clientes' => $this->clientes(),
        ]);
    }

    public function update(string $id): void
    {
        $this->service()->update($id, $_POST);
        $this->ctx->redirect($this->ctx->adminUrl('contactos'), 'Contacto actualizado.');
    }

    public function destroy(string $id): void
    {
        (new AjustesService($this->ctx->db()->pdo()))->borrarDeDueno('contacto', $id);
        $this->service()->delete($id);
        $this->ctx->redirect($this->ctx->adminUrl('contactos'), 'Contacto eliminado.');
    }
}

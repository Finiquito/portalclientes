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
            'fmt'           => new Fmt(),
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
            'firma'    => $this->ui->firma(),
        ]);
    }

    public function store(): void
    {
        $existente = $this->service()->findByEmail((string) ($_POST['email'] ?? ''));
        if ($existente !== null) {
            $this->ui->redirect($this->ui->url('contactos'), 'Ya existe un contacto con ese email.', 'error');
            return;
        }
        $id = $this->service()->create($_POST);
        if (!empty($_POST['invitar'])) {
            $ok = $this->enviarInvitacion($id, (string) ($_POST['mensaje'] ?? ''));
            $this->ui->redirect($this->ui->url('contactos/' . $id), $ok ? 'Contacto creado y la invitación va en camino.' : 'Contacto creado, pero la invitación no salió: revisa el correo en Portal · Ajustes.', $ok ? 'success' : 'error');
            return;
        }
        $this->ui->redirect($this->ui->url('contactos/' . $id), 'Contacto creado. Cuando su portal tenga algo que mostrar, envíale la invitación desde aquí.');
    }

    /** Enviar (o reenviar) la invitación al portal, con un mensaje opcional. */
    public function invitar(string $id): void
    {
        if ($this->service()->find($id) === null) {
            $this->ui->redirect($this->ui->url('contactos'), 'Contacto no encontrado.', 'error');
            return;
        }
        $ok = $this->enviarInvitacion($id, (string) ($_POST['mensaje'] ?? ''));
        $this->ui->redirect($this->ui->url('contactos/' . $id), $ok ? 'Invitación enviada.' : 'La invitación no salió: revisa el correo en Portal · Ajustes.', $ok ? 'success' : 'error');
    }

    /** «Ver como cliente» desde el admin de TypeDock (sólo lectura). */
    public function verComo(string $id): void
    {
        $c = $this->service()->find($id);
        if ($c === null) {
            $this->ui->redirect($this->ui->url('contactos'), 'Contacto no encontrado.', 'error');
            return;
        }
        PortalSession::iniciarVistaPrevia($id, $this->ui->firma(), $this->ui->url('contactos'));
        $this->ui->redirect('/portal');
    }

    private function enviarInvitacion(string $id, string $mensaje): bool
    {
        $c = $this->service()->find($id);
        if ($c === null) {
            return false;
        }
        try {
            $ok = (new Notifier($this->ctx, $this->ctx->db()->pdo()))->invitacionCliente($c, mb_substr($mensaje, 0, 1500), $this->ui->firma());
        } catch (\Throwable) {
            $ok = false;
        }
        if ($ok) {
            $this->service()->marcarInvitado($id);
        }
        return $ok;
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
            'fmt'      => new Fmt(),
            'firma'    => $this->ui->firma(),
            'flash_success' => $this->ui->flash('success'),
            'flash_error'   => $this->ui->flash('error'),
        ]);
    }

    public function update(string $id): void
    {
        $this->service()->update($id, $_POST);
        $this->ui->redirect($this->ui->url('contactos/' . $id), 'Contacto actualizado.');
    }

    public function destroy(string $id): void
    {
        (new AjustesService($this->ctx->db()->pdo()))->borrarDeDueno('contacto', $id);
        $this->service()->delete($id);
        $this->ui->redirect($this->ui->url('contactos'), 'Contacto eliminado.');
    }
}

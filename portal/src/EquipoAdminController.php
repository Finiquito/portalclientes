<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

use TypeDock\Core\PluginContext;

/**
 * Personas de la agencia y qué clientes/proyectos ven. Sirve al admin de TypeDock
 * (Portal · Equipo) y al panel de equipo (/equipo/personas, solo Coordinación).
 */
class EquipoAdminController
{
    protected readonly Pantalla $ui;

    public function __construct(private readonly PluginContext $ctx, ?Pantalla $ui = null)
    {
        $this->ui = $ui ?? new PantallaAdmin($ctx);
    }

    private function pdo(): \PDO
    {
        return $this->ctx->db()->pdo();
    }

    private function service(): EquipoService
    {
        return new EquipoService($this->pdo());
    }

    /** @return array<int, array<string, mixed>> Clientes con sus proyectos, para las casillas de asignación. */
    private function arbol(): array
    {
        $clientes = (new ClienteService($this->pdo()))->listAll();
        $proys = $this->pdo()->query('SELECT id, cliente_id, nombre, estado FROM portal_proyectos ORDER BY created_at, id')->fetchAll();
        foreach ($clientes as &$c) {
            $c['proyectos'] = array_values(array_filter($proys, fn(array $p): bool => $p['cliente_id'] === $c['id']));
        }
        return $clientes;
    }

    public function index(): void
    {
        $this->ui->view('equipo/index.latte', [
            'usuarios'      => $this->service()->listAll(),
            've'            => $this->service()->resumenAsignaciones(),
            'fmt'           => new Fmt(),
            'roles'         => EquipoService::ROLES,
            'flash_success' => $this->ui->flash('success'),
            'flash_error'   => $this->ui->flash('error'),
        ]);
    }

    public function create(): void
    {
        $this->form(null);
    }

    public function edit(string $id): void
    {
        $u = $this->service()->find($id);
        if ($u === null) {
            $this->ui->redirect($this->ui->url('equipo'), 'Usuario no encontrado.', 'error');
            return;
        }
        $this->form($u);
    }

    /** @param array<string, mixed>|null $u */
    private function form(?array $u): void
    {
        $asig = $u !== null ? $this->service()->asignaciones((string) $u['id']) : [];
        $this->ui->view('equipo/edit.latte', [
            'persona'      => $u,
            'roles'        => EquipoService::ROLES,
            'arbol'        => $this->arbol(),
            'clientesFull' => array_values(array_map(fn($a) => $a['cliente_id'], array_filter($asig, fn($a) => $a['proyecto_id'] === null))),
            'proyAsig'     => array_values(array_filter(array_map(fn($a) => $a['proyecto_id'], $asig))),
            'flash_success' => $this->ui->flash('success'),
            'flash_error'   => $this->ui->flash('error'),
        ]);
    }

    /** @return array{0: array<int, string>, 1: array<int, string>} */
    private function asignacionesPost(): array
    {
        $c = array_map('strval', (array) ($_POST['clientes'] ?? []));
        $p = array_map('strval', (array) ($_POST['proyectos'] ?? []));
        return [$c, $p];
    }

    public function store(): void
    {
        $datos = $_POST + ['activo' => 1];
        if (($err = $this->service()->error($datos)) !== null) {
            $this->ui->redirect($this->ui->url('equipo/nuevo'), $err, 'error');
            return;
        }
        $id = $this->service()->create($datos);
        [$c, $p] = $this->asignacionesPost();
        $this->service()->guardarAsignaciones($id, $c, $p);
        $msg = 'Listo: ' . trim((string) ($datos['nombre'] ?? '')) . ' ya es parte del equipo.';
        if (!empty($_POST['invitar'])) {
            $msg .= $this->invitar($id) ? ' Le enviamos la invitación por correo.' : ' No se pudo enviar la invitación (revisa el correo en Ajustes).';
        }
        $this->ui->redirect($this->ui->url('equipo/' . $id), $msg);
    }

    public function update(string $id): void
    {
        if ($this->service()->find($id) === null) {
            $this->ui->redirect($this->ui->url('equipo'), 'Usuario no encontrado.', 'error');
            return;
        }
        if (($err = $this->service()->error($_POST, $id)) !== null) {
            $this->ui->redirect($this->ui->url('equipo/' . $id), $err, 'error');
            return;
        }
        // Nadie se deja fuera a sí mismo (perdería el acceso a esta misma pantalla).
        if ($id === $this->ui->autorId() && (($_POST['rol'] ?? '') !== 'coordinador' || empty($_POST['activo']))) {
            $this->ui->redirect($this->ui->url('equipo/' . $id), 'No puedes quitarte Coordinación ni desactivarte a ti misma/o. Pídeselo a otra persona de Coordinación.', 'error');
            return;
        }
        $this->service()->update($id, $_POST);
        [$c, $p] = $this->asignacionesPost();
        $this->service()->guardarAsignaciones($id, $c, $p);
        $this->ui->redirect($this->ui->url('equipo/' . $id), 'Cambios guardados.');
    }

    public function destroy(string $id): void
    {
        if ($id === $this->ui->autorId()) {
            $this->ui->redirect($this->ui->url('equipo/' . $id), 'No puedes eliminarte a ti misma/o.', 'error');
            return;
        }
        (new AjustesService($this->pdo()))->borrarDeDueno('equipo', $id);
        $this->service()->delete($id);
        $this->ui->redirect($this->ui->url('equipo'), 'Usuario eliminado. Sus comentarios y tareas se conservan.');
    }

    public function invitarPost(string $id): void
    {
        $ok = $this->invitar($id);
        $this->ui->redirect(
            $this->ui->url('equipo/' . $id),
            $ok ? 'Invitación enviada.' : 'No se pudo enviar la invitación (revisa el correo en Ajustes).',
            $ok ? 'success' : 'error'
        );
    }

    private function invitar(string $id): bool
    {
        $u = $this->service()->find($id);
        if ($u === null || (int) $u['activo'] !== 1) {
            return false;
        }
        try {
            return (new Notifier($this->ctx, $this->pdo()))->invitacionEquipo($u);
        } catch (\Throwable $e) {
            error_log('[portal] invitación equipo: ' . $e->getMessage());
            return false;
        }
    }
}

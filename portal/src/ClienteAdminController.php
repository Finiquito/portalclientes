<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

use TypeDock\Core\PluginContext;

class ClienteAdminController
{
    private const LOGO_EXT = ['png', 'jpg', 'jpeg', 'webp'];
    private const LOGO_MAX = 2 * 1048576;

    protected readonly Pantalla $ui;

    public function __construct(private readonly PluginContext $ctx, ?Pantalla $ui = null)
    {
        $this->ui = $ui ?? new PantallaAdmin($ctx);
    }

    private function pdo(): \PDO
    {
        return $this->ctx->db()->pdo();
    }

    private function service(): ClienteService
    {
        return new ClienteService($this->pdo());
    }

    private function ajustes(): AjustesService
    {
        return new AjustesService($this->pdo());
    }

    public function index(): void
    {
        $this->ui->view('clientes/index.latte', [
            'clientes'      => $this->ui->filtrar($this->service()->listAll(), 'id', 'cliente'),
            'flash_success' => $this->ui->flash('success'),
            'flash_error'   => $this->ui->flash('error'),
        ]);
    }

    public function create(): void
    {
        $this->ui->view('clientes/edit.latte', [
            'cliente'  => null,
            // Personas que pueden quedar a cargo desde el alta (Coordinación ya ve todo).
            'personas' => array_values(array_filter((new EquipoService($this->pdo()))->activos(), fn($p) => $p['rol'] !== 'coordinador')),
        ]);
    }

    /**
     * Alta en un paso: el cliente y, si se completan, su primer proyecto, su primer contacto
     * (con invitación ahora o más tarde) y las personas del equipo que lo llevan.
     */
    public function store(): void
    {
        $contactoEmail = trim(strtolower((string) ($_POST['contacto_email'] ?? '')));
        if ($contactoEmail !== '' && filter_var($contactoEmail, FILTER_VALIDATE_EMAIL) === false) {
            $this->ui->redirect($this->ui->url('clientes/nuevo'), 'El correo del contacto no es válido.', 'error');
            return;
        }
        if ($contactoEmail !== '' && (new ContactoService($this->pdo()))->findByEmail($contactoEmail) !== null) {
            $this->ui->redirect($this->ui->url('clientes/nuevo'), 'Ya existe un contacto con el correo ' . $contactoEmail . '. Usa otro o agrégalo después desde el cliente.', 'error');
            return;
        }
        $id = $this->service()->create($_POST);
        $hechos = [];

        $proyecto = trim((string) ($_POST['proyecto_nombre'] ?? ''));
        if ($proyecto !== '') {
            (new ProyectoService($this->pdo()))->create(['cliente_id' => $id, 'nombre' => $proyecto, 'estado' => 'activo']);
            $hechos[] = 'el proyecto «' . $proyecto . '»';
        }

        $aviso = '';
        if ($contactoEmail !== '') {
            $cs = new ContactoService($this->pdo());
            $cid = $cs->create([
                'cliente_id' => $id,
                'nombre'     => trim((string) ($_POST['contacto_nombre'] ?? '')) ?: $contactoEmail,
                'email'      => $contactoEmail,
                'rol'        => ($_POST['contacto_rol'] ?? '') === 'viewer' ? 'viewer' : 'aprobador',
            ]);
            $hechos[] = 'el contacto ' . $contactoEmail;
            if (($_POST['invitar'] ?? '') === 'ahora') {
                try {
                    $ok = (new Notifier($this->ctx, $this->pdo()))->invitacionCliente((array) $cs->find($cid), '', $this->ui->firma());
                } catch (\Throwable) {
                    $ok = false;
                }
                if ($ok) {
                    $cs->marcarInvitado($cid);
                    $hechos[] = 'la invitación ya va en camino';
                } else {
                    $aviso = ' La invitación no salió: revisa el correo en los ajustes.';
                }
            }
        }

        $eq = new EquipoService($this->pdo());
        $nombres = [];
        foreach ((array) ($_POST['personas'] ?? []) as $pid) {
            $p = $eq->find((string) $pid);
            if ($p !== null && (int) $p['activo'] === 1) {
                $eq->asignar((string) $p['id'], $id);
                $nombres[] = (string) $p['nombre'];
            }
        }
        if ($nombres !== []) {
            $hechos[] = 'a cargo de ' . implode(' y ', $nombres);
        }

        $msg = 'Cliente creado' . ($hechos !== [] ? ' con ' . implode(', ', $hechos) : '') . '.' . $aviso;
        // En el panel se va a la ficha del cliente; en el admin, a su edición (ahí se personaliza el portal).
        $this->ui->redirect($this->ui->url('clientes') . '/' . $id, $msg, $aviso === '' ? 'success' : 'error');
    }

    public function edit(string $id): void
    {
        $cliente = $this->service()->find($id);
        if ($cliente === null) {
            $this->ui->redirect($this->ui->url(), 'Cliente no encontrado.', 'error');
            return;
        }
        $cfg = $this->ajustes()->todos('cliente', $id);

        $this->ui->view('clientes/edit.latte', [
            'cliente'       => $cliente,
            'cfg'           => [
                'titulo' => $cfg['titulo'] ?? '',
                'color'  => AjustesService::colorValido($cfg['color'] ?? ''),
                'frases' => implode("\n", $this->ajustes()->frasesDeCliente($id)),
                'logo_id' => $cfg['logo_id'] ?? '',
            ],
            'flash_success' => $this->ui->flash('success'),
            'flash_error'   => $this->ui->flash('error'),
        ]);
    }

    public function update(string $id): void
    {
        $this->service()->update($id, $_POST);
        $this->ui->redirect($this->ui->url('clientes/' . $id), 'Cliente actualizado.');
    }

    /** Marca del portal de este cliente: título, color, logo y frases de bienvenida. */
    public function personalizar(string $id): void
    {
        $cliente = $this->service()->find($id);
        if ($cliente === null) {
            $this->ui->redirect($this->ui->url(), 'Cliente no encontrado.', 'error');
            return;
        }
        $volver = $this->ui->url('clientes/' . $id);
        $aj     = $this->ajustes();

        $aj->setMuchos('cliente', $id, [
            'titulo' => mb_substr(trim((string) ($_POST['titulo'] ?? '')), 0, 60),
            'color'  => AjustesService::colorValido((string) ($_POST['color'] ?? '')),
            'frases' => json_encode(AjustesService::frasesDesdeTexto((string) ($_POST['frases'] ?? '')), JSON_UNESCAPED_UNICODE),
        ]);

        $avisoLogo = '';
        $archivos  = new ArchivoService($this->pdo());

        if (!empty($_POST['quitar_logo'])) {
            $this->quitarLogo($id);
        }

        $subidos = ArchivoService::normalizar($_FILES['logo'] ?? null);
        if ($subidos !== []) {
            $f   = $subidos[0];
            $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, self::LOGO_EXT, true)) {
                $avisoLogo = ' El logo debe ser PNG, JPG o WEBP (no se aceptan SVG).';
            } elseif ($f['error'] === UPLOAD_ERR_OK && (int) $f['size'] > self::LOGO_MAX) {
                $avisoLogo = ' El logo pesa más de 2 MB.';
            } else {
                try {
                    $fila = $archivos->guardarUno(
                        $f, $id, null, 'cliente_logo', $id, ['tipo' => 'equipo', 'id' => null, 'nombre' => 'Equipo'], 2
                    );
                    $this->quitarLogo($id, (string) $fila['id']);
                    $aj->set('cliente', $id, 'logo_id', (string) $fila['id']);
                } catch (\RuntimeException $e) {
                    $avisoLogo = ' Logo: ' . $e->getMessage() . '.';
                }
            }
        }

        $this->ui->redirect(
            $volver,
            $avisoLogo === '' ? 'Personalización guardada.' : 'Guardamos los ajustes, pero:' . $avisoLogo,
            $avisoLogo === '' ? 'success' : 'error'
        );
    }

    /** Borra los logos anteriores (menos $conservar) y limpia el ajuste si no queda ninguno. */
    private function quitarLogo(string $clienteId, ?string $conservar = null): void
    {
        $archivos = new ArchivoService($this->pdo());
        foreach ($archivos->deEntidad('cliente_logo', $clienteId) as $a) {
            if ($a['id'] !== $conservar) {
                $archivos->borrar((string) $a['id']);
            }
        }
        if ($conservar === null) {
            $this->ajustes()->borrar('cliente', $clienteId, 'logo_id');
        }
    }

    public function destroy(string $id): void
    {
        // Las FK borran las filas; los archivos físicos y los ajustes se limpian a mano.
        (new EntregaService($this->pdo()))->borrarDeCliente($id);
        (new ArchivoService($this->pdo()))->borrarFisicosDeCliente($id);
        $this->ajustes()->borrarDeCliente($id);
        $this->service()->delete($id);
        $this->ui->redirect($this->ui->url(), 'Cliente eliminado.');
    }
}

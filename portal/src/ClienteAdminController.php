<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

use TypeDock\Core\PluginContext;

class ClienteAdminController
{
    private const LOGO_EXT = ['png', 'jpg', 'jpeg', 'webp'];
    private const LOGO_MAX = 2 * 1048576;

    public function __construct(private readonly PluginContext $ctx) {}

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
        $this->ctx->view('templates/admin/clientes/index.latte', [
            'clientes'      => $this->service()->listAll(),
            'flash_success' => $this->ctx->getFlash('success'),
            'flash_error'   => $this->ctx->getFlash('error'),
        ]);
    }

    public function create(): void
    {
        $this->ctx->view('templates/admin/clientes/edit.latte', [
            'cliente' => null,
        ]);
    }

    public function store(): void
    {
        $id = $this->service()->create($_POST);
        // A la edición, donde está la personalización del portal de este cliente.
        $this->ctx->redirect($this->ctx->adminUrl('clientes/' . $id), 'Cliente creado. Puedes personalizar su portal aquí abajo.');
    }

    public function edit(string $id): void
    {
        $cliente = $this->service()->find($id);
        if ($cliente === null) {
            $this->ctx->redirect($this->ctx->adminUrl(), 'Cliente no encontrado.', 'error');
            return;
        }
        $cfg = $this->ajustes()->todos('cliente', $id);

        $this->ctx->view('templates/admin/clientes/edit.latte', [
            'cliente'       => $cliente,
            'cfg'           => [
                'titulo' => $cfg['titulo'] ?? '',
                'color'  => AjustesService::colorValido($cfg['color'] ?? ''),
                'frases' => implode("\n", $this->ajustes()->frasesDeCliente($id)),
                'logo_id' => $cfg['logo_id'] ?? '',
            ],
            'flash_success' => $this->ctx->getFlash('success'),
            'flash_error'   => $this->ctx->getFlash('error'),
        ]);
    }

    public function update(string $id): void
    {
        $this->service()->update($id, $_POST);
        $this->ctx->redirect($this->ctx->adminUrl('clientes/' . $id), 'Cliente actualizado.');
    }

    /** Marca del portal de este cliente: título, color, logo y frases de bienvenida. */
    public function personalizar(string $id): void
    {
        $cliente = $this->service()->find($id);
        if ($cliente === null) {
            $this->ctx->redirect($this->ctx->adminUrl(), 'Cliente no encontrado.', 'error');
            return;
        }
        $volver = $this->ctx->adminUrl('clientes/' . $id);
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

        $this->ctx->redirect(
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
        $this->ctx->redirect($this->ctx->adminUrl(), 'Cliente eliminado.');
    }
}

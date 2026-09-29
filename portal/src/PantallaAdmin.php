<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

use TypeDock\Core\PluginContext;

/** Pantallas de gestión dentro del admin de TypeDock: ve todo y firma con el nombre del equipo. */
final class PantallaAdmin implements Pantalla
{
    public function __construct(private readonly PluginContext $ctx) {}

    public function view(string $plantilla, array $datos): void
    {
        $this->ctx->view('templates/admin/' . $plantilla, $datos + ['pre' => self::preseleccion()]);
    }

    /** ?proyecto_id= / ?cliente_id= de la URL, para dejar elegido el proyecto o cliente en un formulario nuevo. */
    public static function preseleccion(): array
    {
        $out = [];
        foreach (['proyecto_id', 'cliente_id'] as $k) {
            $v = (string) ($_GET[$k] ?? '');
            if (preg_match('/^[0-9a-f-]{36}$/i', $v) === 1) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    public function url(string $ruta = ''): string
    {
        return $this->ctx->adminUrl($ruta);
    }

    public function redirect(string $url, ?string $mensaje = null, string $tipo = 'success'): void
    {
        if ($mensaje === null) {
            $this->ctx->redirect($url);
            return;
        }
        $this->ctx->redirect($url, $mensaje, $tipo);
    }

    public function flash(string $tipo): ?string
    {
        return $this->ctx->getFlash($tipo);
    }

    public function firma(): string
    {
        return (new MarcaService($this->ctx->db()->pdo()))->nombreEquipo();
    }

    public function autorId(): ?string
    {
        return null;
    }

    public function filtrar(array $filas, string $columna, string $tipo = 'proyecto'): array
    {
        return $filas;
    }
}

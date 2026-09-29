<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * Pantallas de gestión dentro del panel de equipo (/equipo): mismas plantillas
 * y controladores que el admin, con la gráfica del panel, las URLs /equipo/…,
 * la firma del usuario de agencia y sólo los clientes/proyectos que tiene asignados.
 */
final class PantallaEquipo implements Pantalla
{
    /** @param array<string, mixed> $usuario */
    public function __construct(
        private readonly EquipoGestion $panel,
        private readonly array $usuario,
        private readonly EquipoAcceso $acceso,
    ) {}

    public function view(string $plantilla, array $datos): void
    {
        $this->panel->vistaGestion($plantilla, $datos + ['pre' => PantallaAdmin::preseleccion()], $this);
    }

    /**
     * Las rutas del admin se traducen al panel. Clientes y proyectos tienen en el panel
     * su propia vista de lectura, así que su formulario de edición queda en …/editar.
     */
    public function url(string $ruta = ''): string
    {
        $ruta = trim($ruta, '/');
        if ($ruta === '' || $ruta === 'clientes' || $ruta === 'proyectos') {
            return '/equipo/clientes';
        }
        if (preg_match('#^(clientes|proyectos)/([0-9a-f-]{36})$#i', $ruta, $m) === 1) {
            return '/equipo/' . $m[1] . '/' . $m[2] . '/editar';
        }
        if (str_starts_with($ruta, 'ajustes') || str_starts_with($ruta, 'equipo') || $ruta === 'actividad') {
            return '/equipo';
        }
        return '/equipo/' . $ruta;
    }

    public function redirect(string $url, ?string $mensaje = null, string $tipo = 'success'): void
    {
        if ($mensaje !== null && $mensaje !== '') {
            PortalSession::flash($tipo === 'error' ? 'error' : 'ok', $mensaje);
        }
        $this->panel->irA($url);
    }

    /** El panel muestra el aviso en su propio marco (no en la plantilla). */
    public function flash(string $tipo): ?string
    {
        return null;
    }

    public function firma(): string
    {
        return (string) $this->usuario['nombre'];
    }

    public function autorId(): ?string
    {
        return (string) $this->usuario['id'];
    }

    public function filtrar(array $filas, string $columna, string $tipo = 'proyecto'): array
    {
        if ($this->acceso->todo()) {
            return $filas;
        }
        $ids = $tipo === 'cliente' ? $this->acceso->clienteIds() : $this->acceso->proyectoIds();
        $ids = array_flip($ids ?? []);
        return array_values(array_filter($filas, static fn(array $f): bool => isset($ids[(string) ($f[$columna] ?? '')])));
    }

    public function acceso(): EquipoAcceso
    {
        return $this->acceso;
    }
}

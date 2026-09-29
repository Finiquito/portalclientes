<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * Dónde se muestran las pantallas de gestión (clientes, proyectos, fases,
 * contactos, tareas, entregas, reuniones). Los mismos controladores sirven
 * al admin de TypeDock (PantallaAdmin) y al panel de equipo (PantallaEquipo):
 * cambia la gráfica, las URLs, quién firma y qué registros se pueden ver.
 */
interface Pantalla
{
    /** Dibuja una plantilla de templates/admin/ (ruta relativa, ej. 'tareas/edit.latte'). */
    public function view(string $plantilla, array $datos): void;

    /** URL de una ruta de gestión (mismas rutas que el admin: 'tareas/ID', 'entregas/nuevo'…). */
    public function url(string $ruta = ''): string;

    public function redirect(string $url, ?string $mensaje = null, string $tipo = 'success'): void;

    public function flash(string $tipo): ?string;

    /** Nombre con que firma comentarios, archivos y actividad. */
    public function firma(): string;

    /** Id del usuario de agencia que actúa (null en el admin de TypeDock). */
    public function autorId(): ?string;

    /**
     * Deja sólo las filas que este usuario puede ver.
     *
     * @param array<int, array<string, mixed>> $filas
     * @param string $columna columna con el id de proyecto (o de cliente si $tipo = 'cliente')
     * @return array<int, array<string, mixed>>
     */
    public function filtrar(array $filas, string $columna, string $tipo = 'proyecto'): array;
}

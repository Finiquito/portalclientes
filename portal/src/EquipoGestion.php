<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * Gestión completa desde el panel de equipo: las mismas pantallas y acciones
 * del admin (clientes, proyectos, fases, contactos, tareas, entregas y
 * contenidos, reuniones con IA), servidas bajo /equipo con la gráfica del panel.
 *
 * Seguridad, en este orden, antes de tocar cualquier controlador:
 *  1. Usuario de agencia activo en sesión.
 *  2. Token CSRF en todo POST.
 *  3. El registro de la URL (tarea, entrega, contenido, reunión, fase, contacto,
 *     archivo, proyecto o cliente) pertenece a un proyecto/cliente que el usuario ve.
 *  4. Los ids que vienen en el formulario (proyecto_id, cliente_id, reunion_origen_id)
 *     también tienen que ser visibles: no se puede mover algo a un proyecto ajeno.
 *  5. Crear, editar o borrar clientes y borrar proyectos: sólo Coordinación.
 */
class EquipoGestion extends EquipoController
{
    private ?\Latte\Engine $latte = null;

    /** Registra las rutas de gestión. Van antes que las vistas propias del panel (…/nuevo gana a …/@id). */
    public function registrar(): void
    {
        $r = function (string $metodo, string $ruta, string $clase, string $accion, ?string $tipo = null, bool $coord = false): void {
            \Flight::route($metodo . ' /equipo/' . $ruta, function (string ...$args) use ($clase, $accion, $tipo, $coord): void {
                $this->gestionar($clase, $accion, array_values($args), $tipo, $coord);
            });
        };

        // Clientes (Coordinación): la ficha de lectura es /equipo/clientes/@id.
        $r('GET',  'clientes/nuevo',                ClienteAdminController::class, 'create', null, true);
        $r('POST', 'clientes',                      ClienteAdminController::class, 'store', null, true);
        $r('GET',  'clientes/@id/editar',           ClienteAdminController::class, 'edit', 'cliente', true);
        $r('POST', 'clientes/@id/editar',           ClienteAdminController::class, 'update', 'cliente', true);
        $r('POST', 'clientes/@id/personalizar',     ClienteAdminController::class, 'personalizar', 'cliente', true);
        $r('POST', 'clientes/@id/borrar',           ClienteAdminController::class, 'destroy', 'cliente', true);

        // Proyectos: la vista de lectura es /equipo/proyectos/@id.
        $r('GET',  'proyectos/nuevo',               ProyectoAdminController::class, 'create');
        $r('POST', 'proyectos',                     ProyectoAdminController::class, 'store');
        $r('GET',  'proyectos/@id/editar',          ProyectoAdminController::class, 'edit', 'proyecto');
        $r('POST', 'proyectos/@id/editar',          ProyectoAdminController::class, 'update', 'proyecto');
        $r('POST', 'proyectos/@id/borrar',          ProyectoAdminController::class, 'destroy', 'proyecto', true);

        $r('GET',  'fases',                         FaseAdminController::class, 'index');
        $r('GET',  'fases/nuevo',                   FaseAdminController::class, 'create');
        $r('POST', 'fases',                         FaseAdminController::class, 'store');
        $r('GET',  'fases/@id',                     FaseAdminController::class, 'edit', 'fase');
        $r('POST', 'fases/@id',                     FaseAdminController::class, 'update', 'fase');
        $r('POST', 'fases/@id/borrar',              FaseAdminController::class, 'destroy', 'fase');

        $r('GET',  'contactos',                     ContactoAdminController::class, 'index');
        $r('GET',  'contactos/nuevo',               ContactoAdminController::class, 'create');
        $r('POST', 'contactos',                     ContactoAdminController::class, 'store');
        $r('GET',  'contactos/@id',                 ContactoAdminController::class, 'edit', 'contacto');
        $r('POST', 'contactos/@id',                 ContactoAdminController::class, 'update', 'contacto');
        $r('POST', 'contactos/@id/borrar',          ContactoAdminController::class, 'destroy', 'contacto');

        $r('GET',  'tareas',                        TareaAdminController::class, 'index');
        $r('GET',  'tareas/nuevo',                  TareaAdminController::class, 'create');
        $r('POST', 'tareas',                        TareaAdminController::class, 'store');
        $r('POST', 'tareas/lote',                   TareaAdminController::class, 'lote');
        $r('GET',  'tareas/@id',                    TareaAdminController::class, 'edit', 'tarea');
        $r('POST', 'tareas/@id',                    TareaAdminController::class, 'update', 'tarea');
        $r('POST', 'tareas/@id/borrar',             TareaAdminController::class, 'destroy', 'tarea');
        $r('POST', 'tareas/@id/comentarios',        TareaAdminController::class, 'comentar', 'tarea');
        $r('POST', 'tareas/@id/comentarios/@cid/borrar', TareaAdminController::class, 'borrarComentario', 'tarea');
        $r('POST', 'tareas/@id/archivos',           TareaAdminController::class, 'subirArchivos', 'tarea');
        $r('GET',  'archivos/@id',                  TareaAdminController::class, 'verArchivo', 'archivo');
        $r('POST', 'archivos/@id/borrar',           TareaAdminController::class, 'borrarArchivo', 'archivo');

        $r('GET',  'entregas',                      EntregaAdminController::class, 'index');
        $r('GET',  'entregas/nuevo',                EntregaAdminController::class, 'create');
        $r('POST', 'entregas',                      EntregaAdminController::class, 'store');
        $r('GET',  'entregas/plantilla',            EntregaAdminController::class, 'plantilla');
        $r('GET',  'entregas/@id',                  EntregaAdminController::class, 'edit', 'entrega');
        $r('POST', 'entregas/@id',                  EntregaAdminController::class, 'update', 'entrega');
        $r('POST', 'entregas/@id/borrar',           EntregaAdminController::class, 'destroy', 'entrega');
        $r('POST', 'entregas/@id/publicar',         EntregaAdminController::class, 'publicar', 'entrega');
        $r('POST', 'entregas/@id/borrador',         EntregaAdminController::class, 'borrador', 'entrega');
        $r('POST', 'entregas/@id/contenidos',       EntregaAdminController::class, 'agregarContenido', 'entrega');
        $r('POST', 'entregas/@id/masivo',           EntregaAdminController::class, 'subidaMasiva', 'entrega');
        $r('GET',  'contenidos/@id',                EntregaAdminController::class, 'editContenido', 'contenido');
        $r('POST', 'contenidos/@id',                EntregaAdminController::class, 'updateContenido', 'contenido');
        $r('POST', 'contenidos/@id/borrar',         EntregaAdminController::class, 'borrarContenido', 'contenido');
        $r('POST', 'contenidos/@id/mover',          EntregaAdminController::class, 'moverContenido', 'contenido');
        $r('POST', 'contenidos/@id/versiones',      EntregaAdminController::class, 'nuevaVersion', 'contenido');
        $r('POST', 'contenidos/@id/archivos',       EntregaAdminController::class, 'agregarArchivos', 'contenido');
        $r('POST', 'contenidos/@id/comentarios',    EntregaAdminController::class, 'comentar', 'contenido');
        $r('POST', 'contenidos/@id/comentarios/@cid/borrar', EntregaAdminController::class, 'borrarComentario', 'contenido');

        $r('GET',  'solicitudes',                   SolicitudAdminController::class, 'index');
        $r('GET',  'solicitudes/@id',               SolicitudAdminController::class, 'ver', 'solicitud');
        $r('POST', 'solicitudes/@id/aceptar',       SolicitudAdminController::class, 'aceptar', 'solicitud');
        $r('POST', 'solicitudes/@id/agendar',       SolicitudAdminController::class, 'agendar', 'solicitud');
        $r('POST', 'solicitudes/@id/cotizar',       SolicitudAdminController::class, 'cotizar', 'solicitud');
        $r('POST', 'solicitudes/@id/cerrar',        SolicitudAdminController::class, 'cerrar', 'solicitud');
        $r('POST', 'solicitudes/@id/comentarios',   SolicitudAdminController::class, 'comentar', 'solicitud');
        $r('POST', 'solicitudes/@id/borrar',        SolicitudAdminController::class, 'destroy', 'solicitud', true);

        $r('GET',  'reuniones',                     ReunionAdminController::class, 'index');
        $r('GET',  'reuniones/nuevo',               ReunionAdminController::class, 'create');
        $r('POST', 'reuniones',                     ReunionAdminController::class, 'store');
        $r('GET',  'reuniones/@id',                 ReunionAdminController::class, 'edit', 'reunion');
        $r('POST', 'reuniones/@id',                 ReunionAdminController::class, 'update', 'reunion');
        $r('POST', 'reuniones/@id/borrar',          ReunionAdminController::class, 'destroy', 'reunion');
        $r('POST', 'reuniones/@id/propuestas/@pid/borrar', ReunionAdminController::class, 'borrarPropuesta', 'reunion');
    }

    /**
     * Revisa permisos y ejecuta la acción del controlador compartido.
     *
     * @param class-string $clase
     * @param array<int, string> $args el primero es el id del registro cuando $tipo no es null
     */
    public function gestionar(string $clase, string $accion, array $args, ?string $tipo = null, bool $soloCoordinacion = false): void
    {
        $u   = $this->requerirUsuario();
        $acc = $this->acceso($u);

        if ($soloCoordinacion && !$acc->todo()) {
            $this->denegar('Esa acción la hace Coordinación o un admin del portal.');
            return;
        }
        if ($tipo !== null && !$this->permitido($acc, $tipo, (string) ($args[0] ?? ''))) {
            $this->denegar();
            return;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!$this->csrfPost()) {
                PortalSession::flash('error', 'Tu sesión expiró. Vuelve a intentarlo.');
                $this->irA((string) ($_SERVER['HTTP_REFERER'] ?? '/equipo'));
                return;
            }
            foreach (['proyecto_id' => 'proyecto', 'cliente_id' => 'cliente', 'reunion_origen_id' => 'reunion'] as $campo => $t) {
                $v = trim((string) ($_POST[$campo] ?? ''));
                if ($v !== '' && !$this->permitido($acc, $t, $v)) {
                    $this->denegar();
                    return;
                }
            }
        }

        $ctl = new $clase($this->ctx, new PantallaEquipo($this, $u, $acc));
        $ctl->{$accion}(...$args);
    }

    /** Acepta el token del panel (_csrf) y el de las plantillas del admin (_csrf_token). */
    private function csrfPost(): bool
    {
        $enviado = (string) ($_POST['_csrf_token'] ?? $_POST['_csrf'] ?? '');
        return $enviado !== '' && hash_equals(PortalSession::csrf(), $enviado);
    }

    protected function denegar(string $msg = 'No tienes acceso a eso: no está entre tus clientes o proyectos asignados.'): void
    {
        PortalSession::flash('error', $msg);
        $this->irA('/equipo');
    }

    /** ¿El registro $id de tipo $tipo cae dentro de lo que ve el usuario? */
    public function permitido(EquipoAcceso $acc, string $tipo, string $id): bool
    {
        if ($id === '') {
            return false;
        }
        $tablas = [
            'tarea' => 'portal_tareas', 'reunion' => 'portal_reuniones', 'entrega' => 'portal_entregas',
            'contenido' => 'portal_contenidos', 'fase' => 'portal_fases', 'solicitud' => 'portal_solicitudes',
        ];
        switch ($tipo) {
            case 'proyecto':
                return $acc->puedeVerProyecto($id);
            case 'cliente':
                return $acc->puedeVerCliente($id);
            case 'contacto':
                $c = $this->fetchOne('SELECT cliente_id FROM portal_contactos WHERE id = ?', [$id]);
                return $c !== null && $acc->puedeVerCliente((string) $c['cliente_id']);
            case 'archivo':
                $a = $this->fetchOne('SELECT cliente_id, proyecto_id, entidad_tipo FROM portal_archivos WHERE id = ?', [$id]);
                if ($a === null) {
                    return false;
                }
                return $a['proyecto_id'] !== null && $a['proyecto_id'] !== ''
                    ? $acc->puedeVerProyecto((string) $a['proyecto_id'])
                    : $acc->puedeVerCliente((string) $a['cliente_id']);
        }
        if (!isset($tablas[$tipo])) {
            return false;
        }
        $f = $this->fetchOne('SELECT proyecto_id FROM ' . $tablas[$tipo] . ' WHERE id = ?', [$id]);
        return $f !== null && $acc->puedeVerProyecto((string) $f['proyecto_id']);
    }

    public function irA(string $url): void
    {
        $this->redirectTo($url);
    }

    /** Sección del menú según la plantilla. */
    private static function nav(string $plantilla): string
    {
        return match (strtok($plantilla, '/')) {
            'tareas'    => 'tareas',
            'entregas'  => 'contenidos',
            'reuniones' => 'reuniones',
            'solicitudes' => 'solicitudes',
            default     => 'clientes',
        };
    }

    /**
     * Dibuja una plantilla de templates/admin/ dentro del marco del panel.
     * Se usa un motor Latte propio para no depender de cómo el núcleo arma las vistas de admin.
     */
    public function vistaGestion(string $plantilla, array $datos, PantallaEquipo $pantalla): void
    {
        $u = $this->requerirUsuario();
        $datos = $this->contexto($u, self::nav($plantilla), [
            'plugin_ui_layout' => dirname(__DIR__) . '/templates/equipo/_gestion.latte',
            'admin_url'        => fn(string $r = ''): string => $pantalla->url($r),
            'csrf_token'       => PortalSession::csrf(),
            'enPanel'          => true,
        ]) + $datos;
        echo $this->motor()->renderToString(dirname(__DIR__) . '/templates/admin/' . ltrim($plantilla, '/'), $datos);
    }

    private function motor(): \Latte\Engine
    {
        if ($this->latte === null) {
            $this->latte = new \Latte\Engine();
            $dir = (new ArchivoService($this->pdo()))->directorioBase() . '/.latte';
            if (!is_dir($dir)) {
                @mkdir($dir, 0750, true);
            }
            $this->latte->setTempDirectory(is_dir($dir) && is_writable($dir) ? $dir : sys_get_temp_dir());
        }
        return $this->latte;
    }
}

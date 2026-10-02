<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

use TypeDock\Contract\PluginInterface;
use TypeDock\Core\PluginContext;

/**
 * Portal de Clientes — plugin drop-in bajo plugins/portal/.
 *
 * Fase 1 (seguimiento): tareas asignables al cliente (tarea / pedir archivos /
 * revisar y aprobar), comentarios, archivos, actividad, personalización por
 * cliente y contacto, y el portal público rediseñado.
 * Fase 2a: entregas de contenido (post/carrusel, reel, story, pieza gráfica, logo,
 * mockup, documento, sitio) con versiones, aprobación, comentarios y reacciones.
 * Pendiente: visor de PDF por páginas, sitio en marco, pines y comparación v1/v2 (2b/2c).
 */
class PortalPlugin implements PluginInterface
{
    public function register(PluginContext $ctx): void
    {
        $ctx->migrate(__DIR__ . '/../migrations');

        // Columnas nuevas de portal_tareas (tipo, visible_cliente, completada_en), idempotente.
        try {
            Schema::asegurar($ctx->db()->pdo());
            Zona::desdeAjustes($ctx->db()->pdo());
        } catch (\Throwable) {
            // Si la BD no está lista no bloqueamos el arranque del resto del sistema.
        }

        $clientes = new ClienteAdminController($ctx);
        $ctx->registerAdminRoute('GET',  '',                 [$clientes, 'index']);
        $ctx->registerAdminRoute('GET',  'clientes/nuevo',    [$clientes, 'create']);
        $ctx->registerAdminRoute('POST', 'clientes',          [$clientes, 'store']);
        $ctx->registerAdminRoute('GET',  'clientes/@id',      fn(string $id) => $clientes->edit($id));
        $ctx->registerAdminRoute('POST', 'clientes/@id',      fn(string $id) => $clientes->update($id));
        $ctx->registerAdminRoute('POST', 'clientes/@id/borrar', fn(string $id) => $clientes->destroy($id));
        $ctx->registerAdminRoute('POST', 'clientes/@id/personalizar', fn(string $id) => $clientes->personalizar($id));

        $proyectos = new ProyectoAdminController($ctx);
        $ctx->registerAdminRoute('GET',  'proyectos',                [$proyectos, 'index']);
        $ctx->registerAdminRoute('GET',  'proyectos/nuevo',           [$proyectos, 'create']);
        $ctx->registerAdminRoute('POST', 'proyectos',                 [$proyectos, 'store']);
        $ctx->registerAdminRoute('GET',  'proyectos/@id',             fn(string $id) => $proyectos->edit($id));
        $ctx->registerAdminRoute('POST', 'proyectos/@id',             fn(string $id) => $proyectos->update($id));
        $ctx->registerAdminRoute('POST', 'proyectos/@id/borrar',      fn(string $id) => $proyectos->destroy($id));

        $reuniones = new ReunionAdminController($ctx);
        $ctx->registerAdminRoute('GET',  'reuniones',                [$reuniones, 'index']);
        $ctx->registerAdminRoute('GET',  'reuniones/nuevo',           [$reuniones, 'create']);
        $ctx->registerAdminRoute('POST', 'reuniones',                 [$reuniones, 'store']);
        $ctx->registerAdminRoute('GET',  'reuniones/@id',             fn(string $id) => $reuniones->edit($id));
        $ctx->registerAdminRoute('POST', 'reuniones/@id',             fn(string $id) => $reuniones->update($id));
        $ctx->registerAdminRoute('POST', 'reuniones/@id/borrar',      fn(string $id) => $reuniones->destroy($id));
        $ctx->registerAdminRoute('POST', 'reuniones/@id/propuestas/@pid/borrar', fn(string $id, string $pid) => $reuniones->borrarPropuesta($id, $pid));

        $tareas = new TareaAdminController($ctx);
        $ctx->registerAdminRoute('GET',  'tareas',                [$tareas, 'index']);
        $ctx->registerAdminRoute('GET',  'tareas/nuevo',           [$tareas, 'create']);
        $ctx->registerAdminRoute('POST', 'tareas',                 [$tareas, 'store']);
        $ctx->registerAdminRoute('POST', 'tareas/lote',            [$tareas, 'lote']);
        $ctx->registerAdminRoute('GET',  'tareas/@id',             fn(string $id) => $tareas->edit($id));
        $ctx->registerAdminRoute('POST', 'tareas/@id',             fn(string $id) => $tareas->update($id));
        $ctx->registerAdminRoute('POST', 'tareas/@id/borrar',      fn(string $id) => $tareas->destroy($id));
        $ctx->registerAdminRoute('POST', 'tareas/@id/comentarios', fn(string $id) => $tareas->comentar($id));
        $ctx->registerAdminRoute('POST', 'tareas/@id/comentarios/@cid/borrar', fn(string $id, string $cid) => $tareas->borrarComentario($id, $cid));
        $ctx->registerAdminRoute('POST', 'tareas/@id/archivos',    fn(string $id) => $tareas->subirArchivos($id));
        $ctx->registerAdminRoute('GET',  'archivos/@id',           fn(string $id) => $tareas->verArchivo($id));
        $ctx->registerAdminRoute('POST', 'archivos/@id/borrar',    fn(string $id) => $tareas->borrarArchivo($id));

        $solicitudes = new SolicitudAdminController($ctx);
        $ctx->registerAdminRoute('GET',  'solicitudes',                    [$solicitudes, 'index']);
        $ctx->registerAdminRoute('GET',  'solicitudes/@id',                fn(string $id) => $solicitudes->ver($id));
        $ctx->registerAdminRoute('POST', 'solicitudes/@id/aceptar',        fn(string $id) => $solicitudes->aceptar($id));
        $ctx->registerAdminRoute('POST', 'solicitudes/@id/agendar',        fn(string $id) => $solicitudes->agendar($id));
        $ctx->registerAdminRoute('POST', 'solicitudes/@id/cotizar',        fn(string $id) => $solicitudes->cotizar($id));
        $ctx->registerAdminRoute('POST', 'solicitudes/@id/cerrar',         fn(string $id) => $solicitudes->cerrar($id));
        $ctx->registerAdminRoute('POST', 'solicitudes/@id/comentarios',    fn(string $id) => $solicitudes->comentar($id));
        $ctx->registerAdminRoute('POST', 'solicitudes/@id/borrar',         fn(string $id) => $solicitudes->destroy($id));

        $fases = new FaseAdminController($ctx);
        $ctx->registerAdminRoute('GET',  'fases',                [$fases, 'index']);
        $ctx->registerAdminRoute('GET',  'fases/nuevo',           [$fases, 'create']);
        $ctx->registerAdminRoute('POST', 'fases',                 [$fases, 'store']);
        $ctx->registerAdminRoute('GET',  'fases/@id',             fn(string $id) => $fases->edit($id));
        $ctx->registerAdminRoute('POST', 'fases/@id',             fn(string $id) => $fases->update($id));
        $ctx->registerAdminRoute('POST', 'fases/@id/borrar',      fn(string $id) => $fases->destroy($id));

        $contactos = new ContactoAdminController($ctx);
        $ctx->registerAdminRoute('GET',  'contactos',                [$contactos, 'index']);
        $ctx->registerAdminRoute('GET',  'contactos/nuevo',           [$contactos, 'create']);
        $ctx->registerAdminRoute('POST', 'contactos',                 [$contactos, 'store']);
        $ctx->registerAdminRoute('GET',  'contactos/@id',             fn(string $id) => $contactos->edit($id));
        $ctx->registerAdminRoute('POST', 'contactos/@id',             fn(string $id) => $contactos->update($id));
        $ctx->registerAdminRoute('POST', 'contactos/@id/borrar',      fn(string $id) => $contactos->destroy($id));
        $ctx->registerAdminRoute('POST', 'contactos/@id/invitar',     fn(string $id) => $contactos->invitar($id));
        $ctx->registerAdminRoute('POST', 'contactos/@id/ver-como',    fn(string $id) => $contactos->verComo($id));

        $extras = new ActividadAdminController($ctx);
        $ctx->registerAdminRoute('GET',  'actividad', [$extras, 'actividad']);
        $ctx->registerAdminRoute('GET',  'ajustes',   [$extras, 'ajustesForm']);
        $ctx->registerAdminRoute('POST', 'ajustes',   [$extras, 'ajustesGuardar']);
        $ctx->registerAdminRoute('POST', 'ajustes/ia-probar', [$extras, 'iaProbar']);
        $ctx->registerAdminRoute('POST', 'ajustes/correo',         [$extras, 'correoGuardar']);
        $ctx->registerAdminRoute('POST', 'ajustes/correo-prueba',  [$extras, 'correoPrueba']);
        $ctx->registerAdminRoute('POST', 'ajustes/smtp-probar',    [$extras, 'smtpProbar']);
        $ctx->registerAdminRoute('POST', 'ajustes/cola/@id/enviar',   fn(string $id) => $extras->colaEnviar($id));
        $ctx->registerAdminRoute('POST', 'ajustes/cola/@id/cancelar', fn(string $id) => $extras->colaCancelar($id));
        $ctx->registerAdminRoute('POST', 'ajustes/cron-clave',     [$extras, 'cronRegenerar']);

        $equipo = new EquipoAdminController($ctx);
        $ctx->registerAdminRoute('GET',  'equipo',                [$equipo, 'index']);
        $ctx->registerAdminRoute('GET',  'equipo/nuevo',           [$equipo, 'create']);
        $ctx->registerAdminRoute('POST', 'equipo',                 [$equipo, 'store']);
        $ctx->registerAdminRoute('GET',  'equipo/@id',             fn(string $id) => $equipo->edit($id));
        $ctx->registerAdminRoute('POST', 'equipo/@id',             fn(string $id) => $equipo->update($id));
        $ctx->registerAdminRoute('POST', 'equipo/@id/borrar',      fn(string $id) => $equipo->destroy($id));
        $ctx->registerAdminRoute('POST', 'equipo/@id/invitar',     fn(string $id) => $equipo->invitarPost($id));

        $entregas = new EntregaAdminController($ctx);
        $ctx->registerAdminRoute('GET',  'entregas',                       [$entregas, 'index']);
        $ctx->registerAdminRoute('GET',  'entregas/nuevo',                  [$entregas, 'create']);
        $ctx->registerAdminRoute('POST', 'entregas',                        [$entregas, 'store']);
        $ctx->registerAdminRoute('GET',  'entregas/plantilla',              [$entregas, 'plantilla']);
        $ctx->registerAdminRoute('GET',  'entregas/@id',                    fn(string $id) => $entregas->edit($id));
        $ctx->registerAdminRoute('POST', 'entregas/@id',                    fn(string $id) => $entregas->update($id));
        $ctx->registerAdminRoute('POST', 'entregas/@id/borrar',             fn(string $id) => $entregas->destroy($id));
        $ctx->registerAdminRoute('POST', 'entregas/@id/publicar',           fn(string $id) => $entregas->publicar($id));
        $ctx->registerAdminRoute('POST', 'entregas/@id/borrador',           fn(string $id) => $entregas->borrador($id));
        $ctx->registerAdminRoute('POST', 'entregas/@id/contenidos',         fn(string $id) => $entregas->agregarContenido($id));
        $ctx->registerAdminRoute('POST', 'entregas/@id/masivo',             fn(string $id) => $entregas->subidaMasiva($id));
        $ctx->registerAdminRoute('GET',  'contenidos/@id',                  fn(string $id) => $entregas->editContenido($id));
        $ctx->registerAdminRoute('POST', 'contenidos/@id',                  fn(string $id) => $entregas->updateContenido($id));
        $ctx->registerAdminRoute('POST', 'contenidos/@id/borrar',           fn(string $id) => $entregas->borrarContenido($id));
        $ctx->registerAdminRoute('POST', 'contenidos/@id/mover',            fn(string $id) => $entregas->moverContenido($id));
        $ctx->registerAdminRoute('POST', 'contenidos/@id/versiones',        fn(string $id) => $entregas->nuevaVersion($id));
        $ctx->registerAdminRoute('POST', 'contenidos/@id/archivos',         fn(string $id) => $entregas->agregarArchivos($id));
        $ctx->registerAdminRoute('POST', 'contenidos/@id/comentarios',      fn(string $id) => $entregas->comentar($id));
        $ctx->registerAdminRoute('POST', 'contenidos/@id/comentarios/@cid/borrar', fn(string $id, string $cid) => $entregas->borrarComentario($id, $cid));

        // Rutas públicas del portal de clientes, con URL limpia (sin el
        // prefijo /plugins/portal/). Van directo por Flight — un rewrite de
        // Apache no sirve acá porque PHP sigue viendo el REQUEST_URI
        // original, no el reescrito, así que el router de Core nunca las
        // vería si dependiéramos de .htaccess.
        $publico = new PortalPublicController($ctx);
        \Flight::route('GET /login',                          [$publico, 'loginForm']);
        \Flight::route('POST /login',                         [$publico, 'requestCode']);
        \Flight::route('GET /verificar',                      [$publico, 'verifyForm']);
        \Flight::route('POST /verificar',                     [$publico, 'verifyCode']);
        \Flight::route('GET /logout',                         [$publico, 'logout']);
        \Flight::route('POST /portal/vista-previa/salir',     [$publico, 'salirVistaPrevia']);
        \Flight::route('GET /portal/marca/agencia',           [$publico, 'marcaAgencia']);
        \Flight::route('GET /portal/marca/cliente/@id',       fn(string $id) => $publico->marcaCliente($id));
        \Flight::route('GET /portal/cron/correos',            [$publico, 'cronCorreos']);

        \Flight::route('GET /portal',                         [$publico, 'dashboard']);
        \Flight::route('GET /portal/tareas',                  [$publico, 'tareas']);
        \Flight::route('GET /portal/tareas/@id',              fn(string $id) => $publico->tarea($id));
        \Flight::route('POST /portal/tareas/@id/accion',      fn(string $id) => $publico->accionTarea($id));
        \Flight::route('POST /portal/tareas/@id/comentarios', fn(string $id) => $publico->comentar($id));
        \Flight::route('POST /portal/tareas/@id/archivos',    fn(string $id) => $publico->subirArchivos($id));
        $revision = new EntregaPublicController($ctx);
        \Flight::route('GET /portal/entregas',                      [$revision, 'entregas']);
        \Flight::route('GET /portal/entregas/@id',                  fn(string $id) => $revision->entrega($id));
        \Flight::route('POST /portal/entregas/@id/enviar',          fn(string $id) => $revision->enviar($id));
        \Flight::route('GET /portal/contenidos/@id',                fn(string $id) => $revision->contenido($id));
        \Flight::route('POST /portal/contenidos/@id/decidir',       fn(string $id) => $revision->decidir($id));
        \Flight::route('POST /portal/contenidos/@id/reaccion',      fn(string $id) => $revision->reaccionar($id));
        \Flight::route('POST /portal/contenidos/@id/comentarios',   fn(string $id) => $revision->comentar($id));
        \Flight::route('GET /portal/archivos',                [$publico, 'archivosPagina']);
        \Flight::route('GET /portal/archivo/@id',             fn(string $id) => $publico->archivo($id));
        \Flight::route('POST /portal/archivos/@id/borrar',    fn(string $id) => $publico->borrarArchivo($id));
        \Flight::route('GET /portal/reuniones',               [$publico, 'reuniones']);
        \Flight::route('GET /portal/reuniones/@id',           fn(string $id) => $publico->reunion($id));
        \Flight::route('GET /portal/reuniones/@id/calendario.ics', fn(string $id) => $publico->reunionIcs($id));
        $sol = new SolicitudPublicController($ctx);
        \Flight::route('GET /portal/solicitudes',                 [$sol, 'lista']);
        \Flight::route('GET /portal/solicitudes/nueva',           [$sol, 'nueva']);
        \Flight::route('POST /portal/solicitudes',                [$sol, 'crear']);
        \Flight::route('POST /portal/solicitudes/ordenar',        [$sol, 'ordenar']);
        \Flight::route('GET /portal/solicitudes/@id',             fn(string $id) => $sol->ver($id));
        \Flight::route('POST /portal/solicitudes/@id/comentarios', fn(string $id) => $sol->comentar($id));
        \Flight::route('POST /portal/solicitudes/@id/decidir',    fn(string $id) => $sol->decidir($id));
        \Flight::route('GET /portal/ayuda',                   [$publico, 'ayuda']);
        \Flight::route('POST /portal/primeros-pasos/ocultar', [$publico, 'ocultarPrimerosPasos']);
        \Flight::route('GET /portal/ajustes',                 [$publico, 'ajustesForm']);
        \Flight::route('POST /portal/ajustes',                [$publico, 'ajustesGuardar']);
        \Flight::route('POST /portal/ajustes/tema',           [$publico, 'temaRapido']);

        // Front de agencia (/equipo): usuarios de portal_equipo, con código por correo.
        $eq = new EquipoGestion($ctx);
        $eq->registrar();   // gestión (tareas, entregas, reuniones…): antes que las vistas propias
        \Flight::route('GET /equipo/entrar',          [$eq, 'entrarForm']);
        \Flight::route('POST /equipo/entrar',         [$eq, 'pedirCodigo']);
        \Flight::route('GET /equipo/verificar',       [$eq, 'verificarForm']);
        \Flight::route('POST /equipo/verificar',      [$eq, 'verificarCodigo']);
        \Flight::route('GET /equipo/salir',           [$eq, 'salir']);
        \Flight::route('POST /equipo/tema',           [$eq, 'tema']);
        \Flight::route('GET /equipo/ajustes',         [$eq, 'misAjustes']);
        \Flight::route('POST /equipo/ajustes',        [$eq, 'guardarAjustes']);
        \Flight::route('GET /equipo',                 [$eq, 'inicio']);
        \Flight::route('GET /equipo/clientes',        [$eq, 'clientes']);
        \Flight::route('GET /equipo/clientes/@id',    fn(string $id) => $eq->cliente($id));
        \Flight::route('POST /equipo/ver-como/@id',   fn(string $id) => $eq->verComo($id));
        \Flight::route('GET /equipo/proyectos/@id',   fn(string $id) => $eq->proyecto($id));

        $ctx->addAdminMenuItem('Portal · Clientes', '');
        $ctx->addAdminMenuItem('Portal · Contactos', 'contactos');
        $ctx->addAdminMenuItem('Portal · Proyectos', 'proyectos');
        $ctx->addAdminMenuItem('Portal · Fases', 'fases');
        $ctx->addAdminMenuItem('Portal · Reuniones', 'reuniones');
        $ctx->addAdminMenuItem('Portal · Solicitudes', 'solicitudes');
        $ctx->addAdminMenuItem('Portal · Tareas', 'tareas');
        $ctx->addAdminMenuItem('Portal · Actividad', 'actividad');
        $ctx->addAdminMenuItem('Portal · Ajustes', 'ajustes');
        $ctx->addAdminMenuItem('Portal · Contenidos', 'entregas');
        $ctx->addAdminMenuItem('Portal · Equipo', 'equipo');
    }

    public function getName(): string
    {
        return 'Portal de Clientes';
    }

    public function getVersion(): string
    {
        return '0.15.1';
    }

    public function provides(): array
    {
        return [];
    }
}

<?php
declare(strict_types=1);

/**
 * Pruebas del plugin portal.
 *
 *   php tests/run.php                       # SQLite en memoria
 *   PORTAL_DB_DSN="mysql:host=127.0.0.1;dbname=portal_test;charset=utf8mb4" \
 *   PORTAL_DB_USER=root PORTAL_DB_PASS=root php tests/run.php   # MySQL/MariaDB (borra las tablas portal_*)
 */

use TypeDock\Plugin\Portal as P;

require dirname(__DIR__) . '/dev/bootstrap.php';

$GLOBALS['ok'] = 0;
$GLOBALS['fallas'] = [];
$GLOBALS['seccion'] = '';

function seccion(string $s): void
{
    $GLOBALS['seccion'] = $s;
    echo "\n{$s}\n";
}

function check(bool $cond, string $msg): void
{
    if ($cond) {
        $GLOBALS['ok']++;
        echo '.';
    } else {
        $GLOBALS['fallas'][] = $GLOBALS['seccion'] . ' → ' . $msg;
        echo 'F';
    }
}

/** Base limpia: SQLite en memoria o, si hay DSN, borra las tablas portal_* y users. */
function baseLimpia(): PDO
{
    $dsn = getenv('PORTAL_DB_DSN') ?: 'sqlite::memory:';
    $pdo = portal_dev_pdo($dsn);
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $t) {
            if (str_starts_with((string) $t, 'portal_') || $t === 'users') {
                $pdo->exec("DROP TABLE `{$t}`");
            }
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        $pdo->exec('CREATE TABLE IF NOT EXISTS users (id VARCHAR(36) PRIMARY KEY, name VARCHAR(255), email VARCHAR(255))');
    }
    P\Schema::reiniciar();
    return $pdo;
}

/** Controlador de equipo que no termina el proceso: guarda la redirección. */
final class EquipoPrueba extends P\EquipoController
{
    public ?string $redir = null;

    protected function redirectTo(string $url): void
    {
        $this->redir = $url;
        throw new RuntimeException('redirect');
    }

    protected function terminate(): void
    {
        throw new RuntimeException('fin');
    }

    /** Ejecuta una acción y devuelve [html, redirección]. */
    public function correr(callable $f): array
    {
        $this->redir = null;
        ob_start();
        try {
            $f();
        } catch (RuntimeException $e) {
            if (!in_array($e->getMessage(), ['redirect', 'fin'], true)) {
                ob_end_clean();
                throw $e;
            }
        }
        return [(string) ob_get_clean(), $this->redir];
    }
}

$pdo = baseLimpia();
$ctx = portal_dev_contexto($pdo);
$motor = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
echo "Motor: {$motor}\n";

// ---------------------------------------------------------------------------
seccion('Esquema');
foreach (['portal_equipo', 'portal_equipo_asignaciones', 'portal_equipo_codigos', 'portal_equipo_intentos', 'portal_reunion_propuestas', 'portal_correos_cola'] as $t) {
    $existe = true;
    try {
        $pdo->query("SELECT 1 FROM {$t} WHERE 1 = 0");
    } catch (Throwable) {
        $existe = false;
    }
    check($existe, "existe {$t}");
}
P\Schema::reiniciar();
try {
    P\Schema::asegurar($pdo);
    check(true, 'asegurar() dos veces no falla');
} catch (Throwable $e) {
    check(false, 'asegurar() dos veces: ' . $e->getMessage());
}

// Datos base
$cs = new P\ClienteService($pdo);
$c1 = $cs->create(['nombre' => 'Cliente Uno', 'pais' => 'CL']);
$c2 = $cs->create(['nombre' => 'Cliente Dos', 'pais' => 'MX']);
$c3 = $cs->create(['nombre' => 'Cliente Tres']);
$ps = new P\ProyectoService($pdo);
$p1a = $ps->create(['cliente_id' => $c1, 'nombre' => 'Uno A']);
$p1b = $ps->create(['cliente_id' => $c1, 'nombre' => 'Uno B']);
$p2a = $ps->create(['cliente_id' => $c2, 'nombre' => 'Dos A']);
$p2b = $ps->create(['cliente_id' => $c2, 'nombre' => 'Dos B']);
$p3a = $ps->create(['cliente_id' => $c3, 'nombre' => 'Tres A']);
$contacto = (new P\ContactoService($pdo))->create(['cliente_id' => $c1, 'nombre' => 'Clara Cliente', 'email' => 'clara@uno.cl', 'rol' => 'aprobador']);

// ---------------------------------------------------------------------------
seccion('Textos largos (transcripción de ~80 KB)');
$rs = new P\ReunionService($pdo);
$rid = $rs->create(['proyecto_id' => $p1a, 'titulo' => 'Larga', 'fecha' => '2026-10-01 10:00']);
$largo = str_repeat('Reunión: acordamos año, diseño y campaña. ', 1800);
try {
    $rs->update($rid, ['proyecto_id' => $p1a, 'titulo' => 'Larga', 'fecha' => '2026-10-01 10:00', 'transcripcion' => $largo]);
    $guardada = (string) $pdo->query("SELECT transcripcion FROM portal_reuniones WHERE id = '{$rid}'")->fetchColumn();
    check(mb_strlen($guardada) === mb_strlen(trim($largo)), 'la transcripción se guarda completa');
} catch (Throwable $e) {
    check(false, 'guardar transcripción larga: ' . $e->getMessage());
}

// ---------------------------------------------------------------------------
seccion('Código de acceso (contactos y equipo comparten la lógica)');
$eq  = new P\EquipoService($pdo);
$ana = $eq->create(['nombre' => 'Ana', 'email' => ' ANA@agencia.cl ', 'rol' => 'equipo', 'activo' => 1]);
check(($eq->find($ana)['email'] ?? '') === 'ana@agencia.cl', 'email normalizado a minúsculas y sin espacios');

foreach ([[new P\ContactoAuthService($pdo), $contacto, 'contacto'], [new P\EquipoAuthService($pdo), $ana, 'equipo']] as [$auth, $pid, $quien]) {
    $cod = $auth->generarCodigo($pid);
    check(preg_match('/^\d{6}$/', $cod) === 1, "{$quien}: código de 6 dígitos");
    $cod2 = $auth->generarCodigo($pid);
    check(!$auth->verificarCodigo($pid, $cod) || $cod === $cod2, "{$quien}: un código nuevo invalida el anterior");
    check($auth->verificarCodigo($pid, $cod2), "{$quien}: el código vigente sirve");
    check(!$auth->verificarCodigo($pid, $cod2), "{$quien}: el código es de un solo uso");
    for ($i = 0; $i < 5; $i++) {
        $auth->verificarCodigo($pid, '000000');
    }
    check($auth->bloqueado($pid), "{$quien}: 5 fallos bloquean");
    $cod3 = $auth->generarCodigo($pid);
    check(!$auth->verificarCodigo($pid, $cod3), "{$quien}: bloqueado no entra ni con código válido");
    for ($i = 0; $i < 5; $i++) {
        $auth->generarCodigo($pid);
    }
    check($auth->demasiadosCodigos($pid), "{$quien}: tope de códigos pedidos");
}
$pdo->exec('DELETE FROM portal_equipo_codigos');
$pdo->exec('DELETE FROM portal_equipo_intentos');

// ---------------------------------------------------------------------------
seccion('Usuarios de agencia: validación');
check($eq->error(['nombre' => '', 'email' => 'x@y.cl']) !== null, 'nombre obligatorio');
check($eq->error(['nombre' => 'X', 'email' => 'no-es-email']) !== null, 'email inválido');
check($eq->error(['nombre' => 'X', 'email' => 'ana@agencia.cl']) !== null, 'email repetido');
check($eq->error(['nombre' => 'Ana', 'email' => 'ana@agencia.cl'], $ana) === null, 'editar sin cambiar email es válido');
check($eq->error(['nombre' => 'X', 'email' => 'CLARA@uno.cl']) !== null, 'no puede usar el email de un contacto de cliente');
$u = $eq->create(['nombre' => 'Rol raro', 'email' => 'raro@agencia.cl', 'rol' => 'superadmin']);
check($eq->find($u)['rol'] === 'equipo', 'rol desconocido cae en «equipo»');
check((int) $eq->find($u)['activo'] === 0, 'sin casilla «activo» queda inactivo');
$eq->delete($u);

// ---------------------------------------------------------------------------
seccion('Asignaciones y acceso');
$eq->guardarAsignaciones($ana, [$c1], [$p1a, $p2a, 'no-existe']);
$asig = $eq->asignaciones($ana);
check(count($asig) === 2, 'cliente completo + 1 proyecto suelto (el proyecto del cliente completo y el inexistente se ignoran)');
$acc = new P\EquipoAcceso($pdo, $eq->find($ana));
check($acc->puedeVerProyecto($p1a) && $acc->puedeVerProyecto($p1b), 've los proyectos del cliente completo');
check($acc->puedeVerProyecto($p2a), 've el proyecto suelto');
check(!$acc->puedeVerProyecto($p2b), 'no ve otro proyecto de ese cliente');
check(!$acc->puedeVerProyecto($p3a), 'no ve proyectos de clientes no asignados');
check(!$acc->puedeVerCliente($c3), 'no ve clientes no asignados');
$p1c = $ps->create(['cliente_id' => $c1, 'nombre' => 'Uno C (nuevo)']);
$acc = new P\EquipoAcceso($pdo, $eq->find($ana));
check($acc->puedeVerProyecto($p1c), 'un proyecto nuevo del cliente completo queda visible');
[$sql, $par] = $acc->filtroProyecto('p.id');
$n = $pdo->prepare("SELECT COUNT(*) FROM portal_proyectos p WHERE {$sql}");
$n->execute($par);
check((int) $n->fetchColumn() === 4, 'filtroProyecto devuelve sólo los visibles (3 de Uno + 1 de Dos)');

$coord = $eq->create(['nombre' => 'Coordina', 'email' => 'coord@agencia.cl', 'rol' => 'coordinador', 'activo' => 1]);
$accC = new P\EquipoAcceso($pdo, $eq->find($coord));
check($accC->todo() && $accC->puedeVerProyecto($p3a) && $accC->puedeVerCliente($c3), 'coordinación ve todo');
check(!$accC->puedeVerProyecto('no-existe'), 'coordinación: id inexistente no es visible');

$sinNada = $eq->create(['nombre' => 'Nadie', 'email' => 'nadie@agencia.cl', 'rol' => 'equipo', 'activo' => 1]);
$accN = new P\EquipoAcceso($pdo, $eq->find($sinNada));
[$sqlN] = $accN->filtroProyecto('p.id');
check($sqlN === '1 = 0', 'sin asignaciones no ve nada');

$personas = array_column($eq->delProyecto($p1a), 'nombre');
check(in_array('Ana', $personas, true) && in_array('Coordina', $personas, true) && !in_array('Nadie', $personas, true), 'personas del proyecto: asignadas + coordinación');

$eq->guardarAsignaciones($ana, [$c1], [$p2a]);
$eq->delete($sinNada);
check($eq->find($sinNada) === null, 'borrar usuario');

// ---------------------------------------------------------------------------
seccion('Front /equipo: login y páginas');
$_SESSION = [];
$ctl = new EquipoPrueba($ctx);

[, $r] = $ctl->correr(fn() => $ctl->inicio());
check($r === '/equipo/entrar', 'sin sesión redirige a entrar');

[$html] = $ctl->correr(fn() => $ctl->entrarForm());
check(str_contains($html, 'action="/equipo/entrar"'), 'formulario de entrada');

$_POST = ['_csrf' => 'malo', 'email' => 'ana@agencia.cl'];
[, $r] = $ctl->correr(fn() => $ctl->pedirCodigo());
check($r === '/equipo/entrar?error=sesion', 'CSRF inválido rechazado');

$ctx->correos = [];
$_POST = ['_csrf' => P\PortalSession::csrf(), 'email' => 'ANA@agencia.cl'];
[, $r] = $ctl->correr(fn() => $ctl->pedirCodigo());
check($r === '/equipo/verificar', 'pedir código lleva a verificar');
$cod = (string) $pdo->query("SELECT codigo FROM portal_equipo_codigos WHERE usuario_id = '{$ana}' ORDER BY created_at DESC LIMIT 1")->fetchColumn();
check($cod !== '', 'se generó un código');
check(count($ctx->correos) === 1 && str_contains($ctx->correos[0]['body'], $cod), 'el código llega por correo');

$_POST = ['_csrf' => P\PortalSession::csrf(), 'email' => 'no-existe@agencia.cl'];
[, $r] = $ctl->correr(fn() => $ctl->pedirCodigo());
check($r === '/equipo/verificar', 'email desconocido: misma respuesta (no revela)');
$_SESSION['portal_equipo_email'] = 'ana@agencia.cl';

$_POST = ['_csrf' => P\PortalSession::csrf(), 'codigo' => '000000'];
[, $r] = $ctl->correr(fn() => $ctl->verificarCodigo());
check($r === '/equipo/verificar?error=1', 'código malo');

$_POST = ['_csrf' => P\PortalSession::csrf(), 'codigo' => substr($cod, 0, 3) . ' ' . substr($cod, 3)];
[, $r] = $ctl->correr(fn() => $ctl->verificarCodigo());
check($r === '/equipo', 'código bueno (con espacio) entra');
check(($_SESSION[P\EquipoController::SESION] ?? null) === $ana, 'queda la sesión de equipo');
check(!empty($eq->find($ana)['ultimo_acceso']), 'registra último acceso');

// Datos para la bandeja
$ts = new P\TareaService($pdo);
$tMia = $ts->create(['proyecto_id' => $p1a, 'titulo' => 'Tarea de Ana', 'asignado' => 'equipo', 'responsable_usuario_id' => $ana, 'visible_cliente' => 1]);
$tEnt = $ts->create(['proyecto_id' => $p1b, 'titulo' => 'Logos del cliente', 'tipo' => 'archivo', 'asignado' => 'cliente', 'responsable_contacto_id' => $contacto]);
$ts->cambiarEstado($tEnt, 'entregada');
$tOtra = $ts->create(['proyecto_id' => $p3a, 'titulo' => 'Tarea ajena', 'asignado' => 'equipo']);

[$html] = $ctl->correr(fn() => $ctl->inicio());
check(str_contains($html, 'Tarea de Ana'), 'inicio: muestra lo asignado a mí');
check(str_contains($html, 'Logos del cliente'), 'inicio: muestra lo que entregó el cliente');
check(!str_contains($html, 'Tarea ajena'), 'inicio: no muestra tareas de proyectos no asignados');
check(str_contains($html, '--brand: #6d5df6'), 'inicio: usa el color por defecto de la agencia');

(new P\AjustesService($pdo))->set('global', 'portal', 'color_agencia', '#e4572e');
[$html] = $ctl->correr(fn() => $ctl->inicio());
check(str_contains($html, '--brand: #e4572e'), 'inicio: usa el color de agencia de Ajustes');

[$html] = $ctl->correr(fn() => $ctl->clientes());
check(str_contains($html, 'Cliente Uno') && str_contains($html, 'Dos A'), 'clientes: completo + proyecto suelto');
check(!str_contains($html, 'Dos B') && !str_contains($html, 'Cliente Tres'), 'clientes: oculta lo no asignado');

[$html] = $ctl->correr(fn() => $ctl->cliente($c1));
check(str_contains($html, 'Clara Cliente'), 'ficha de cliente con contactos');
[, $r] = $ctl->correr(fn() => $ctl->cliente($c3));
check($r === '/equipo/clientes', 'ficha de cliente ajeno: redirige');

[$html] = $ctl->correr(fn() => $ctl->proyecto($p1a));
check(str_contains($html, 'Tarea de Ana') && str_contains($html, 'id="t-' . $tMia . '"'), 'proyecto: lista tareas con ancla');
[, $r] = $ctl->correr(fn() => $ctl->proyecto($p3a));
check($r === '/equipo/clientes', 'proyecto ajeno: redirige');
[, $r] = $ctl->correr(fn() => $ctl->proyecto($p2b));
check($r === '/equipo/clientes', 'proyecto no asignado de un cliente con otro asignado: redirige');

$_POST = ['_csrf' => P\PortalSession::csrf(), 'tema' => 'oscuro'];
$ctl->correr(fn() => $ctl->tema());
check((new P\AjustesService($pdo))->get('equipo', $ana, 'tema') === 'oscuro', 'guarda el tema del usuario');
[$html] = $ctl->correr(fn() => $ctl->inicio());
check(str_contains($html, 'class="dark"'), 'aplica el tema oscuro');

$eq->update($ana, ['nombre' => 'Ana', 'email' => 'ana@agencia.cl', 'rol' => 'equipo', 'activo' => 0]);
[, $r] = $ctl->correr(fn() => $ctl->inicio());
check($r === '/equipo/entrar', 'usuario desactivado pierde la sesión');
$eq->update($ana, ['nombre' => 'Ana', 'email' => 'ana@agencia.cl', 'rol' => 'equipo', 'activo' => 1]);

$_SESSION['portal_equipo_email'] = 'ana@agencia.cl';
$auth = new P\EquipoAuthService($pdo);
$eq->update($ana, ['nombre' => 'Ana', 'email' => 'ana@agencia.cl', 'rol' => 'equipo', 'activo' => 0]);
$c = $auth->generarCodigo($ana);
$_POST = ['_csrf' => P\PortalSession::csrf(), 'codigo' => $c];
[, $r] = $ctl->correr(fn() => $ctl->verificarCodigo());
check($r === '/equipo/verificar?error=1', 'usuario inactivo no entra aunque tenga código');

// ---------------------------------------------------------------------------
seccion('Admin · Equipo');
$adm = new P\EquipoAdminController($ctx);
$correr = static function (callable $f): array {
    ob_start();
    $redir = null;
    try {
        $f();
    } catch (TypeDock\Core\RedirectException $e) {
        $redir = $e->getMessage();
    }
    return [(string) ob_get_clean(), $redir];
};
[$html] = $correr(fn() => $adm->index());
check(str_contains($html, 'Coordina') && str_contains($html, 'Todos los clientes'), 'lista de usuarios');
[$html] = $correr(fn() => $adm->create());
check(str_contains($html, 'name="clientes[]"') && str_contains($html, 'name="proyectos[]"'), 'formulario con casillas de asignación');

$ctx->correos = [];
$_POST = ['nombre' => 'Beto', 'email' => 'beto@agencia.cl', 'rol' => 'equipo', 'invitar' => '1', 'proyectos' => [$p3a]];
[, $r] = $correr(fn() => $adm->store());
$beto = $eq->findByEmail('beto@agencia.cl');
check($beto !== null && (int) $beto['activo'] === 1, 'crear desde el admin (activo por defecto)');
check(count($ctx->correos) === 1 && str_contains($ctx->correos[0]['body'], '/equipo/entrar'), 'invitación por correo con el enlace');
check((new P\EquipoAcceso($pdo, $beto))->puedeVerProyecto($p3a), 'asignación guardada');

$_POST = ['nombre' => 'Otro', 'email' => 'beto@agencia.cl', 'rol' => 'equipo'];
[, $r] = $correr(fn() => $adm->store());
check(str_ends_with((string) $r, 'equipo/nuevo') && $_SESSION['td_flash']['error'] !== '', 'no permite email repetido');
unset($_SESSION['td_flash']);

$_POST = ['nombre' => 'Beto', 'email' => 'beto@agencia.cl', 'rol' => 'coordinador', 'activo' => '1', 'clientes' => [$c2]];
$correr(fn() => $adm->update($beto['id']));
check($eq->find($beto['id'])['rol'] === 'coordinador', 'editar rol');
check(count($eq->asignaciones($beto['id'])) === 1, 'editar asignaciones reemplaza las anteriores');

$correr(fn() => $adm->destroy($beto['id']));
check($eq->find($beto['id']) === null && $eq->asignaciones($beto['id']) === [], 'eliminar borra usuario y asignaciones');

// ---------------------------------------------------------------------------
seccion('Gestión desde el panel (mismos controladores que el admin)');

/** Panel de gestión que no termina el proceso. */
final class GestionPrueba extends P\EquipoGestion
{
    public ?string $redir = null;

    protected function redirectTo(string $url): void
    {
        $this->redir = $url;
        throw new RuntimeException('redirect');
    }

    protected function terminate(): void
    {
        throw new RuntimeException('fin');
    }

    /** @return array{0: string, 1: ?string} */
    public function hacer(string $metodo, string $clase, string $accion, array $args = [], ?string $tipo = null, bool $coord = false, array $post = []): array
    {
        $_SERVER['REQUEST_METHOD'] = $metodo;
        $_POST = $post;
        $this->redir = null;
        ob_start();
        try {
            $this->gestionar($clase, $accion, $args, $tipo, $coord);
        } catch (RuntimeException $e) {
            if (!in_array($e->getMessage(), ['redirect', 'fin'], true)) {
                ob_end_clean();
                throw $e;
            }
        }
        $_SERVER['REQUEST_METHOD'] = 'GET';
        return [(string) ob_get_clean(), $this->redir];
    }
}

$eq->update($ana, ['nombre' => 'Ana', 'email' => 'ana@agencia.cl', 'rol' => 'equipo', 'activo' => 1]);
$_SESSION[P\EquipoController::SESION] = $ana;
P\PortalSession::tomarFlash();
$g = new GestionPrueba($ctx);
$tok = P\PortalSession::csrf();
$T = P\TareaAdminController::class;

[$html, $r] = $g->hacer('GET', $T, 'index');
check($r === null && str_contains($html, 'Tarea de Ana') && !str_contains($html, 'Tarea ajena'), 'lista de tareas filtrada por asignación');
check(str_contains($html, 'class="min-w-0 pb-32 lg:pb-8 adm"'), 'se dibuja dentro del marco del panel');
check(str_contains($html, 'href="/equipo/tareas/' . $tMia . '"'), 'los enlaces apuntan al panel, no al admin');

[$html, $r] = $g->hacer('GET', $T, 'edit', [$tMia], 'tarea');
check($r === null && str_contains($html, 'action="/equipo/tareas/' . $tMia . '"'), 'editar tarea propia');
check(!str_contains($html, 'Tres A'), 'el selector de proyectos no ofrece proyectos ajenos');

[, $r] = $g->hacer('GET', $T, 'edit', [$tOtra], 'tarea');
check($r === '/equipo', 'tarea de un proyecto ajeno: bloqueada');

[, $r] = $g->hacer('POST', $T, 'update', [$tMia], 'tarea', false, ['titulo' => 'Hackeada', 'proyecto_id' => $p1a]);
check($r !== null && $ts->find($tMia)['titulo'] === 'Tarea de Ana', 'POST sin token CSRF: rechazado');

[, $r] = $g->hacer('POST', $T, 'update', [$tMia], 'tarea', false, ['_csrf_token' => $tok, 'titulo' => 'Movida', 'proyecto_id' => $p3a]);
check($r === '/equipo' && $ts->find($tMia)['proyecto_id'] === $p1a, 'no se puede mover una tarea a un proyecto ajeno');

[, $r] = $g->hacer('POST', $T, 'store', [], null, false, ['_csrf_token' => $tok, 'titulo' => 'Colada', 'proyecto_id' => $p3a]);
check($r === '/equipo' && (int) $pdo->query("SELECT COUNT(*) FROM portal_tareas WHERE titulo = 'Colada'")->fetchColumn() === 0, 'no se puede crear en un proyecto ajeno');

[, $r] = $g->hacer('POST', $T, 'store', [], null, false, ['_csrf_token' => $tok, 'titulo' => 'Nueva desde el panel', 'proyecto_id' => $p1b, 'asignado' => 'equipo', 'responsable_usuario_id' => $ana]);
$nueva = $pdo->query("SELECT id FROM portal_tareas WHERE titulo = 'Nueva desde el panel'")->fetchColumn();
check($nueva !== false && $r === '/equipo/tareas/' . $nueva, 'crear tarea desde el panel y volver a ella');

[, $r] = $g->hacer('POST', $T, 'update', [$tMia], 'tarea', false, ['_csrf_token' => $tok, 'titulo' => 'Tarea de Ana', 'proyecto_id' => $p1a, 'estado' => 'en_progreso', 'asignado' => 'equipo', 'responsable_usuario_id' => $ana, 'visible_cliente' => '1']);
check($ts->find($tMia)['estado'] === 'en_progreso', 'cambiar estado desde el panel');
$act = $pdo->query("SELECT actor_nombre FROM portal_actividad WHERE entidad_id = '{$tMia}' ORDER BY created_at DESC LIMIT 1")->fetchColumn();
check($act === 'Ana', 'la actividad queda firmada por la persona, no por «Equipo»');

$g->hacer('POST', $T, 'comentar', [$tMia], 'tarea', false, ['_csrf_token' => $tok, 'cuerpo' => 'Voy con esto']);
$com = $pdo->query("SELECT autor_id, autor_nombre, autor_tipo FROM portal_comentarios WHERE entidad_id = '{$tMia}'")->fetch();
check($com && $com['autor_id'] === $ana && $com['autor_nombre'] === 'Ana' && $com['autor_tipo'] === 'equipo', 'comentario con autor (id y nombre) del usuario de agencia');

$lista = array_column($ts->listAll(), 'responsable_nombre', 'id');
check(($lista[$tMia] ?? '') === 'Ana', 'responsable de agencia visible en la lista de tareas');

// Archivos
$archAjeno = typedock_uuid7();
$pdo->prepare("INSERT INTO portal_archivos (id, cliente_id, proyecto_id, entidad_tipo, entidad_id, nombre_original, ruta, mime, tamano, subido_por_tipo, subido_por_nombre, created_at) VALUES (?, ?, ?, 'tarea', ?, 'x.png', 'x.png', 'image/png', 1, 'equipo', 'Equipo', '2026-01-01')")
    ->execute([$archAjeno, $c3, $p3a, $tOtra]);
[, $r] = $g->hacer('GET', $T, 'verArchivo', [$archAjeno], 'archivo');
check($r === '/equipo', 'archivo de un proyecto ajeno: bloqueado');

// Entregas, reuniones, fases, contactos
$E = P\EntregaAdminController::class;
[, $r] = $g->hacer('POST', $E, 'store', [], null, false, ['_csrf_token' => $tok, 'titulo' => 'Grilla panel', 'proyecto_id' => $p1a]);
$ent = $pdo->query("SELECT id FROM portal_entregas WHERE titulo = 'Grilla panel'")->fetchColumn();
check($ent !== false && $r === '/equipo/entregas/' . $ent, 'crear entrega desde el panel');
$g->hacer('POST', $E, 'agregarContenido', [$ent], 'entrega', false, ['_csrf_token' => $tok, 'tipo' => 'post', 'titulo' => 'Post 1', 'copy' => 'Hola']);
check((int) $pdo->query("SELECT COUNT(*) FROM portal_contenidos WHERE entrega_id = '{$ent}'")->fetchColumn() === 1, 'agregar contenido');
$ctx->correos = [];
$g->hacer('POST', $E, 'publicar', [$ent], 'entrega', false, ['_csrf_token' => $tok]);
check($pdo->query("SELECT estado FROM portal_entregas WHERE id = '{$ent}'")->fetchColumn() === 'publicada', 'publicar entrega');
[$html] = $g->hacer('GET', $E, 'index');
check(str_contains($html, 'Grilla panel'), 'lista de entregas');
$entAjena = (new P\EntregaService($pdo))->create(['proyecto_id' => $p3a, 'titulo' => 'Ajena']);
[$html] = $g->hacer('GET', $E, 'index');
check(!str_contains($html, '>Ajena<'), 'no lista entregas ajenas');
[, $r] = $g->hacer('POST', $E, 'destroy', [$entAjena], 'entrega', false, ['_csrf_token' => $tok]);
check($r === '/equipo' && (new P\EntregaService($pdo))->find($entAjena) !== null, 'no puede borrar entregas ajenas');

$R = P\ReunionAdminController::class;
[, $r] = $g->hacer('POST', $R, 'store', [], null, false, ['_csrf_token' => $tok, 'proyecto_id' => $p1a, 'titulo' => 'Reunión panel', 'fecha_d' => '2026-10-10', 'fecha_t' => '11:00', 'publicada' => '1']);
$reu = $pdo->query("SELECT id FROM portal_reuniones WHERE titulo = 'Reunión panel'")->fetchColumn();
check($reu !== false && $r === '/equipo/reuniones/' . $reu, 'agendar reunión desde el panel');
[$html] = $g->hacer('GET', $R, 'edit', [$reu], 'reunion');
check(str_contains($html, 'Reunión panel') && str_contains($html, 'transcrip'), 'espacio de trabajo de la reunión (transcripción, resumen, tareas)');

[, $r] = $g->hacer('POST', P\FaseAdminController::class, 'store', [], null, false, ['_csrf_token' => $tok, 'proyecto_id' => $p1a, 'nombre' => 'Fase panel', 'orden' => 9]);
check((int) $pdo->query("SELECT COUNT(*) FROM portal_fases WHERE nombre = 'Fase panel'")->fetchColumn() === 1, 'crear fase');
[, $r] = $g->hacer('POST', P\ContactoAdminController::class, 'store', [], null, false, ['_csrf_token' => $tok, 'cliente_id' => $c3, 'nombre' => 'Intruso', 'email' => 'in@tres.cl']);
check($r === '/equipo' && (new P\ContactoService($pdo))->findByEmail('in@tres.cl') === null, 'no crea contactos en clientes ajenos');

// Sólo Coordinación
$C = P\ClienteAdminController::class;
[, $r] = $g->hacer('GET', $C, 'create', [], null, true);
check($r === '/equipo', 'Equipo no puede crear clientes');
[, $r] = $g->hacer('POST', P\ProyectoAdminController::class, 'destroy', [$p1b], 'proyecto', true, ['_csrf_token' => $tok]);
check($r === '/equipo' && (new P\ProyectoService($pdo))->find($p1b) !== null, 'Equipo no puede borrar proyectos');
$_SESSION[P\EquipoController::SESION] = $coord;
[$html, $r] = $g->hacer('GET', $C, 'edit', [$c3], 'cliente', true);
check($r === null && str_contains($html, 'Cliente Tres'), 'Coordinación edita clientes (y su portal)');
$_SESSION[P\EquipoController::SESION] = $ana;

// Avisos al equipo asignado
$ctx->correos = [];
(new P\AjustesService($pdo))->set('global', 'portal', 'email_avisos', 'jefe@agencia.cl');
$n = new P\Notifier($ctx, $pdo);
$n->alEquipo('María comentó', '', 'tareas/' . $tMia, ['proyecto_id' => $p1a]);
$para = array_column($ctx->correos, 'to');
check(in_array('jefe@agencia.cl', $para, true) && in_array('ana@agencia.cl', $para, true), 'aviso al correo de Ajustes y a la persona asignada');
$aAna = array_values(array_filter($ctx->correos, fn($c) => $c['to'] === 'ana@agencia.cl'))[0] ?? ['body' => ''];
check(str_contains($aAna['body'], '/equipo/tareas/' . $tMia), 'el aviso a la persona lleva el enlace al panel');
(new P\AjustesService($pdo))->set('equipo', $ana, 'avisos', '0');
$ctx->correos = [];
$n->alEquipo('Otra vez', '', 'tareas/' . $tMia, ['proyecto_id' => $p1a]);
check(!in_array('ana@agencia.cl', array_column($ctx->correos, 'to'), true), 'quien desactiva los avisos no los recibe');
$ctx->correos = [];
$n->alEquipo('Ajeno', '', 'tareas/' . $tOtra, ['proyecto_id' => $p3a]);
check(!in_array('ana@agencia.cl', array_column($ctx->correos, 'to'), true), 'no avisa de proyectos no asignados');
(new P\AjustesService($pdo))->set('equipo', $ana, 'avisos', '1');

// Mis ajustes
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['_csrf' => $tok, 'tema' => 'claro'];
$ctl->correr(fn() => $ctl->guardarAjustes());
check((new P\AjustesService($pdo))->get('equipo', $ana, 'avisos') === '0', 'Mis ajustes: desmarcar avisos los apaga');
$_SERVER['REQUEST_METHOD'] = 'GET';

// Admin de TypeDock: sigue viendo todo y firmando como equipo
$adminT = new P\TareaAdminController($ctx);
ob_start();
$adminT->index();
$html = (string) ob_get_clean();
check(str_contains($html, 'Tarea ajena') && str_contains($html, 'Tarea de Ana'), 'el admin sigue viendo todas las tareas');

// ---------------------------------------------------------------------------
seccion('Listas: filtros, orden, archivar en lote y colores por proyecto');
$_SESSION[P\EquipoController::SESION] = $ana;
$tok = P\PortalSession::csrf();
$ts->cambiarEstado($tMia, 'hecha');

$_GET = ['estado' => 'hecha'];
[$html] = $g->hacer('GET', $T, 'index');
check(str_contains($html, 'Tarea de Ana') && !str_contains($html, 'Logos del cliente'), 'filtro «Listas» muestra sólo las terminadas');
check(preg_match('/Listas <span class="pa-n">(\d+)/', $html, $m) === 1 && (int) $m[1] >= 1, 'las pastillas muestran conteos');
$_GET = ['estado' => 'abiertas', 'proyecto' => $p1b];
[$html] = $g->hacer('GET', $T, 'index');
check(str_contains($html, 'Logos del cliente') && !str_contains($html, 'Tarea de Ana'), 'filtro por proyecto');
$_GET = ['estado' => 'todas', 'turno' => 'cliente'];
[$html] = $g->hacer('GET', $T, 'index');
check(str_contains($html, 'Logos del cliente') && !str_contains($html, 'Nueva desde el panel'), 'filtro «le toca a: cliente»');
$_GET = ['estado' => 'todas', 'turno' => 'mias'];
[$html] = $g->hacer('GET', $T, 'index');
check(str_contains($html, 'Nueva desde el panel') && !str_contains($html, 'Logos del cliente'), 'filtro «asignadas a mí»');
$_GET = ['estado' => 'inventado', 'proyecto' => "x' OR 1=1", 'orden' => 'turno'];
[$html, $r] = $g->hacer('GET', $T, 'index');
check($r === null && str_contains($html, 'aria-current="true">') , 'valores de filtro desconocidos se ignoran');
$_GET = [];

$mapa = P\Fmt::coloresTodos($pdo);
check(isset($mapa[$p1a], $mapa[$p1b]) && $mapa[$p1a] !== $mapa[$p1b], 'cada proyecto de un cliente tiene su color');
[$html] = $g->hacer('GET', $T, 'index');
check(str_contains($html, 'pa-dot" style="background: ' . $mapa[$p1b]), 'el punto de color aparece en la lista');

// Archivar
$L = fn(array $post) => $g->hacer('POST', $T, 'lote', [], null, false, $post + ['_csrf_token' => $tok, 'volver' => '/equipo/tareas?estado=hecha']);
[, $r] = $L(['accion' => 'archivar', 'ids' => [$tMia, $tOtra]]);
check((int) $ts->find($tMia)['archivada'] === 1, 'archivar en lote');
check((int) $ts->find($tOtra)['archivada'] === 0, 'en lote no se tocan tareas de proyectos ajenos');
check($r === '/equipo/tareas?estado=hecha', 'vuelve a la lista con los mismos filtros');
[, $r] = $L(['accion' => 'archivar', 'ids' => [], 'volver' => 'https://otro.sitio/']);
check($r === '/equipo/tareas', 'no redirige a sitios externos');
$_GET = ['estado' => 'hecha'];
[$html] = $g->hacer('GET', $T, 'index');
check(!str_contains($html, 'Tarea de Ana'), 'las archivadas salen de «Listas»');
$_GET = ['estado' => 'archivadas'];
[$html] = $g->hacer('GET', $T, 'index');
check(str_contains($html, 'Tarea de Ana') && str_contains($html, 'Devolver a la lista'), 'aparecen en «Archivadas»');
$_GET = [];
$ts->cambiarEstado($tMia, 'en_progreso');
[$html] = $ctl->correr(fn() => $ctl->inicio());
check(!str_contains($html, 'Tarea de Ana'), 'una tarea archivada no aparece en la bandeja');
$L(['accion' => 'desarchivar', 'ids' => [$tMia]]);
[$html] = $ctl->correr(fn() => $ctl->inicio());
check((int) $ts->find($tMia)['archivada'] === 0 && str_contains($html, 'Tarea de Ana'), 'desarchivar la devuelve');
$L(['accion' => 'lista', 'ids' => [$tMia]]);
check($ts->find($tMia)['estado'] === 'hecha', 'marcar como listas en lote');
$L(['accion' => 'archivar_listas']);
check((int) $ts->find($tMia)['archivada'] === 1, '«Archivar todas las listas»');
$L(['accion' => 'desarchivar', 'ids' => [$tMia]]);

// Contenidos y reuniones
$E = P\EntregaAdminController::class;
$_GET = ['estado' => 'publicada'];
[$html] = $g->hacer('GET', $E, 'index');
check(str_contains($html, 'Grilla panel') && !str_contains($html, '>Ajena<'), 'contenidos: filtro por estado');
$_GET = ['estado' => 'todas', 'proyecto' => $p1b];
[$html] = $g->hacer('GET', $E, 'index');
check(!str_contains($html, 'Grilla panel'), 'contenidos: filtro por proyecto');
$R = P\ReunionAdminController::class;
$_GET = ['cuando' => 'todas'];
[$html] = $g->hacer('GET', $R, 'index');
check(str_contains($html, 'Reunión panel') && str_contains($html, 'Larga'), 'reuniones: «Todas»');
$_GET = ['cuando' => 'pasadas'];
[$html] = $g->hacer('GET', $R, 'index');
check(!str_contains($html, 'Reunión panel'), 'reuniones: «Pasadas» no muestra las próximas');
$_GET = ['cuando' => 'todas', 'estado' => 'ocultas'];
[$html] = $g->hacer('GET', $R, 'index');
check(!str_contains($html, 'Reunión panel'), 'reuniones: filtro de estado');
$_GET = [];

// ---------------------------------------------------------------------------
seccion('Portal del cliente sigue funcionando');
final class PublicoPrueba extends P\PortalPublicController
{
    protected function terminate(): void
    {
        throw new RuntimeException('fin');
    }
}
$_SESSION[P\PortalSession::CONTACTO] = $contacto;
$pub = new PublicoPrueba($ctx);
ob_start();
try {
    $pub->dashboard();
} catch (RuntimeException) {
}
$html = (string) ob_get_clean();
check(str_contains($html, 'Clara'), 'inicio del cliente se dibuja');
check(str_contains($html, 'id="i-home"'), 'íconos compartidos incluidos en el layout del cliente');

// ---------------------------------------------------------------------------
echo "\n\n" . $GLOBALS['ok'] . ' comprobaciones OK, ' . count($GLOBALS['fallas']) . " fallas ({$motor}).\n";
foreach ($GLOBALS['fallas'] as $f) {
    echo "  ✗ {$f}\n";
}
exit($GLOBALS['fallas'] === [] ? 0 : 1);

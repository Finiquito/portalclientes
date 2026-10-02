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
seccion('Solicitudes del cliente');
final class SolicitudPrueba extends P\SolicitudPublicController
{
    public ?string $redir = null;

    protected function redirectTo(string $path, array $query = []): void
    {
        $this->redir = $this->publicUrl($path, $query);
        throw new RuntimeException('redirect');
    }

    protected function terminate(): void
    {
        throw new RuntimeException('fin');
    }

    /** @return array{0: string, 1: ?string} */
    public function correr(string $metodo, array $args = [], array $post = []): array
    {
        $_POST = $post;
        $this->redir = null;
        ob_start();
        try {
            $this->{$metodo}(...$args);
        } catch (RuntimeException $e) {
            if (!in_array($e->getMessage(), ['redirect', 'fin'], true)) {
                ob_end_clean();
                throw $e;
            }
        }
        return [(string) ob_get_clean(), $this->redir];
    }
}

$sv = new P\SolicitudService($pdo, new DateTimeImmutable('2026-10-01 10:00', new DateTimeZone('America/Santiago')));
check($sv->fechaSugerida('urgente') === '2026-10-02' && $sv->fechaSugerida('semana') === '2026-10-08' && $sv->fechaSugerida('sin_apuro') === null, 'fechas sugeridas un jueves (prioritario = máximo 5 días hábiles)');
check($sv->rango('semana') === ['2026-10-05', '2026-10-08'], 'prioritario: entre 2 y 5 días hábiles, sin contar el fin de semana');
check((new P\Fmt(new DateTimeImmutable('2026-10-01')))->rangoFechas('2026-10-05', '2026-10-08') === 'lun 5 al jue 8 oct', 'rango legible para el cliente');
$svV = new P\SolicitudService($pdo, new DateTimeImmutable('2026-10-02 10:00', new DateTimeZone('America/Santiago')));
check($svV->fechaSugerida('urgente') === '2026-10-05' && $svV->fechaSugerida('semana') === '2026-10-09', 'un viernes: urgente salta el fin de semana');

$_SESSION[P\PortalSession::CONTACTO] = $contacto;
$ctk = P\PortalSession::csrf();
(new P\AjustesService($pdo))->set('equipo', $ana, 'avisos', '1');
$sp = new SolicitudPrueba($ctx);
$ss = new P\SolicitudService($pdo);
$contar = fn(): int => (int) $pdo->query('SELECT COUNT(*) FROM portal_solicitudes')->fetchColumn();

[$html] = $sp->correr('lista');
check(str_contains($html, 'Un presupuesto') && str_contains($html, 'Reportar un problema'), 'lista del cliente con los cuatro tipos');
$_GET = ['tipo' => 'pedido'];
[$html] = $sp->correr('nueva');
check(str_contains($html, 'name="urgencia"') && str_contains($html, 'Uno B'), 'formulario de pedido con urgencia y proyectos del cliente');
$_GET = [];

[, $r] = $sp->correr('crear', [], ['tipo' => 'pedido', 'proyecto_id' => $p1a, 'titulo' => 'Banner']);
check($contar() === 0, "sin token CSRF: no se guarda ({$r})");

[, $r] = $sp->correr('crear', [], ['_csrf' => $ctk, 'tipo' => 'pedido', 'proyecto_id' => $p3a, 'titulo' => 'Ajeno']);
check($contar() === 0, 'no se puede pedir en un proyecto de otro cliente');

[, $r] = $sp->correr('crear', [], ['_csrf' => $ctk, 'tipo' => 'pedido', 'proyecto_id' => $p1a, 'titulo' => 'Banner', 'urgencia' => 'urgente']);
check($contar() === 0 && str_contains((string) ($_SESSION['td_flash']['mensaje'] ?? json_encode($_SESSION)), 'urgente'), 'urgente sin motivo: se pide el motivo');
P\PortalSession::tomarFlash();

$ctx->correos = [];
[, $r] = $sp->correr('crear', [], ['_csrf' => $ctk, 'tipo' => 'pedido', 'proyecto_id' => $p1a, 'titulo' => 'Banner promo', 'detalle' => 'Formato 1080x1080', 'urgencia' => 'urgente', 'motivo_urgencia' => 'Sale el lunes']);
$sid = (string) $pdo->query("SELECT id FROM portal_solicitudes WHERE titulo = 'Banner promo'")->fetchColumn();
check($sid !== '' && $r === '/portal/solicitudes/' . $sid, 'pedido urgente con motivo: guardado');
$aviso = array_values(array_filter($ctx->correos, fn($c) => $c['to'] === 'ana@agencia.cl'))[0] ?? null;
check($aviso !== null && str_contains($aviso['subject'], '[URGENTE]') && str_contains($aviso['body'], 'Sale el lunes'), 'aviso al equipo asignado, marcado urgente y con el motivo');
check(str_contains((string) ($aviso['body'] ?? ''), '/equipo/solicitudes/' . $sid), 'el aviso enlaza a la solicitud en el panel');

[, $r] = $sp->correr('crear', [], ['_csrf' => $ctk, 'tipo' => 'pedido', 'proyecto_id' => $p1b, 'titulo' => 'Otro urgente', 'urgencia' => 'urgente', 'motivo_urgencia' => 'Todo es urgente']);
check((int) $pdo->query("SELECT COUNT(*) FROM portal_solicitudes WHERE titulo = 'Otro urgente'")->fetchColumn() === 0, 'segunda urgencia abierta: no se acepta (tope 1)');
P\PortalSession::tomarFlash();
[, $r] = $sp->correr('crear', [], ['_csrf' => $ctk, 'tipo' => 'problema', 'proyecto_id' => $p1b, 'titulo' => 'La web no carga']);
$prob = $ss->find((string) $pdo->query("SELECT id FROM portal_solicitudes WHERE titulo = 'La web no carga'")->fetchColumn());
check($prob !== null && $prob['urgencia'] === 'urgente', 'un problema siempre entra como urgente y no choca con el tope');

[, $r] = $sp->correr('crear', [], ['_csrf' => $ctk, 'tipo' => 'reunion', 'proyecto_id' => $p1a, 'titulo' => 'Revisar campaña', 'horarios' => ['2020-01-01T10:00']]);
check((int) $pdo->query("SELECT COUNT(*) FROM portal_solicitudes WHERE tipo = 'reunion'")->fetchColumn() === 0, 'reunión con horarios pasados: rechazada');
P\PortalSession::tomarFlash();
$futuro = (new DateTimeImmutable('+3 days', new DateTimeZone('America/Santiago')))->format('Y-m-d') . 'T11:00';
$sp->correr('crear', [], ['_csrf' => $ctk, 'tipo' => 'reunion', 'proyecto_id' => $p1a, 'titulo' => 'Revisar campaña', 'horarios' => [$futuro, '', $futuro], 'modalidad' => 'video']);
$sre = $ss->find((string) $pdo->query("SELECT id FROM portal_solicitudes WHERE tipo = 'reunion'")->fetchColumn());
check($sre !== null && P\SolicitudService::horarios($sre['horarios']) === [str_replace('T', ' ', $futuro)], 'reunión con horarios válidos (sin repetidos)');
$sp->correr('crear', [], ['_csrf' => $ctk, 'tipo' => 'presupuesto', 'proyecto_id' => $p1a, 'titulo' => 'Rediseño web', 'urgencia' => 'sin_apuro']);
$spre = (string) $pdo->query("SELECT id FROM portal_solicitudes WHERE tipo = 'presupuesto'")->fetchColumn();

[$html] = $sp->correr('ver', [$sid]);
check(str_contains($html, 'Banner promo') && str_contains($html, 'Sale el lunes'), 'detalle de la solicitud para el cliente');
$ajena = $ss->crear(['id' => 'x', 'cliente_id' => $c3, 'nombre' => 'Otro'], ['tipo' => 'pedido', 'proyecto_id' => $p3a, 'titulo' => 'De otro cliente']);
[, $r] = $sp->correr('ver', [(string) $ajena['id']]);
check($r === '/portal/solicitudes', 'el cliente no ve solicitudes de otro cliente');

// Equipo
$_SESSION[P\EquipoController::SESION] = $ana;
$tok = P\PortalSession::csrf();
$S = P\SolicitudAdminController::class;
[$html] = $g->hacer('GET', $S, 'index');
check(str_contains($html, 'Banner promo') && !str_contains($html, 'De otro cliente'), 'bandeja del panel filtrada por asignación');
check(strpos($html, 'Banner promo') < strpos($html, 'Rediseño web'), 'las urgentes primero');
[, $r] = $g->hacer('GET', $S, 'ver', [(string) $ajena['id']], 'solicitud');
check($r === '/equipo', 'solicitud de un proyecto ajeno: bloqueada');
[$html] = $g->hacer('GET', $S, 'ver', [$sid], 'solicitud');
check(str_contains($html, 'Aceptar y crear la tarea') && str_contains($html, 'value="' . $ss->fechaSugerida('urgente') . '"'), 'ficha con la fecha sugerida por la urgencia');

$ctx->correos = [];
[, $r] = $g->hacer('POST', $S, 'aceptar', [$sid], 'solicitud', false, ['_csrf_token' => $tok, 'titulo' => 'Banner promo', 'descripcion' => 'Formato 1080x1080', 'fecha_vencimiento' => '2026-10-02', 'responsable_usuario_id' => $ana, 'mensaje' => 'Lo tomamos']);
$sAc = $ss->find($sid);
$tarea = $ts->find((string) $sAc['tarea_id']);
check($sAc['estado'] === 'en_curso' && $tarea !== null && $tarea['responsable_usuario_id'] === $ana && (int) $tarea['visible_cliente'] === 1 && $tarea['fecha_vencimiento'] === '2026-10-02', 'aceptar crea la tarea visible, con responsable y fecha');
check($r === '/equipo/tareas/' . $sAc['tarea_id'], 'después de aceptar se abre la tarea');
$enCola = (int) $pdo->query("SELECT COUNT(*) FROM portal_correos_cola WHERE destino = 'clara@uno.cl' AND asunto LIKE 'Tomamos%'")->fetchColumn();
check(count(array_filter($ctx->correos, fn($c) => $c['to'] === 'clara@uno.cl' && str_contains($c['subject'], 'Tomamos'))) + $enCola === 1, 'el cliente recibe el aviso (o queda en cola hasta su horario hábil)');
[, $r] = $g->hacer('POST', $S, 'aceptar', [$sid], 'solicitud', false, ['_csrf_token' => $tok]);
check((int) $pdo->query("SELECT COUNT(*) FROM portal_tareas WHERE titulo = 'Banner promo'")->fetchColumn() === 1, 'aceptar dos veces no duplica la tarea');
check($ss->urgentesAbiertas($c1) === 1, 'la urgencia sigue abierta mientras la tarea no esté lista');
$ts->cambiarEstado((string) $sAc['tarea_id'], 'hecha');
check($ss->urgentesAbiertas($c1) === 0 && $ss->puedeUrgente($c1), 'tarea lista: se libera el cupo de urgencia');

[, $r] = $g->hacer('POST', $S, 'cotizar', [$spre], 'solicitud', false, ['_csrf_token' => $tok, 'monto' => '', 'mensaje' => 'x']);
check($ss->find($spre)['estado'] === 'nueva', 'cotizar sin valor: no se envía');
$g->hacer('POST', $S, 'cotizar', [$spre], 'solicitud', false, ['_csrf_token' => $tok, 'monto' => '$450.000 + IVA', 'validez' => '2026-10-30', 'mensaje' => 'Incluye 2 rondas']);
check($ss->find($spre)['estado'] === 'cotizada' && $ss->find($spre)['monto'] === '$450.000 + IVA', 'cotización enviada');
$_SESSION[P\PortalSession::CONTACTO] = $contacto;
[$html] = $sp->correr('ver', [$spre]);
check(str_contains($html, '$450.000 + IVA') && str_contains($html, 'Aprobar presupuesto'), 'el cliente ve la cotización y puede aprobarla');
$ctx->correos = [];
$sp->correr('decidir', [$spre], ['_csrf' => $ctk, 'decision' => 'aprobar', 'cuerpo' => 'Dale']);
check($ss->find($spre)['estado'] === 'aprobada' && count(array_filter($ctx->correos, fn($c) => str_contains($c['subject'], 'aprobó el presupuesto'))) >= 1, 'aprobar avisa al equipo');
$g->hacer('POST', $S, 'aceptar', [$spre], 'solicitud', false, ['_csrf_token' => $tok, 'titulo' => 'Rediseño web']);
check($ss->find($spre)['estado'] === 'en_curso', 'presupuesto aprobado: se convierte en tarea');

$ctx->correos = [];
[, $r] = $g->hacer('POST', $S, 'agendar', [$sre['id']], 'solicitud', false, ['_csrf_token' => $tok, 'fecha_elegida' => str_replace('T', ' ', $futuro), 'duracion_min' => '45', 'enlace_meet' => 'https://meet.google.com/abc-defg-hij']);
$sAg = $ss->find((string) $sre['id']);
$reu = $rs->find((string) $sAg['reunion_id']);
check($sAg['estado'] === 'agendada' && $reu !== null && (int) $reu['publicada'] === 1 && str_starts_with((string) $reu['fecha'], substr($futuro, 0, 10)), 'agendar crea la reunión publicada en el horario elegido');
check(count(array_filter($ctx->correos, fn($c) => $c['to'] === 'clara@uno.cl')) === 1, 'reunión confirmada al cliente');

[, $r] = $g->hacer('POST', $S, 'cerrar', [(string) $prob['id']], 'solicitud', false, ['_csrf_token' => $tok, 'estado' => 'respondida', 'mensaje' => '']);
check($ss->find((string) $prob['id'])['estado'] === 'nueva', 'cerrar exige un mensaje para el cliente');
$g->hacer('POST', $S, 'cerrar', [(string) $prob['id']], 'solicitud', false, ['_csrf_token' => $tok, 'estado' => 'respondida', 'mensaje' => 'Era la caché, ya está']);
check($ss->find((string) $prob['id'])['estado'] === 'respondida', 'responder y cerrar');
[, $r] = $g->hacer('POST', $S, 'destroy', [(string) $prob['id']], 'solicitud', true, ['_csrf_token' => $tok]);
check($ss->find((string) $prob['id']) !== null, 'borrar solicitudes: sólo Coordinación');

// Horarios en la hora del cliente (Cliente Dos está en México)
$c2c = (new P\ContactoService($pdo))->create(['cliente_id' => $c2, 'nombre' => 'Mario México', 'email' => 'mario@dos.mx', 'rol' => 'aprobador']);
$enMx = (new DateTimeImmutable('+4 days', new DateTimeZone('America/Mexico_City')))->format('Y-m-d') . ' 10:00';
$rmx = $ss->crear(['id' => $c2c, 'cliente_id' => $c2, 'nombre' => 'Mario México'], ['tipo' => 'reunion', 'proyecto_id' => $p2a, 'titulo' => 'Reunión MX', 'horarios' => [$enMx]]);
$hz = P\SolicitudService::horariosZona($ss->find((string) $rmx['id'])['horarios']);
check($hz['pais'] === 'MX' && $hz['lista'] === [$enMx], 'los horarios se guardan en la hora del país del cliente');
$enCl = P\SolicitudService::convertir($enMx, 'America/Mexico_City', P\ReunionService::ZONA);
check($enCl !== $enMx && substr($enCl, 0, 10) === substr($enMx, 0, 10), 'y se convierten a la hora de la agencia');
[$html] = $g->hacer('GET', $S, 'ver', [(string) $rmx['id']], 'solicitud');
check(str_contains($html, 'value="' . $enCl . '"') && str_contains($html, 'México'), 'al agendar se elige el horario ya convertido, con el país a la vista');
$g->hacer('POST', $S, 'agendar', [(string) $rmx['id']], 'solicitud', false, ['_csrf_token' => $tok, 'fecha_elegida' => $enCl]);
check(str_starts_with((string) $rs->find((string) $ss->find((string) $rmx['id'])['reunion_id'])['fecha'], $enCl), 'la reunión queda en hora de la agencia');

// Presupuesto para un proyecto nuevo
$_SESSION[P\PortalSession::CONTACTO] = $contacto;
$sp->correr('crear', [], ['_csrf' => $ctk, 'tipo' => 'presupuesto', 'proyecto_id' => 'nuevo', 'titulo' => 'App de reservas', 'urgencia' => 'sin_apuro']);
$pn = $ss->find((string) $pdo->query("SELECT id FROM portal_solicitudes WHERE titulo = 'App de reservas'")->fetchColumn());
check($pn !== null && $pn['proyecto_id'] === '' && $pn['proyecto_nombre'] === null, 'presupuesto de un proyecto nuevo: sin proyecto todavía');
[, $r] = $sp->correr('crear', [], ['_csrf' => $ctk, 'tipo' => 'pedido', 'proyecto_id' => 'nuevo', 'titulo' => 'Pedido sin proyecto']);
check((int) $pdo->query("SELECT COUNT(*) FROM portal_solicitudes WHERE titulo = 'Pedido sin proyecto'")->fetchColumn() === 0, '«proyecto nuevo» sólo vale para presupuestos');
P\PortalSession::tomarFlash();
[$html] = $g->hacer('GET', $S, 'index');
check(str_contains($html, 'App de reservas') && str_contains($html, 'Proyecto nuevo'), 'aparece en la bandeja de quien tiene el cliente asignado');
$ss->cotizar((string) $pn['id'], 'USD 2.000', '', 'Primera etapa', 'Ana');
$ss->decidir((string) $pn['id'], true);
[, $r] = $g->hacer('POST', $S, 'aceptar', [(string) $pn['id']], 'solicitud', false, ['_csrf_token' => $tok, 'titulo' => 'App de reservas', 'proyecto_id' => '', 'proyecto_nuevo' => '']);
check($ss->find((string) $pn['id'])['estado'] === 'aprobada', 'aceptar sin elegir ni nombrar el proyecto: no se crea nada');
$g->hacer('POST', $S, 'aceptar', [(string) $pn['id']], 'solicitud', false, ['_csrf_token' => $tok, 'titulo' => 'App de reservas', 'proyecto_id' => '', 'proyecto_nuevo' => 'App de reservas']);
$pnA = $ss->find((string) $pn['id']);
check($pnA['estado'] === 'en_curso' && $pnA['proyecto_nombre'] === 'App de reservas' && $ps->find((string) $pnA['proyecto_id'])['cliente_id'] === $c1, 'al aceptar se crea el proyecto del cliente y la tarea queda en él');

// Un presupuesto no es urgente ni ocupa el cupo de urgencias
$_SESSION[P\PortalSession::CONTACTO] = $contacto;
$antesU = $ss->urgentesAbiertas($c1);
$pu = $ss->crear(['id' => $contacto, 'cliente_id' => $c1, 'nombre' => 'Clara'], ['tipo' => 'presupuesto', 'proyecto_id' => $p1a, 'titulo' => 'Cotizar video', 'urgencia' => 'urgente', 'motivo_urgencia' => 'ya']);
check($ss->find((string) $pu['id'])['urgencia'] === 'semana' && $ss->urgentesAbiertas($c1) === $antesU, 'un presupuesto nunca queda urgente ni cuenta para el tope');
$_GET = ['tipo' => 'presupuesto'];
[$html] = $sp->correr('nueva');
check(!str_contains($html, 'value="urgente"') && str_contains($html, 'Prioritario'), 'el formulario de presupuesto no ofrece «Urgente»');
$_GET = [];
$_SESSION[P\EquipoController::SESION] = $ana;
[$html] = $g->hacer('GET', $S, 'ver', [(string) $pu['id']], 'solicitud');
check(preg_match('#<title>[^<]*</title>#', $html) === 1, 'el título de la ficha no trae el script del reloj');

// Ordenar un dictado con IA (simulada)
final class IaFalsa extends P\IaService
{
    public array $enviado = [];
    public function activa(): bool { return true; }
    protected function llamar(array $cuerpo): array { $this->enviado = $cuerpo; return ['content' => [['type' => 'text', 'text' => "- Carrusel de 3 láminas\n- Promo 2x1"]]]; }
}
final class SolicitudIa extends P\SolicitudPublicController
{
    public ?IaFalsa $falsa = null;
    protected function ia(): P\IaService { return $this->falsa ??= new IaFalsa($this->pdo()); }
    protected function terminate(): void { throw new RuntimeException('fin'); }
}
$si = new SolicitudIa($ctx);
$_POST = ['_csrf' => $ctk, 'texto' => 'eh necesito un carrusel de tres láminas eh con la promo dos por uno', 'tipo' => 'pedido'];
ob_start();
try { $si->ordenar(); } catch (RuntimeException) {}
$resp = json_decode((string) ob_get_clean(), true);
check(($resp['texto'] ?? '') === "- Carrusel de 3 láminas\n- Promo 2x1" && str_contains(json_encode($si->falsa->enviado, JSON_UNESCAPED_UNICODE), 'No inventes'), 'ordenar con IA devuelve el texto limpio y pide no inventar');
$_SESSION['portal_ia_dictado'] = array_fill(0, 10, time());
ob_start();
try { $si->ordenar(); } catch (RuntimeException) {}
$sal = (string) ob_get_clean();
check(str_contains($sal, 'última hora'), 'tope de 10 usos por hora y persona');
$_POST = ['texto' => 'x'];
ob_start();
try { $si->ordenar(); } catch (RuntimeException) {}
check(str_contains((string) ob_get_clean(), 'expiró'), 'sin token CSRF no se llama a la IA');
$_SESSION['portal_ia_dictado'] = [];

// Relojes del panel: Ana tiene clientes en Chile y México
$_SESSION[P\EquipoController::SESION] = $ana;
[$html] = $g->hacer('GET', $S, 'index');
check(str_contains($html, 'Hora de tus clientes') && str_contains($html, 'data-reloj="America/Mexico_City"') && str_contains($html, '🇲🇽'), 'la barra muestra la hora de cada país de sus clientes');

// Bienvenida: invitación, primeros pasos y ayuda
seccion('Bienvenida del cliente');
$_SESSION[P\EquipoController::SESION] = $ana;
$tok = P\PortalSession::csrf();
$CT = P\ContactoAdminController::class;
$ts->create(['proyecto_id' => $p1a, 'titulo' => 'Enviar el logo en alta', 'asignado' => 'cliente', 'tipo' => 'archivo', 'fecha_vencimiento' => '2030-01-10']);
$ctx->correos = [];
[, $r] = $g->hacer('POST', $CT, 'store', [], null, false, ['_csrf_token' => $tok, 'cliente_id' => $c1, 'nombre' => 'Nora Nueva', 'email' => 'nora@uno.cl', 'rol' => 'aprobador']);
$nora = (new P\ContactoService($pdo))->findByEmail('nora@uno.cl');
check($nora !== null && $ctx->correos === [] && $nora['invitado_en'] === null, 'crear contacto sin marcar la casilla: no se invita');
check($r === '/equipo/contactos/' . $nora['id'], 'y queda en su ficha para invitarlo después');
[$html] = $g->hacer('GET', $CT, 'edit', [$nora['id']], 'contacto');
check(str_contains($html, 'Sin invitar') && str_contains($html, 'Enviar invitación'), 'la ficha muestra que está sin invitar');
[, $r] = $g->hacer('POST', $CT, 'invitar', [$nora['id']], 'contacto', false, ['_csrf_token' => $tok, 'mensaje' => 'Aquí vamos a subir las piezas']);
$inv = $ctx->correos[0] ?? ['to' => '', 'subject' => '', 'body' => ''];
check($inv['to'] === 'nora@uno.cl' && str_contains($inv['subject'], 'te invita a tu portal'), 'invitación enviada al contacto');
check(str_contains($inv['body'], '/login?email=nora%40uno.cl') && str_contains($inv['body'], 'Aquí vamos a subir las piezas') && str_contains($inv['body'], 'Ana'), 'con el enlace al login, el mensaje personal y la firma');
check(str_contains($inv['body'], 'Enviar el logo en alta') && str_contains($inv['body'], 'código de 6 dígitos'), 'destaca la primera tarea y explica cómo entrar');
check((new P\ContactoService($pdo))->find($nora['id'])['invitado_en'] !== null, 'queda la fecha de invitación');
$ctx->correos = [];
$g->hacer('POST', $CT, 'store', [], null, false, ['_csrf_token' => $tok, 'cliente_id' => $c1, 'nombre' => 'Óscar Otro', 'email' => 'oscar@uno.cl', 'rol' => 'viewer', 'invitar' => '1']);
check(count($ctx->correos) === 1 && $ctx->correos[0]['to'] === 'oscar@uno.cl', 'con la casilla marcada, invita al crear');
$oscarTres = (new P\ContactoService($pdo))->create(['cliente_id' => $c3, 'nombre' => 'Ajeno', 'email' => 'ajeno@tres.cl']);
[, $r] = $g->hacer('POST', $CT, 'invitar', [$oscarTres], 'contacto', false, ['_csrf_token' => $tok]);
check($r === '/equipo', 'no se puede invitar a un contacto de un cliente ajeno');
(new P\ContactoService($pdo))->marcarAcceso($nora['id']);
$nf = (new P\ContactoService($pdo))->find($nora['id']);
$primero = $nf['primer_acceso'];
(new P\ContactoService($pdo))->marcarAcceso($nora['id']);
check($primero !== null && (new P\ContactoService($pdo))->find($nora['id'])['primer_acceso'] === $primero, 'el primer acceso no se pisa con los siguientes');

$_SESSION[P\PortalSession::CONTACTO] = $nora['id'];
$ctk = P\PortalSession::csrf();
$sn = new SolicitudPrueba($ctx);
[$html] = $sn->correr('dashboard');
check(str_contains($html, 'Primeros pasos') && str_contains($html, 'Revisa tu primera tarea') && str_contains($html, '1 de 5'), 'el inicio muestra los primeros pasos');
[$html] = $sn->correr('ayuda');
check(str_contains($html, '¿Cómo funciona?') && str_contains($html, 'Cambios pedidos') && str_contains($html, 'sin contraseña'), 'página «¿Cómo funciona?»');
$sn->correr('lista');
[$html] = $sn->correr('dashboard');
check(str_contains($html, '3 de 5'), 'leer la ayuda y ver Solicitudes marcan sus pasos');
$sn->correr('ocultarPrimerosPasos', [], ['_csrf' => $ctk]);
[$html] = $sn->correr('dashboard');
check(!str_contains($html, 'Primeros pasos'), '«Ya lo entendí» los oculta');
$_SESSION[P\PortalSession::CONTACTO] = $contacto;
$_SESSION[P\EquipoController::SESION] = $ana;

// Reuniones en la hora del cliente (Cliente Dos está en México; la agencia, en Chile)
seccion('Reuniones en la hora del cliente');
$_SESSION[P\EquipoController::SESION] = $ana;
$tok = P\PortalSession::csrf();
$R = P\ReunionAdminController::class;
$diaMx = (new DateTimeImmutable('+10 days'))->format('Y-m-d');
[, $r] = $g->hacer('POST', $R, 'store', [], null, false, ['_csrf_token' => $tok, 'proyecto_id' => $p2a, 'titulo' => 'Reunión hora MX', 'fecha_d' => $diaMx, 'fecha_t' => '10:00', 'publicada' => '1']);
$rmxId = (string) $pdo->query("SELECT id FROM portal_reuniones WHERE titulo = 'Reunión hora MX'")->fetchColumn();
$guardada = (string) $rs->find($rmxId)['fecha'];
check($guardada === $diaMx . ' 10:00', 'la hora se escribe y se guarda en la de la agencia');
[$html] = $g->hacer('GET', $R, 'edit', [$rmxId], 'reunion');
check(str_contains($html, 'name="fecha_t" class="form-input" value="10:00"') && str_contains($html, 'data-zona="America/Mexico_City"') && str_contains($html, 'Hora (Chile'), 'al editar se ve en la hora de la agencia, con la zona del cliente para la referencia');
$_SESSION[P\PortalSession::CONTACTO] = $c2c;
$sm = new SolicitudPrueba($ctx);
[$html] = $sm->correr('reunion', [$rmxId]);
check(str_contains($html, substr(P\Zona::aPais($diaMx . ' 10:00', 'MX'), 11, 5) . ' (hora de México)'), 'el cliente ve su reunión en su hora');
P\Zona::configurar('ES');
check(P\Zona::agencia() === 'Europe/Madrid' && P\Zona::aPais('2026-07-10 12:00', 'CL') === '2026-07-10 06:00', 'con la agencia en España, las horas se convierten desde Madrid');
P\Zona::configurar('CL');
(new P\AjustesService($pdo))->set('global', 'portal', 'pais_agencia', 'MX');
P\Zona::desdeAjustes($pdo);
check(P\Zona::pais() === 'MX', 'el país de la agencia se lee de Ajustes');
(new P\AjustesService($pdo))->set('global', 'portal', 'pais_agencia', 'CL');
P\Zona::desdeAjustes($pdo);
$_SESSION[P\PortalSession::CONTACTO] = $contacto;

// «Ver como cliente» (sólo lectura)
seccion('Ver como cliente');
unset($_SESSION[P\PortalSession::CONTACTO], $_SESSION[P\PortalSession::VISTA]);
$_SESSION[P\EquipoController::SESION] = $ana;
$ep = new EquipoPrueba($ctx);
$_POST = ['_csrf' => P\PortalSession::csrf()];
[, $r] = $ep->correr(fn() => $ep->verComo($oscarTres));
check($r === '/equipo/clientes' && empty($_SESSION[P\PortalSession::CONTACTO]), 'no se puede ver el portal de un cliente ajeno');
$_POST = [];
[, $r] = $ep->correr(fn() => $ep->verComo($contacto));
check($r === '/equipo/clientes' && empty($_SESSION[P\PortalSession::CONTACTO]), 'sin token CSRF no entra');
$_POST = ['_csrf' => P\PortalSession::csrf()];
[, $r] = $ep->correr(fn() => $ep->verComo($contacto));
check($r === '/portal' && $_SESSION[P\PortalSession::CONTACTO] === $contacto && P\PortalSession::vistaPrevia()['volver'] === '/equipo/clientes/' . $c1, 'entra al portal del contacto en vista previa');
$sv2 = new SolicitudPrueba($ctx);
[$html] = $sv2->correr('dashboard');
check(str_contains($html, 'Vista previa:') && str_contains($html, 'Clara Cliente') && str_contains($html, 'Salir de la vista previa'), 'el portal muestra el aviso de vista previa');
$antes = (int) $pdo->query('SELECT COUNT(*) FROM portal_solicitudes')->fetchColumn();
$sv2->correr('crear', [], ['_csrf' => P\PortalSession::csrf(), 'tipo' => 'pedido', 'proyecto_id' => $p1a, 'titulo' => 'Desde la vista previa', 'urgencia' => 'sin_apuro']);
check((int) $pdo->query('SELECT COUNT(*) FROM portal_solicitudes')->fetchColumn() === $antes && str_contains((string) json_encode($_SESSION['portal_flash'] ?? '', JSON_UNESCAPED_UNICODE), 'vista previa'), 'en vista previa no se puede enviar nada');
P\PortalSession::tomarFlash();
$sv2->correr('ayuda');
check((new P\AjustesService($pdo))->get('contacto', $contacto, 'paso_ayuda') === '', 'mirar no le marca los primeros pasos al cliente');
[, $r] = $sv2->correr('salirVistaPrevia');
check($r === '/equipo/clientes/' . $c1 && empty($_SESSION[P\PortalSession::CONTACTO]) && P\PortalSession::vistaPrevia() === null, 'salir vuelve a la ficha del cliente');
[$html] = $ep->correr(fn() => $ep->cliente($c1));
check(str_contains($html, 'Ver su portal') && str_contains($html, '/equipo/ver-como/'), 'la ficha del cliente tiene «Ver su portal»');
$_SESSION[P\PortalSession::CONTACTO] = $contacto;

$ss->borrarDeProyecto($p3a);
check($ss->find((string) $ajena['id']) === null, 'al borrar un proyecto se van sus solicitudes');

// ---------------------------------------------------------------------------
echo "\n\n" . $GLOBALS['ok'] . ' comprobaciones OK, ' . count($GLOBALS['fallas']) . " fallas ({$motor}).\n";
foreach ($GLOBALS['fallas'] as $f) {
    echo "  ✗ {$f}\n";
}
exit($GLOBALS['fallas'] === [] ? 0 : 1);

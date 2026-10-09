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
(new P\AjustesService($pdo))->set('equipo', $ana, 'avisos_como', 'instante');
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
$_POST = ['_csrf' => $tok, 'tema' => 'claro', 'avisos_que' => 'nada', 'avisos_como' => 'instante'];
$ctl->correr(fn() => $ctl->guardarAjustes());
$prefAna = P\Avisos::preferencias(new P\AjustesService($pdo), $ana);
check($prefAna['que'] === 'nada' && $prefAna['como'] === 'instante' && !$prefAna['resumen'], 'Mis ajustes: guarda qué, cómo y el resumen de la mañana');
$_POST = ['_csrf' => $tok, 'tema' => 'claro', 'avisos_que' => 'mio', 'avisos_como' => 'instante', 'resumen_diario' => '1'];
$ctl->correr(fn() => $ctl->guardarAjustes());
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
check(str_contains($html, 'Entraste a tu portal') && !str_contains($html, 'line-through decoration-1">Entraste a tu portal'), 'si el contacto nunca entró, «Entraste a tu portal» no aparece marcado');
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

// Reuniones: próxima, pasada o archivada
seccion('Reuniones pasadas y archivadas');
$ahoraR = new DateTimeImmutable('2026-10-02 15:00', new DateTimeZone(P\Zona::agencia()));
check(P\ReunionService::estado(['fecha' => '2026-10-02 13:30', 'duracion_min' => 60], $ahoraR) === 'pasada', 'una reunión de hoy que ya terminó cuenta como pasada');
check(P\ReunionService::estado(['fecha' => '2026-10-02 14:30', 'duracion_min' => 60], $ahoraR) === 'proxima', 'la que está en curso sigue como próxima');
check(P\ReunionService::estado(['fecha' => '2026-10-09 10:00', 'resumen' => 'Ya hablamos'], $ahoraR) === 'pasada', 'si ya tiene resumen, se asume pasada');
check(P\ReunionService::estado(['fecha' => '2026-09-01 10:00'], $ahoraR) === 'archivada', 'más de 20 días: archivada');
$rPas = $rs->create(['proyecto_id' => $p1a, 'titulo' => 'Reunión vieja', 'fecha' => (new DateTimeImmutable('-30 days'))->format('Y-m-d') . ' 10:00', 'publicada' => '1']);
$rRec = $rs->create(['proyecto_id' => $p1a, 'titulo' => 'Reunión de ayer', 'fecha' => (new DateTimeImmutable('-1 day'))->format('Y-m-d') . ' 10:00', 'enlace_meet' => 'https://meet.google.com/aaa-bbbb-ccc', 'publicada' => '1']);
$_SESSION[P\PortalSession::CONTACTO] = $contacto;
unset($_SESSION[P\PortalSession::VISTA]);
$sr = new SolicitudPrueba($ctx);
[$html] = $sr->correr('reuniones');
$posAyer = strpos($html, 'Reunión de ayer');
check($posAyer !== false && strpos($html, 'Archivadas (') !== false && strpos($html, 'Reunión vieja') > strpos($html, 'Archivadas ('), 'el cliente ve las viejas en «Archivadas», al final');
check(!str_contains(substr($html, $posAyer, 900), 'Entrar a Meet') && str_contains(substr($html, $posAyer, 900), 'Ya pasó'), 'las pasadas no muestran el botón de Meet');
$_SESSION[P\EquipoController::SESION] = $ana;
$_GET = ['cuando' => 'archivadas'];
[$html] = $g->hacer('GET', P\ReunionAdminController::class, 'index');
check(str_contains($html, 'Reunión vieja') && !str_contains($html, 'Reunión de ayer'), 'el panel tiene el filtro «Archivadas»');
$_GET = [];

// Revisión de contenidos: se envía sola al decidir la última pieza
seccion('Revisión que se envía sola');
final class RevisionPrueba extends P\EntregaPublicController
{
    public ?string $redir = null;
    protected function redirectTo(string $path, array $query = []): void { $this->redir = $this->publicUrl($path, $query); throw new RuntimeException('redirect'); }
    protected function terminate(): void { throw new RuntimeException('fin'); }
    public function correr(string $m, array $args, array $post): ?string
    {
        $_POST = $post; $this->redir = null; ob_start();
        try { $this->{$m}(...$args); } catch (RuntimeException) {}
        ob_end_clean();
        return $this->redir;
    }
}
$es2 = new P\EntregaService($pdo);
$cs2 = new P\ContenidoService($pdo);
$eRev = $es2->create(['proyecto_id' => $p1a, 'titulo' => 'Dos piezas']);
foreach (['Pieza A', 'Pieza B'] as $tt) { $cs2->crear($es2->find($eRev), ['tipo' => 'grafica', 'titulo' => $tt]); }
$es2->publicar($eRev);
$piezas = $pdo->query("SELECT id FROM portal_contenidos WHERE entrega_id = '{$eRev}' ORDER BY orden")->fetchAll(PDO::FETCH_COLUMN);
unset($_SESSION[P\PortalSession::VISTA]);
$_SESSION[P\PortalSession::CONTACTO] = $contacto;
$rp = new RevisionPrueba($ctx);
$ctx->correos = [];
$r = $rp->correr('decidir', [$piezas[0]], ['_csrf' => P\PortalSession::csrf(), 'accion' => 'aprobar']);
check($es2->find($eRev)['estado'] === 'publicada' && $r === '/portal/contenidos/' . $piezas[1] && $ctx->correos === [], 'con piezas pendientes no se envía: sigue a la siguiente');
$r = $rp->correr('decidir', [$piezas[1]], ['_csrf' => P\PortalSession::csrf(), 'accion' => 'aprobar']);
check($es2->find($eRev)['estado'] === 'aprobada' && $r === '/portal/entregas/' . $eRev, 'al decidir la última, la revisión se envía sola');
check(count(array_filter($ctx->correos, fn($c) => str_contains($c['subject'], 'respondió la revisión'))) >= 1, 'y le llega un solo aviso al equipo');
$eUna = $es2->create(['proyecto_id' => $p1a, 'titulo' => 'Una pieza']);
$cs2->crear($es2->find($eUna), ['tipo' => 'grafica', 'titulo' => 'Única']);
$es2->publicar($eUna);
$unica = (string) $pdo->query("SELECT id FROM portal_contenidos WHERE entrega_id = '{$eUna}'")->fetchColumn();
$rp->correr('decidir', [$unica], ['_csrf' => P\PortalSession::csrf(), 'accion' => 'cambios', 'cuerpo' => 'Más grande el logo']);
check($es2->find($eUna)['estado'] === 'respondida', 'con una sola pieza, pedir cambios también la envía al tiro');

$ss->borrarDeProyecto($p3a);
check($ss->find((string) $ajena['id']) === null, 'al borrar un proyecto se van sus solicitudes');

// ---------------------------------------------------------------------------
seccion('Brief de cada pieza: objetivo, láminas, notas y etiqueta');
$eBr = $es2->create(['proyecto_id' => $p1a, 'titulo' => 'Grilla con brief']);
[$cBr] = $cs2->crear($es2->find($eBr), [
    'tipo' => 'post', 'titulo' => 'Post 1 · Señales', 'copy' => 'Respirar bien…',
    'objetivo' => 'Que el paciente se reconozca en los síntomas.', 'pilar' => 'Funcional',
    'laminas' => "Lámina 1 (portada): foto F17. Texto: «5 señales de que tu nariz no está trabajando bien»\nilustración de fosas | Siempre se te tapa del mismo lado",
    'notas' => 'Daniella debe validar la explicación técnica.',
]);
$xBr = $cs2->find($cBr);
$lams = P\TiposContenido::laminas($xBr['laminas']);
check(count($lams) === 2 && $lams[0]['texto'] === '5 señales de que tu nariz no está trabajando bien' && $lams[1]['idea'] === 'ilustración de fosas', 'las láminas se guardan con idea y texto en imagen');
check(P\TiposContenido::etiqueta($xBr) === 'Carrusel · 2 láminas' && P\TiposContenido::etiqueta($xBr, 1) === 'Post', 'etiqueta automática: carrusel según láminas o imágenes');
check(P\TiposContenido::etiqueta(['tipo' => 'brandbook']) === 'Documento' && P\TiposContenido::etiqueta(['tipo' => 'brandbook', 'etiqueta' => 'Informe mensual']) === 'Informe mensual', 'un PDF ya no se presenta como «Brandbook»; la etiqueta propia manda');
$cs2->actualizar($cBr, ['titulo' => 'Post 1 · Señales', 'tipo' => 'post']);
check($cs2->find($cBr)['objetivo'] === 'Que el paciente se reconozca en los síntomas.', 'editar sin los campos del brief no los borra');
[$cDoc] = $cs2->crear($es2->find($eBr), ['tipo' => 'brandbook', 'titulo' => 'Informe']);
$es2->publicar($eBr);
$_SESSION[P\PortalSession::CONTACTO] = $contacto;
ob_start();
try { $rp->contenido($cBr); } catch (RuntimeException) {}
$htmlC = (string) ob_get_clean();
check(str_contains($htmlC, 'Objetivo') && str_contains($htmlC, 'Que el paciente se reconozca') && str_contains($htmlC, 'Pilar: Funcional'), 'el cliente ve el objetivo y el pilar');
check(str_contains($htmlC, 'nota-pegada') && str_contains($htmlC, 'Lámina 1 de 2') && str_contains($htmlC, '«Siempre se te tapa del mismo lado»'), 'sin imágenes, cada lámina se ve como nota pegada sobre su placeholder');
check(str_contains($htmlC, 'Notas del equipo') && str_contains($htmlC, 'Daniella debe validar'), 'las notas del equipo van en su propia caja');
check(str_contains($htmlC, 'Carrusel · 2 láminas'), 'el cliente ve la etiqueta automática');
ob_start();
try { $rp->contenido($cDoc); } catch (RuntimeException) {}
check(!str_contains((string) ob_get_clean(), 'Brandbook'), 'un documento no dice «Brandbook»');

$csv = tempnam(sys_get_temp_dir(), 'csv');
file_put_contents($csv, "titulo;objetivo;instrucciones_imagen;texto_en_imagen;comentarios\nPost 1;Vender;\"1. Foto. 2. Cifras. 3. Cierre.\";\"Slide 1: Hola\nSlide 3: Chao\";Ojo con las cifras\n");
$fila = P\ImportadorContenidos::leer($csv)['filas'][0] ?? [];
unlink($csv);
check(($fila['objetivo'] ?? '') === 'Vender' && ($fila['notas'] ?? '') === 'Ojo con las cifras' && count($fila['laminas'] ?? []) === 3 && ($fila['laminas'][2]['texto'] ?? '') === 'Chao' && ($fila['laminas'][1]['texto'] ?? 'x') === '', 'la planilla CSV ahora carga objetivo, láminas (con su texto) y notas');

// ---------------------------------------------------------------------------
seccion('Importar grilla (Word o texto)');
// Un .docx mínimo: título, lista numerada de Word y una tabla.
$docx = tempnam(sys_get_temp_dir(), 'dx') . '.docx';
$zip = new ZipArchive();
$zip->open($docx, ZipArchive::CREATE);
$w = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';
$par = fn(string $t, string $num = '') => '<w:p>' . ($num !== '' ? '<w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="' . $num . '"/></w:numPr></w:pPr>' : '') . '<w:r><w:t xml:space="preserve">' . htmlspecialchars($t) . '</w:t></w:r></w:p>';
$zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?><w:document ' . $w . '><w:body>'
    . $par('POST 1') . $par('Tipo: Carrusel (2 láminas)') . $par('Instrucciones:') . $par('Foto del parque', '5') . $par('Detalle del pasto', '5')
    . '<w:tbl><w:tr><w:tc>' . $par('Copy:') . '</w:tc><w:tc>' . $par('Hola Chiloé') . '</w:tc></w:tr></w:tbl>'
    . '<w:p><w:r><w:t>Uno</w:t><w:br/><w:t>Dos</w:t></w:r></w:p></w:body></w:document>');
$zip->addFromString('word/numbering.xml', '<?xml version="1.0" encoding="UTF-8"?><w:numbering ' . $w . '><w:abstractNum w:abstractNumId="1"><w:lvl w:ilvl="0"><w:numFmt w:val="decimal"/></w:lvl></w:abstractNum><w:num w:numId="5"><w:abstractNumId w:val="1"/></w:num></w:numbering>');
$zip->close();
$txtDocx = P\LectorDocx::texto($docx);
unlink($docx);
check(str_contains($txtDocx, "1. Foto del parque\n2. Detalle del pasto"), 'el .docx se lee con su lista numerada de Word');
check(str_contains($txtDocx, "Copy:\nHola Chiloé") && str_contains($txtDocx, "Uno\nDos"), 'tablas y saltos de línea del .docx');
$falso = tempnam(sys_get_temp_dir(), 'no');
file_put_contents($falso, 'no soy un zip');
$err = '';
try { P\LectorDocx::texto($falso); } catch (RuntimeException $ex) { $err = $ex->getMessage(); }
unlink($falso);
check(str_contains($err, 'no es un .docx válido'), 'un archivo que no es .docx da un mensaje claro');

$grilla = "Grilla de prueba\nConsideraciones: no mencionar precios.\n\nPOST 1 · Carrusel (2 fotos)\nPilar: Segunda vivienda\nObjetivo: Instalar la idea de desconexión.\nInstrucciones de imagen:\n1. Foto panorámica.\n2. Detalle de pasto.\nTexto sugerido en la imagen:\nSlide 2: “Aquí puedes construir”\nCopy:\nHay lugares para el fin de semana.\n\nDesconectarte para conectar.\nHashtags: #AltoRilan #Chiloe\n\nPILAR 2 — DÓNDE INVERTIR\nPOST 2 · Reel (sin texto)\nObjetivo: Reel sensorial.\nGuion de video:\nPlano 1 (3 seg): Viento en el pasto.\nPlano 2 (3 seg): Vista al canal.\nAudio: sonido ambiente real.\nCopy:\nSin filtro, sin efectos.\nPOST 3\nTipo: Post único\nObjetivo: Prueba social. La estrella es el paciente.\nIdea en simple:\n• Fondo liso, cita en grande.\n• Texto sobreimpreso: «Gracias por todo»\nCopy:\nEstas palabras no son mías.\nNota: siempre con consentimiento por escrito.\nOrden sugerido de la grilla\nFila 1: Post 1 - Post 2\n";
$dv = P\GrillaImport::dividir($grilla);
check(count($dv['bloques']) === 3 && str_contains($dv['general'], 'no mencionar precios') && !str_contains($dv['bloques'][0], 'PILAR 2'), 'se divide en una pieza por encabezado; lo de arriba son indicaciones generales');
$l1 = P\GrillaImport::leer($dv['bloques'][0], 1);
check($l1['tipo'] === 'post' && $l1['pilar'] === 'Segunda vivienda' && count($l1['laminas']) === 2 && $l1['laminas'][1]['texto'] === 'Aquí puedes construir' && $l1['laminas'][0]['texto'] === '', 'sin IA: pilar, láminas y el texto de la «Slide 2» en la lámina 2');
check(str_ends_with($l1['copy'], "Desconectarte para conectar.\n\n#AltoRilan #Chiloe"), 'sin IA: el copy queda tal cual y los hashtags al final');
$l2 = P\GrillaImport::leer($dv['bloques'][1], 2);
check($l2['tipo'] === 'reel' && count($l2['laminas']) === 2 && $l2['laminas'][0]['idea'] === 'Viento en el pasto' && $l2['notas'] === 'Audio: sonido ambiente real.', 'sin IA: un reel con su guion por planos y el audio como nota');
$l3 = P\GrillaImport::leer($dv['bloques'][2], 3);
check(count($l3['laminas']) === 1 && $l3['laminas'][0]['texto'] === 'Gracias por todo' && $l3['notas'] === 'Siempre con consentimiento por escrito.' && str_contains($l3['anexo'], 'Orden sugerido'), 'sin IA: post único en una lámina; lo que sigue a la nota es del documento, no de la pieza');
check(P\GrillaImport::tipoDesde('Carrusel storytelling (5 fotos)') === 'post' && P\GrillaImport::tipoDesde('Story') === 'story', '«storytelling» no se confunde con una story');
check(P\GrillaImport::esLiteral("Hay lugares para el fin de semana.\n\n#Otra", $grilla) && !P\GrillaImport::esLiteral('Hay lugares para el finde.', $grilla), 'se detecta si el copy no aparece tal cual en el documento');

final class IaGrillaFalsa extends P\IaService
{
    public array $enviado = [];
    public bool $falla = false;
    public function activa(): bool { return true; }
    protected function llamar(array $cuerpo): array
    {
        $this->enviado = $cuerpo;
        if ($this->falla) {
            throw new RuntimeException('La IA está con problemas por ahora.');
        }
        preg_match_all('/<pieza n="(\d+)">/', (string) ($cuerpo['messages'][0]['content'] ?? ''), $m);
        $piezas = [];
        foreach ($m[1] as $n) {
            $piezas[] = ['n' => (int) $n, 'tipo' => $n === '2' ? 'reel' : 'post', 'titulo' => 'Post ' . $n . ' · IA', 'objetivo' => 'Objetivo ' . $n,
                'laminas' => [['idea' => 'Foto ' . $n, 'texto' => 'Texto ' . $n]], 'copy' => $n === '1' ? 'Hay lugares para el finde.' : 'Sin filtro, sin efectos.',
                'notas' => '', 'fecha' => null, 'anexo' => $n === '3' ? 'Orden sugerido de la grilla' : ''];
        }
        return ['content' => [['type' => 'tool_use', 'name' => 'registrar_piezas', 'input' => ['piezas' => $piezas]]]];
    }
}
class GrillaPrueba extends P\GrillaAdminController
{
    public static ?IaGrillaFalsa $ia = null;
    protected function ia(): P\IaService { return self::$ia ??= new IaGrillaFalsa($this->pdo()); }
    protected function terminate(): void { throw new RuntimeException('fin'); }
    protected function limpiarSalida(): void {}
}
$_SESSION[P\EquipoController::SESION] = $coord;
$tok = P\PortalSession::csrf();
$G = GrillaPrueba::class;
$eGr = $es2->create(['proyecto_id' => $p1a, 'titulo' => 'Grilla Word']);
[, $r] = $g->hacer('POST', $G, 'subir', [$eGr], 'entrega', false, ['_csrf_token' => $tok, 'texto' => $grilla, 'cuenta' => '@altorilan']);
check(preg_match('#^/equipo/entregas/' . $eGr . '/grilla/([a-f0-9]{32})$#', (string) $r, $mt) === 1, 'subir la grilla lleva a la vista previa');
$tokG = $mt[1] ?? '';
[$html] = $g->hacer('GET', $G, 'ver', [$eGr, $tokG], 'entrega');
check(str_contains($html, 'Leyendo la grilla con IA') && str_contains($html, 'data-pendientes="0"'), 'con IA, la vista previa procesa las tandas');
[$json] = $g->hacer('POST', $G, 'tanda', [$eGr, $tokG, '0'], 'entrega', false, ['_csrf_token' => $tok]);
$j = json_decode($json, true);
check(($j['ok'] ?? false) === true && ($j['hechas'] ?? 0) === 1 && str_contains(json_encode(GrillaPrueba::$ia->enviado, JSON_UNESCAPED_UNICODE), 'COPIA LITERAL'), 'una tanda se procesa con IA y se le pide copiar literal');
[$html] = $g->hacer('GET', $G, 'ver', [$eGr, $tokG], 'entrega');
check(str_contains($html, 'Post 2 · IA') && str_contains($html, 'Crear contenidos') && str_contains($html, 'Revisa el copy: no calza'), 'vista previa editable; avisa cuando la IA cambió el copy');
check(str_contains($html, 'no mencionar precios') && str_contains($html, 'Orden sugerido de la grilla'), 'las indicaciones generales (y lo que sobraba al final) se ofrecen para el mensaje de la entrega');
[, $r] = $g->hacer('POST', $G, 'crear', [$eGr, $tokG], 'entrega', false, ['_csrf_token' => $tok, 'cuenta' => '@altorilan', 'general' => 'No mencionar precios.', 'usar_general' => '1', 'p' => [
    0 => ['incluir' => '1', 'tipo' => 'post', 'titulo' => 'Post 1 · Desconexión', 'objetivo' => 'Instalar la idea', 'pilar' => 'Segunda vivienda', 'laminas' => "Foto panorámica\nDetalle | Aquí puedes construir", 'copy' => "Hay lugares.\n\n#AltoRilan", 'notas' => '', 'fecha' => '2026-10-20'],
    1 => ['incluir' => '1', 'tipo' => 'reel', 'titulo' => 'Post 2 · Reel', 'objetivo' => '', 'laminas' => 'Viento', 'copy' => 'Sin filtro', 'notas' => 'Audio: ambiente', 'fecha' => ''],
    2 => ['titulo' => 'Post 3 · no va', 'copy' => 'x'],
]]);
$creados = $cs2->listar($eGr);
check(count($creados) === 2 && $creados[0]['titulo'] === 'Post 1 · Desconexión' && $creados[0]['cuenta'] === '@altorilan' && str_starts_with((string) $creados[0]['fecha_publicacion'], '2026-10-20'), 'se crean solo las piezas marcadas, en orden y con la cuenta');
check($creados[0]['pilar'] === 'Segunda vivienda' && count(P\TiposContenido::laminas($creados[0]['laminas'])) === 2 && $creados[0]['copy'] === "Hay lugares.\n\n#AltoRilan" && $creados[1]['notas'] === 'Audio: ambiente' && $creados[1]['tipo'] === 'reel', 'cada pieza con su pilar, láminas, copy, notas y tipo');
check(str_contains((string) $es2->find($eGr)['mensaje'], 'No mencionar precios.') && str_contains((string) $r, '#imagenes'), 'las indicaciones generales pasan al mensaje; luego toca subir imágenes');
[, $r] = $g->hacer('GET', $G, 'ver', [$eGr, $tokG], 'entrega');
check($r === '/equipo/entregas/' . $eGr, 'una importación ya creada no se puede repetir');

GrillaPrueba::$ia = new IaGrillaFalsa($pdo);
GrillaPrueba::$ia->falla = true;
[, $r] = $g->hacer('POST', $G, 'subir', [$eGr], 'entrega', false, ['_csrf_token' => $tok, 'texto' => $grilla]);
preg_match('#/grilla/([a-f0-9]{32})$#', (string) $r, $mt);
[$json] = $g->hacer('POST', $G, 'tanda', [$eGr, $mt[1] ?? '', '0'], 'entrega', false, ['_csrf_token' => $tok]);
$j = json_decode($json, true);
[$html] = $g->hacer('GET', $G, 'ver', [$eGr, $mt[1] ?? ''], 'entrega');
check(str_contains((string) ($j['aviso'] ?? ''), 'se leyeron sin IA') && str_contains($html, 'Leída sin IA') && str_contains($html, 'Segunda vivienda'), 'si la IA falla, la tanda se lee sin IA y se avisa');
[, $r] = $g->hacer('POST', $G, 'subir', [$eGr], 'entrega', false, ['_csrf_token' => $tok, 'texto' => $grilla, 'modo' => 'simple']);
preg_match('#/grilla/([a-f0-9]{32})$#', (string) $r, $mt);
[$html] = $g->hacer('GET', $G, 'ver', [$eGr, $mt[1] ?? ''], 'entrega');
check(!str_contains($html, 'Leyendo la grilla') && str_contains($html, 'sin IA (por etiquetas)'), '«Leer sin IA» muestra la vista previa al instante');
[, $r] = $g->hacer('POST', $G, 'subir', [$entAjena], 'entrega', false, ['_csrf_token' => $tok, 'texto' => $grilla]);
$_SESSION[P\EquipoController::SESION] = $ana;
[, $r] = $g->hacer('POST', $G, 'subir', [$entAjena], 'entrega', false, ['_csrf_token' => $tok, 'texto' => $grilla]);
check($r === '/equipo', 'no se puede importar en una entrega ajena');
$_SESSION[P\EquipoController::SESION] = $coord;

// Imágenes por número
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
P\ArchivoService::$mover = fn(string $a, string $b) => copy($a, $b);
$subir = [];
foreach (['post-1-2.png', 'post-1-1.png', '2.png', 'foto-sin-numero.png', '9.png'] as $nom) {
    $tmp = tempnam(sys_get_temp_dir(), 'im');
    file_put_contents($tmp, $png);
    $subir['name'][] = $nom; $subir['type'][] = 'image/png'; $subir['tmp_name'][] = $tmp; $subir['error'][] = UPLOAD_ERR_OK; $subir['size'][] = strlen($png);
}
$_FILES = ['imagenes' => $subir];
[, $r] = $g->hacer('POST', $G, 'imagenes', [$eGr], 'entrega', false, ['_csrf_token' => $tok]);
$_FILES = [];
$a1 = $cs2->archivos((string) $creados[0]['version_id']);
$a2 = $cs2->archivos((string) $creados[1]['version_id']);
check(count($a1) === 2 && $a1[0]['nombre_original'] === 'post-1-1.png' && $a1[1]['nombre_original'] === 'post-1-2.png' && count($a2) === 1, 'las imágenes van a su pieza y en el orden de sus láminas');
$flash = json_encode(P\PortalSession::tomarFlash(), JSON_UNESCAPED_UNICODE);
check(str_contains($flash, 'foto-sin-numero.png') && str_contains($flash, '9.png') && str_contains($flash, '3 imagen(es) repartidas en 2 pieza(s)'), 'las que no calzan con ninguna pieza se informan');
P\ArchivoService::$mover = null;

// ---------------------------------------------------------------------------
seccion('Pines sobre imágenes y páginas');
$eP = $es2->create(['proyecto_id' => $p1a, 'titulo' => 'Con pines']);
[$cP, $vP] = $cs2->crear($es2->find($eP), ['tipo' => 'post', 'titulo' => 'Post con foto']);
P\ArchivoService::$mover = fn(string $a, string $b) => copy($a, $b);
$imgs = [];
foreach (['a.png', 'b.png'] as $nom) {
    $tmp = tempnam(sys_get_temp_dir(), 'im');
    file_put_contents($tmp, $png);
    $imgs[] = ['name' => $nom, 'type' => 'image/png', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($png)];
}
$subidas = (new P\ArchivoService($pdo))->guardarVarios($imgs, (string) $es2->find($eP)['cliente_id'], $p1a, 'version', $vP, ['tipo' => 'equipo', 'id' => null, 'nombre' => 'Equipo'])['ok'];
P\ArchivoService::$mover = null;
$img2 = (string) $subidas[1]['id'];
$es2->publicar($eP);
$_SESSION[P\PortalSession::CONTACTO] = $contacto;
$csrfC = P\PortalSession::csrf();
$rp->correr('comentar', [$cP], ['_csrf' => $csrfC, 'cuerpo' => 'Este logo más chico', 'ubicacion' => 'i' . $img2 . '@40.5,62']);
$rp->correr('comentar', [$cP], ['_csrf' => $csrfC, 'cuerpo' => 'Pin falso', 'ubicacion' => 'i' . typedock_uuid7() . '@10,10']);
$rp->correr('comentar', [$cP], ['_csrf' => $csrfC, 'cuerpo' => 'Fuera de rango', 'ubicacion' => 'p2@140,10']);
$rp->correr('comentar', [$cP], ['_csrf' => $csrfC, 'cuerpo' => 'En la página', 'ubicacion' => 'p2@10,20']);
$ubs = $pdo->query("SELECT cuerpo, ubicacion FROM portal_comentarios WHERE entidad_id = '{$cP}' ORDER BY created_at, id")->fetchAll(PDO::FETCH_KEY_PAIR);
check(($ubs['Este logo más chico'] ?? '') === 'i' . $img2 . '@40.5,62' && ($ubs['En la página'] ?? '') === 'p2@10,20', 'se guarda el punto marcado en una imagen o en una página');
check(array_key_exists('Pin falso', $ubs) && $ubs['Pin falso'] === null && $ubs['Fuera de rango'] === null, 'un pin en una imagen ajena o fuera de la imagen se descarta (el comentario queda)');
ob_start();
try { $rp->contenido($cP); } catch (RuntimeException) {}
$htmlP = (string) ob_get_clean();
check(str_contains($htmlP, 'data-pinable data-archivo="' . $img2 . '"') && preg_match('/class="pin" data-pin="[^"]+" data-x="40.5" data-y="62"[^>]*>\d</', $htmlP) === 1, 'el pin aparece sobre su imagen, numerado');
check(str_contains($htmlP, 'data-goto-pin=') && str_contains($htmlP, 'Imagen 2') && str_contains($htmlP, 'Marcar un punto en la imagen'), 'la nota muestra a qué imagen apunta y hay botón para marcar');
check(str_contains($htmlP, 'data-pag-input') && substr_count($htmlP, 'name="ubicacion"') === 2, 'el punto viaja con la nota o con el pedido de cambios');
$rp->correr('decidir', [$cP], ['_csrf' => $csrfC, 'accion' => 'cambios', 'cuerpo' => 'Mover el texto', 'ubicacion' => 'i' . $img2 . '@10,90']);
$ubC = $pdo->query("SELECT ubicacion FROM portal_comentarios WHERE entidad_id = '{$cP}' AND cuerpo = 'Mover el texto'")->fetchColumn();
check($ubC === 'i' . $img2 . '@10,90', 'pedir cambios también guarda el punto');
$pinsAdm = P\Ubicacion::pines((new P\ComentarioService($pdo))->listar('contenido', $cP), $vP, [(string) $subidas[0]['id'] => 1, $img2 => 2]);
check(count($pinsAdm) === 3 && array_column($pinsAdm, 'n') === [1, 2, 3], 'los pines se numeran en orden de llegada');
$_SESSION[P\EquipoController::SESION] = $coord;
[$htmlA] = $g->hacer('GET', P\EntregaAdminController::class, 'editContenido', [$cP], 'contenido');
check(str_contains($htmlA, 'class="pa-pin" style="left:40.5%;top:62%"') && str_contains($htmlA, 'Imagen 2 · punto ') && str_contains($htmlA, 'Página 2 · punto '), 'el equipo ve los puntos sobre la imagen y en cada comentario');

// ---------------------------------------------------------------------------
seccion('Gestión de la agencia en el panel: proyectos, equipo, alta de clientes, actividad y ajustes');

/** Corre un método propio del panel (no compartido con el admin) y devuelve [html, redirección]. */
$panel = static function (GestionPrueba $g, string $metodo, array $args = [], array $post = [], string $verbo = 'GET'): array {
    $_SERVER['REQUEST_METHOD'] = $verbo;
    $_POST = $post;
    $g->redir = null;
    ob_start();
    try {
        $g->{$metodo}(...$args);
    } catch (RuntimeException $e) {
        if (!in_array($e->getMessage(), ['redirect', 'fin'], true)) {
            ob_end_clean();
            throw $e;
        }
    }
    $_SERVER['REQUEST_METHOD'] = 'GET';
    return [(string) ob_get_clean(), $g->redir];
};

$_SESSION[P\EquipoController::SESION] = $coord;
$tok = P\PortalSession::csrf();
[$html, $r] = $panel($g, 'proyectos');
check($r === null && str_contains($html, 'Uno A') && str_contains($html, 'Dos B') && str_contains($html, 'Cliente Uno'), 'Coordinación ve la lista de todos los proyectos, con su cliente');
check(str_contains($html, 'href="/equipo/personas"') && str_contains($html, 'href="/equipo/agencia"'), 'Coordinación tiene el bloque «Agencia» en el menú');

// Personas: solo Coordinación.
$E = P\EquipoAdminController::class;
$_SESSION[P\EquipoController::SESION] = $ana;
[, $r] = $g->hacer('GET', $E, 'index', [], null, true);
check($r === '/equipo', 'alguien del equipo no entra a la sección Equipo');
[$html] = $panel($g, 'proyectos');
check(!str_contains($html, 'Dos B') && !str_contains($html, 'href="/equipo/agencia"'), 'la lista de proyectos respeta las asignaciones y no muestra «Agencia»');

$_SESSION[P\EquipoController::SESION] = $coord;
[$html, $r] = $g->hacer('GET', $E, 'index', [], null, true);
check($r === null && str_contains($html, 'Ana') && str_contains($html, 'Coordina'), 'Coordinación ve la lista del equipo');
[, $r] = $g->hacer('POST', $E, 'store', [], null, true, ['_csrf_token' => $tok, 'nombre' => 'Beto', 'email' => 'beto@agencia.cl', 'rol' => 'equipo', 'activo' => '1']);
$beto = $eq->findByEmail('beto@agencia.cl');
check($beto !== null && $r === '/equipo/personas/' . $beto['id'], 'crear una persona desde el panel y quedar en su ficha');
[, $r] = $g->hacer('POST', $E, 'update', [$coord], null, true, ['_csrf_token' => $tok, 'nombre' => 'Coordina', 'email' => 'coord@agencia.cl', 'rol' => 'equipo', 'activo' => '1']);
check($eq->find($coord)['rol'] === 'coordinador', 'nadie se quita Coordinación a sí mismo');
[, $r] = $g->hacer('POST', $E, 'destroy', [$coord], null, true, ['_csrf_token' => $tok]);
check($eq->find($coord) !== null, 'nadie se elimina a sí mismo');

// «Quién trabaja aquí»: asignar y quitar desde la ficha.
[, $r] = $panel($g, 'asignarCliente', [$c2], ['_csrf' => $tok, 'persona_id' => $beto['id']], 'POST');
check($r === '/equipo/clientes/' . $c2 . '#equipo-asignado', 'asignar una persona a un cliente vuelve a la ficha');
check(in_array($beto['id'], array_column($eq->delCliente($c2), 'id'), true), 'la persona queda asignada al cliente');
[, $r] = $panel($g, 'asignarProyecto', [$p1a], ['_csrf' => $tok, 'persona_id' => $beto['id']], 'POST');
$fila = array_values(array_filter($eq->delCliente($c1), fn($x) => $x['id'] === $beto['id']))[0] ?? null;
check($fila !== null && !$fila['completo'] && in_array($p1a, $fila['proyecto_ids'], true), 'asignar solo un proyecto');
[, $r] = $panel($g, 'quitarCliente', [$c2, $beto['id']], ['_csrf' => $tok], 'POST');
check(!in_array($beto['id'], array_column($eq->delCliente($c2), 'id'), true), 'quitar a la persona del cliente');
[, $r] = $panel($g, 'asignarCliente', [$c2], ['persona_id' => $beto['id']], 'POST');
check(!in_array($beto['id'], array_column($eq->delCliente($c2), 'id'), true), 'sin token no se asigna');
$_SESSION[P\EquipoController::SESION] = $ana;
[, $r] = $panel($g, 'asignarCliente', [$c1], ['_csrf' => P\PortalSession::csrf(), 'persona_id' => $beto['id']], 'POST');
$filaC1 = array_values(array_filter($eq->delCliente($c1), fn($x) => $x['id'] === $beto['id']))[0] ?? null;
check($filaC1 !== null && !$filaC1['completo'], 'solo Coordinación asigna personas');

// Alta de cliente en un paso, con invitación postergada.
$_SESSION[P\EquipoController::SESION] = $coord;
$antes = count($ctx->correos);
$Cl = P\ClienteAdminController::class;
[, $r] = $g->hacer('POST', $Cl, 'store', [], null, true, ['_csrf_token' => $tok, 'nombre' => 'Cliente Cuatro', 'pais' => 'CL',
    'proyecto_nombre' => 'Lanzamiento', 'contacto_nombre' => 'Rita', 'contacto_email' => 'rita@cuatro.cl', 'contacto_rol' => 'aprobador',
    'invitar' => 'despues', 'personas' => [$beto['id']]]);
$c4 = $pdo->query("SELECT id FROM portal_clientes WHERE nombre = 'Cliente Cuatro'")->fetchColumn();
check($c4 !== false && $r === '/equipo/clientes/' . $c4, 'crear cliente desde el panel y quedar en su ficha');
check((int) $pdo->query("SELECT COUNT(*) FROM portal_proyectos WHERE cliente_id = '{$c4}' AND nombre = 'Lanzamiento'")->fetchColumn() === 1, 'con su primer proyecto');
$rita = (new P\ContactoService($pdo))->findByEmail('rita@cuatro.cl');
check($rita !== null && $rita['cliente_id'] === $c4 && count($ctx->correos) === $antes, 'con su contacto, sin invitarlo todavía');
check(in_array($beto['id'], array_column($eq->delCliente((string) $c4), 'id'), true), 'y con el equipo asignado');
[$html] = $panel($g, 'cliente', [(string) $c4]);
check(str_contains($html, 'Falta invitar') && str_contains($html, '/equipo/contactos/' . $rita['id'] . '/invitar'), 'la ficha recuerda invitar al contacto');
[, $r] = $g->hacer('POST', $Cl, 'store', [], null, true, ['_csrf_token' => $tok, 'nombre' => 'Cliente Repetido', 'contacto_email' => 'rita@cuatro.cl']);
check((int) $pdo->query("SELECT COUNT(*) FROM portal_clientes WHERE nombre = 'Cliente Repetido'")->fetchColumn() === 0, 'un correo de contacto que ya existe no crea el cliente');

// Actividad con filtros, y cada persona solo la de sus clientes.
$Ac = P\ActividadAdminController::class;
$_GET = ['quien' => 'cliente'];
[$html, $r] = $g->hacer('GET', $Ac, 'actividad');
check($r === null && str_contains($html, 'aria-current="true">De clientes'), 'Actividad filtra por quién');
check(!str_contains($html, 'Coordina publicó'), 'con «De clientes» no aparece lo que hizo el equipo');
$_GET = ['cliente' => $c2];
[$html] = $g->hacer('GET', $Ac, 'actividad');
check(!str_contains($html, '<td>Cliente Uno</td>'), 'Actividad filtra por cliente');
$_GET = [];
$act = new P\ActividadService($pdo);
$act->registrar($c2, $p2a, 'contacto', 'Mario', 'comento', 'tarea', typedock_uuid7(), 'Nota en Dos A');
$act->registrar($c2, $p2b, 'contacto', 'Mario', 'comento', 'tarea', typedock_uuid7(), 'Nota en Dos B');
$act->registrar($c3, null, 'contacto', 'Tere', 'solicito', 'solicitud', typedock_uuid7(), 'Pedido de Tres');
$_SESSION[P\EquipoController::SESION] = $ana;
[$html] = $g->hacer('GET', $Ac, 'actividad');
check(str_contains($html, 'Nota en Dos A') && !str_contains($html, 'Nota en Dos B') && !str_contains($html, 'Pedido de Tres'), 'cada persona ve la actividad de sus clientes y proyectos');
check(str_contains($html, 'class="min-w-0 pb-32 lg:pb-8 adm"'), 'Actividad se dibuja dentro del panel');

// Ajustes de la agencia: solo Coordinación, y guardar no pisa el color.
[, $r] = $g->hacer('GET', $Ac, 'ajustesForm', [], null, true);
check($r === '/equipo', 'alguien del equipo no entra a los ajustes de la agencia');
$_SESSION[P\EquipoController::SESION] = $coord;
(new P\AjustesService($pdo))->set('global', 'portal', 'color_agencia', '#2448b0');
[$html, $r] = $g->hacer('GET', $Ac, 'ajustesForm', [], null, true);
check($r === null && str_contains($html, 'action="/equipo/agencia"') && str_contains($html, 'value="#2448b0"'), 'Coordinación abre los ajustes con el color guardado');

// Carga del equipo en Inicio.
[$html] = $panel($g, 'inicio');
check(str_contains($html, 'Carga del equipo') && str_contains($html, 'href="/equipo/personas/' . $ana . '"'), 'Coordinación ve la carga del equipo en Inicio');
$_SESSION[P\EquipoController::SESION] = $ana;
[$html] = $panel($g, 'inicio');
check(!str_contains($html, 'Carga del equipo'), 'el resto del equipo no la ve');
check((new P\Fmt())->iconoActividad('estado') === 'i-check' && (new P\Fmt())->iconoActividad('reunion') === 'i-calendar', 'íconos de actividad coherentes con el menú');

// ---------------------------------------------------------------------------
seccion('Avisos por persona: agrupados, resumen de la mañana, vencimientos y reuniones con convocados');

$aj = new P\AjustesService($pdo);
$enAgencia = fn(string $local): DateTimeImmutable => (new DateTimeImmutable($local, new DateTimeZone(P\Zona::agencia())))->setTimezone(new DateTimeZone('UTC'));
$para = fn(string $email): array => array_values(array_filter($ctx->correos, fn($c) => $c['to'] === $email));
$aj->set('global', 'portal', 'email_avisos', '');
$c5 = $cs->create(['nombre' => 'Cliente Cinco', 'pais' => 'CL']);
$p5 = $ps->create(['cliente_id' => $c5, 'nombre' => 'Cinco A']);
$luz = (new P\ContactoService($pdo))->create(['cliente_id' => $c5, 'nombre' => 'Luz Cinco', 'email' => 'luz@cinco.cl', 'rol' => 'aprobador']);
$eq->asignar($ana, $c5);
foreach ([$ana, $coord, $beto['id']] as $uid) {
    $aj->setMuchos('equipo', $uid, ['avisos_que' => 'mio', 'avisos_como' => 'agrupado', 'resumen_diario' => '1']);
}
$av = new P\Avisos($ctx, $pdo);
$pdo->exec('DELETE FROM portal_avisos_buzon');   // lo que dejaron las pruebas anteriores

// Reglas de «qué me llega»
check(P\Avisos::leToca('mio', 'a', 'a', true, true, false) && !P\Avisos::leToca('mio', 'b', 'a', true, true, false), 'lo que tiene responsable le llega sólo a esa persona');
check(P\Avisos::leToca('mio', 'b', null, true, true, false) && !P\Avisos::leToca('mio', 'c', null, false, true, true), 'lo que no tiene responsable, a quienes tienen asignado el cliente');
check(P\Avisos::leToca('mio', 'c', null, false, false, true), 'si nadie tiene asignado el cliente, le llega a Coordinación');
check(P\Avisos::leToca('todo', 'b', 'a', false, true, false) && !P\Avisos::leToca('nada', 'a', 'a', true, true, false), '«todo» recibe todo y «nada» nada');
$dest = array_column($av->destinatarios($p5, null, []), 'id');
check(in_array($ana, $dest, true) && !in_array($coord, $dest, true) && !in_array($beto['id'], $dest, true), 'sin responsable: a quien tiene asignado el cliente (Coordinación con «solo lo mío» no)');
$aj->set('equipo', $coord, 'avisos_que', 'todo');
check(in_array($coord, array_column($av->destinatarios($p5, null, []), 'id'), true), 'Coordinación con «todo» lo recibe');
$aj->set('equipo', $coord, 'avisos_que', 'mio');
check(array_column($av->destinatarios($p5, null, ['responsable' => $beto['id']]), 'id') === [], 'con responsable que no lo ve ni lo tiene asignado, los demás no reciben');
check(array_column($av->destinatarios($p5, null, ['responsable' => $beto['id'], 'solo_responsable' => true]), 'id') === [$beto['id']], '«te asignaron»: sólo a esa persona');
check(array_column($av->destinatarios($p5, null, ['actor' => $ana]), 'id') === [], 'a quien lo hizo no se le avisa');

// Agrupados
P\Notifier::$ahora = $enAgencia('2027-01-13 10:00');   // miércoles
$ctx->correos = [];
$n = new P\Notifier($ctx, $pdo);
for ($i = 1; $i <= 3; $i++) {
    P\Notifier::$ahora = $enAgencia('2027-01-13 09:5' . $i);
    $n->alEquipo("Luz comentó ({$i})", 'Texto', 'tareas/t-uno', ['proyecto_id' => $p5, 'clave' => 'tareas/t-uno']);
}
$n->alEquipo('Luz subió archivos', '', 'tareas/t-dos', ['proyecto_id' => $p5]);
check($para('ana@agencia.cl') === [] && count($av->pendientes($ana)) === 4, 'agrupado: los avisos esperan en el buzón');
check($av->barrer() === 0, 'con 4 avisos y menos de 3 horas, todavía no sale');
$n->alEquipo('[URGENTE] Se cayó la web', '', 'solicitudes/s1', ['proyecto_id' => $p5, 'urgente' => true]);
check(count($para('ana@agencia.cl')) === 1 && count($av->pendientes($ana)) === 4, 'las urgencias salen al tiro');
$n->alEquipo('Luz aprobó', '', 'tareas/t-tres', ['proyecto_id' => $p5]);
$ctx->correos = [];
check($av->barrer() === 1 && count($av->pendientes($ana)) === 0, 'con 5 avisos sale un solo correo agrupado');
$cuerpo = $para('ana@agencia.cl')[0]['body'] ?? '';
check(str_contains($cuerpo, 'CLIENTE CINCO · CINCO A') && str_contains($cuerpo, 'Luz comentó (3)') && str_contains($cuerpo, 'y 2 novedades más') && !str_contains($cuerpo, 'Luz comentó (1)'), 'ordenado por proyecto y con lo repetido junto');
$n->alEquipo('Uno solo', '', 'tareas/t-uno', ['proyecto_id' => $p5]);
P\Notifier::$ahora = $enAgencia('2027-01-13 13:05');
$ctx->correos = [];
check($av->barrer() === 1, 'a las 3 horas sale aunque sea uno');
$n->alEquipo('De noche', '', 'tareas/t-uno', ['proyecto_id' => $p5]);
P\Notifier::$ahora = $enAgencia('2027-01-13 23:30');
check($av->barrer() === 0, 'de noche no se barre');

// Resumen de la mañana
$rs5 = $rs->create(['proyecto_id' => $p5, 'titulo' => 'Revisión con Luz', 'fecha' => '2027-01-14 11:00', 'duracion_min' => 45, 'publicada' => 1, 'enlace_meet' => 'https://meet.google.com/xyz']);
(new P\Convocados($ctx, $pdo))->agregar($rs5, 'equipo', $ana);
$tVence = $ts->create(['proyecto_id' => $p5, 'titulo' => 'Entregar logo', 'asignado' => 'equipo', 'responsable_usuario_id' => $ana, 'fecha_vencimiento' => '2027-01-14']);
P\Notifier::$ahora = $enAgencia('2027-01-14 07:30');
check($av->barrer() === 0 && count($av->pendientes($ana)) === 1, 'antes de las 8:30 lo de la noche espera al resumen');
P\Notifier::$ahora = $enAgencia('2027-01-14 08:40');
$ctx->correos = [];
$av->resumenes();
$res = $para('ana@agencia.cl')[0] ?? ['subject' => '', 'body' => ''];
check(str_starts_with($res['subject'], 'Tu día: 1 reunión, 1 tarea por cerrar'), 'resumen de la mañana con lo del día');
check(str_contains($res['body'], 'Revisión con Luz') && str_contains($res['body'], 'Entregar logo') && str_contains($res['body'], 'De noche'), 'trae reuniones, lo que vence y lo que llegó en la noche');
check(count($av->pendientes($ana)) === 0, 'lo de la noche queda enviado con el resumen');
$ctx->correos = [];
$av->resumenes();
check($para('ana@agencia.cl') === [], 'el resumen sale una sola vez al día');
check($para('beto@agencia.cl') === [], 'si no hay nada, no hay resumen');
$aj->set('contacto', $luz, 'resumen_diario', '1');
$tCli = $ts->create(['proyecto_id' => $p5, 'titulo' => 'Enviar textos', 'asignado' => 'cliente', 'visible_cliente' => 1]);
P\Notifier::$ahora = $enAgencia('2027-01-15 08:40');
$ctx->correos = [];
$av->resumenes();
check(str_contains(($para('luz@cinco.cl')[0]['body'] ?? ''), 'Enviar textos'), 'el contacto que lo pidió recibe su resumen con lo que le toca');

// Vencimientos
$aj->set('equipo', $ana, 'avisos_como', 'instante');
$ts->create(['proyecto_id' => $p5, 'titulo' => 'Mañana sin falta', 'asignado' => 'equipo', 'responsable_usuario_id' => $ana, 'fecha_vencimiento' => '2027-01-16']);
$ctx->correos = [];
$av->vencimientos();
$av->vencimientos();
check(count(array_filter($para('ana@agencia.cl'), fn($c) => str_contains($c['subject'], 'Vence mañana: «Mañana sin falta»'))) === 1, 'vence mañana: un aviso, una sola vez');
check(count(array_filter($para('ana@agencia.cl'), fn($c) => str_contains($c['subject'], 'Se atrasó: «Entregar logo»'))) === 1, 'atrasada: un aviso');

// Te asignaron una tarea
$_SESSION[P\EquipoController::SESION] = $coord;
$tok = P\PortalSession::csrf();
$ctx->correos = [];
$aj->set('equipo', $beto['id'], 'avisos_como', 'instante');
$eq->asignar($beto['id'], $c5);
$g->hacer('POST', $T, 'store', [], null, false, ['_csrf_token' => $tok, 'titulo' => 'Diseñar banner', 'proyecto_id' => $p5, 'asignado' => 'equipo', 'responsable_usuario_id' => $beto['id']]);
check(count(array_filter($para('beto@agencia.cl'), fn($c) => str_contains($c['subject'], 'Te asignaron: «Diseñar banner»'))) === 1, 'a quien le asignan una tarea le llega el aviso');
check($para('ana@agencia.cl') === [], 'a los demás no');

// Reuniones con convocados
P\Notifier::$ahora = $enAgencia('2027-01-15 10:00');
$ctx->correos = [];
[, $r] = $g->hacer('POST', $R, 'store', [], null, false, ['_csrf_token' => $tok, 'proyecto_id' => $p5, 'titulo' => 'Kickoff Cinco', 'fecha_d' => '2027-01-26', 'fecha_t' => '10:00',
    'duracion_min' => '60', 'enlace_meet' => 'https://meet.google.com/kick', 'publicada' => '1', 'convocados_form' => '1', 'conv_equipo' => [$ana, $coord], 'conv_contacto' => [$luz, $contacto], 'invitar' => '1']);
$rk = (string) $pdo->query("SELECT id FROM portal_reuniones WHERE titulo = 'Kickoff Cinco'")->fetchColumn();
$conv = new P\Convocados($ctx, $pdo);
check($conv->ids($rk) === ['equipo' => [$ana, $coord], 'contacto' => [$luz]] || ($conv->ids($rk)['contacto'] === [$luz] && count($conv->ids($rk)['equipo']) === 2), 'se guardan los convocados (y no un contacto de otro cliente)');
$invAna = $para('ana@agencia.cl')[0] ?? ['subject' => '', 'body' => ''];
check(str_starts_with($invAna['subject'], 'Invitación: Kickoff Cinco') && str_contains($invAna['body'], 'calendar.google.com') && str_contains($invAna['body'], '/portal/reuniones/' . $rk . '/invitacion.ics?t='), 'invitación al equipo con Google Calendar y el .ics');
check(count($para('coord@agencia.cl')) === 1, 'a cada convocado del equipo le llega la suya (aunque la haya creado)');
$enCola = (int) $pdo->query("SELECT COUNT(*) FROM portal_correos_cola WHERE destino = 'luz@cinco.cl' AND asunto LIKE 'Invitación: Kickoff Cinco%' AND ics LIKE '%METHOD:REQUEST%'")->fetchColumn();
check(count($para('luz@cinco.cl')) + $enCola === 1, 'al contacto convocado le llega (en su horario hábil) con la invitación de calendario');
check($conv->tokenValido($rk, $conv->token($rk)) && !$conv->tokenValido($rk, 'falso'), 'el enlace del .ics va firmado');
$ctx->correos = [];
$pdo->exec("DELETE FROM portal_correos_cola");
$g->hacer('POST', $R, 'update', [$rk], 'reunion', false, ['_csrf_token' => $tok, 'proyecto_id' => $p5, 'titulo' => 'Kickoff Cinco', 'fecha_d' => '2027-01-27', 'fecha_t' => '10:00',
    'duracion_min' => '60', 'enlace_meet' => 'https://meet.google.com/kick', 'publicada' => '1', 'convocados_form' => '1', 'conv_equipo' => [$ana], 'conv_contacto' => [$luz], 'invitar' => '1', 'accion' => 'guardar']);
check(str_starts_with(($para('ana@agencia.cl')[0]['subject'] ?? ''), 'Cambió la reunión: Kickoff Cinco'), 'cambiar la fecha manda la versión nueva a los convocados');
check(str_starts_with(($para('coord@agencia.cl')[0]['subject'] ?? ''), 'Se canceló la reunión'), 'a quien se quita le llega la cancelación');
check((int) $rs->find($rk)['ics_seq'] === 1, 'la invitación sube de versión (el calendario la reemplaza)');
$ics = $rs->ics($rs->find($rk) + ['ics_seq' => 1], '', ['metodo' => 'REQUEST', 'organizador' => 'hola@agencia.cl', 'nombre_org' => 'Agencia', 'para' => 'luz@cinco.cl', 'nombre_para' => 'Luz']);
$ics = str_replace("\r\n ", '', $ics);   // las líneas largas vienen plegadas
check(str_contains($ics, 'METHOD:REQUEST') && str_contains($ics, 'SEQUENCE:1') && str_contains($ics, 'ORGANIZER;CN="Agencia":mailto:hola@agencia.cl') && str_contains($ics, 'mailto:luz@cinco.cl'), 'invitación .ics con organizador, invitado y versión');
$ctx->correos = [];
$g->hacer('POST', $R, 'update', [$rk], 'reunion', false, ['_csrf_token' => $tok, 'proyecto_id' => $p5, 'titulo' => 'Kickoff Cinco', 'fecha_d' => '2027-01-27', 'fecha_t' => '10:00',
    'duracion_min' => '60', 'enlace_meet' => 'https://meet.google.com/kick', 'publicada' => '1', 'convocados_form' => '1', 'conv_equipo' => [$ana], 'conv_contacto' => [$luz], 'invitar' => '1', 'accion' => 'guardar']);
check($ctx->correos === [], 'guardar sin cambios no reenvía nada');

// Recordatorio del día anterior
P\Notifier::$ahora = $enAgencia('2027-01-26 09:00');
$pdo->exec("DELETE FROM portal_avisos_marcas WHERE clave LIKE 'inv:%'");
$ctx->correos = [];
$pdo->exec("DELETE FROM portal_correos_cola");
$av->recordatorios();
$av->recordatorios();
$recCola = (int) $pdo->query("SELECT COUNT(*) FROM portal_correos_cola WHERE destino = 'luz@cinco.cl' AND asunto LIKE 'Mañana: Kickoff Cinco%'")->fetchColumn();
check(count(array_filter($para('ana@agencia.cl'), fn($c) => str_starts_with($c['subject'], 'Mañana: Kickoff Cinco'))) === 1 && count($para('luz@cinco.cl')) + $recCola === 1, 'recordatorio el día anterior, una sola vez, a cada convocado');

// Borrar la reunión cancela
$ctx->correos = [];
P\Notifier::$ahora = $enAgencia('2027-01-15 10:00');
$g->hacer('POST', $R, 'destroy', [$rk], 'reunion', false, ['_csrf_token' => $tok]);
check(str_starts_with(($para('ana@agencia.cl')[0]['subject'] ?? ''), 'Se canceló la reunión: Kickoff Cinco'), 'borrar la reunión manda la cancelación');

// Correo con invitación por SMTP: alternativa text/calendar y adjunto
$smtp = new P\SmtpCliente('localhost', 25, '', '', '');
$m = (new ReflectionMethod($smtp, 'mensaje'));
$m->setAccessible(true);
$mime = (string) $m->invoke($smtp, 'a@b.cl', 'Agencia', 'c@d.cl', 'Invitación', '<p>x</p>', 'x', null, "BEGIN:VCALENDAR\r\nMETHOD:REQUEST\r\nEND:VCALENDAR\r\n");
check(str_contains($mime, 'multipart/mixed') && str_contains($mime, 'text/calendar; charset=UTF-8; method=REQUEST') && str_contains($mime, 'filename="invitacion.ics"'), 'por SMTP la invitación va como calendario y como adjunto');
$mime = (string) $m->invoke($smtp, 'a@b.cl', 'Agencia', 'c@d.cl', 'Hola', '<p>x</p>', 'x', null, null);
check(str_contains($mime, 'multipart/alternative') && !str_contains($mime, 'multipart/mixed'), 'sin invitación, el correo queda igual que antes');

// Respaldo sin cron
$aj->set('global', 'portal', 'cron_ultimo', P\Notifier::$ahora->format('Y-m-d H:i:s'));
$aj->set('global', 'portal', 'avisos_ultimo', '');
$av->correrSiToca();
check($aj->get('global', 'portal', 'avisos_ultimo') === '', 'si el cron anda, las visitas no corren los avisos');
$aj->set('global', 'portal', 'cron_ultimo', '');
$av->correrSiToca();
check($aj->get('global', 'portal', 'avisos_ultimo') !== '', 'sin cron, las visitas los corren (como mucho cada 5 minutos)');
P\Notifier::$ahora = null;

// ---------------------------------------------------------------------------
seccion('Editor de láminas: una fila por lámina con su imagen');

$u1 = typedock_uuid7(); $u2 = typedock_uuid7(); $u3 = typedock_uuid7();
$lam = P\TiposContenido::laminas([['idea' => 'Foto', 'texto' => 'Hola', 'img' => $u2], ['idea' => '', 'texto' => '', 'img' => $u1], ['idea' => '', 'texto' => ''], ['idea' => 'x', 'img' => 'no-es-id']]);
check(count($lam) === 3 && $lam[0]['img'] === $u2 && $lam[1] === ['idea' => '', 'texto' => '', 'img' => $u1] && !isset($lam[2]['img']), 'cada lámina guarda su imagen; una fila vacía sin imagen se descarta');
$imgs = [['id' => $u1], ['id' => $u2], ['id' => $u3]];
$f = P\EntregaAdminController::filasLaminas([['idea' => 'A', 'texto' => ''], ['idea' => 'B', 'texto' => '']], $imgs);
check(count($f) === 3 && $f[0]['img']['id'] === $u1 && $f[1]['img']['id'] === $u2 && $f[2]['idea'] === '' && $f[2]['img']['id'] === $u3, 'láminas sin imagen amarrada: se reparten en orden y la imagen que sobra queda como fila');
$f = P\EntregaAdminController::filasLaminas([['idea' => 'A', 'texto' => '', 'img' => $u3], ['idea' => 'B', 'texto' => '']], $imgs);
check($f[0]['img']['id'] === $u3 && $f[1]['img'] === null && count($f) === 4, 'con imágenes amarradas se respeta la amarra y las demás van al final');

// ---------------------------------------------------------------------------
seccion('Avisos que ya no hacen falta no salen');

$aj->set('global', 'portal', 'horario_respetar', '1');
P\Notifier::$ahora = $enAgencia('2027-02-03 23:00');   // de noche: lo del cliente espera su horario
$nv = new P\Notifier($ctx, $pdo);
$tPend = $ts->create(['proyecto_id' => $p5, 'titulo' => 'Mandar el logo en alta', 'asignado' => 'cliente', 'visible_cliente' => 1]);
$nv->alCliente($c5, null, 'Tienes algo pendiente: Mandar el logo en alta', '', '/portal/tareas/' . $tPend, ['vigencia' => P\Vigencia::tarea($tPend)]);
$nv->alCliente($c5, null, 'Nuevo comentario', 'Hola', '/portal');
$enCola = fn(string $asunto) => (string) $pdo->query("SELECT estado FROM portal_correos_cola WHERE asunto = " . $pdo->quote($asunto))->fetchColumn();
check($enCola('Tienes algo pendiente: Mandar el logo en alta') === 'pendiente', 'el aviso al cliente espera su horario hábil');
$ts->cambiarEstado($tPend, 'hecha');
P\Notifier::$ahora = $enAgencia('2027-02-04 09:00');
$ctx->correos = [];
$nv->vaciarCola(50);
check($enCola('Tienes algo pendiente: Mandar el logo en alta') === 'descartado' && $para('luz@cinco.cl') !== [] && !in_array('Tienes algo pendiente: Mandar el logo en alta', array_column($ctx->correos, 'subject'), true), 'si la tarea se completó mientras esperaba, el aviso se descarta (y lo demás sale)');
check(P\Vigencia::sigue($pdo, null) && P\Vigencia::sigue($pdo, 'otra:cosa'), 'sin marca o con una marca desconocida, el aviso sale');

// Buzón del equipo
$aj->set('equipo', $beto['id'], 'avisos_como', 'agrupado');
$pdo->exec('DELETE FROM portal_avisos_buzon');
$tB = $ts->create(['proyecto_id' => $p5, 'titulo' => 'Retocar foto', 'asignado' => 'equipo', 'responsable_usuario_id' => $beto['id']]);
$nv->alEquipo('Te asignaron: «Retocar foto»', '', 'tareas/' . $tB, ['proyecto_id' => $p5, 'responsable' => $beto['id'], 'solo_responsable' => true, 'vigencia' => P\Vigencia::tarea($tB)]);
$nv->alEquipo('Luz comentó', '', 'tareas/' . $tB, ['proyecto_id' => $p5, 'responsable' => $beto['id'], 'solo_responsable' => true]);
check(count($av->pendientes($beto['id'])) === 2, 'los dos avisos esperan en el buzón');
$ts->cambiarEstado($tB, 'hecha');
check(array_column($av->pendientes($beto['id']), 'asunto') === ['Luz comentó'], 'si la tarea se completó, «te asignaron» sale del buzón; el comentario se mantiene');

// Invitación vieja a una reunión que cambió de hora
$rv = $rs->create(['proyecto_id' => $p5, 'titulo' => 'Revisión', 'fecha' => '2027-02-20 10:00', 'duracion_min' => 30, 'publicada' => 1]);
$marca = P\Vigencia::reunion($rv, 0);
check(P\Vigencia::sigue($pdo, $marca), 'la invitación vale para su versión');
$pdo->exec("UPDATE portal_reuniones SET ics_seq = 1 WHERE id = " . $pdo->quote($rv));
check(!P\Vigencia::sigue($pdo, $marca) && P\Vigencia::sigue($pdo, P\Vigencia::reunion($rv)), 'si la reunión cambió, la invitación vieja ya no sale (el recordatorio sí)');
$rs->delete($rv);
check(!P\Vigencia::sigue($pdo, P\Vigencia::reunion($rv)), 'si la reunión se borró, nada de ella sale');
P\Notifier::$ahora = null;

// ---------------------------------------------------------------------------
seccion('Equipo de una persona y títulos de tareas');

check(P\Fmt::mayusculaInicial('revisar el logo') === 'Revisar el logo' && P\Fmt::mayusculaInicial('¿cuándo publicamos?') === '¿Cuándo publicamos?'
    && P\Fmt::mayusculaInicial('«ñandú» en portada') === '«Ñandú» en portada' && P\Fmt::mayusculaInicial('3 fotos') === '3 fotos', 'primera letra en mayúscula (respeta signos al inicio)');
$tMay = $ts->create(['proyecto_id' => $p5, 'titulo' => 'enviar propuesta', 'asignado' => 'equipo']);
check($ts->find($tMay)['titulo'] === 'Enviar propuesta', 'las tareas se guardan con mayúscula inicial');
$rProp = $rs->create(['proyecto_id' => $p5, 'titulo' => 'Seguimiento', 'fecha' => '2027-03-01 10:00']);
$pid = $rs->agregarPropuesta($rProp, ['titulo' => 'mandar cotización'], 'ia');
check($rs->propuesta((string) $pid)['titulo'] === 'Mandar cotización', 'las tareas que propone la IA también');

check($eq->unico() === null && $ts->find($tMay)['responsable_usuario_id'] === null, 'con varias personas en el equipo, la tarea queda sin asignar para elegir');
$pdo->exec("UPDATE portal_equipo SET activo = 0 WHERE id <> " . $pdo->quote($ana));
check($eq->unico() === $ana, 'con una sola persona activa, esa es la del equipo');
$tSola = $ts->create(['proyecto_id' => $p5, 'titulo' => 'Diseñar post', 'asignado' => 'equipo']);
check($ts->find($tSola)['responsable_usuario_id'] === $ana, 'y las tareas del equipo se le asignan solas');
$tCli = $ts->create(['proyecto_id' => $p5, 'titulo' => 'Enviar fotos', 'asignado' => 'cliente']);
check($ts->find($tCli)['responsable_usuario_id'] === null, 'las del cliente no');
$pdo->exec('UPDATE portal_equipo SET activo = 1');

// ---------------------------------------------------------------------------
seccion('Línea de tiempo: hitos, dependencias y fechas estimadas');

use TypeDock\Plugin\Portal\Cronograma as Cr;
check(Cr::habilDesde('2027-03-06') === '2027-03-08' && Cr::siguienteHabil('2027-03-05') === '2027-03-08', 'días hábiles: el sábado pasa al lunes; después del viernes viene el lunes');
check(Cr::finTras('2027-03-08', 5) === '2027-03-12' && Cr::finTras('2027-03-11', 3) === '2027-03-15' && Cr::habilesEntre('2027-03-11', '2027-03-15') === 3, '5 días hábiles desde el lunes terminan el viernes; se saltan los fines de semana');

P\Notifier::$ahora = new DateTimeImmutable('2027-03-08 12:00', new DateTimeZone('UTC'));   // lunes
$pT = $ps->create(['cliente_id' => $c5, 'nombre' => 'Sitio web Cinco']);
$fs = new P\FaseService($pdo);
$fDis = $fs->create(['proyecto_id' => $pT, 'nombre' => 'Diseño', 'orden' => 1]);
$tA = $ts->create(['proyecto_id' => $pT, 'fase_id' => $fDis, 'titulo' => 'Brief', 'asignado' => 'equipo', 'fecha_inicio' => '2027-03-08', 'fecha_vencimiento' => '2027-03-10']);
$tB = $ts->create(['proyecto_id' => $pT, 'fase_id' => $fDis, 'titulo' => 'Bocetos', 'asignado' => 'equipo', 'depende_de' => $tA, 'duracion_dias' => 3, 'fecha_inicio' => '2030-01-01']);
$tC = $ts->create(['proyecto_id' => $pT, 'titulo' => 'Presentación', 'asignado' => 'equipo', 'depende_de' => $tB, 'duracion_dias' => 2]);
$bB = $ts->find($tB);
check($bB['depende_de'] === $tA && (int) $bB['duracion_dias'] === 3 && $bB['fecha_inicio'] === null, 'una tarea con dependencia guarda la anterior y la duración, sin fechas a mano');
$cr = new Cr($pdo);
$d = $cr->datos($pT);
$porT = array_column($d['tareas'], null, 'id');
check($porT[$tB]['estimada'] && $porT[$tB]['ini'] === '2027-03-11' && $porT[$tB]['fin'] === '2027-03-15', 'mientras la anterior sigue abierta, la fecha es estimada (parte el día hábil siguiente)');
check($porT[$tC]['estimada'] && $porT[$tC]['ini'] === '2027-03-16' && $porT[$tC]['fin'] === '2027-03-17', 'la estimación sigue la cadena');
check(!$cr->dependenciaValida($tA, $tC, $pT) && !$cr->dependenciaValida($tA, $tA, $pT), 'no se permiten dependencias en círculo');
$ts->update($tA, array_merge($ts->find($tA), ['asignado' => 'equipo', 'estado' => 'hecha']));
$bB = $ts->find($tB);
check($bB['fecha_inicio'] !== null && $bB['fecha_vencimiento'] === Cr::finTras((string) $bB['fecha_inicio'], 3), 'al completar la anterior, la siguiente recibe fechas reales según su duración');
$d = $cr->datos($pT);
check(!array_column($d['tareas'], null, 'id')[$tB]['estimada'] && array_column($d['tareas'], null, 'id')[$tC]['estimada'], 'la que ya tiene fecha deja de ser estimada; la siguiente sigue estimada');

$hs = new P\HitoService($pdo);
$h1 = $hs->create(['proyecto_id' => $pT, 'nombre' => 'diseño aprobado', 'fecha' => '2027-03-19', 'fase_id' => $fDis, 'visible_cliente' => '1']);
$h2 = $hs->create(['proyecto_id' => $pT, 'nombre' => 'Interno', 'fecha' => '2027-03-01']);
check($hs->find((string) $h1)['nombre'] === 'Diseño aprobado' && $hs->create(['proyecto_id' => $pT, 'nombre' => '', 'fecha' => '2027-03-01']) === null, 'hitos con nombre y fecha');
$d = $cr->datos($pT);
$hh = array_column($d['hitos'], null, 'id');
check($hh[$h1]['estado'] === 'pendiente' && $hh[$h2]['estado'] === 'atrasado', 'un hito sin cumplir y con fecha pasada se ve atrasado');
$ts->cambiarEstado($tB, 'hecha');
$hh = array_column($cr->datos($pT)['hitos'], null, 'id');
check($hh[$h1]['estado'] === 'cumplido', 'el hito ligado a una fase se cumple solo cuando la fase termina');
$hc = array_column($cr->datos($pT, true)['hitos'], 'id');
check(in_array($h1, $hc, true) && !in_array($h2, $hc, true), 'el cliente sólo ve los hitos visibles');
check($ts->moverFechas($tC, '2027-03-22', '2027-03-26') && (int) $ts->find($tC)['duracion_dias'] === 5, 'arrastrar una tarea con dependencia cambia su duración');
$tD = $ts->create(['proyecto_id' => $pT, 'titulo' => 'Libre', 'asignado' => 'equipo']);
check($ts->moverFechas($tD, '2027-03-24', '2027-03-22') && $ts->find($tD)['fecha_inicio'] === '2027-03-22' && $ts->find($tD)['fecha_vencimiento'] === '2027-03-24', 'arrastrar una tarea libre cambia sus fechas');
check($d['desde'] <= '2027-03-01' && $d['hasta'] >= '2027-03-19' && $d['dias'] >= 42, 'el eje cubre todo, con margen');
$eje = Cr::eje('2027-03-29', 10);
check(count($eje['meses']) === 2 && $eje['meses'][0]['nombre'] === 'Marzo 2027' && $eje['meses'][0]['dias'] === 3 && $eje['dias'][5]['finde'] && $eje['dias'][0]['lunes'], 'el eje separa los meses y marca fines de semana');
check((Cr::columna('2027-03-01'))('2027-03-11') === 10 && (Cr::columna('2027-03-01'))(null) === 0, 'cada día cae en su columna');

// Pantalla del equipo: Gantt, arrastre y hitos
class CronogramaPrueba extends P\CronogramaAdminController
{
    protected function terminate(): void { throw new RuntimeException('fin'); }
    protected function limpiarSalida(): void {}
}
$_SESSION[P\EquipoController::SESION] = $coord;
$tok = P\PortalSession::csrf();
$CR = CronogramaPrueba::class;
[$html] = $g->hacer('GET', $CR, 'ver', [$pT], 'proyecto');
check(str_contains($html, 'Línea de tiempo') && str_contains($html, 'Presentación') && str_contains($html, 'Diseño aprobado') && str_contains($html, 'data-tipo="tarea"'), 'el equipo ve la línea de tiempo con tareas e hitos');
check(str_contains($html, 'pa-gb-asa') && str_contains($html, '/equipo/proyectos/' . $pT . '/linea/mover'), 'en el panel las barras se pueden arrastrar');
[$json] = $g->hacer('POST', $CR, 'mover', [$pT], 'proyecto', false, ['_csrf_token' => $tok, 'tipo' => 'tarea', 'obj' => $tD, 'ini' => '2027-03-29', 'fin' => '2027-03-31']);
check((json_decode($json, true)['ok'] ?? false) === true && $ts->find($tD)['fecha_inicio'] === '2027-03-29' && $ts->find($tD)['fecha_vencimiento'] === '2027-03-31', 'arrastrar en el panel guarda las fechas');
[$json] = $g->hacer('POST', $CR, 'mover', [$pT], 'proyecto', false, ['_csrf_token' => $tok, 'tipo' => 'hito', 'obj' => (string) $h2, 'ini' => '2027-03-05', 'fin' => '2027-03-05']);
check((json_decode($json, true)['ok'] ?? false) === true && $hs->find((string) $h2)['fecha'] === '2027-03-05', 'los hitos también se arrastran');
$tOtro = $ts->create(['proyecto_id' => $p1a, 'titulo' => 'De otro proyecto', 'asignado' => 'equipo']);
[$json] = $g->hacer('POST', $CR, 'mover', [$pT], 'proyecto', false, ['_csrf_token' => $tok, 'tipo' => 'tarea', 'obj' => $tOtro, 'ini' => '2027-03-29', 'fin' => '2027-03-31']);
check(isset(json_decode($json, true)['error']) && $ts->find($tOtro)['fecha_inicio'] === null, 'no se puede mover algo de otro proyecto');
[, $r] = $g->hacer('POST', $CR, 'hitoGuardar', [$pT], 'proyecto', false, ['_csrf_token' => $tok, 'nombre' => 'lanzamiento', 'fecha' => '2027-04-15', 'visible_cliente' => '1']);
$hL = (string) $pdo->query("SELECT id FROM portal_hitos WHERE nombre = 'Lanzamiento'")->fetchColumn();
check($r === '/equipo/proyectos/' . $pT . '/linea' && $hL !== '', 'el diálogo crea hitos y vuelve a la línea de tiempo');
[, $r] = $g->hacer('POST', $CR, 'hitoGuardar', [$pT], 'proyecto', false, ['_csrf_token' => $tok, 'hito_id' => $hL, 'nombre' => 'Lanzamiento', 'fecha' => '2027-04-16', 'visible_cliente' => '1', 'cumplido' => '1']);
check($hs->find($hL)['fecha'] === '2027-04-16' && $hs->find($hL)['cumplido_en'] !== null, 'y los edita (fecha y cumplido)');
$_SESSION[P\EquipoController::SESION] = $eq->create(['nombre' => 'Sin Proyectos', 'email' => 'sinp@agencia.cl', 'rol' => 'equipo', 'activo' => 1]);
[, $r] = $g->hacer('GET', $CR, 'ver', [$pT], 'proyecto');
check($r === '/equipo', 'quien no tiene el proyecto asignado no ve su línea de tiempo');

// El cliente: Calendario del proyecto
$ctT = (new P\ContactoService($pdo))->create(['cliente_id' => $c5, 'nombre' => 'Carla Cinco', 'email' => 'carla@cinco.cl', 'rol' => 'aprobador']);
$_SESSION[P\PortalSession::CONTACTO] = $ctT;
$pdo->prepare('UPDATE portal_tareas SET visible_cliente = 1 WHERE id IN (?, ?)')->execute([$tA, $tB]);
$_GET = ['proyecto' => $pT];
ob_start();
try { $pub->calendario(); } catch (RuntimeException) {}
$html = (string) ob_get_clean();
$_GET = [];
check(str_contains($html, 'Calendario del proyecto') && str_contains($html, 'Diseño aprobado') && str_contains($html, 'Lanzamiento') && !str_contains($html, 'Interno'), 'el cliente ve su calendario, sin los hitos internos');
check(!str_contains($html, 'pa-gb-asa') && str_contains($html, 'pa-gantt') && str_contains($html, 'lectura'), 'para el cliente la línea de tiempo es de solo lectura');
$mom = P\PortalPublicController::momentos($cr->datos($pT, true));
$plano = array_merge(...array_values($mom));
check(count($plano) > 0 && $plano === array_values(array_filter($plano, fn($m) => true)) && array_column($plano, 'fecha') === (function ($f) { sort($f); return $f; })(array_column($plano, 'fecha')), 'en el celular, la lista va en orden de fecha');
check(in_array('Empieza Diseño', array_column($plano, 'titulo'), true) && in_array('hito', array_column($plano, 'tipo'), true), 'la lista incluye etapas e hitos');

// Reuniones y entregas con fase; reunión que vale como hito
$fOtra = $fs->create(['proyecto_id' => $p1a, 'nombre' => 'Fase ajena', 'orden' => 1]);
$rF = $rs->create(['proyecto_id' => $pT, 'titulo' => 'Revisión de bocetos', 'fecha' => '2027-03-12 10:00', 'publicada' => 1, '_linea' => '1', 'fase_id' => $fDis]);
$rH = $rs->create(['proyecto_id' => $pT, 'titulo' => 'Presentación final', 'fecha' => '2027-03-25 10:00', 'publicada' => 1, '_linea' => '1', 'fase_id' => $fOtra, 'es_hito' => '1']);
check($rs->find($rF)['fase_id'] === $fDis && $rs->find($rH)['fase_id'] === null && (int) $rs->find($rH)['es_hito'] === 1, 'una reunión se liga a una fase de su proyecto (no a una ajena) y puede ser hito');
$rs->update($rF, array_merge($rs->find($rF), ['titulo' => 'Revisión de bocetos']));
check($rs->find($rF)['fase_id'] === $fDis, 'guardar la reunión desde otro lado no le borra la fase');
$es3 = new P\EntregaService($pdo);
$eF = $es3->create(['proyecto_id' => $pT, 'titulo' => 'Bocetos v1', 'fecha_limite' => '2027-03-15', '_linea' => '1', 'fase_id' => $fDis]);
check($es3->find($eF)['fase_id'] === $fDis, 'una entrega también se liga a una fase');
$dF = $cr->datos($pT);
$rr = array_column($dF['reuniones'], null, 'id');
check($rr[$rF]['fase_id'] === $fDis && !$rr[$rF]['es_hito'] && $rr[$rH]['es_hito'], 'la línea de tiempo sabe la fase de cada reunión y cuáles son hitos');
$_SESSION[P\EquipoController::SESION] = $coord;
[$html] = $g->hacer('GET', $CR, 'ver', [$pT], 'proyecto');
check(str_contains($html, 'Revisión de bocetos') && str_contains($html, 'Hito · reunión: Presentación final') && str_contains($html, 'Bocetos v1'), 'reuniones, entregas y reuniones-hito aparecen en la línea');
$plano = array_merge(...array_values(P\PortalPublicController::momentos($cr->datos($pT, true))));
$porTit = array_column($plano, null, 'titulo');
check(($porTit['Presentación final']['tipo'] ?? '') === 'hito' && ($porTit['Revisión de bocetos']['fase'] ?? '') === 'Diseño', 'el cliente ve la reunión-hito como hito y la fase de cada reunión');
$fBorrar = $fs->create(['proyecto_id' => $pT, 'nombre' => 'Temporal', 'orden' => 9]);
$rs->update($rF, array_merge($rs->find($rF), ['_linea' => '1', 'fase_id' => $fBorrar]));
$fs->delete($fBorrar);
check($rs->find($rF)['fase_id'] === null, 'al borrar una fase, sus reuniones quedan en el proyecto sin fase');

// Crear desde la línea de tiempo vuelve a ella; hitos desde la ficha de la fase
$_GET = ['proyecto_id' => $pT, 'fase_id' => $fDis, 'linea' => '1'];
check((P\PantallaAdmin::preseleccion()['a_linea'] ?? '') === $pT && (P\PantallaAdmin::preseleccion()['fase_id'] ?? '') === $fDis, 'abrir «Nueva tarea» desde la línea deja elegidos proyecto y fase');
$_GET = [];
[, $r] = $g->hacer('POST', P\TareaAdminController::class, 'store', [], null, false, ['_csrf_token' => $tok, 'proyecto_id' => $pT, 'fase_id' => $fDis, 'titulo' => 'Desde la línea', 'asignado' => 'equipo', 'a_linea' => $pT]);
check($r === '/equipo/proyectos/' . $pT . '/linea', 'al guardar, vuelve a la línea de tiempo');
[, $r] = $g->hacer('POST', $CR, 'hitoGuardar', [$pT], 'proyecto', false, ['_csrf_token' => $tok, 'nombre' => 'Bocetos aprobados', 'fecha' => '2027-03-16', 'fase_id' => $fDis, 'volver_fase' => $fDis]);
check($r === '/equipo/fases/' . $fDis, 'el hito creado desde la ficha de la fase vuelve a ella');
[$html] = $g->hacer('GET', P\FaseAdminController::class, 'edit', [$fDis], 'fase');
check(str_contains($html, 'En esta fase') && str_contains($html, 'Bocetos aprobados') && str_contains($html, 'Revisión de bocetos') === false && str_contains($html, 'Bocetos v1'), 'la ficha de la fase muestra sus hitos, reuniones y entregas');
$pVacio = $ps->create(['cliente_id' => $c5, 'nombre' => 'Vacío']);
[$html] = $g->hacer('GET', $CR, 'ver', [$pVacio], 'proyecto');
check(str_contains($html, 'Arma la línea de tiempo en 3 pasos') && !str_contains($html, 'class="pa-gantt'), 'sin nada con fecha, la línea enseña cómo armarla');

// Proyectos por último movimiento
$pdo->prepare('UPDATE portal_proyectos SET updated_at = ? WHERE id = ?')->execute(['2000-01-01 00:00:00', $pVacio]);
$tRec = $ts->create(['proyecto_id' => $pVacio, 'titulo' => 'Recién', 'asignado' => 'equipo']);
$pdo->prepare('UPDATE portal_tareas SET updated_at = ? WHERE id = ?')->execute(['2099-01-01 00:00:00', $tRec]);   // lo más nuevo de todo
check(((new P\ProyectoService($pdo))->porMovimiento()[0]['id'] ?? '') === $pVacio, 'en «Proyecto», primero el que tuvo movimiento más reciente');
check(str_contains((string) file_get_contents(dirname(__DIR__) . '/portal/templates/equipo/_layout.latte'), '_guardando.latte') && str_contains((string) file_get_contents(dirname(__DIR__) . '/portal/templates/public/_layout.latte'), '_guardando.latte'), 'el aviso «Guardando…» está en el panel y en el portal');
P\Notifier::$ahora = null;

// ---------------------------------------------------------------------------
echo "\n\n" . $GLOBALS['ok'] . ' comprobaciones OK, ' . count($GLOBALS['fallas']) . " fallas ({$motor}).\n";
foreach ($GLOBALS['fallas'] as $f) {
    echo "  ✗ {$f}\n";
}
exit($GLOBALS['fallas'] === [] ? 0 : 1);

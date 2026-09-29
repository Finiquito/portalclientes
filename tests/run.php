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

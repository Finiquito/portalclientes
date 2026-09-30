<?php
declare(strict_types=1);

/**
 * Pruebas del plugin de invitaciones (lista de espera + Mailchimp simulado).
 *   php tests/invitaciones.php
 */

use TypeDock\Plugin\Invitaciones as I;

require dirname(__DIR__) . '/dev/bootstrap.php';

$ok = 0;
$fallas = [];
$check = function (bool $c, string $msg) use (&$ok, &$fallas): void {
    if ($c) {
        $ok++;
        echo '.';
    } else {
        $fallas[] = $msg;
        echo 'F';
    }
};

$dir = sys_get_temp_dir() . '/inv-pruebas-' . bin2hex(random_bytes(4));
putenv('INVITACIONES_DIR=' . $dir);
putenv('MAILCHIMP_API_KEY');
$pdo = portal_dev_pdo('sqlite::memory:');
$ctx = portal_dev_invitaciones($pdo);
$aj = new I\Ajustes($pdo);
$srv = new I\InvitacionService($pdo, $aj);

// ---- Validación y normalización ----
$check(I\InvitacionService::emailValido('ana@estudio.cl'), 'correo válido');
$check(!I\InvitacionService::emailValido('ana@estudio'), 'correo sin dominio completo');
$check(!I\InvitacionService::emailValido('hola'), 'correo inválido');
$d = I\InvitacionService::normalizar(['email' => ' ANA@Estudio.CL ', 'nombre' => '<b>Ana</b>  María', 'tamano' => '6-10', 'rubro' => 'Publicidad', 'origen' => 'x']);
$check($d['email'] === 'ana@estudio.cl' && $d['nombre'] === 'Ana María', 'normaliza correo y limpia etiquetas del nombre');
$check($d['tamano'] === '6-10' && $d['rubro'] === 'Publicidad' && $d['origen'] === 'otro', 'tamaño y rubro válidos, origen desconocido');
$d2 = I\InvitacionService::normalizar(['email' => 'x@y.cl', 'tamano' => '999', 'rubro' => 'Hackeo']);
$check($d2['tamano'] === '' && $d2['rubro'] === '', 'valores fuera de lista se descartan');

// ---- Clave cifrada ----
$clave = str_repeat('a1b2c3d4', 4) . '-us21';
$aj->guardarClave($clave);
$crudo = (string) $pdo->query("SELECT valor FROM invitaciones_ajustes WHERE clave = 'mc_clave'")->fetchColumn();
$check($crudo !== '' && !str_contains($crudo, 'a1b2c3d4'), 'la clave no queda en texto plano en la base');
$check($aj->clave() === $clave, 'la clave se descifra bien');
$check($aj->claveVisible() === '••••us21', 'sólo se muestran los últimos 4 caracteres');
$check(is_file($dir . '/.secreto'), 'el secreto vive en un archivo fuera de la base');
$check(I\Mailchimp::region($clave) === 'us21' && I\Mailchimp::claveValida($clave), 'región desde la clave');
$check(!I\Mailchimp::claveValida('417ce82c846ccaf50250a42bfaxxxxxx'), 'una clave sin región se rechaza');
putenv('MAILCHIMP_API_KEY=' . str_repeat('f', 32) . '-us5');
$check($aj->clave() === str_repeat('f', 32) . '-us5' && $aj->origenClave() === 'entorno', 'la variable de entorno tiene prioridad');
putenv('MAILCHIMP_API_KEY');

// ---- Guardar (upsert) ----
$id = $srv->guardar(I\InvitacionService::normalizar(['email' => 'ana@estudio.cl', 'origen' => 'portada']), 'ip1');
$id2 = $srv->guardar(I\InvitacionService::normalizar(['email' => 'ANA@estudio.cl', 'nombre' => 'Ana', 'tamano' => '2-5', 'origen' => 'formulario']), 'ip1');
$f = $srv->find($id);
$check($id === $id2 && $f['nombre'] === 'Ana' && $f['tamano'] === '2-5' && $f['origen'] === 'portada', 'el mismo correo completa la fila en vez de duplicar');

// ---- Mailchimp simulado ----
$llamadas = [];
$respuestas = [];
I\Mailchimp::$transporte = function (string $m, string $url, ?array $json) use (&$llamadas, &$respuestas): array {
    $llamadas[] = [$m, $url, $json];
    return array_shift($respuestas) ?? [200, ['status' => 'pending']];
};
$check(!$srv->configurado(), 'sin audiencia no está configurado');
$check(!$srv->sincronizar($id) && $srv->find($id)['mc_estado'] === 'pendiente', 'sin configurar queda pendiente');
$aj->set('mc_audiencia', 'fc1f3c0eb6');
$aj->set('mc_campo_tamano', 'MERGE7');
$aj->set('mc_etiquetas', 'invitacion-prisma, landing');

$llamadas = [];
$check($srv->sincronizar($id) && $srv->find($id)['mc_estado'] === 'ok', 'sincroniza con Mailchimp');
[$m, $url, $json] = $llamadas[0];
$check($m === 'PUT' && $url === 'https://us21.api.mailchimp.com/3.0/lists/fc1f3c0eb6/members/' . md5('ana@estudio.cl'), 'upsert al miembro correcto, en la región de la clave');
$check($json['status_if_new'] === 'pending', 'doble confirmación activada por defecto');
$check(($json['merge_fields']['MERGE7'] ?? '') === '2 a 5' && ($json['merge_fields']['FNAME'] ?? '') === 'Ana', 'tamaño en el campo elegido (MERGE7) y nombre en FNAME');
$check(isset($llamadas[1]) && str_ends_with($llamadas[1][1], '/tags') && count($llamadas[1][2]['tags']) === 2, 'agrega las etiquetas');

$llamadas = [];
$respuestas = [[400, ['title' => 'Invalid Resource', 'detail' => 'Your merge fields were invalid.']], [200, ['status' => 'pending']]];
$check($srv->sincronizar($id), 'si un campo no existe, reintenta sin campos extra');
$check(!isset($llamadas[1][2]['merge_fields']) && str_contains((string) $srv->find($id)['mc_error'], 'MERGE7'), 'y avisa qué campo revisar');

$respuestas = [[401, ['detail' => 'API Key Invalid']]];
$check(!$srv->sincronizar($id) && str_contains((string) $srv->find($id)['mc_error'], 'clave'), 'clave revocada: error claro');
$respuestas = [[404, []]];
$srv->sincronizar($id);
$check(str_contains((string) $srv->find($id)['mc_error'], 'audiencia'), 'audiencia inexistente: error claro');
$respuestas = [[200, ['status' => 'subscribed']]];
[$bien, $mal] = $srv->reintentar();
$check($bien === 1 && $mal === 0, 'reintentar las pendientes');

$respuestas = [[200, ['name' => 'Prisma · lista de espera', 'stats' => ['member_count' => 12]]], [200, ['merge_fields' => [['tag' => 'FNAME', 'name' => 'Nombre', 'type' => 'text'], ['tag' => 'MERGE7', 'name' => 'Tamaño', 'type' => 'dropdown', 'options' => ['choices' => ['Independiente', '2 a 5']]]]]]];
$p = (new I\Mailchimp($aj->clave(), 'fc1f3c0eb6'))->probar();
$check($p['ok'] && $p['audiencia'] === 'Prisma · lista de espera' && count($p['campos']) === 2 && $p['campos'][1]['opciones'][1] === '2 a 5', 'probar conexión lista los campos de la audiencia');

// ---- POST /invitacion ----
final class PublicoPrueba extends I\PublicoController
{
    public string $salida = '';
    protected function responder(array $r, int $codigo = 200): void
    {
        $this->salida = json_encode($r + ['_codigo' => $codigo]) ?: '';
    }
}
$pub = new PublicoPrueba($ctx);
$_SERVER['HTTP_ACCEPT'] = 'application/json';
$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
$post = fn(array $x) => $_POST = $x + ['t' => (string) (floor(microtime(true) * 1000) - 5000)];

$post(['email' => 'nuevo@agencia.cl', 'tamano' => '1', 'origen' => 'portada']);
$pub->inscribir();
$check(str_contains($pub->salida, '"ok":true') && $pdo->query("SELECT COUNT(*) FROM invitaciones WHERE email = 'nuevo@agencia.cl'")->fetchColumn() == 1, 'inscripción por POST');
$post(['email' => 'malo']);
$pub->inscribir();
$check(str_contains($pub->salida, '"ok":false') && str_contains($pub->salida, '422'), 'correo inválido: 422 con mensaje');
$post(['email' => 'bot@spam.cl', 'sitio_web' => 'http://spam']);
$pub->inscribir();
$check(str_contains($pub->salida, '"ok":true') && $pdo->query("SELECT COUNT(*) FROM invitaciones WHERE email = 'bot@spam.cl'")->fetchColumn() == 0, 'campo trampa: responde ok pero no guarda');
$_POST = ['email' => 'rapido@spam.cl', 't' => (string) floor(microtime(true) * 1000)];
$pub->inscribir();
$check($pdo->query("SELECT COUNT(*) FROM invitaciones WHERE email = 'rapido@spam.cl'")->fetchColumn() == 0, 'enviado en menos de 2 s: no guarda');
for ($i = 0; $i < 7; $i++) {
    $post(['email' => "muchos{$i}@spam.cl"]);
    $pub->inscribir();
}
$check(str_contains($pub->salida, '429'), 'tope de envíos por IP');
I\Mailchimp::$transporte = fn() => throw new RuntimeException('caído');
$_SERVER['REMOTE_ADDR'] = '10.0.0.2';
$post(['email' => 'resiste@agencia.cl']);
$pub->inscribir();
$check(str_contains($pub->salida, '"ok":true') && $pdo->query("SELECT mc_estado FROM invitaciones WHERE email = 'resiste@agencia.cl'")->fetchColumn() === 'error', 'si Mailchimp se cae, la inscripción queda guardada para reintentar');

// ---- Admin ----
$adm = new I\AdminController($ctx);
ob_start();
$adm->index();
$html = (string) ob_get_clean();
$check(str_contains($html, 'resiste@agencia.cl') && str_contains($html, '••••us21') && !str_contains($html, 'a1b2c3d4'), 'admin: lista y clave oculta');
$_POST = ['mc_clave' => '417ce82c846ccaf50250a42bfaxxxxxx', 'mc_audiencia' => 'fc1f3c0eb6'];
try {
    $adm->guardar();
} catch (TypeDock\Core\RedirectException) {
}
$check($aj->clave() === $clave && !empty($_SESSION['td_flash']['error']), 'admin: rechaza una clave incompleta y conserva la anterior');

echo "\n\n{$ok} comprobaciones OK, " . count($fallas) . " fallas (invitaciones).\n";
foreach ($fallas as $f) {
    echo "  ✗ {$f}\n";
}
exit($fallas === [] ? 0 : 1);

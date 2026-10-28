<?php
declare(strict_types=1);

// Contactos ganados de ejemplo (con la revisión de Mailchimp ya hecha) para ver esas pantallas:  php dev/seed-leads.php
// Se corre después de dev/seed-demo.php.

use TypeDock\Plugin\Portal as P;

require __DIR__ . '/bootstrap.php';

$pdo = portal_dev_pdo();
portal_dev_contexto($pdo);

$ps = new P\ProyectoService($pdo);
$proy = $pdo->query("SELECT * FROM portal_proyectos WHERE nombre = 'Nueva carta' LIMIT 1")->fetch() ?: $pdo->query('SELECT * FROM portal_proyectos LIMIT 1')->fetch();
$ps->update((string) $proy['id'], ['cliente_id' => $proy['cliente_id'], 'nombre' => $proy['nombre'], 'estado' => 'activo', 'con_leads' => 1, 'publico_b2c' => '1']);
$proy = $ps->find((string) $proy['id']);

$ls = new P\LeadService($pdo);
$ids = [];
foreach ([
    ['Marcela Soto', 'marcela@correo.cl', '+56975197482', 'Landing masa madre · Make'],
    ['Pedro Núñez', 'pedro@correo.cl', '', 'Meta · Masa madre octubre'],
    ['Ana Contreras', '', '+56911112222', 'Landing masa madre · Make'],
] as [$n, $c, $t, $camp]) {
    $r = $ls->crear($proy, ['nombre' => $n, 'correo' => $c, 'telefono' => $t, 'campana' => $camp], 'manual', 'Camila Rojas', null, 'agencia');
    $ids[] = $r['id'] ?? null;
}
$mc = [
    'alta' => date('Y-m-d H:i:s', strtotime('-1 day')), 'cambio' => date('Y-m-d H:i:s'), 'rating' => 2, 'aperturas' => 50, 'clics' => 0,
    'etiquetas' => ['landing-campana-form', 'default'], 'motivo' => '', 'origen' => 'Make',
    'actividad' => [
        ['tipo' => 'sent', 'fecha' => date('Y-m-d H:i:s', strtotime('-20 hours')), 'titulo' => 'Enviar correos electrónicos a los contactos de tu flujo de automatización'],
        ['tipo' => 'open', 'fecha' => date('Y-m-d H:i:s', strtotime('-19 hours')), 'titulo' => 'Bienvenida por Landing'],
        ['tipo' => 'sent', 'fecha' => date('Y-m-d H:i:s', strtotime('-20 hours')), 'titulo' => 'Bienvenida por Landing'],
    ],
    'conteo' => ['enviados' => 2, 'abiertos' => 1, 'clics' => 0, 'completo' => true],
];
$pdo->prepare('UPDATE portal_leads SET mc_estado = ?, mc_datos = ?, mc_revisado_en = ? WHERE id = ?')
    ->execute(['subscribed', json_encode($mc, JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s'), $ids[0]]);
$pdo->prepare('UPDATE portal_leads SET mc_estado = ?, mc_datos = ?, mc_revisado_en = ? WHERE id = ?')
    ->execute(['error', json_encode(['error' => 'La clave de Mailchimp no es válida o fue revocada.'], JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s'), $ids[1]]);
echo "Contactos ganados de ejemplo en el proyecto «{$proy['nombre']}»: ", implode(', ', array_filter($ids)), "\n";

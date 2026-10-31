<?php
declare(strict_types=1);

// Muchos contactos de ejemplo, repartidos en cinco semanas y con todos los estados, para la captura de «Contactos ganados» del landing:
//   php dev/seed-demo.php && php dev/seed-leads.php && php dev/seed-leads-landing.php

use TypeDock\Plugin\Portal as P;

require __DIR__ . '/bootstrap.php';

$pdo = portal_dev_pdo();
portal_dev_contexto($pdo);
mt_srand(7);

$proy = $pdo->query("SELECT * FROM portal_proyectos WHERE nombre = 'Nueva carta' LIMIT 1")->fetch();
$ls = new P\LeadService($pdo);
$ll = new P\LeadLinea($pdo);
$pdo->exec("DELETE FROM portal_leads WHERE proyecto_id = " . $pdo->quote((string) $proy['id']));

$nombres = ['Marcela Soto', 'Pedro Núñez', 'Ana Contreras', 'Javiera Rojas', 'Tomás Vidal', 'Camila Fuentes', 'Diego Araya', 'Francisca Muñoz', 'Matías Pizarro', 'Valentina Lagos', 'Ignacio Reyes', 'Constanza Bravo', 'Felipe Tapia', 'Daniela Soto', 'Sebastián Cortés', 'Catalina Herrera', 'Nicolás Vera', 'Paula Godoy', 'Joaquín Mella', 'Fernanda Ruiz', 'Cristóbal Pino', 'Antonia Silva', 'Benjamín Olivares', 'Isidora Campos', 'Vicente Salas', 'Martina Peña', 'Lucas Ortiz', 'Josefina Cáceres', 'Maximiliano Gil', 'Renata Lara'];
$campanas = [['Landing masa madre · Make', 'Make'], ['Meta · Masa madre octubre', 'Meta Lead Ads'], ['Google · Panadería cerca', 'Google Ads'], ['Instagram · Nueva carta', 'Formulario']];
$finales = ['ganado', 'ganado', 'ganado', 'ganado', 'ganado', 'calificado', 'calificado', 'calificado', 'calificado', 'contactado', 'contactado', 'contactado', 'contactado', 'contactado', 'contactado', 'perdido', 'perdido', 'perdido', 'nuevo', 'nuevo', 'nuevo'];
$dia = static fn(float $d): string => date('Y-m-d H:i:s', (int) (strtotime('today 11:00') - $d * 86400));

foreach ($nombres as $i => $n) {
    [$camp, $origen] = $campanas[$i % 4];
    $llego = 34 - $i * 1.1 + mt_rand(0, 6) / 10;                       // de más antiguo a más nuevo
    $r = $ls->crear($proy, ['nombre' => $n, 'correo' => strtolower(str_replace([' ', 'á', 'é', 'í', 'ó', 'ú', 'ñ'], ['.', 'a', 'e', 'i', 'o', 'u', 'n'], $n)) . '@correo.cl', 'telefono' => '', 'campana' => $camp], 'manual', 'Camila Rojas', null, 'agencia');
    $id = (string) $r['id'];
    $pdo->prepare('UPDATE portal_leads SET created_at = ?, origen = ? WHERE id = ?')->execute([$dia($llego), $origen, $id]);
    $final = $finales[$i % count($finales)];
    if ($llego < 1.5 && $final !== 'nuevo') { $final = 'nuevo'; }
    $ruta = ['nuevo' => [], 'contactado' => ['contactado'], 'calificado' => ['contactado', 'calificado'], 'ganado' => ['contactado', 'calificado', 'ganado'], 'perdido' => ['contactado', 'perdido']][$final];
    $t = $llego;
    foreach ($ruta as $est) {
        $t = max(0.2, $t - (0.6 + mt_rand(0, 40) / 10));
        $lead = $ls->find($id);
        $ll->cambiarEstado($lead, $est, $dia($t), 'Camila Rojas', 'agencia');
    }
}
echo count($nombres), " contactos de ejemplo en «{$proy['nombre']}».\n";

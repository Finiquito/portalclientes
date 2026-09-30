<?php
declare(strict_types=1);

// Datos de demo creíbles para las capturas del landing:  php dev/seed-demo.php
// (borra y recrea dev/storage/dev.sqlite)

use TypeDock\Plugin\Portal as P;

require __DIR__ . '/bootstrap.php';

$db = __DIR__ . '/storage/dev.sqlite';
if (!getenv('PORTAL_DB_DSN') && is_file($db)) {
    unlink($db);
}
$pdo = portal_dev_pdo();
portal_dev_contexto($pdo);

$hoy = new DateTimeImmutable('today');
$d = static fn(int $dias): string => $hoy->modify("{$dias} days")->format('Y-m-d');
$aj = new P\AjustesService($pdo);
$aj->set('global', 'portal', 'nombre_equipo', 'Estudio Pampa');
$aj->set('global', 'portal', 'color_agencia', '#2448b0');

$ts = new P\TareaService($pdo);
$rs = new P\ReunionService($pdo);
$es = new P\EntregaService($pdo);
$cs = new P\ContenidoService($pdo);
$act = new P\ActividadService($pdo);
$eq = new P\EquipoService($pdo);
$camila = $eq->create(['nombre' => 'Camila Rojas', 'email' => 'camila@estudiopampa.cl', 'cargo' => 'Directora', 'rol' => 'coordinador', 'activo' => 1]);
$aj->set('equipo', $camila, 'tema', 'claro');

$clientes = [
    // nombre, empresa, color, contacto, email, proyectos => [fases, tareas]
    ['Panadería Ruiz', 'Panadería Ruiz', '#b45309', 'Josefa Ruiz', 'josefa@panaderiaruiz.cl', ['Redes octubre', 'Nueva carta']],
    ['Viña Los Robles', 'Viña Los Robles SpA', '#7f1d1d', 'Tomás Herrera', 'tomas@losrobles.cl', ['Etiqueta reserva 2026']],
    ['Clínica Dental Sur', 'Clínica Dental Sur', '#0e7490', 'Paula Méndez', 'paula@dentalsur.cl', ['Sitio web']],
];
$ids = [];
foreach ($clientes as [$nombre, $empresa, $color, $contacto, $email, $proyectos]) {
    $cid = (new P\ClienteService($pdo))->create(['nombre' => $nombre, 'empresa' => $empresa, 'pais' => 'CL']);
    $aj->set('cliente', $cid, 'color', $color);
    $ct = (new P\ContactoService($pdo))->create(['cliente_id' => $cid, 'nombre' => $contacto, 'email' => $email, 'rol' => 'aprobador']);
    foreach ($proyectos as $pn) {
        $pid = (new P\ProyectoService($pdo))->create(['cliente_id' => $cid, 'nombre' => $pn]);
        $ids[$pn] = ['p' => $pid, 'c' => $cid, 'ct' => $ct, 'contacto' => $contacto];
        foreach (['Brief', 'Propuesta', 'Producción', 'Entrega'] as $i => $fn) {
            (new P\FaseService($pdo))->create(['proyecto_id' => $pid, 'nombre' => $fn, 'orden' => $i + 1]);
        }
    }
}

$t = function (string $proy, string $titulo, array $x = []) use ($ts, $ids): string {
    return $ts->create($x + ['proyecto_id' => $ids[$proy]['p'], 'titulo' => $titulo, 'asignado' => 'equipo', 'visible_cliente' => 1]);
};
$c = fn(string $proy, string $titulo, string $tipo, int $vence, array $x = []) => $t($proy, $titulo, $x + ['asignado' => 'cliente', 'responsable_contacto_id' => $ids[$proy]['ct'], 'tipo' => $tipo, 'fecha_vencimiento' => $d($vence)]);

$t('Redes octubre', 'Brief de campaña de primavera', ['estado' => 'hecha']);
$x = $c('Redes octubre', 'Fotos del pan de masa madre', 'archivo', 1);
$ts->cambiarEstado($x, 'entregada');
$act->registrar($ids['Redes octubre']['c'], $ids['Redes octubre']['p'], 'contacto', 'Josefa Ruiz', 'entrego', 'tarea', $x, 'Fotos del pan de masa madre', '6 archivo(s)');
$t('Redes octubre', 'Grilla de la segunda quincena', ['estado' => 'en_progreso', 'responsable_usuario_id' => $camila, 'fecha_vencimiento' => $d(3)]);
$c('Nueva carta', 'Aprobar precios de la carta', 'revision', 2);
$t('Nueva carta', 'Diagramar carta en dos columnas', ['fecha_vencimiento' => $d(6)]);
$x = $c('Etiqueta reserva 2026', 'Revisar prueba de color de la etiqueta', 'revision', 0);
$ts->cambiarEstado($x, 'cambios');
(new P\ComentarioService($pdo))->crear($ids['Etiqueta reserva 2026']['c'], 'tarea', $x, 'contacto', $ids['Etiqueta reserva 2026']['ct'], 'Tomás Herrera', 'El burdeo se ve muy café en la prueba. ¿Lo podemos subir un poco?');
$act->registrar($ids['Etiqueta reserva 2026']['c'], $ids['Etiqueta reserva 2026']['p'], 'contacto', 'Tomás Herrera', 'pidio_cambios', 'tarea', $x, 'Revisar prueba de color de la etiqueta');
$t('Etiqueta reserva 2026', 'Cotizar imprenta para 3.000 etiquetas', ['fecha_vencimiento' => $d(4), 'visible_cliente' => 0]);
$c('Sitio web', 'Enviar textos de la página de tratamientos', 'archivo', 5);
$t('Sitio web', 'Maqueta de la página de inicio', ['estado' => 'en_progreso', 'fecha_vencimiento' => $d(8)]);

$rs->create(['proyecto_id' => $ids['Redes octubre']['p'], 'titulo' => 'Revisión de la grilla', 'fecha' => $d(1) . ' 10:00', 'publicada' => 1, 'enlace_meet' => 'https://meet.google.com/abc-defg-hij']);
$rs->create(['proyecto_id' => $ids['Sitio web']['p'], 'titulo' => 'Reunión de contenidos', 'fecha' => $d(3) . ' 16:30', 'publicada' => 1, 'enlace_meet' => 'https://meet.google.com/xyz-abcd-efg']);
$rs->create(['proyecto_id' => $ids['Etiqueta reserva 2026']['p'], 'titulo' => 'Visita a la imprenta', 'fecha' => $d(6) . ' 09:30', 'publicada' => 0]);

$eid = $es->create(['proyecto_id' => $ids['Redes octubre']['p'], 'titulo' => 'Grilla primera quincena', 'fecha_limite' => $d(2)]);
$ent = $es->find($eid);
foreach (['post' => 'Lanzamiento marraqueta integral', 'reel' => 'Así se hace la masa madre', 'story' => 'Encuesta: ¿dulce o salado?', 'post' => 'Horario de fiestas patrias'] as $tipo => $tit) {
    $cs->crear($ent, ['tipo' => $tipo, 'titulo' => $tit]);
}
$es->publicar($eid);
$eid2 = $es->create(['proyecto_id' => $ids['Etiqueta reserva 2026']['p'], 'titulo' => 'Propuestas de etiqueta', 'fecha_limite' => $d(1)]);
$ent2 = $es->find($eid2);
foreach (['Versión A · clásica', 'Versión B · tipográfica', 'Versión C · ilustrada'] as $tit) {
    $cs->crear($ent2, ['tipo' => 'grafica', 'titulo' => $tit]);
}
$es->publicar($eid2);
$lista = $pdo->query("SELECT id FROM portal_contenidos WHERE entrega_id = '{$eid2}' ORDER BY orden")->fetchAll(PDO::FETCH_COLUMN);
$pdo->prepare("UPDATE portal_contenidos SET estado = 'aprobado' WHERE id = ?")->execute([$lista[1]]);
$pdo->prepare("UPDATE portal_contenidos SET estado = 'cambios' WHERE id = ?")->execute([$lista[0]]);
$es->responder($eid2, 'Tomás Herrera');

echo "Demo lista. Camila (coordinación): camila@estudiopampa.cl · Josefa (cliente): josefa@panaderiaruiz.cl\n";

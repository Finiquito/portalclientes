<?php
declare(strict_types=1);

// Datos de ejemplo:  php dev/seed.php   (borra y recrea dev/storage/dev.sqlite)

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
$aj->set('global', 'portal', 'equipo_nombre', 'Equipo RichGT');

$clientes = [
    ['Café Altura', 'Café Altura SpA', '#b45309', 'CL', ['Redes sociales 2026', 'Web nueva']],
    ['Nómade Viajes', 'Nómade Viajes Ltda.', '#0e7490', 'CL', ['Identidad de marca']],
    ['Luma Studio', 'Luma Studio', '#7c3aed', 'MX', ['Lanzamiento app']],
];

$ids = [];
foreach ($clientes as [$nombre, $empresa, $color, $pais, $proyectos]) {
    $cid = (new P\ClienteService($pdo))->create(['nombre' => $nombre, 'empresa' => $empresa, 'email' => 'hola@' . strtolower(str_replace(' ', '', $nombre)) . '.cl', 'pais' => $pais]);
    $aj->set('cliente', $cid, 'color', $color);
    $slug = strtolower(preg_replace('/[^a-z]/i', '', (string) iconv('UTF-8', 'ASCII//TRANSLIT', $nombre)));
    $ct = (new P\ContactoService($pdo))->create(['cliente_id' => $cid, 'nombre' => 'María ' . $nombre, 'email' => "maria@{$slug}.cl", 'rol' => 'aprobador']);
    (new P\ContactoService($pdo))->create(['cliente_id' => $cid, 'nombre' => 'Pedro ' . $nombre, 'email' => "pedro@{$slug}.cl", 'rol' => 'viewer']);
    foreach ($proyectos as $pn) {
        $pid = (new P\ProyectoService($pdo))->create(['cliente_id' => $cid, 'nombre' => $pn, 'descripcion' => 'Proyecto de ejemplo.']);
        $ids[$nombre][$pn] = $pid;
        $fases = [];
        foreach (['Descubrimiento', 'Diseño', 'Producción', 'Lanzamiento'] as $i => $fn) {
            $fases[] = (new P\FaseService($pdo))->create(['proyecto_id' => $pid, 'nombre' => $fn, 'orden' => $i + 1, 'fecha_inicio' => $d($i * 14 - 20), 'fecha_fin' => $d($i * 14 - 7)]);
        }
        $ts = new P\TareaService($pdo);
        $ts->create(['proyecto_id' => $pid, 'fase_id' => $fases[0], 'titulo' => 'Brief inicial', 'estado' => 'hecha', 'asignado' => 'equipo', 'visible_cliente' => 1]);
        $ts->create(['proyecto_id' => $pid, 'fase_id' => $fases[1], 'titulo' => 'Enviar logos en alta', 'tipo' => 'archivo', 'asignado' => 'cliente', 'responsable_contacto_id' => $ct, 'fecha_vencimiento' => $d(3)]);
        $ts->create(['proyecto_id' => $pid, 'fase_id' => $fases[1], 'titulo' => 'Revisar propuesta de moodboard', 'tipo' => 'revision', 'estado' => 'entregada', 'asignado' => 'cliente', 'responsable_contacto_id' => $ct, 'fecha_vencimiento' => $d(-1)]);
        $ts->create(['proyecto_id' => $pid, 'fase_id' => $fases[2], 'titulo' => 'Diseñar grilla de noviembre', 'estado' => 'en_progreso', 'asignado' => 'equipo', 'visible_cliente' => 1, 'fecha_vencimiento' => $d(5)]);
        $ts->create(['proyecto_id' => $pid, 'fase_id' => $fases[2], 'titulo' => 'Ajustar paleta (interno)', 'asignado' => 'equipo', 'visible_cliente' => 0, 'fecha_vencimiento' => $d(2)]);

        $rs = new P\ReunionService($pdo);
        $rs->create(['proyecto_id' => $pid, 'titulo' => 'Kickoff', 'fecha' => $d(-12) . ' 10:00', 'publicada' => 1, 'resumen_publicado' => 1, 'resumen' => 'Definimos objetivos y calendario.', 'acuerdos' => "- Entregar brief\n- Agendar revisión"]);
        $rs->create(['proyecto_id' => $pid, 'titulo' => 'Revisión quincenal', 'fecha' => $d(2) . ' 15:30', 'publicada' => 1, 'enlace_meet' => 'https://meet.google.com/abc-defg-hij']);

        $es = new P\EntregaService($pdo);
        $eid = $es->create(['proyecto_id' => $pid, 'titulo' => 'Grilla ' . $pn, 'mensaje' => 'Aquí va la propuesta.', 'fecha_limite' => $d(4)]);
        $ent = $es->find($eid);
        $cs = new P\ContenidoService($pdo);
        foreach (['post' => 'Post lanzamiento', 'reel' => 'Reel detrás de cámaras', 'story' => 'Story encuesta'] as $tipo => $tit) {
            $cs->crear($ent, ['tipo' => $tipo, 'titulo' => $tit, 'copy' => 'Texto de ejemplo para ' . strtolower($tit) . '.', 'fecha_publicacion' => $d(7)]);
        }
        $es->publicar($eid);
    }
}

// Usuarios de agencia: Ana ve Café Altura completo y un proyecto de Luma; Richard coordina todo.
$eq  = new P\EquipoService($pdo);
$ana = $eq->create(['nombre' => 'Ana Pérez', 'email' => 'ana@richgt.com', 'cargo' => 'Diseñadora', 'rol' => 'equipo', 'activo' => 1]);
$eq->create(['nombre' => 'Richard González', 'email' => 'richard@richgt.com', 'cargo' => 'Director', 'rol' => 'coordinador', 'activo' => 1]);
$cafe = (string) $pdo->query("SELECT id FROM portal_clientes WHERE nombre = 'Café Altura'")->fetchColumn();
$eq->guardarAsignaciones($ana, [$cafe], [$ids['Luma Studio']['Lanzamiento app']]);
$pdo->prepare("UPDATE portal_tareas SET responsable_usuario_id = ? WHERE titulo = 'Diseñar grilla de noviembre'")->execute([$ana]);
$aj->set('global', 'portal', 'color_agencia', '#e4572e');
$aj->set('global', 'portal', 'nombre_equipo', 'RichGT');

// Respuestas del cliente para la bandeja.
$t = $pdo->query("SELECT t.id, t.proyecto_id FROM portal_tareas t WHERE t.titulo = 'Enviar logos en alta' LIMIT 1")->fetch();
(new P\TareaService($pdo))->cambiarEstado($t['id'], 'entregada');
$ent = $pdo->query("SELECT id FROM portal_entregas ORDER BY created_at LIMIT 1")->fetchColumn();
$con = $pdo->prepare("SELECT id FROM portal_contenidos WHERE entrega_id = ? ORDER BY orden");
$con->execute([$ent]);
$cs = $con->fetchAll(PDO::FETCH_COLUMN);
$pdo->prepare("UPDATE portal_contenidos SET estado = 'aprobado' WHERE id = ?")->execute([$cs[0]]);
$pdo->prepare("UPDATE portal_contenidos SET estado = 'cambios' WHERE id = ?")->execute([$cs[1]]);
(new P\EntregaService($pdo))->responder($ent, 'María Café Altura');
$act = new P\ActividadService($pdo);
$act->registrar($cafe, $t['proyecto_id'], 'contacto', 'María Café Altura', 'entrego', 'tarea', $t['id'], 'Enviar logos en alta', '2 archivo(s)');

$n = static fn(string $t): int => (int) $pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
echo "Listo: {$n('portal_clientes')} clientes, {$n('portal_proyectos')} proyectos, {$n('portal_tareas')} tareas, {$n('portal_entregas')} entregas.\n";

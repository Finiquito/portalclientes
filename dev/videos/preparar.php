<?php
declare(strict_types=1);
/**
 * Base de datos de los videos: demo de seed-demo.php + piezas con imágenes, una entrega para soltar
 * imágenes, una reunión por resumir y conversaciones. Uso: bash dev/videos/preparar.sh
 */
use TypeDock\Plugin\Portal as P;

require dirname(__DIR__) . '/bootstrap.php';
$pdo = portal_dev_pdo();
$ctx = portal_dev_contexto($pdo);
$arte = __DIR__ . '/arte';
$es = new P\EntregaService($pdo);
$cs = new P\ContenidoService($pdo);
$com = new P\ComentarioService($pdo);
$hoy = new DateTimeImmutable('today');
$d = static fn(int $n): string => $hoy->modify("{$n} days")->format('Y-m-d');
$q = fn(string $sql, array $a = []) => (function () use ($pdo, $sql, $a) { $s = $pdo->prepare($sql); $s->execute($a); return $s; })();

$cliente = (string) $q("SELECT id FROM portal_clientes WHERE nombre = 'Panadería Ruiz'")->fetchColumn();
$proy = (string) $q("SELECT id FROM portal_proyectos WHERE nombre = 'Redes octubre'")->fetchColumn();
$josefa = $q("SELECT * FROM portal_contactos WHERE email = 'josefa@panaderiaruiz.cl'")->fetch();
$camila = $q("SELECT * FROM portal_equipo WHERE email = 'camila@estudiopampa.cl'")->fetch();
$autor = ['tipo' => 'equipo', 'id' => $camila['id'], 'nombre' => 'Camila Rojas'];
P\ArchivoService::$mover = fn(string $a, string $b) => copy($a, $b);
$subir = function (string $vid, array $archivos) use ($pdo, $cliente, $proy, $autor, $arte) {
    $f = [];
    foreach ($archivos as $nom => $origen) {
        $tmp = tempnam(sys_get_temp_dir(), 'vi');
        copy($arte . '/' . $origen, $tmp);
        $f[] = ['name' => $nom, 'type' => 'image/jpeg', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)];
    }
    return (new P\ArchivoService($pdo))->guardarVarios($f, $cliente, $proy, 'version', $vid, $autor, 20);
};

// A. La entrega publicada que revisa Josefa: carrusel, reel con portada y story.
$eid = (string) $q("SELECT id FROM portal_entregas WHERE titulo = 'Grilla primera quincena'")->fetchColumn();
$q("UPDATE portal_entregas SET titulo = 'Grilla de noviembre', mensaje = ? WHERE id = ?", ["¡Hola Josefa! Aquí va la grilla de la primera quincena. Si algo no te convence, marca el punto en la imagen y lo ajustamos.", $eid]);
foreach ($cs->listar($eid) as $c) {
    $cs->borrar((string) $c['id']);
}
$ent = $es->find($eid);
[$c1, $v1] = $cs->crear($ent, ['tipo' => 'post', 'titulo' => 'Post 1 · Masa madre', 'cuenta' => '@panaderiaruiz', 'fecha_publicacion' => $d(3), 'objetivo' => 'Contar por qué nuestro pan dura más.', 'copy' => "Nuestro pan no se apura. 🕰️ 48 horas de fermentación lenta y una masa madre que cuidamos hace 6 años.\n\n#masamadre #panaderiadebarrio"]);
$subir($v1, ['1-1.jpg' => '1-1.jpg', '1-2.jpg' => '1-2.jpg', '1-3.jpg' => '1-3.jpg']);
[$c2, $v2] = $cs->crear($ent, ['tipo' => 'reel', 'titulo' => 'Reel 2 · Un día en el horno', 'cuenta' => '@panaderiaruiz', 'fecha_publicacion' => $d(5), 'copy' => 'Mientras duermes, nosotros ya estamos amasando. ☕🥖', 'laminas' => "5:00 am, se encienden las luces del local\nPrimer lote saliendo del horno\nClienta llevándose su marraqueta"]);
$subir($v2, ['portada.jpg' => 'reel.jpg']);
[$c3, $v3] = $cs->crear($ent, ['tipo' => 'story', 'titulo' => 'Story 3 · ¿Dulce o salado?', 'cuenta' => '@panaderiaruiz', 'fecha_publicacion' => $d(6), 'copy' => 'Encuesta: ¿qué quieres los domingos?']);
$subir($v3, ['story.jpg' => 'story.jpg']);
$es->publicar($eid);

// B. Entrega en borrador con las piezas de la grilla, sin imágenes (video «soltar imágenes»).
$eb = $es->create(['proyecto_id' => $proy, 'titulo' => 'Grilla de diciembre']);
$entB = $es->find($eb);
$cs->crear($entB, ['tipo' => 'post', 'titulo' => 'Post 1 · Masa madre', 'laminas' => "Manos amasando | 48 horas de paciencia\nLa masa madre en el frasco | Nuestra masa madre tiene 6 años\nPan cortado | Por eso dura más", 'copy' => 'Nuestro pan no se apura. 🕰️']);
$cs->crear($entB, ['tipo' => 'reel', 'titulo' => 'Reel 2 · Un día en el horno', 'laminas' => "5:00 am, se encienden las luces\nPrimer lote saliendo del horno", 'copy' => 'Mientras duermes, nosotros ya estamos amasando.']);
$cs->crear($entB, ['tipo' => 'story', 'titulo' => 'Story 3 · ¿Dulce o salado?', 'copy' => 'Encuesta para los domingos.']);

// C. Reunión de ayer, sin resumen (video «la reunión se resume sola»).
(new P\ReunionService($pdo))->create(['proyecto_id' => $proy, 'titulo' => 'Revisión grilla de noviembre', 'fecha' => $d(-1) . ' 10:00', 'publicada' => 1]);

// D. Conversaciones para la bandeja de mensajes.
$com->crear($cliente, 'contenido', $c1, 'contacto', $josefa['id'], 'Josefa Ruiz', '¿Podemos usar la foto del local en la lámina 2? Esa nos gusta más 🙏', $v1);
$tarea = (string) $q("SELECT id FROM portal_tareas WHERE titulo = 'Fotos del pan de masa madre'")->fetchColumn();
$com->crear($cliente, 'tarea', $tarea, 'contacto', $josefa['id'], 'Josefa Ruiz', 'Subí 6 fotos. Las del horno quedaron un poco oscuras, avísame si sirven.');
(new P\AjustesService($pdo))->set('contacto', (string) $josefa['id'], 'primeros_pasos', 'oculto');
echo "Videos: datos listos.\n";

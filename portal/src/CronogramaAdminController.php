<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

use TypeDock\Core\PluginContext;

/**
 * Línea de tiempo (Gantt) de un proyecto, para el equipo. La misma en el admin y en el panel /equipo.
 * Fases, tareas (con fechas estimadas si dependen de otra), hitos, reuniones, entregas y hoy.
 * Arrastrar una barra cambia sus fechas (o su duración si depende de otra); clic para editar.
 */
class CronogramaAdminController
{
    public const ESTADOS = ['pendiente' => 'Pendiente', 'en_progreso' => 'En curso', 'entregada' => 'Entregada', 'cambios' => 'Con cambios', 'hecha' => 'Hecha'];

    private Pantalla $ui;

    public function __construct(private readonly PluginContext $ctx, ?Pantalla $ui = null)
    {
        $this->ui = $ui ?? new PantallaAdmin($ctx);
    }

    private function pdo(): \PDO
    {
        return $this->ctx->db()->pdo();
    }

    protected function terminate(): void
    {
        exit;
    }

    protected function limpiarSalida(): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }

    /** @param array<string, mixed> $datos */
    private function json(array $datos, int $http = 200): void
    {
        $this->limpiarSalida();
        if (!headers_sent()) {
            http_response_code($http);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($datos, JSON_UNESCAPED_UNICODE);
        $this->terminate();
    }

    public function ver(string $id): void
    {
        $p = (new ProyectoService($this->pdo()))->find($id);
        if ($p === null) {
            $this->ui->redirect($this->ui->url('proyectos'), 'Proyecto no encontrado.', 'error');
            return;
        }
        $st = $this->pdo()->prepare('SELECT nombre FROM portal_clientes WHERE id = ?');
        $st->execute([(string) $p['cliente_id']]);
        $d = (new Cronograma($this->pdo()))->datos($id);
        $zoom = ($_GET['zoom'] ?? '') === 'mes' ? 'mes' : 'semana';
        $this->ui->view('cronograma/index.latte', [
            'proyecto' => $p, 'clienteNombre' => (string) $st->fetchColumn(), 'd' => $d, 'zoom' => $zoom,
            'ancho' => $zoom === 'mes' ? 12 : 30,   // px por día
            'eje' => Cronograma::eje($d['desde'], $d['dias']),
            'x' => Cronograma::columna($d['desde']),
            'enlace' => fn(string $tipo, string $obj): string => $this->ui->url($tipo . '/' . $obj),
            'editable' => true,
            'mover' => $this->ui->url('proyectos/' . $id . '/linea/mover'),
            'estados' => self::ESTADOS,
            'plantillas' => (new PlantillaService($this->pdo()))->lista(),
            'fasesProyecto' => (new FaseService($this->pdo()))->listByProyecto($id),
            'color' => self::hex(Fmt::coloresTodos($this->pdo())[$id] ?? ''),
            'fmt' => new Fmt(),
            'flash_success' => $this->ui->flash('success'),
            'flash_error'   => $this->ui->flash('error'),
        ]);
    }

    /** Color seguro para ir dentro de un style (Latte escaparía el #). */
    private static function hex(string $c): string
    {
        return preg_match('/^#[0-9a-f]{3,8}$/i', $c) === 1 ? $c : '#6d5df6';
    }

    /** Arrastrar: {tipo: tarea|hito|fase, id, ini, fin}. Responde JSON. */
    public function mover(string $id): void
    {
        $tipo = (string) ($_POST['tipo'] ?? '');
        $obj = (string) ($_POST['obj'] ?? '');
        $ini = substr((string) ($_POST['ini'] ?? ''), 0, 10);
        $fin = substr((string) ($_POST['fin'] ?? $ini), 0, 10);
        $tabla = ['tarea' => 'portal_tareas', 'hito' => HitoService::TABLE, 'fase' => 'portal_fases'][$tipo] ?? null;
        if ($tabla === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $ini) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $fin) !== 1) {
            $this->json(['error' => 'Datos inválidos.'], 400);
            return;
        }
        // Lo que se mueve tiene que ser de este proyecto.
        $st = $this->pdo()->prepare("SELECT 1 FROM {$tabla} WHERE id = ? AND proyecto_id = ?");
        $st->execute([$obj, $id]);
        if ($st->fetchColumn() === false) {
            $this->json(['error' => 'No encontrado.'], 404);
            return;
        }
        $ok = match ($tipo) {
            'tarea' => (new TareaService($this->pdo()))->moverFechas($obj, $ini, $fin),
            'hito'  => (new HitoService($this->pdo()))->mover($obj, $ini),
            'fase'  => (bool) $this->pdo()->prepare('UPDATE portal_fases SET fecha_inicio = ?, fecha_fin = ?, updated_at = ? WHERE id = ?')
                ->execute([min($ini, $fin), max($ini, $fin), (new \DateTimeImmutable())->format('Y-m-d H:i:s'), $obj]),
        };
        $this->json(['ok' => $ok]);
    }

    /** Crear o editar un hito (desde el diálogo de la línea de tiempo). */
    public function hitoGuardar(string $id): void
    {
        $volver = $this->ui->url('proyectos/' . $id . '/linea');
        $svc = new HitoService($this->pdo());
        $hid = (string) ($_POST['hito_id'] ?? '');
        if ($hid !== '') {
            $h = $svc->find($hid);
            $ok = $h !== null && $h['proyecto_id'] === $id && $svc->update($hid, $_POST);
        } else {
            $ok = $svc->create(['proyecto_id' => $id] + $_POST) !== null;
        }
        // Desde la ficha de una fase se vuelve a ella.
        $fase = (string) ($_POST['volver_fase'] ?? '');
        if ($fase !== '' && (new FaseService($this->pdo()))->deProyecto($fase, $id) !== null) {
            $volver = $this->ui->url('fases/' . $fase);
        }
        $this->ui->redirect($volver, $ok ? 'Hito guardado.' : 'El hito necesita nombre y fecha.', $ok ? 'success' : 'error');
    }

    /** Arma el proyecto con una plantilla a partir de una fecha de inicio. */
    public function plantillaAplicar(string $id): void
    {
        $volver = $this->ui->url('proyectos/' . $id . '/linea');
        $inicio = substr((string) ($_POST['inicio'] ?? ''), 0, 10);
        $n = (new PlantillaService($this->pdo()))->aplicar((string) ($_POST['plantilla_id'] ?? ''), $id, $inicio);
        $this->ui->redirect($volver, $n > 0
            ? "Listo: {$n} tareas con sus fases e hitos. Las que dependen de otra muestran fechas estimadas; ajústalas a tu gusto."
            : 'Elige una plantilla y la fecha de inicio.', $n > 0 ? 'success' : 'error');
    }

    /** Guarda la estructura de este proyecto (fases, tareas, hitos) como plantilla nueva. */
    public function plantillaGuardar(string $id): void
    {
        $svc = new PlantillaService($this->pdo());
        $ok = $svc->crear((string) ($_POST['nombre'] ?? ''), (string) ($_POST['descripcion'] ?? ''), $svc->estructuraDe($id)) !== null;
        $this->ui->redirect($this->ui->url('proyectos/' . $id . '/linea'), $ok ? 'Plantilla guardada. La verás al crear un proyecto.' : 'La plantilla necesita nombre y al menos una tarea.', $ok ? 'success' : 'error');
    }

    public function hitoBorrar(string $id, string $hid): void
    {
        $svc = new HitoService($this->pdo());
        $h = $svc->find($hid);
        if ($h !== null && $h['proyecto_id'] === $id) {
            $svc->delete($hid);
        }
        $this->ui->redirect($this->ui->url('proyectos/' . $id . '/linea'), 'Hito eliminado.');
    }
}

<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

use TypeDock\Core\PluginContext;

/**
 * Espacio de trabajo de una reunión: datos + Meet, transcripción pegada,
 * resumen / acuerdos / análisis interno, tareas propuestas con pre-aprobación
 * y próxima reunión. La IA es opcional: sólo rellena una *propuesta* que el
 * equipo revisa; publicar y crear tareas es siempre una acción humana.
 */
class ReunionAdminController
{
    protected readonly Pantalla $ui;

    public function __construct(protected readonly PluginContext $ctx, ?Pantalla $ui = null)
    {
        $this->ui = $ui ?? new PantallaAdmin($ctx);
    }

    private function pdo(): \PDO
    {
        return $this->ctx->db()->pdo();
    }

    private function service(): ReunionService
    {
        return new ReunionService($this->pdo());
    }

    protected function ia(): IaService
    {
        return new IaService($this->pdo());
    }

    private function proyectos(): array
    {
        return $this->ui->filtrar((new ProyectoService($this->pdo()))->listAll(), 'id');
    }

    private function firma(): string
    {
        return $this->ui->firma();
    }

    private function url(string $ruta): string
    {
        return $this->ui->url($ruta);
    }

    /** Une los campos <input type=date> + <input type=time> ('fecha_d' / 'fecha_t'); si no vienen, respeta el valor directo. */
    private function combinar(string $k): string
    {
        if (!isset($_POST[$k . '_d'])) {
            return (string) ($_POST[$k] ?? '');
        }
        $d = trim((string) $_POST[$k . '_d']);
        $t = trim((string) ($_POST[$k . '_t'] ?? ''));
        return $d === '' ? '' : ($t !== '' ? $d . ' ' . $t : $d);
    }

    public function index(): void
    {
        $this->ui->view('reuniones/index.latte', [
            'reuniones'     => $this->ui->filtrar($this->service()->listAll(), 'proyecto_id'),
            'fmt'           => new Fmt(),
            'flash_success' => $this->ui->flash('success'),
            'flash_error'   => $this->ui->flash('error'),
        ]);
    }

    public function create(): void
    {
        $proyectos = $this->proyectos();
        if ($proyectos === []) {
            $this->ui->redirect($this->url('reuniones'), 'Crea un proyecto primero.', 'error');
            return;
        }
        $this->ui->view('reuniones/nueva.latte', [
            'proyectos' => $proyectos,
            'proyectoId' => (string) ($_GET['proyecto'] ?? $_GET['proyecto_id'] ?? ''),
        ]);
    }

    public function store(): void
    {
        $_POST['fecha'] = $this->combinar('fecha');
        $_POST['publicada'] = $_POST['publicada'] ?? '';
        $_POST['resumen_publicado'] = '';
        $id = $this->service()->create($_POST);
        $this->ui->redirect($this->url('reuniones/' . $id), 'Reunión creada. Aquí puedes pegar la transcripción cuando termine.');
    }

    public function edit(string $id): void
    {
        $reunion = $this->service()->find($id);
        if ($reunion === null) {
            $this->ui->redirect($this->url('reuniones'), 'Reunión no encontrada.', 'error');
            return;
        }
        $ia = $this->ia();
        $this->ui->view('reuniones/edit.latte', [
            'reunion'    => $reunion,
            'proyectos'  => $this->proyectos(),
            'propuestas' => $this->service()->propuestas($id),
            'iaActiva'   => $ia->activa(),
            'gcal'       => $this->service()->enlaceGoogleCalendar($reunion),
            'proxima'    => $reunion['prox_reunion_id'] ? $this->service()->find((string) $reunion['prox_reunion_id']) : null,
            'fmt'        => new Fmt(),
            'flash_success' => $this->ui->flash('success'),
            'flash_error'   => $this->ui->flash('error'),
        ]);
    }

    /** Guardar / analizar con IA / aplicar (crear tareas aprobadas, agendar, publicar y avisar). */
    public function update(string $id): void
    {
        $svc = $this->service();
        $antes = $svc->find($id);
        if ($antes === null) {
            $this->ui->redirect($this->url('reuniones'), 'Reunión no encontrada.', 'error');
            return;
        }
        $accion = (string) ($_POST['accion'] ?? 'guardar');
        $volver = $this->url('reuniones/' . $id);

        // Un proyecto distinto sólo se acepta si existe.
        if (!isset(array_column($this->proyectos(), null, 'id')[(string) ($_POST['proyecto_id'] ?? '')])) {
            $_POST['proyecto_id'] = $antes['proyecto_id'];
        }
        $_POST['fecha'] = $this->combinar('fecha');
        $_POST['prox_fecha'] = $this->combinar('prox_fecha');
        $svc->update($id, $_POST);

        // Tarea nueva escrita a mano en la fila final de la tabla.
        if (is_array($_POST['nueva'] ?? null) && trim((string) ($_POST['nueva']['titulo'] ?? '')) !== '') {
            $svc->agregarPropuesta($id, $_POST['nueva']);
        }

        // Propuestas marcadas con «Quitar»: se descartan al guardar (sin recargar una por una).
        $quitadas = 0;
        foreach (array_unique(array_map('strval', (array) ($_POST['quitar'] ?? []))) as $pid) {
            $q = $svc->propuesta($pid);
            if ($q !== null && $q['reunion_id'] === $id && $q['estado'] === 'propuesta') {
                $svc->borrarPropuesta($pid);
                $quitadas++;
            }
            unset($_POST['prop'][$pid]);
            $_POST['aprobar'] = array_values(array_diff(array_map('strval', (array) ($_POST['aprobar'] ?? [])), [$pid]));
        }

        // Ediciones de las propuestas de la tabla.
        foreach ((array) ($_POST['prop'] ?? []) as $pid => $datos) {
            $q = $svc->propuesta((string) $pid);
            if ($q !== null && $q['reunion_id'] === $id && is_array($datos)) {
                $svc->actualizarPropuesta((string) $pid, $datos);
            }
        }

        $msg = 'Reunión guardada.' . ($quitadas > 0 ? ' Propuestas quitadas: ' . $quitadas . '.' : '');
        $tipo = 'success';

        // Cualquier fallo inesperado se muestra como aviso (y queda en el log) en vez de un error 500 mudo.
        try {
            if ($accion === 'analizar') {
                [$msg, $tipo] = $this->analizar($id);
            } elseif ($accion === 'aplicar') {
                [$msg, $tipo] = $this->aplicar($id, $antes);
            } else {
                $this->registrarPublicacion($antes, $svc->find($id) ?? $antes, 0, false, !empty($_POST['avisar']));
            }
        } catch (\Throwable $e) {
            error_log('[portal] reunión ' . $id . ' (' . $accion . '): ' . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
            $msg = 'No se pudo completar la acción por un error interno (' . get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 200) . ' · ' . basename($e->getFile()) . ':' . $e->getLine() . '). Tus datos de la reunión se guardaron.';
            if (stripos($e->getMessage(), 'gone away') !== false || stripos($e->getMessage(), 'Lost connection') !== false) {
                $msg = 'La conexión con la base de datos se cerró mientras la IA trabajaba (tu hosting corta las conexiones inactivas). Vuelve a apretar el botón; si se repite, prueba con un modelo más rápido en Ajustes.';
            }
            $tipo = 'error';
        }

        $this->ui->redirect($volver, $msg, $tipo);
    }

    /** @return array{0: string, 1: string} */
    private function analizar(string $id): array
    {
        $svc = $this->service();
        $ia = $this->ia();
        if (!$ia->activa()) {
            return ['La IA no está configurada. Puedes escribir el resumen y las tareas a mano.', 'error'];
        }
        $r = $svc->find($id);
        try {
            $p = $ia->analizar($r, (string) $r['transcripcion']);
        } catch (\RuntimeException $e) {
            return [$e->getMessage(), 'error'];
        }

        // La propuesta reemplaza resumen/acuerdos/análisis y las tareas aún pendientes; nunca se publica sola.
        $this->pdo()->prepare(
            'UPDATE ' . ReunionService::TABLE . ' SET resumen = ?, acuerdos = ?, analisis = ?, resumen_publicado = 0,
             prox_fecha = ?, prox_titulo = ?, ia_generado_en = ?, ia_modelo = ?, updated_at = ? WHERE id = ?'
        )->execute([
            $p['resumen'], implode("\n", $p['acuerdos']), $p['analisis'],
            $r['prox_reunion_id'] ? $r['prox_fecha'] : $p['prox_fecha'], $r['prox_reunion_id'] ? $r['prox_titulo'] : $p['prox_titulo'],
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'), $ia->modelo(), (new \DateTimeImmutable())->format('Y-m-d H:i:s'), $id,
        ]);
        $svc->limpiarPropuestasPendientes($id);
        foreach ($p['tareas'] as $t) {
            $svc->agregarPropuesta($id, $t, 'ia');
        }
        $extra = !empty($p['recortada']) ? ' La transcripción era muy larga y se analizó sólo el comienzo.' : '';
        return ['Propuesta lista: revisa el resumen y marca las tareas que quieres crear. Nada se publica hasta que lo apruebes.' . $extra, 'success'];
    }

    /**
     * Crea las tareas aprobadas, agenda la próxima reunión si se pidió,
     * y (si se marcó) avisa por correo al cliente de lo que quedó visible.
     *
     * @param array<string, mixed> $antes
     * @return array{0: string, 1: string}
     */
    private function aplicar(string $id, array $antes): array
    {
        $svc = $this->service();
        $ids = array_values(array_filter(array_map('strval', (array) ($_POST['aprobar'] ?? []))));
        $res = $svc->aprobarPropuestas($id, $ids);

        $proxId = null;
        if (!empty($_POST['agendar_prox'])) {
            $proxId = $svc->agendarProxima($id, !empty($_POST['prox_publicar']));
        }
        $ahora = $svc->find($id) ?? $antes;
        $proxPublicada = $proxId !== null && !empty($_POST['prox_publicar']);
        $this->registrarPublicacion($antes, $ahora, $res['visibles'], $proxPublicada, !empty($_POST['avisar']), $proxId);

        $partes = [];
        if ($res['creadas'] > 0) {
            $partes[] = $res['creadas'] . ($res['creadas'] === 1 ? ' tarea creada' : ' tareas creadas') . ($res['visibles'] > 0 ? " ({$res['visibles']} visibles para el cliente)" : ' (internas)');
        }
        if ($proxId !== null) {
            $partes[] = 'próxima reunión agendada';
        }
        if ((int) $ahora['resumen_publicado'] === 1 && (int) $antes['resumen_publicado'] === 0) {
            $partes[] = 'resumen publicado';
        }
        if ($partes === []) {
            return ['No había nada nuevo que aplicar: marca tareas, o la próxima reunión, y vuelve a intentar.', 'error'];
        }
        return [ucfirst(implode(', ', $partes)) . '.', 'success'];
    }

    /**
     * Deja rastro en la actividad del cliente y, si se pidió, le avisa por correo
     * (un solo correo con lo nuevo). Sólo actúa si algo quedó visible para él.
     *
     * @param array<string, mixed> $antes
     * @param array<string, mixed> $ahora
     */
    private function registrarPublicacion(array $antes, array $ahora, int $tareasVisibles, bool $proxPublicada, bool $avisar, ?string $proxId = null): void
    {
        $visibleAhora = (int) $ahora['publicada'] === 1;
        $resumenNuevo = $visibleAhora && (int) $ahora['resumen_publicado'] === 1 && (int) $antes['resumen_publicado'] === 0;
        if (!$visibleAhora || (!$resumenNuevo && $tareasVisibles === 0 && !$proxPublicada)) {
            return;
        }

        (new ActividadService($this->pdo()))->registrar(
            (string) $ahora['cliente_id'], (string) $ahora['proyecto_id'], 'equipo', $this->firma(),
            'reunion', 'reunion', (string) $ahora['id'], (string) $ahora['titulo']
        );
        if (!$avisar) {
            return;
        }

        $bloques = [];
        if ($resumenNuevo && trim((string) $ahora['resumen']) !== '') {
            $bloques[] = ['p' => 'Esto fue lo que conversamos:'];
            $bloques[] = ['cita' => trim((string) $ahora['resumen'])];
            $acuerdos = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) ($ahora['acuerdos'] ?? '')) ?: [])));
            if ($acuerdos !== []) {
                $bloques[] = ['p' => 'Acuerdos:'];
                $bloques[] = ['lista' => $acuerdos];
            }
        }
        if ($tareasVisibles > 0) {
            $bloques[] = ['p' => $tareasVisibles === 1 ? 'Hay 1 tarea nueva del proyecto para revisar en el portal.' : "Hay {$tareasVisibles} tareas nuevas del proyecto para revisar en el portal."];
        }
        if ($proxPublicada && $proxId !== null) {
            $p = $this->service()->find($proxId);
            if ($p !== null) {
                $f = new Fmt();
                $bloques[] = ['datos' => [
                    ['Próxima reunión', (string) $p['titulo']],
                    ['Cuándo', $f->fechaLarga((string) $p['fecha']) . ($f->hora((string) $p['fecha']) !== '' ? ' a las ' . $f->hora((string) $p['fecha']) : '')],
                ]];
            }
        }
        (new Notifier($this->ctx, $this->pdo()))->alCliente(
            (string) $ahora['cliente_id'], null, 'Novedades de la reunión «' . $ahora['titulo'] . '»', '', '/portal/reuniones/' . $ahora['id'],
            ['etiqueta' => 'Reunión', 'titulo' => 'Novedades de la reunión', 'resaltado' => 'reunión', 'boton' => 'Ver el detalle', 'bloques' => $bloques,
             'preheader' => 'Resumen, acuerdos y próximos pasos de «' . $ahora['titulo'] . '»']
        );
    }

    public function borrarPropuesta(string $id, string $pid): void
    {
        $q = $this->service()->propuesta($pid);
        if ($q !== null && $q['reunion_id'] === $id) {
            $this->service()->borrarPropuesta($pid);
        }
        $this->ui->redirect($this->url('reuniones/' . $id), 'Propuesta descartada.');
    }

    public function destroy(string $id): void
    {
        $this->service()->delete($id);
        $this->ui->redirect($this->url('reuniones'), 'Reunión eliminada.');
    }
}

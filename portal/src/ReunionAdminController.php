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
        return $this->ui->filtrar((new ProyectoService($this->pdo()))->porMovimiento(), 'id');
    }

    private function firma(): string
    {
        return $this->ui->firma();
    }

    private function url(string $ruta): string
    {
        return $this->ui->url($ruta);
    }

    /**
     * Une los campos <input type=date> + <input type=time> ('fecha_d' / 'fecha_t'); si no vienen, respeta el valor directo.
     * La hora se escribe en la de la agencia (la que se guarda); el formulario muestra su equivalente en la del cliente.
     */
    private function combinar(string $k): string
    {
        if (!isset($_POST[$k . '_d'])) {
            return (string) ($_POST[$k] ?? '');
        }
        $d = trim((string) $_POST[$k . '_d']);
        $t = trim((string) ($_POST[$k . '_t'] ?? ''));
        if ($d === '') {
            return '';
        }
        return $t !== '' ? $d . ' ' . $t : $d;
    }

    private function paisDelProyecto(string $proyectoId): string
    {
        $st = $this->pdo()->prepare('SELECT c.pais FROM portal_proyectos p JOIN portal_clientes c ON c.id = p.cliente_id WHERE p.id = ?');
        $st->execute([$proyectoId]);
        return HorarioHabil::paisValido((string) $st->fetchColumn());
    }

    private function convocados(): Convocados
    {
        return new Convocados($this->ctx, $this->pdo());
    }

    /**
     * Personas que se pueden convocar: el equipo activo y los contactos de los clientes de los proyectos
     * del formulario (el formulario muestra sólo los del cliente del proyecto elegido).
     *
     * @param array<int, array<string, mixed>> $proyectos
     * @param array{equipo: array<int, string>, contacto: array<int, string>} $marcados
     */
    private function datosConvocados(array $proyectos, array $marcados): array
    {
        $clientes = array_values(array_unique(array_map(fn($p) => (string) $p['cliente_id'], $proyectos)));
        $contactos = [];
        if ($clientes !== []) {
            Schema::asegurar($this->pdo());
            $st = $this->pdo()->prepare('SELECT id, nombre, email, cliente_id, pais FROM portal_contactos WHERE cliente_id IN (' . implode(',', array_fill(0, count($clientes), '?')) . ') ORDER BY nombre');
            $st->execute($clientes);
            $contactos = $st->fetchAll();
        }
        $equipo = $this->pdo()->query("SELECT id, nombre, cargo, rol FROM portal_equipo WHERE activo = 1 ORDER BY nombre");
        return [
            'convEquipo'    => $equipo ? $equipo->fetchAll() : [],
            'convContactos' => $contactos,
            'convMarcados'  => $marcados,
        ];
    }

    /**
     * Guarda los convocados del formulario y, si se pidió, manda invitaciones, cambios y cancelaciones.
     *
     * @param array<string, mixed>|null $antes
     * @return int invitaciones enviadas
     */
    private function guardarConvocados(string $id, ?array $antes): int
    {
        $r = $this->service()->find($id);
        if ($r === null) {
            return 0;
        }
        $cambios = ['nuevos' => [], 'quitados' => []];
        if (!empty($_POST['convocados_form'])) {
            $cambios = $this->convocados()->guardar($id, (string) $r['cliente_id'],
                array_map('strval', (array) ($_POST['conv_equipo'] ?? [])), array_map('strval', (array) ($_POST['conv_contacto'] ?? [])));
        }
        if (empty($_POST['invitar'])) {
            return 0;
        }
        return $this->convocados()->sincronizar($antes, $r, $cambios);
    }

    /** Datos para que el formulario muestre la hora del cliente y la equivalencia en la de la agencia. */
    private function zonasFormulario(): array
    {
        return ['zonaAgencia' => Zona::agencia(), 'paisAgencia' => Zona::pais(), 'nombreAgencia' => Zona::nombre()];
    }

    public const FILTRO_CUANDO = ['proximas' => 'Próximas', 'pasadas' => 'Pasadas', 'archivadas' => 'Archivadas', 'todas' => 'Todas'];
    public const FILTRO_ESTADO = [
        'por_revisar'  => 'Con tareas por revisar',
        'sin_resumen'  => 'Sin resumen',
        'sin_publicar' => 'Resumen sin publicar',
        'publicado'    => 'Resumen publicado',
        'ocultas'      => 'Ocultas al cliente',
    ];

    /** ¿La reunión cumple el filtro de estado? */
    private static function enEstado(array $r, string $e): bool
    {
        return match ($e) {
            'por_revisar'  => (int) $r['n_propuestas'] > 0,
            'sin_resumen'  => trim((string) $r['resumen']) === '',
            'sin_publicar' => trim((string) $r['resumen']) !== '' && !(int) $r['resumen_publicado'],
            'publicado'    => trim((string) $r['resumen']) !== '' && (int) $r['resumen_publicado'] && (int) $r['publicada'],
            'ocultas'      => !(int) $r['publicada'],
            default        => true,
        };
    }

    public function index(): void
    {
        if (($volver = ListaRecordada::volverA($this->ui->url('reuniones'))) !== null) {
            $this->ui->redirect($volver);
            return;
        }
        $f = FiltrosLista::desdeGet($this->ui->url('reuniones'), ['cuando' => 'proximas', 'estado' => '', 'proyecto' => ''], [
            'cuando'   => array_keys(self::FILTRO_CUANDO),
            'estado'   => array_keys(self::FILTRO_ESTADO),
            'proyecto' => 'uuid',
        ]);
        $hoy = (new \DateTimeImmutable('now', new \DateTimeZone(Zona::agencia())))->format('Y-m-d');
        $todas = $this->ui->filtrar($this->service()->listAll(), 'proyecto_id');
        $base = array_values(array_filter($todas, fn(array $r): bool => ($f->get('proyecto') === '' || $r['proyecto_id'] === $f->get('proyecto'))
            && self::enEstado($r, $f->get('estado'))));
        // Próxima / pasada / archivada (más de 20 días): la misma regla que ve el cliente.
        $est = static fn(array $r): string => ReunionService::estado($r);
        $conteos = [
            'proximas'   => count(array_filter($base, fn($r) => $est($r) === 'proxima')),
            'pasadas'    => count(array_filter($base, fn($r) => $est($r) === 'pasada')),
            'archivadas' => count(array_filter($base, fn($r) => $est($r) === 'archivada')),
            'todas'      => count($base),
        ];
        $lista = array_values(array_filter($base, fn(array $r): bool => match ($f->get('cuando')) {
            'proximas'   => $est($r) === 'proxima',
            'pasadas'    => $est($r) === 'pasada',
            'archivadas' => $est($r) === 'archivada',
            default      => true,
        }));
        if ($f->es('cuando', 'proximas')) {
            $lista = array_reverse($lista);   // la más cercana primero; las pasadas, la más reciente primero
        }
        $proyectos = $this->proyectos();
        usort($proyectos, fn($a, $b) => [$a['cliente_nombre'], $a['nombre']] <=> [$b['cliente_nombre'], $b['nombre']]);

        $pag = Paginacion::delEquipo($this->pdo(), $this->ui->autorId(), $this->ui->url('reuniones'), count($lista));
        $lista = $pag->cortar($pag->sentido($lista));
        $this->ui->view('reuniones/index.latte', [
            'pag'           => $pag,
            'reuniones'     => $lista,
            'filtros'       => $f,
            'conteos'       => $conteos,
            'cuandos'       => Idioma::lista(self::FILTRO_CUANDO),
            'estadosF'      => Idioma::lista(self::FILTRO_ESTADO),
            'proyectosF'    => $proyectos,
            'colorProy'     => Fmt::coloresTodos($this->pdo()),
            'marcasCli'     => Fmt::marcasClientes($this->pdo()),
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
        $yo = $this->ui->autorId() ?? (new EquipoService($this->pdo()))->unico();   // equipo de una persona: convocada
        $this->ui->view('reuniones/nueva.latte', $this->zonasFormulario() + $this->datosConvocados($proyectos, ['equipo' => $yo !== null ? [$yo] : [], 'contacto' => []]) + [
            'proyectos' => $proyectos,
            'proyectoId' => (string) ($_GET['proyecto'] ?? $_GET['proyecto_id'] ?? ''),
            'fasesRango' => $this->ui->filtrar((new FaseService($this->pdo()))->conRangos(), 'proyecto_id'),
        ]);
    }

    public function store(): void
    {
        $_POST['fecha'] = $this->combinar('fecha');
        $_POST['publicada'] = $_POST['publicada'] ?? '';
        $_POST['resumen_publicado'] = '';
        $id = $this->service()->create($_POST);
        $n = $this->guardarConvocados($id, null);
        $this->ui->redirect($this->url('reuniones/' . $id), ($n > 0 ? Idioma::t('Reunión creada e invitaciones enviadas (%d). Aquí puedes pegar la transcripción cuando termine.', $n) : Idioma::t('Reunión creada. Aquí puedes pegar la transcripción cuando termine.')));
    }

    public function edit(string $id): void
    {
        $reunion = $this->service()->find($id);
        if ($reunion === null) {
            $this->ui->redirect($this->url('reuniones'), 'Reunión no encontrada.', 'error');
            return;
        }
        $ia = $this->ia();
        $paisCli = $this->paisDelProyecto((string) $reunion['proyecto_id']);
        $proyectos = $this->proyectos();
        $this->ui->view('reuniones/edit.latte', $this->zonasFormulario() + $this->datosConvocados($proyectos, $this->convocados()->ids($id)) + [
            'reunion'    => $reunion,
            'paisCliente' => $paisCli,
            'fasesRango' => $this->ui->filtrar((new FaseService($this->pdo()))->conRangos(), 'proyecto_id'),
            'proyectos'  => $proyectos,
            'tareasReunion' => $this->service()->propuestasParaEditar($id),
            'iaActiva'   => $ia->activa(),
            'gcal'       => $this->service()->enlaceGoogleCalendar($reunion),
            'proxima'    => $reunion['prox_reunion_id'] ? $this->service()->find((string) $reunion['prox_reunion_id']) : null,
            'fmt'        => new Fmt(),
            'flash_success' => $this->ui->flash('success'),
            'flash_error'   => $this->ui->flash('error'),
        ]);
    }

    /**
     * Un solo «Guardar» (o «Analizar con IA»). Guarda los datos, las ediciones de las tareas ya creadas
     * y de las sugeridas, descarta las marcadas y, al guardar, crea las marcadas y aplica «Qué ve el cliente».
     * Las sugerencias sin decidir quedan pendientes.
     */
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
        $invitaciones = $this->guardarConvocados($id, $antes);

        // Decisión de cada sugerencia: «crear», «descartar» o nada (queda pendiente para otro día).
        // También se aceptan los nombres de antes (aprobar[] / quitar[]).
        $decision = [];
        foreach ((array) ($_POST['decision'] ?? []) as $pid => $d) {
            if (in_array($d, ['crear', 'descartar'], true)) {
                $decision[(string) $pid] = $d;
            }
        }
        foreach ((array) ($_POST['aprobar'] ?? []) as $pid) {
            $decision[(string) $pid] ??= 'crear';
        }
        foreach ((array) ($_POST['quitar'] ?? []) as $pid) {
            $decision[(string) $pid] = 'descartar';
        }

        $quitadas = 0;
        foreach (array_keys($decision, 'descartar', true) as $pid) {
            $q = $svc->propuesta((string) $pid);
            if ($q !== null && $q['reunion_id'] === $id && $q['estado'] === 'propuesta') {
                $svc->borrarPropuesta((string) $pid);
                $quitadas++;
            }
            unset($_POST['prop'][$pid], $decision[$pid]);
        }

        // Ediciones de las sugerencias pendientes.
        foreach ((array) ($_POST['prop'] ?? []) as $pid => $datos) {
            $q = $svc->propuesta((string) $pid);
            if ($q !== null && $q['reunion_id'] === $id && is_array($datos)) {
                $svc->actualizarPropuesta((string) $pid, $datos);
            }
        }

        // Tareas ya creadas: los cambios hechos aquí pasan a la tarea real.
        $actualizadas = 0;
        $nuevasVisibles = 0;
        foreach ((array) ($_POST['creada'] ?? []) as $pid => $datos) {
            if (is_array($datos) && ($c = $svc->actualizarCreada($id, (string) $pid, $datos)) !== null) {
                $actualizadas++;
                $nuevasVisibles += $c['nuevaVisible'] ? 1 : 0;
            }
        }

        // Tarea escrita a mano: se crea al guardar.
        if (is_array($_POST['nueva'] ?? null) && trim((string) ($_POST['nueva']['titulo'] ?? '')) !== '') {
            $nid = $svc->agregarPropuesta($id, $_POST['nueva']);
            if ($nid !== null) {
                $decision[$nid] = 'crear';
            }
        }

        $msg = 'Reunión guardada.';
        $tipo = 'success';

        // Cualquier fallo inesperado se muestra como aviso (y queda en el log) en vez de un error 500 mudo.
        try {
            if ($accion === 'analizar') {
                [$msg, $tipo] = $this->analizar($id);
            } else {
                $msg = $this->aplicar($id, $antes, array_keys($decision, 'crear', true), [
                    'actualizadas' => $actualizadas, 'nuevasVisibles' => $nuevasVisibles, 'quitadas' => $quitadas, 'invitaciones' => $invitaciones,
                ]);
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
     * El único «Guardar»: crea las sugerencias marcadas, agenda o muestra la próxima reunión,
     * deja rastro de lo publicado y, si se pidió, avisa por correo de lo nuevo. Devuelve el mensaje.
     *
     * @param array<string, mixed> $antes
     * @param array<string> $crear
     * @param array{actualizadas: int, nuevasVisibles: int, quitadas: int, invitaciones: int} $hecho
     */
    private function aplicar(string $id, array $antes, array $crear, array $hecho): string
    {
        $svc = $this->service();
        $res = $svc->aprobarPropuestas($id, array_values(array_map('strval', $crear)));

        $proxId = null;
        $agendada = false;
        $proxPublicada = false;
        if (!empty($_POST['agendar_prox'])) {
            $proxId = $svc->agendarProxima($id, !empty($_POST['prox_publicar']));
            if ($proxId !== null) {
                $agendada = true;
                $proxPublicada = !empty($_POST['prox_publicar']);
                // La próxima reunión hereda los convocados y, si se pidió, les llega la invitación.
                $conv = $this->convocados();
                $nuevos = [];
                foreach ($conv->ids($id) as $tipoC => $ids) {
                    foreach ($ids as $pid) {
                        $conv->agregar($proxId, $tipoC, $pid);
                        $nuevos[] = ['tipo' => $tipoC, 'id' => $pid];
                    }
                }
                if (!empty($_POST['invitar']) && ($prox = $svc->find($proxId)) !== null) {
                    $conv->sincronizar(null, $prox, ['nuevos' => $nuevos, 'quitados' => []]);
                }
            }
        } elseif (!empty($_POST['prox_form']) && (string) $antes['prox_reunion_id'] !== '') {
            // Ya agendada: el interruptor de «Qué ve el cliente» la muestra u oculta.
            $proxId = (string) $antes['prox_reunion_id'];
            $mostrar = !empty($_POST['prox_publicar']);
            $proxPublicada = $svc->fijarPublicada($proxId, $mostrar) && $mostrar;
        }
        $ahora = $svc->find($id) ?? $antes;
        $avisar = !empty($_POST['avisar']);
        $enviado = $this->registrarPublicacion($antes, $ahora, $res['visibles'] + $hecho['nuevasVisibles'], $proxPublicada, $avisar, $proxId);

        $n = static fn(int $k, string $uno, string $varios): string => $k . ' ' . Idioma::t($k === 1 ? $uno : $varios);
        $partes = [];
        if ($res['creadas'] > 0) {
            $partes[] = $n($res['creadas'], 'tarea creada', 'tareas creadas') . ($res['visibles'] > 0 ? ' (' . ($res['visibles'] === 1 ? Idioma::t('%d la ve el cliente', $res['visibles']) : Idioma::t('%d las ve el cliente', $res['visibles'])) . ')' : ' ' . Idioma::t('(internas)'));
        }
        if ($hecho['actualizadas'] > 0) {
            $partes[] = $n($hecho['actualizadas'], 'tarea actualizada', 'tareas actualizadas');
        }
        if ($hecho['quitadas'] > 0) {
            $partes[] = $n($hecho['quitadas'], 'sugerencia descartada', 'sugerencias descartadas');
        }
        if ($agendada) {
            $partes[] = Idioma::t('próxima reunión agendada');
        }
        if ((int) $ahora['resumen_publicado'] === 1 && (int) $antes['resumen_publicado'] === 0 && (int) $ahora['publicada'] === 1) {
            $partes[] = Idioma::t('resumen publicado');
        }
        if ($hecho['invitaciones'] > 0) {
            $partes[] = Idioma::t('invitaciones o cambios enviados a los convocados (%d)', $hecho['invitaciones']);
        }
        if ($enviado) {
            $partes[] = Idioma::t('le avisamos por correo al cliente');
        }
        $msg = Idioma::t('Reunión guardada') . ($partes !== [] ? ': ' . implode(', ', $partes) : '') . '.';
        if ($avisar && !$enviado) {
            $msg .= ' ' . Idioma::t('No se envió correo: no había nada nuevo que el cliente pudiera ver.');
        }
        return $msg;
    }

    /**
     * Deja rastro en la actividad del cliente y, si se pidió, le avisa por correo
     * (un solo correo con lo nuevo). Sólo actúa si algo quedó visible para él.
     *
     * @param array<string, mixed> $antes
     * @param array<string, mixed> $ahora
     */
    private function registrarPublicacion(array $antes, array $ahora, int $tareasVisibles, bool $proxPublicada, bool $avisar, ?string $proxId = null): bool
    {
        $visibleAhora = (int) $ahora['publicada'] === 1;
        $resumenNuevo = $visibleAhora && (int) $ahora['resumen_publicado'] === 1 && (int) $antes['resumen_publicado'] === 0;
        if (!$visibleAhora || (!$resumenNuevo && $tareasVisibles === 0 && !$proxPublicada)) {
            return false;
        }

        (new ActividadService($this->pdo()))->registrar(
            (string) $ahora['cliente_id'], (string) $ahora['proyecto_id'], 'equipo', $this->firma(),
            'reunion', 'reunion', (string) $ahora['id'], (string) $ahora['titulo']
        );
        if (!$avisar) {
            return false;
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
                $paisCli = $this->paisDelProyecto((string) $p['proyecto_id']);
                $loc = Zona::aPais((string) $p['fecha'], $paisCli);
                $bloques[] = ['datos' => [
                    ['Próxima reunión', (string) $p['titulo']],
                    ['Cuándo', $f->fechaLarga($loc) . ($f->hora($loc) !== '' ? ' a las ' . $f->hora($loc) . ' (hora de ' . HorarioHabil::nombreDe($paisCli) . ')' : '')],
                ]];
            }
        }
        $convContactos = $this->convocados()->ids((string) $ahora['id'])['contacto'];
        (new Notifier($this->ctx, $this->pdo()))->alCliente(
            (string) $ahora['cliente_id'], null, Idioma::f('Novedades de la reunión «%s»', (string) $ahora['titulo']), '', '/portal/reuniones/' . $ahora['id'],
            ($convContactos !== [] ? ['contactos' => $convContactos] : []) + ['etiqueta' => Idioma::f('Reunión'), 'titulo' => Idioma::f('Novedades de la reunión'), 'resaltado' => Idioma::f('reunión'), 'boton' => Idioma::f('Ver el detalle'), 'bloques' => $bloques,
             'preheader' => Idioma::f('Resumen, acuerdos y próximos pasos de «%s»', (string) $ahora['titulo'])]
        );
        return true;
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
        $r = $this->service()->find($id);
        if ($r !== null) {
            $this->convocados()->cancelarTodo($r);
            $this->pdo()->prepare('DELETE FROM ' . Convocados::TABLA . ' WHERE reunion_id = ?')->execute([$id]);
        }
        $this->service()->delete($id);
        $this->ui->redirect($this->url('reuniones'), 'Reunión eliminada.');
    }
}

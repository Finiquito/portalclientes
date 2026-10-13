<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

use TypeDock\Core\PluginContext;

/**
 * Contactos ganados del lado de la agencia (admin de TypeDock y panel /equipo):
 * el consolidado por campaña, la lista con filtros, la ficha de cada contacto y el
 * ingreso manual. Cada persona ve sólo los proyectos que tiene asignados.
 */
class LeadAdminController
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

    private function service(): LeadService
    {
        return new LeadService($this->pdo());
    }

    /** @return array<int, array<string, mixed>> proyectos con el módulo activo que ve esta persona */
    private function proyectos(): array
    {
        return $this->ui->filtrar($this->service()->proyectosConLeads(), 'id');
    }

    public function index(): void
    {
        $proyectos = $this->proyectos();
        $f = FiltrosLista::desdeGet($this->ui->url('leads'), ['estado' => '', 'proyecto' => '', 'q' => ''], [
            'estado'   => array_keys(LeadService::ESTADOS),
            'proyecto' => 'uuid',
            'q'        => 'texto',
        ]);
        $svc = $this->service();
        $base = $this->ui->filtrar($svc->lista(['proyecto_id' => $f->get('proyecto'), 'q' => $f->get('q')]), 'proyecto_id');
        $leads = $f->get('estado') === '' ? $base : array_values(array_filter($base, fn(array $l): bool => $l['estado'] === $f->get('estado')));

        $this->ui->view('leads/index.latte', [
            'mcProyecto'  => $f->get('proyecto') !== '' && $svc->mailchimp($f->get('proyecto')) !== null,
            'mcEstados'   => MailchimpConsulta::ESTADOS,
            'leads'       => $leads,
            'porCampana'  => LeadService::porCampana($base),
            'conteo'      => LeadService::conteo($base),
            'total'       => count($base),
            'filtros'     => $f,
            'proyectos'   => $proyectos,
            'estados'     => LeadService::ESTADOS,
            'chips'       => LeadService::CHIPS_ADMIN,
            'origenes'    => LeadService::ORIGENES,
            'colorProy'   => Fmt::coloresTodos($this->pdo()),
            'fmt'         => new Fmt(),
            'hoy'         => Cronograma::hoy(),
            'flash_success' => $this->ui->flash('success'),
            'flash_error'   => $this->ui->flash('error'),
        ]);
    }

    public function create(): void
    {
        $proyectos = $this->proyectos();
        if ($proyectos === []) {
            $this->ui->redirect($this->ui->url('leads'), 'Primero activa «Con contactos ganados» en un proyecto.', 'error');
            return;
        }
        $this->ui->view('leads/edit.latte', [
            'l'         => null,
            'proyectos' => $proyectos,
            'pre'       => ['proyecto_id' => (string) ($_GET['proyecto'] ?? '')],
            'estados'   => LeadService::ESTADOS,
            'chips'     => LeadService::CHIPS_ADMIN,
            'origenes'  => LeadService::ORIGENES,
            'fmt'       => new Fmt(),
        ]);
    }

    public function store(): void
    {
        $svc = $this->service();
        $pid = (string) ($_POST['proyecto_id'] ?? '');
        $p = in_array($pid, array_column($this->proyectos(), 'id'), true) ? $svc->proyecto($pid) : null;
        if ($p === null) {
            $this->ui->redirect($this->ui->url('leads/nuevo'), 'Elige un proyecto con contactos ganados.', 'error');
            return;
        }
        $r = $svc->crear($p, $_POST, 'manual', $this->ui->firma());
        if (isset($r['error'])) {
            $this->ui->redirect($this->ui->url('leads/nuevo') . '?proyecto=' . rawurlencode($pid), $r['error'], 'error');
            return;
        }
        $this->ui->redirect($this->ui->url('leads/' . $r['id']), !empty($r['duplicado'])
            ? 'Ese contacto ya estaba (mismo correo o teléfono en los últimos ' . LeadService::DIAS_DUPLICADO . ' días).'
            : 'Contacto agregado. El cliente lo ve en su portal.');
    }

    /** El lead, si su proyecto sigue con el módulo activo y esta persona lo ve. */
    private function lead(string $id): ?array
    {
        $l = $this->service()->find($id);
        if ($l === null || $this->service()->proyecto((string) $l['proyecto_id']) === null) {
            return null;
        }
        return $this->ui->filtrar([$l], 'proyecto_id') !== [] ? $l : null;
    }

    public function ver(string $id): void
    {
        $l = $this->lead($id);
        if ($l === null) {
            $this->ui->redirect($this->ui->url('leads'), 'Contacto no encontrado.', 'error');
            return;
        }
        $svc = $this->service();
        $conMc = $svc->mailchimp((string) $l['proyecto_id']) !== null;
        $mcError = null;
        if ($conMc && LeadService::mcVencido($l) && (string) $l['correo'] !== '' && empty($l['anonimizado_en'])) {
            $mcError = $svc->revisarMailchimp($l);
            $l = $svc->find($id) ?? $l;
        }
        $this->ui->view('leads/edit.latte', [
            'l'         => $l,
            'mc'        => LeadService::mc($l),
            'conMc'     => $conMc,
            'mcError'   => $mcError,
            'mcEventos' => MailchimpConsulta::EVENTOS,
            'proyectos' => [],
            'pre'       => [],
            'estados'   => LeadService::ESTADOS,
            'chips'     => LeadService::CHIPS_ADMIN,
            'origenes'  => LeadService::ORIGENES,
            'fmt'       => new Fmt(),
            'flash_success' => $this->ui->flash('success'),
            'flash_error'   => $this->ui->flash('error'),
        ]);
    }

    public function update(string $id): void
    {
        $l = $this->lead($id);
        if ($l === null) {
            $this->ui->redirect($this->ui->url('leads'), 'Contacto no encontrado.', 'error');
            return;
        }
        $datos = array_intersect_key($_POST, array_flip(['nombre', 'correo', 'telefono', 'empresa', 'estado', 'notas', 'proximo_paso', 'proximo_paso_fecha']));
        $error = $this->service()->actualizar($l, $datos, $this->ui->firma());
        $this->ui->redirect($this->ui->url('leads/' . $id), $error ?? 'Guardado.', $error === null ? 'success' : 'error');
    }

    // ---- Formularios y webhook (fuentes con token) ---------------------------------

    private const SESION_TOKEN = 'portal_lead_token_nuevo';

    public function fuentes(): void
    {
        $f = new LeadFuenteService($this->pdo());
        $sel = (string) ($_GET['proyecto'] ?? '');
        $proyectos = array_values(array_filter($this->proyectos(), fn(array $p): bool => $this->ui->gestionaProyecto((string) $p['id'])
            && ($sel === '' || $p['id'] === $sel)));
        $mc = [];
        foreach ($proyectos as &$p) {
            $p['fuentes'] = $f->delProyecto((string) $p['id']);
            $cid = (string) $p['cliente_id'];
            if (!isset($mc[$cid])) {
                $con = MailchimpConsulta::deCliente($this->pdo(), $cid);
                $mc[$cid] = $con === null ? ['clave' => false] : ['clave' => true] + $con->audiencias();
            }
            $fila = $this->pdo()->prepare('SELECT mc_audiencia, mc_audiencia_nombre FROM portal_proyectos WHERE id = ?');
            $fila->execute([(string) $p['id']]);
            $p += (array) $fila->fetch();
        }
        unset($p);
        PortalSession::iniciar();
        $nuevo = $_SESSION[self::SESION_TOKEN] ?? null;
        unset($_SESSION[self::SESION_TOKEN]);
        $base = Notifier::baseUrl();
        $this->ui->view('leads/fuentes.latte', [
            'proyectos' => $proyectos,
            'mc'        => $mc,
            'nuevo'     => is_array($nuevo) ? $nuevo : null,
            'endpoint'  => $base . '/leads/f',
            'snippet'   => is_array($nuevo) ? LeadFuenteService::snippet($base . '/leads/f/' . $nuevo['token']) : '',
            'fmt'       => new Fmt(),
            'colorProy' => Fmt::coloresTodos($this->pdo()),
            'flash_success' => $this->ui->flash('success'),
            'flash_error'   => $this->ui->flash('error'),
        ]);
    }

    private function volverFuentes(string $proyectoId, string $msg, string $tipo = 'success'): void
    {
        $this->ui->redirect($this->ui->url('leads/fuentes') . '?proyecto=' . rawurlencode($proyectoId), $msg, $tipo);
    }

    /** La fuente, si es de ese proyecto y quien actúa lo administra. */
    private function fuenteDe(string $proyectoId, string $fuenteId): ?array
    {
        $f = (new LeadFuenteService($this->pdo()))->find($fuenteId);
        return $f !== null && (string) $f['proyecto_id'] === $proyectoId && $this->ui->gestionaProyecto($proyectoId) ? $f : null;
    }

    private function mostrarToken(string $fuenteId, string $token): void
    {
        PortalSession::iniciar();
        $_SESSION[self::SESION_TOKEN] = ['id' => $fuenteId, 'token' => $token];
    }

    public function fuenteCrear(string $proyectoId): void
    {
        $p = $this->service()->proyecto($proyectoId);
        if ($p === null || !$this->ui->gestionaProyecto($proyectoId)) {
            $this->ui->redirect($this->ui->url('leads/fuentes'), 'Ese proyecto no tiene contactos ganados activos.', 'error');
            return;
        }
        $r = (new LeadFuenteService($this->pdo()))->crear($p, (string) ($_POST['nombre'] ?? ''), (string) ($_POST['url_gracias'] ?? ''));
        $this->mostrarToken($r['id'], $r['token']);
        $this->volverFuentes($proyectoId, 'Listo. Copia el token ahora: por seguridad no se vuelve a mostrar.');
    }

    public function fuenteEditar(string $proyectoId, string $fuenteId): void
    {
        if ($this->fuenteDe($proyectoId, $fuenteId) === null) {
            $this->volverFuentes($proyectoId, 'No encontramos ese formulario.', 'error');
            return;
        }
        (new LeadFuenteService($this->pdo()))->editar($fuenteId, (string) ($_POST['nombre'] ?? ''), (string) ($_POST['url_gracias'] ?? ''));
        $this->volverFuentes($proyectoId, 'Guardado.');
    }

    public function fuenteRevocar(string $proyectoId, string $fuenteId): void
    {
        if ($this->fuenteDe($proyectoId, $fuenteId) === null) {
            $this->volverFuentes($proyectoId, 'No encontramos ese formulario.', 'error');
            return;
        }
        (new LeadFuenteService($this->pdo()))->revocar($fuenteId);
        $this->volverFuentes($proyectoId, 'Token revocado: ese formulario ya no recibe contactos.');
    }

    public function fuenteRotar(string $proyectoId, string $fuenteId): void
    {
        if ($this->fuenteDe($proyectoId, $fuenteId) === null) {
            $this->volverFuentes($proyectoId, 'No encontramos ese formulario.', 'error');
            return;
        }
        $token = (new LeadFuenteService($this->pdo()))->rotar($fuenteId);
        $this->mostrarToken($fuenteId, $token);
        $this->volverFuentes($proyectoId, 'Token nuevo listo. El anterior dejó de funcionar: actualízalo en la landing o en Make.');
    }

    // ---- Exportar, importar planillas y datos personales ------------------------------

    /** En pruebas no se corta la request después de una descarga. */
    public static bool $sinSalir = false;

    protected function terminate(): void
    {
        if (!self::$sinSalir) {
            exit;
        }
    }

    public function exportar(): void
    {
        $f = FiltrosLista::desdeGet($this->ui->url('leads'), ['estado' => '', 'proyecto' => '', 'q' => ''], [
            'estado' => array_keys(LeadService::ESTADOS), 'proyecto' => 'uuid', 'q' => 'texto',
        ]);
        $leads = $this->ui->filtrar($this->service()->lista(['proyecto_id' => $f->get('proyecto'), 'q' => $f->get('q'), 'estado' => $f->get('estado')]), 'proyecto_id');
        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="contactos-ganados-' . date('Y-m-d') . '.csv"');
            header('Cache-Control: no-store');
        }
        echo LeadService::csv($leads, true);
        $this->terminate();
    }

    private const SESION_IMPORT = 'portal_lead_import';

    /** @return array<int, array<string, mixed>> proyectos con el módulo que esta persona administra */
    private function proyectosGestion(): array
    {
        return array_values(array_filter($this->proyectos(), fn(array $p): bool => $this->ui->gestionaProyecto((string) $p['id'])));
    }

    public function importarForm(): void
    {
        $this->ui->view('leads/importar.latte', [
            'proyectos' => $this->proyectosGestion(),
            'sel'       => (string) ($_GET['proyecto'] ?? ''),
            'revision'  => null,
            'flash_success' => $this->ui->flash('success'),
            'flash_error'   => $this->ui->flash('error'),
        ]);
    }

    public function importarRevisar(string $proyectoId = ''): void
    {
        $proyectoId = $proyectoId !== '' ? $proyectoId : (string) ($_POST['proyecto'] ?? '');
        $p = $this->service()->proyecto($proyectoId);
        if ($p === null || !$this->ui->gestionaProyecto($proyectoId)) {
            $this->ui->redirect($this->ui->url('leads/importar'), 'Elige un proyecto con contactos ganados.', 'error');
            return;
        }
        $a = $_FILES['archivo'] ?? null;
        if (!is_array($a) || (int) ($a['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $a['tmp_name']) && !self::$sinSalir) {
            $this->ui->redirect($this->ui->url('leads/importar') . '?proyecto=' . rawurlencode($proyectoId), 'Sube el archivo .csv de la campaña.', 'error');
            return;
        }
        $leido = LeadImportador::leerTexto((string) @file_get_contents((string) $a['tmp_name'], false, null, 0, LeadImportador::MAX_BYTES + 1));
        if ($leido['filas'] === []) {
            $this->ui->redirect($this->ui->url('leads/importar') . '?proyecto=' . rawurlencode($proyectoId), implode(' ', $leido['errores']) ?: 'La planilla no tiene filas.', 'error');
            return;
        }
        PortalSession::iniciar();
        $_SESSION[self::SESION_IMPORT] = ['proyecto' => $proyectoId, 'filas' => $leido['filas'], 'creado' => $leido['creado'], 'origen' => $leido['origen']];
        $this->ui->view('leads/importar.latte', [
            'proyectos' => $this->proyectosGestion(),
            'sel'       => $proyectoId,
            'proyecto'  => $p,
            'revision'  => $this->service()->revisar($proyectoId, $leido['filas']) + ['avisos' => $leido['errores'], 'total' => count($leido['filas'])],
            'origen'    => $leido['origen'],
            'archivo'   => mb_substr((string) ($a['name'] ?? ''), 0, 120),
            'origenes'  => array_intersect_key(LeadService::ORIGENES, array_flip(['csv_meta', 'csv_google', 'csv_mailchimp', 'csv'])),
        ]);
    }

    public function importarConfirmar(string $proyectoId): void
    {
        PortalSession::iniciar();
        $imp = $_SESSION[self::SESION_IMPORT] ?? null;
        unset($_SESSION[self::SESION_IMPORT]);
        $p = $this->service()->proyecto($proyectoId);
        if (!is_array($imp) || ($imp['proyecto'] ?? '') !== $proyectoId || $p === null || !$this->ui->gestionaProyecto($proyectoId)) {
            $this->ui->redirect($this->ui->url('leads/importar'), 'La vista previa expiró. Vuelve a subir la planilla.', 'error');
            return;
        }
        $origen = (string) ($_POST['origen'] ?? $imp['origen']);
        if (!in_array($origen, ['csv_meta', 'csv_google', 'csv_mailchimp', 'csv'], true)) {
            $origen = 'csv';
        }
        $campana = mb_substr(trim((string) ($_POST['campana'] ?? '')), 0, 255);
        $filas = array_map(fn(array $f): array => $campana !== '' && ($f['campana'] ?? '') === '' && ($f['utm_campaign'] ?? '') === '' ? $f + ['campana' => $campana] : $f, (array) $imp['filas']);
        $n = $this->service()->importar($p, $filas, (array) $imp['creado'], $origen, $this->ui->firma());
        if ($n['nuevos'] > 0 && !empty($_POST['avisar'])) {
            try {
                $this->service()->avisarCliente(new Notifier($this->ctx, $this->pdo()), (string) $p['cliente_id'], (string) $p['nombre']);
            } catch (\Throwable $e) {
                error_log('[portal] aviso de importación: ' . $e->getMessage());
            }
        }
        $msg = $n['nuevos'] === 1 ? 'Importado 1 contacto nuevo.' : "Importados {$n['nuevos']} contactos nuevos.";
        if ($n['duplicados'] > 0) {
            $msg .= $n['duplicados'] === 1 ? ' 1 ya estaba y se saltó.' : " {$n['duplicados']} ya estaban y se saltaron.";
        }
        if ($n['errores'] > 0) {
            $msg .= $n['errores'] === 1 ? ' 1 fila sin correo ni teléfono válido no se importó.' : " {$n['errores']} filas sin correo ni teléfono válidos no se importaron.";
        }
        $this->ui->redirect($this->ui->url('leads') . '?proyecto=' . rawurlencode($proyectoId), $msg);
    }

    public function anonimizar(string $id): void
    {
        $l = $this->lead($id);
        if ($l === null || !$this->ui->gestionaProyecto((string) $l['proyecto_id'])) {
            $this->ui->redirect($this->ui->url('leads'), 'No puedes cambiar ese contacto.', 'error');
            return;
        }
        $this->service()->anonimizar($id);
        $this->ui->redirect($this->ui->url('leads/' . $id), 'Se borraron sus datos personales. Queda sólo para los conteos.');
    }

    public function eliminar(string $id): void
    {
        $l = $this->lead($id);
        if ($l === null || !$this->ui->gestionaProyecto((string) $l['proyecto_id'])) {
            $this->ui->redirect($this->ui->url('leads'), 'No puedes cambiar ese contacto.', 'error');
            return;
        }
        $this->service()->eliminar($id);
        $this->ui->redirect($this->ui->url('leads') . '?proyecto=' . rawurlencode((string) $l['proyecto_id']), 'Contacto eliminado.');
    }

    // ---- Mailchimp (sólo lectura) ----------------------------------------------------

    /** El proyecto con el módulo activo, si quien actúa lo administra. */
    private function proyectoGestion(string $proyectoId): ?array
    {
        $p = $this->service()->proyecto($proyectoId);
        return $p !== null && $this->ui->gestionaProyecto($proyectoId) ? $p : null;
    }

    public function mcClave(string $proyectoId): void
    {
        $p = $this->proyectoGestion($proyectoId);
        $clave = trim((string) ($_POST['clave'] ?? ''));
        if ($p === null) {
            $this->volverFuentes($proyectoId, 'No puedes conectar ese proyecto.', 'error');
            return;
        }
        if (!MailchimpConsulta::claveValida($clave)) {
            $this->volverFuentes($proyectoId, 'Esa no parece una clave de API de Mailchimp (termina en «-us» y un número, por ejemplo «-us21»).', 'error');
            return;
        }
        $r = (new MailchimpConsulta($clave))->audiencias();
        if (!$r['ok']) {
            $this->volverFuentes($proyectoId, (string) $r['error'], 'error');
            return;
        }
        MailchimpConsulta::guardarClave($this->pdo(), (string) $p['cliente_id'], $clave);
        $this->volverFuentes($proyectoId, 'Mailchimp conectado para ' . $p['cliente_nombre'] . '. Ahora elige la audiencia de esta campaña.');
    }

    public function mcQuitar(string $proyectoId): void
    {
        $p = $this->proyectoGestion($proyectoId);
        if ($p === null) {
            $this->volverFuentes($proyectoId, 'No puedes cambiar ese proyecto.', 'error');
            return;
        }
        MailchimpConsulta::quitarClave($this->pdo(), (string) $p['cliente_id']);
        $this->volverFuentes($proyectoId, 'Se quitó la clave de Mailchimp de ' . $p['cliente_nombre'] . ' y sus campañas quedaron desconectadas.');
    }

    public function mcAudiencia(string $proyectoId): void
    {
        $p = $this->proyectoGestion($proyectoId);
        $mc = $p !== null ? MailchimpConsulta::deCliente($this->pdo(), (string) $p['cliente_id']) : null;
        if ($p === null || $mc === null) {
            $this->volverFuentes($proyectoId, 'Primero conecta Mailchimp.', 'error');
            return;
        }
        $id = (string) ($_POST['audiencia'] ?? '');
        $nombre = null;
        if ($id !== '') {
            $r = $mc->audiencias();
            foreach ($r['audiencias'] ?? [] as $a) {
                if ($a['id'] === $id) {
                    $nombre = $a['nombre'];
                }
            }
            if ($nombre === null) {
                $this->volverFuentes($proyectoId, $r['error'] ?? 'Esa audiencia no está en la cuenta de Mailchimp.', 'error');
                return;
            }
        }
        $this->pdo()->prepare('UPDATE portal_proyectos SET mc_audiencia = ?, mc_audiencia_nombre = ? WHERE id = ?')->execute([$id ?: null, $nombre, $proyectoId]);
        // Otra audiencia: lo revisado contra la anterior ya no vale.
        $this->pdo()->prepare('UPDATE portal_leads SET mc_estado = NULL, mc_datos = NULL, mc_revisado_en = NULL WHERE proyecto_id = ?')->execute([$proyectoId]);
        $this->volverFuentes($proyectoId, $id === '' ? 'Campaña desconectada de Mailchimp.' : 'Listo: los contactos de esta campaña se contrastan con «' . $nombre . '».');
    }

    /** Revisa en Mailchimp los contactos de la campaña (los que no se han revisado hoy). */
    public function mcRevisarProyecto(string $proyectoId): void
    {
        $volver = $this->ui->url('leads') . '?proyecto=' . rawurlencode($proyectoId);
        if ($this->service()->mailchimp($proyectoId) === null || $this->ui->filtrar([['p' => $proyectoId]], 'p') === []) {
            $this->ui->redirect($volver, 'Esta campaña no está conectada a Mailchimp.', 'error');
            return;
        }
        $r = $this->service()->revisarProyecto($proyectoId);
        $msg = $r['revisados'] === 1 ? 'Se revisó 1 contacto en Mailchimp.' : "Se revisaron {$r['revisados']} contactos en Mailchimp.";
        if ($r['pendientes'] > 0) {
            $msg .= " Quedan {$r['pendientes']}: vuelve a apretar el botón para seguir.";
        }
        $this->ui->redirect($volver, $r['error'] !== null ? $msg . ' ' . $r['error'] : $msg, $r['error'] !== null && $r['revisados'] === 0 ? 'error' : 'success');
    }

    public function mcRevisar(string $id): void
    {
        $l = $this->lead($id);
        if ($l === null) {
            $this->ui->redirect($this->ui->url('leads'), 'Contacto no encontrado.', 'error');
            return;
        }
        $error = $this->service()->revisarMailchimp($l);
        $this->ui->redirect($this->ui->url('leads/' . $id), $error ?? 'Revisado en Mailchimp.', $error === null ? 'success' : 'error');
    }
}

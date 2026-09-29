<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

use TypeDock\Core\PluginContext;

/** Pantalla "qué pasó" para el admin + ajustes globales del portal. */
class ActividadAdminController
{
    public function __construct(private readonly PluginContext $ctx) {}

    private function pdo(): \PDO
    {
        return $this->ctx->db()->pdo();
    }

    private function ajustes(): AjustesService
    {
        return new AjustesService($this->pdo());
    }

    public function actividad(): void
    {
        try {
            $this->notificador()->vaciarCola();
        } catch (\Throwable) {
        }
        $aj    = $this->ajustes();
        $vista = $aj->get('global', 'portal', 'actividad_vista');
        $svc   = new ActividadService($this->pdo());

        $this->ctx->view('templates/admin/actividad/index.latte', [
            'items'          => $svc->recientes(80),
            'nuevas'         => $svc->contarDeClientesDesde($vista),
            'vistaAnterior'  => $vista,
            'fmt'            => new Fmt(),
        ]);

        // Se marca como vista después de mostrarla (el contador de arriba ya se calculó).
        $aj->set('global', 'portal', 'actividad_vista', (new \DateTimeImmutable())->format('Y-m-d H:i:s'));
    }

    public function ajustesForm(): void
    {
        $aj = $this->ajustes()->todos('global', 'portal');
        $this->ctx->view('templates/admin/ajustes/index.latte', [
            'cfg' => [
                'email_avisos'  => $aj['email_avisos'] ?? '',
                'nombre_equipo' => $aj['nombre_equipo'] ?? '',
                'max_mb'        => $aj['max_mb'] ?? '20',
            ],
            'ia' => (function () {
                $ia = new IaService($this->pdo());
                $provs = [];
                foreach (IaService::PROVEEDORES as $k => $nombre) {
                    $provs[$k] = [
                        'nombre' => $nombre, 'sufijo' => $k === 'anthropic' ? '' : '_' . $k,
                        'origen' => $ia->origenClave($k), 'pista' => $ia->pista($k), 'modelo' => $ia->modelo($k),
                        'modelos' => IaService::modelosDe($k), 'env' => IaService::nombreEnv($k),
                        'personalizado' => !isset(IaService::modelosDe($k)[$ia->modelo($k)]),
                    ];
                }
                return ['activa' => $ia->activa(), 'origen' => $ia->origenClave(), 'pista' => $ia->pista(), 'modelo' => $ia->modelo(),
                    'proveedor' => $ia->proveedor(), 'proveedores' => $provs, 'curl' => function_exists('curl_init')];
            })(),
            'correo'        => $this->datosCorreo(),
            'iniMax'        => (int) round(min(
                ArchivoService::iniBytes((string) ini_get('upload_max_filesize')) ?: PHP_INT_MAX,
                ArchivoService::iniBytes((string) ini_get('post_max_size')) ?: PHP_INT_MAX
            ) / 1048576),
            'flash_success' => $this->ctx->getFlash('success'),
            'flash_error'   => $this->ctx->getFlash('error'),
        ]);
    }

    public function ajustesGuardar(): void
    {
        $email = trim((string) ($_POST['email_avisos'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->ctx->redirect($this->ctx->adminUrl('ajustes'), 'El correo de avisos no es válido.', 'error');
            return;
        }
        $this->ajustes()->setMuchos('global', 'portal', [
            'email_avisos'  => $email,
            'nombre_equipo' => mb_substr(trim((string) ($_POST['nombre_equipo'] ?? '')), 0, 60),
            'max_mb'        => (string) max(1, min(500, (int) ($_POST['max_mb'] ?? 20))),
        ]);
        // IA (opcional): las claves sólo se cambian si se escribe una nueva; nunca se muestran de vuelta.
        $ia = new IaService($this->pdo());
        if (isset($_POST['ia_proveedor'])) {
            $ia->guardarProveedor((string) $_POST['ia_proveedor']);
        }
        foreach (array_keys(IaService::PROVEEDORES) as $prov) {
            $suf = $prov === 'anthropic' ? '' : '_' . $prov;
            $nueva = trim((string) ($_POST['ia_clave' . $suf] ?? ''));
            if (!empty($_POST['ia_quitar' . $suf])) {
                $ia->guardarClave('', $prov);
            } elseif ($nueva !== '') {
                if (!preg_match('/^[\w.\-]{20,300}$/', $nueva)) {
                    $this->ctx->redirect($this->ctx->adminUrl('ajustes'), 'La clave de IA no tiene un formato válido (revisa que no traiga espacios).', 'error');
                    return;
                }
                $ia->guardarClave($nueva, $prov);
            }
            $otro = trim((string) ($_POST['ia_modelo_otro' . $suf] ?? ''));
            if ($otro !== '') {
                $ia->guardarModelo($otro, $prov, true);
            } elseif (isset($_POST['ia_modelo' . $suf])) {
                $ia->guardarModelo((string) $_POST['ia_modelo' . $suf], $prov);
            }
        }
        $this->ctx->redirect($this->ctx->adminUrl('ajustes'), 'Ajustes guardados.');
    }

    private function notificador(): Notifier
    {
        return new Notifier($this->ctx, $this->pdo());
    }

    /** @return array<string, mixed> */
    private function datosCorreo(): array
    {
        $n = $this->notificador();
        $aj = $this->ajustes();
        try {
            $n->vaciarCola();
        } catch (\Throwable) {
        }
        [$respetar, $ini, $fin] = $n->horario();
        $token = $aj->get('global', 'portal', 'cron_token');
        if ($token === '') {
            $token = bin2hex(random_bytes(16));
            $aj->set('global', 'portal', 'cron_token', $token);
        }
        $marca = new MarcaService($this->pdo());
        $dias = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];
        $cola = [];
        foreach ($n->cola() as $f) {
            $pais = $f['cliente_id'] ? $n->paisDe((string) $f['cliente_id']) : HorarioHabil::PAIS_DEFECTO;
            $d = (new \DateTimeImmutable((string) $f['enviar_desde'], new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone(HorarioHabil::zonaDe($pais)));
            $f['sale'] = $dias[(int) $d->format('w')] . ' ' . $d->format('d/m H:i') . ' (hora de ' . HorarioHabil::nombreDe($pais) . ')';
            $cola[] = $f;
        }
        return [
            'modo' => $n->modo(), 'respetar' => $respetar, 'ini' => $ini, 'fin' => $fin,
            'tieneLogo' => $marca->rutaLogoAgencia() !== null, 'diag' => $n->diagnostico(),
            'cronUrl' => Notifier::baseUrl() . '/portal/cron/correos?k=' . $token, 'cola' => $cola,
            'metodoHtml' => $n->diagnostico()['metodos'], 'smtp' => $n->smtpVista(),
        ];
    }

    /** Logo del equipo, modo de envío y horario hábil. */
    public function correoGuardar(): void
    {
        $aj = $this->ajustes();
        $aviso = '';
        $marca = new MarcaService($this->pdo());
        if (!empty($_POST['quitar_logo_agencia'])) {
            $marca->quitarLogoAgencia();
        }
        $subidos = ArchivoService::normalizar($_FILES['logo_agencia'] ?? null);
        if ($subidos !== []) {
            $err = $marca->guardarLogoAgencia($subidos[0]);
            $aviso = $err !== null ? ' ' . $err : '';
        }
        $modo = (string) ($_POST['correo_modo'] ?? 'auto');
        $aj->set('global', 'portal', 'correo_modo', in_array($modo, ['auto', 'smtp', 'html', 'texto'], true) ? $modo : 'auto');
        if (isset($_POST['smtp_host'])) {
            $errSmtp = $this->notificador()->smtpGuardar([
                'host' => $_POST['smtp_host'] ?? '', 'puerto' => $_POST['smtp_puerto'] ?? 465, 'seg' => $_POST['smtp_seg'] ?? 'ssl',
                'usuario' => $_POST['smtp_user'] ?? '', 'desde' => $_POST['smtp_desde'] ?? '', 'clave' => $_POST['smtp_clave'] ?? '',
                'quitar_clave' => $_POST['smtp_quitar_clave'] ?? '',
            ]);
            if ($errSmtp !== null) {
                $aviso .= ' ' . $errSmtp;
            }
        }
        [$i, $f] = HorarioHabil::rango((int) ($_POST['horario_ini'] ?? HorarioHabil::INICIO), (int) ($_POST['horario_fin'] ?? HorarioHabil::FIN));
        $aj->set('global', 'portal', 'horario_ini', (string) $i);
        $aj->set('global', 'portal', 'horario_fin', (string) $f);
        $aj->set('global', 'portal', 'horario_respetar', !empty($_POST['horario_respetar']) ? '1' : '0');
        $this->ctx->redirect($this->ctx->adminUrl('ajustes') . '#correos', 'Ajustes de correo guardados.' . $aviso, $aviso === '' ? 'success' : 'error');
    }

    /** Manda al correo de avisos un ejemplo de aviso al cliente y otro para el equipo. */
    public function correoPrueba(): void
    {
        $to = trim($this->ajustes()->get('global', 'portal', 'email_avisos'));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->ctx->redirect($this->ctx->adminUrl('ajustes') . '#correos', 'Primero escribe «Tu correo para avisos» arriba y guarda.', 'error');
            return;
        }
        $n = $this->notificador();
        $st = $this->pdo()->query('SELECT id, nombre, empresa FROM portal_clientes ORDER BY created_at LIMIT 1');
        $cli = $st ? $st->fetch() : false;
        $cid = $cli !== false ? (string) $cli['id'] : null;
        $mc = new MarcaService($this->pdo());
        $contexto = $cli !== false ? ['cliente' => trim((string) ($cli['empresa'] ?: $cli['nombre'])), 'logo' => $mc->urlLogoCliente((string) $cli['id'], Notifier::baseUrl()), 'proyecto' => 'Nombre del proyecto'] : null;

        [$h1, $t1] = $n->componer('Prueba: aviso al cliente', 'Así se ve un aviso para tu cliente. Cuando algo esté listo para revisar, le llegará con este formato.', [
            'etiqueta' => 'Revisión', 'titulo' => 'Tienes contenido para revisar', 'resaltado' => 'contenido', 'boton' => 'Revisar contenido',
            'bloques' => [['tarjetas' => [['titulo' => 'Post de ejemplo', 'detalle' => 'Imagen · v1', 'chip' => 'Para revisar']]]],
        ], 'Hola,', Notifier::baseUrl() . '/portal', $cid, ['Puedes desactivar estos avisos en Ajustes dentro del portal.']);
        [$h2, $t2] = $n->componer('Prueba: aviso para ti', '', [
            'etiqueta' => 'Revisión', 'titulo' => 'Ana respondió la revisión', 'resaltado' => 'respondió',
            'bloques' => [['datos' => [['Aprobados', '3'], ['Con cambios', '1']]], ['p' => 'Pidió cambios en:'], ['lista' => ['Post 2: subir el contraste del texto']]],
            'boton' => 'Abrir la entrega',
        ], null, Notifier::baseUrl() . $this->ctx->adminUrl(''), $cid, ['Aviso interno: el cliente no ve este correo.'], $contexto);
        $a = $n->enviarCorreo($to, 'Prueba: aviso al cliente', $h1, $t1);
        $b = $n->enviarCorreo($to, 'Prueba: aviso para ti', $h2, $t2);
        $v = $n->smtpVista();
        $msg = $a && $b ? 'Envié 2 correos de prueba a ' . $to . '. Revisa cómo se ven.' : 'El servidor de correo no aceptó el envío de prueba.';
        if ($v['ultimoError'] !== '') {
            $msg .= ' El SMTP propio falló (' . $v['ultimoError'] . ') y se usó el correo del sistema en texto plano.';
        } elseif (!$v['lista'] && $n->modo() !== 'texto') {
            $msg .= ' Aún no configuras el SMTP propio: sin él, el servidor del sistema puede mandar los correos como texto plano.';
        }
        $this->ctx->redirect($this->ctx->adminUrl('ajustes') . '#correos', $msg, $a && $b && $v['ultimoError'] === '' ? 'success' : 'error');
    }

    /** Comprueba conexión y credenciales del SMTP propio (con lo ya guardado). */
    public function smtpProbar(): void
    {
        $err = $this->notificador()->smtpProbar();
        $this->ctx->redirect($this->ctx->adminUrl('ajustes') . '#correos', $err === null ? 'Conexión SMTP correcta: el servidor aceptó el usuario y la contraseña.' : 'No se pudo: ' . $err, $err === null ? 'success' : 'error');
    }

    public function colaEnviar(string $id): void
    {
        $ok = $this->notificador()->enviarAhora($id);
        $this->ctx->redirect($this->ctx->adminUrl('ajustes') . '#correos', $ok ? 'Correo enviado.' : 'No se pudo enviar ese correo.', $ok ? 'success' : 'error');
    }

    public function colaCancelar(string $id): void
    {
        $this->notificador()->cancelar($id);
        $this->ctx->redirect($this->ctx->adminUrl('ajustes') . '#correos', 'Correo cancelado.');
    }

    public function cronRegenerar(): void
    {
        $this->ajustes()->set('global', 'portal', 'cron_token', bin2hex(random_bytes(16)));
        $this->ctx->redirect($this->ctx->adminUrl('ajustes') . '#correos', 'Generé una clave nueva: actualiza la dirección en tu tarea programada.');
    }

    public function iaProbar(): void
    {
        $error = (new IaService($this->pdo()))->probar();
        $this->ctx->redirect(
            $this->ctx->adminUrl('ajustes'),
            $error === null ? 'Conexión con la IA correcta.' : $error,
            $error === null ? 'success' : 'error'
        );
    }
}

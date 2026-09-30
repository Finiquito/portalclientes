<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Invitaciones;

use TypeDock\Core\PluginContext;

/** Admin de TypeDock → Invitaciones: configuración de Mailchimp y lista de inscripciones. */
class AdminController
{
    public function __construct(private readonly PluginContext $ctx) {}

    private function pdo(): \PDO
    {
        return $this->ctx->db()->pdo();
    }

    private function ajustes(): Ajustes
    {
        return new Ajustes($this->pdo());
    }

    private function servicio(): InvitacionService
    {
        return new InvitacionService($this->pdo(), $this->ajustes());
    }

    public function index(): void
    {
        $a = $this->ajustes();
        $this->ctx->view('templates/admin/index.latte', [
            'cfg' => [
                'audiencia'     => $a->get('mc_audiencia'),
                'doble'         => $a->get('mc_doble', '1') === '1',
                'campo_nombre'  => $a->get('mc_campo_nombre', 'FNAME'),
                'campo_tamano'  => $a->get('mc_campo_tamano', 'TAMANO'),
                'campo_rubro'   => $a->get('mc_campo_rubro', ''),
                'etiquetas'     => $a->get('mc_etiquetas', 'invitacion-prisma'),
                'clave'         => $a->claveVisible(),
                'origen'        => $a->origenClave(),
                'region'        => Mailchimp::region($a->clave()) ?? '',
            ],
            'prueba'        => json_decode($a->get('mc_ultima_prueba', '[]'), true) ?: null,
            'lista'         => $this->servicio()->lista(),
            'resumen'       => $this->servicio()->resumen(),
            'tamanos'       => InvitacionService::TAMANOS,
            'flash_success' => $this->ctx->getFlash('success'),
            'flash_error'   => $this->ctx->getFlash('error'),
        ]);
    }

    public function guardar(): void
    {
        $a = $this->ajustes();
        $clave = trim((string) ($_POST['mc_clave'] ?? ''));
        if (!empty($_POST['quitar_clave'])) {
            $a->guardarClave('');
        } elseif ($clave !== '') {
            if (!Mailchimp::claveValida($clave)) {
                $this->ctx->redirect($this->ctx->adminUrl(''), 'La clave no tiene el formato de Mailchimp: 32 caracteres, un guion y la región (por ejemplo …-us21). Cópiala completa desde Mailchimp.', 'error');
                return;
            }
            $a->guardarClave($clave);
        }
        $aud = trim((string) ($_POST['mc_audiencia'] ?? ''));
        if ($aud !== '' && preg_match('/^[0-9a-z]{6,20}$/i', $aud) !== 1) {
            $this->ctx->redirect($this->ctx->adminUrl(''), 'El ID de la audiencia no parece válido (son letras y números, como fc1f3c0eb6).', 'error');
            return;
        }
        $tag = static fn(string $k, string $def): string => strtoupper(preg_replace('/[^A-Za-z0-9_]/', '', (string) ($_POST[$k] ?? $def)) ?? '');
        $a->set('mc_audiencia', $aud);
        $a->set('mc_doble', !empty($_POST['mc_doble']) ? '1' : '0');
        $a->set('mc_campo_nombre', $tag('mc_campo_nombre', 'FNAME'));
        $a->set('mc_campo_tamano', $tag('mc_campo_tamano', 'TAMANO'));
        $a->set('mc_campo_rubro', $tag('mc_campo_rubro', ''));
        $a->set('mc_etiquetas', mb_substr(trim((string) ($_POST['mc_etiquetas'] ?? '')), 0, 200));
        $this->ctx->redirect($this->ctx->adminUrl(''), 'Ajustes guardados.');
    }

    public function probar(): void
    {
        $a = $this->ajustes();
        if (!Mailchimp::claveValida($a->clave()) || $a->get('mc_audiencia') === '') {
            $this->ctx->redirect($this->ctx->adminUrl(''), 'Primero guarda la clave completa y el ID de la audiencia.', 'error');
            return;
        }
        $r = (new Mailchimp($a->clave(), $a->get('mc_audiencia')))->probar();
        $a->set('mc_ultima_prueba', json_encode($r + ['fecha' => date('d/m H:i')], JSON_UNESCAPED_UNICODE) ?: '[]');
        $r['ok']
            ? $this->ctx->redirect($this->ctx->adminUrl(''), 'Conexión correcta con la audiencia «' . ($r['audiencia'] ?? '') . '». Revisa abajo los campos que tiene.')
            : $this->ctx->redirect($this->ctx->adminUrl(''), (string) ($r['error'] ?? 'No se pudo conectar.'), 'error');
    }

    public function reintentar(): void
    {
        [$ok, $mal] = $this->servicio()->reintentar();
        $this->ctx->redirect($this->ctx->adminUrl(''), "Reintento: {$ok} enviadas a Mailchimp" . ($mal ? ", {$mal} siguen con error." : '.'), $mal ? 'error' : 'success');
    }

    public function borrar(string $id): void
    {
        $this->servicio()->borrar($id);
        $this->ctx->redirect($this->ctx->adminUrl(''), 'Inscripción eliminada (solo de la copia local; en Mailchimp sigue).');
    }

    /** Descarga de la copia local en CSV (Excel: UTF-8 con BOM, separado por punto y coma). */
    public function csv(): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="invitaciones-' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['correo', 'nombre', 'tamaño', 'rubro', 'origen', 'mailchimp', 'fecha'], ';');
        foreach ($this->servicio()->lista(100000) as $f) {
            fputcsv($out, [$f['email'], $f['nombre'], InvitacionService::TAMANOS[$f['tamano']] ?? '', $f['rubro'], $f['origen'], $f['mc_estado'], $f['created_at']], ';');
        }
        fclose($out);
        exit;
    }
}

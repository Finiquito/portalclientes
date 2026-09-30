<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Invitaciones;

use TypeDock\Contract\PluginInterface;
use TypeDock\Core\PluginContext;

/**
 * Lista de espera del landing (prismahub.com): POST /invitacion guarda la inscripción
 * y la sincroniza con una audiencia de Mailchimp. Se configura en el admin → Invitaciones.
 */
class InvitacionesPlugin implements PluginInterface
{
    public function register(PluginContext $ctx): void
    {
        $ctx->migrate(__DIR__ . '/../migrations');

        $pub = new PublicoController($ctx);
        \Flight::route('POST /invitacion', [$pub, 'inscribir']);

        $adm = new AdminController($ctx);
        $ctx->registerAdminRoute('GET',  '',              [$adm, 'index']);
        $ctx->registerAdminRoute('POST', 'ajustes',       [$adm, 'guardar']);
        $ctx->registerAdminRoute('POST', 'probar',        [$adm, 'probar']);
        $ctx->registerAdminRoute('POST', 'reintentar',    [$adm, 'reintentar']);
        $ctx->registerAdminRoute('GET',  'csv',           [$adm, 'csv']);
        $ctx->registerAdminRoute('POST', '@id/borrar',    fn(string $id) => $adm->borrar($id));

        $ctx->addAdminMenuItem('Invitaciones', '');
    }

    public function getName(): string
    {
        return 'Invitaciones (lista de espera)';
    }

    public function getVersion(): string
    {
        return '0.1.0';
    }

    public function provides(): array
    {
        return [];
    }
}

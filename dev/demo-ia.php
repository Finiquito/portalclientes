<?php
/**
 * Sólo desarrollo (PORTAL_IA_DEMO=1): una IA de mentira para grabar videos sin clave ni costo.
 * Lee cada pieza de la grilla con el lector sin IA y tarda un poco, como la de verdad.
 * Se registra antes que el plugin, así estas rutas ganan a las reales.
 */
declare(strict_types=1);

use TypeDock\Plugin\Portal as P;

final class DemoIa extends P\IaService
{
    public function activa(): bool { return true; }

    public function analizarGrilla(array $bloques, string $contexto = ''): array
    {
        usleep(3500000);
        $out = [];
        foreach ($bloques as $n => $b) {
            $p = P\GrillaImport::leer($b, (int) $n);
            $p['n'] = (int) $n;
            $pref = ['reel' => 'Reel', 'story' => 'Story'][$p['tipo']] ?? 'Post';
            $tema = preg_match('/^tema\s*:\s*(.+)$/miu', $b, $m) === 1 ? trim($m[1]) : '';
            $p['titulo'] = $pref . ' ' . preg_replace('/^\D*(\d+).*$/su', '$1', $p['titulo']) . ($tema !== '' ? ' · ' . $tema : '');
            $out[(int) $n] = $p;
        }
        return $out;
    }
}

final class DemoGrilla extends P\GrillaAdminController
{
    protected function ia(): P\IaService { return new DemoIa($this->pdo()); }
}

function portal_dev_demo_ia(TypeDock\Core\PluginContext $ctx): void
{
    $eq = fn() => new P\EquipoGestion($ctx);
    Flight::route('POST /equipo/entregas/@id/grilla', fn(string $id) => $eq()->gestionar(DemoGrilla::class, 'subir', [$id], 'entrega'));
    Flight::route('GET /equipo/entregas/@id/grilla/@t', fn(string $id, string $t) => $eq()->gestionar(DemoGrilla::class, 'ver', [$id, $t], 'entrega'));
    Flight::route('POST /equipo/entregas/@id/grilla/@t/tanda/@n', fn(string $id, string $t, string $n) => $eq()->gestionar(DemoGrilla::class, 'tanda', [$id, $t, $n], 'entrega'));
}

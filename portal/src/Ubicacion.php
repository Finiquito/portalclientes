<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * Dónde apunta un comentario sobre un contenido (columna portal_comentarios.ubicacion):
 *
 *   p3                 página 3 de un PDF
 *   p3@41.5,62         un punto (pin) en la página 3: x e y en % del ancho y alto de la página
 *   i<archivoId>@41.5,62   un punto en una imagen de la versión
 *
 * Los porcentajes son sobre la imagen o la página completas (no sobre el recorte que se vea
 * en pantalla), así el pin cae en el mismo lugar en el carrusel, en el zoom y en el admin.
 */
final class Ubicacion
{
    private const RE = '/^(?:p(\d{1,4})|i([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}))(?:@(\d{1,3}(?:\.\d{1,2})?),(\d{1,3}(?:\.\d{1,2})?))?$/';

    /**
     * Valida lo que manda el formulario. Una imagen solo vale si es de la versión que se comenta.
     * @param array<int, string> $archivosVersion ids de las imágenes de la versión vigente
     */
    public static function validar(string $ub, array $archivosVersion): ?string
    {
        $u = self::leer($ub);
        if ($u === null) {
            return null;
        }
        if ($u['tipo'] === 'imagen' && ($u['x'] === null || !in_array($u['archivo'], $archivosVersion, true))) {
            return null;
        }
        return trim($ub);
    }

    /**
     * @return array{tipo: string, pagina: int, archivo: string, x: ?float, y: ?float}|null
     */
    public static function leer(?string $ub): ?array
    {
        if ($ub === null || preg_match(self::RE, trim($ub), $m) !== 1) {
            return null;
        }
        $x = isset($m[3]) && $m[3] !== '' ? (float) $m[3] : null;
        $y = isset($m[4]) && $m[4] !== '' ? (float) $m[4] : null;
        if (($x !== null && $x > 100) || ($y !== null && $y > 100) || ($m[1] !== '' && (int) $m[1] < 1)) {
            return null;
        }
        return [
            'tipo'    => $m[1] !== '' ? 'pagina' : 'imagen',
            'pagina'  => $m[1] !== '' ? (int) $m[1] : 0,
            'archivo' => $m[2] ?? '',
            'x'       => $x,
            'y'       => $y,
        ];
    }

    /**
     * Numera los pines (comentarios con punto) de una versión, en orden de llegada.
     * @param array<int, array<string, mixed>> $comentarios
     * @param array<string, int> $posImagen archivoId => número de imagen (1, 2…)
     * @return array<string, array{n: int, tipo: string, pagina: int, archivo: string, x: float, y: float, imagen: int}> comentarioId => pin
     */
    public static function pines(array $comentarios, ?string $versionId, array $posImagen = []): array
    {
        $out = [];
        $n = 0;
        foreach ($comentarios as $c) {
            $u = self::leer((string) ($c['ubicacion'] ?? ''));
            if ($u === null || $u['x'] === null || ($versionId !== null && ($c['version_id'] ?? null) !== $versionId)) {
                continue;
            }
            $out[(string) $c['id']] = ['n' => ++$n, 'imagen' => $posImagen[$u['archivo']] ?? 0] + $u;
        }
        return $out;
    }

    /** «Página 3», «Página 3 · punto 2» o «Imagen 2 · punto 1». */
    public static function etiqueta(?string $ub, ?int $nPin = null, int $nImagen = 0): string
    {
        $u = self::leer($ub);
        if ($u === null) {
            return '';
        }
        $base = $u['tipo'] === 'pagina' ? 'Página ' . $u['pagina'] : ($nImagen > 0 ? 'Imagen ' . $nImagen : 'Imagen');
        return $base . ($u['x'] !== null ? ' · punto' . ($nPin !== null ? ' ' . $nPin : '') : '');
    }
}

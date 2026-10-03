<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * Registro de tipos de contenido. Cada tipo dice cómo se ve (visor) y qué se
 * le pide al admin. Agregar un tipo nuevo = una fila acá + (si hace falta) un
 * bloque de visor en templates/public/contenido.latte.
 *
 * Visores: ig, reel, story, imagen, identidad, galeria, documento, sitio, generico.
 */
final class TiposContenido
{
    /** tipo => [nombre, visor, ayuda para el admin, icono] */
    public const TIPOS = [
        'post'         => ['Post / carrusel', 'ig',        'Una o varias imágenes (carrusel). El copy va abajo, como en Instagram.', 'i-image'],
        'reel'         => ['Reel / video',    'reel',      'Pega un link (YouTube, Vimeo, Drive) o sube un MP4 liviano.', 'i-eye'],
        'story'        => ['Story',           'story',     'Imagen o video vertical 9:16.', 'i-sparkles'],
        'grafica'      => ['Pieza gráfica',   'imagen',    'Afiche, flyer, banner… Una imagen con zoom.', 'i-image'],
        'logo'         => ['Logo / identidad', 'identidad', 'Variantes del logo: se muestran sobre fondo claro, oscuro y de color.', 'i-sparkles'],
        'mockup'       => ['Mockup',          'galeria',   'Una o varias imágenes en galería.', 'i-image'],
        'brandbook'    => ['Documento PDF',   'documento', 'Sube el PDF (brandbook, informe, manual…). Se ve por páginas y se puede descargar.', 'i-file'],
        'presentacion' => ['Presentación / propuesta', 'documento', 'Sube el PDF o pega un link.', 'i-file'],
        'web'          => ['Sitio web / landing', 'sitio', 'Link al sitio (o a un prototipo) y, si quieres, capturas.', 'i-eye'],
        'otro'         => ['Otro',            'generico',  'Archivos y/o un link.', 'i-file'],
    ];

    /**
     * Lo que ve el cliente como «tipo» (nunca el nombre interno, que puede no calzar:
     * un PDF no siempre es un brandbook). El post se calcula: «Post» o «Carrusel · N láminas».
     * Si el contenido tiene una etiqueta propia, manda esa.
     */
    public const PARA_CLIENTE = [
        'reel' => 'Reel', 'story' => 'Story', 'grafica' => 'Pieza gráfica', 'logo' => 'Logo',
        'mockup' => 'Mockup', 'brandbook' => 'Documento', 'presentacion' => 'Presentación',
        'web' => 'Sitio web', 'otro' => '',
    ];

    public const REACCIONES = [
        'me_encanta' => ['😍', 'Me encanta'],
        'bien'       => ['👍', 'Está bien'],
        'no_convence' => ['😐', 'No me convence'],
    ];

    public const ESTADOS_CONTENIDO = [
        'pendiente' => ['Por revisar', 'muted'],
        'aprobado'  => ['Aprobado', 'ok'],
        'cambios'   => ['Con cambios', 'danger'],
    ];

    public const ESTADOS_ENTREGA = [
        'borrador'   => ['Borrador', 'muted'],
        'publicada'  => ['En revisión', 'info'],
        'respondida' => ['Respondida', 'warn'],
        'aprobada'   => ['Aprobada', 'ok'],
    ];

    public static function valido(string $tipo): bool
    {
        return isset(self::TIPOS[$tipo]);
    }

    public static function nombre(string $tipo): string
    {
        return self::TIPOS[$tipo][0] ?? 'Contenido';
    }

    /**
     * Etiqueta que ve el cliente.
     * @param array<string, mixed> $c contenido (tipo, etiqueta, laminas)
     * @param int $nImagenes imágenes de la versión que se muestra (0 si aún no hay)
     */
    public static function etiqueta(array $c, int $nImagenes = 0): string
    {
        $propia = trim((string) ($c['etiqueta'] ?? ''));
        if ($propia !== '') {
            return $propia;
        }
        $tipo = (string) ($c['tipo'] ?? '');
        if ($tipo === 'post') {
            $n = $nImagenes > 0 ? $nImagenes : count(self::laminas($c['laminas'] ?? null));
            return $n > 1 ? 'Carrusel · ' . $n . ' láminas' : 'Post';
        }
        return self::PARA_CLIENTE[$tipo] ?? '';
    }

    /**
     * Láminas del brief (idea de cada lámina y el texto que va en la imagen).
     * Se guardan como JSON; acepta también el texto del formulario (ver laminasDesdeTexto).
     * @return array<int, array{idea: string, texto: string}>
     */
    public static function laminas(mixed $valor): array
    {
        if (is_string($valor)) {
            $valor = trim($valor);
            if ($valor === '') {
                return [];
            }
            $json = json_decode($valor, true);
            $valor = is_array($json) ? $json : self::laminasDesdeTexto($valor);
        }
        if (!is_array($valor)) {
            return [];
        }
        $out = [];
        foreach ($valor as $l) {
            if (is_string($l)) {
                $l = ['idea' => $l, 'texto' => ''];
            }
            if (!is_array($l)) {
                continue;
            }
            $idea  = mb_substr(trim((string) ($l['idea'] ?? '')), 0, 1000);
            $texto = mb_substr(trim((string) ($l['texto'] ?? '')), 0, 1000);
            if ($idea !== '' || $texto !== '') {
                $out[] = ['idea' => $idea, 'texto' => $texto];
            }
            if (count($out) >= 20) {
                break;
            }
        }
        return $out;
    }

    /**
     * Texto del formulario → láminas. Una lámina por línea; el texto en imagen va
     * después de « | » o de «Texto:». Se toleran «Lámina 2:», «Slide 2:» o «2.» al inicio.
     * @return array<int, array{idea: string, texto: string}>
     */
    public static function laminasDesdeTexto(string $txt): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $txt) ?: [] as $linea) {
            $linea = trim($linea);
            if ($linea === '') {
                continue;
            }
            $linea = preg_replace('/^(?:l[aá]mina|slide|plano)\s*\d+\s*(?:\([^)]*\))?\s*[:.\-–]\s*|^\d+\s*[.)\-–]\s+/iu', '', $linea) ?? $linea;
            $partes = preg_split('/\s+\|\s+|\s*\btexto\s*:\s*/iu', $linea, 2) ?: [$linea];
            $texto = preg_replace('/^[\s«"“]+|[\s»"”]+$/u', '', $partes[1] ?? '') ?? '';
            $out[] = ['idea' => rtrim(trim($partes[0]), '.'), 'texto' => $texto];
        }
        return self::laminas($out);
    }

    /** Láminas → texto editable del formulario (inverso de laminasDesdeTexto). */
    public static function laminasATexto(mixed $valor): string
    {
        return implode("\n", array_map(
            fn($l) => $l['idea'] . ($l['texto'] !== '' ? ' | ' . $l['texto'] : ''),
            self::laminas($valor)
        ));
    }

    public static function visor(string $tipo): string
    {
        return self::TIPOS[$tipo][1] ?? 'generico';
    }

    /** Sólo http(s); cualquier otra cosa (javascript:, data:) se descarta. */
    public static function enlaceSeguro(string $url): string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 500) {
            return '';
        }
        $p = parse_url($url);
        if ($p === false || !in_array(strtolower((string) ($p['scheme'] ?? '')), ['http', 'https'], true) || empty($p['host'])) {
            return '';
        }
        return $url;
    }

    /**
     * Si el link es de un servicio que se puede incrustar, devuelve
     * ['tipo' => 'youtube'|'vimeo'|'drive', 'src' => url del iframe].
     * @return array{tipo: string, src: string}|null
     */
    public static function embed(string $url): ?array
    {
        $url = self::enlaceSeguro($url);
        if ($url === '') {
            return null;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^(www|m)\./', '', $host) ?? $host;
        $path = (string) parse_url($url, PHP_URL_PATH);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        if ($host === 'youtu.be' && preg_match('#^/([\w-]{11})#', $path, $m)) {
            return ['tipo' => 'youtube', 'src' => 'https://www.youtube-nocookie.com/embed/' . $m[1] . '?rel=0&modestbranding=1&playsinline=1'];
        }
        if (in_array($host, ['youtube.com', 'youtube-nocookie.com'], true)) {
            if (!empty($q['v']) && preg_match('/^[\w-]{11}$/', (string) $q['v'])) {
                return ['tipo' => 'youtube', 'src' => 'https://www.youtube-nocookie.com/embed/' . $q['v'] . '?rel=0&modestbranding=1&playsinline=1'];
            }
            if (preg_match('#^/(?:shorts|embed|live)/([\w-]{11})#', $path, $m)) {
                return ['tipo' => 'youtube', 'src' => 'https://www.youtube-nocookie.com/embed/' . $m[1] . '?rel=0&modestbranding=1&playsinline=1'];
            }
        }
        if (in_array($host, ['vimeo.com', 'player.vimeo.com'], true) && preg_match('#/(\d{5,12})(?:/([0-9a-f]{8,}))?#', $path, $m)) {
            return ['tipo' => 'vimeo', 'src' => 'https://player.vimeo.com/video/' . $m[1] . '?title=0&byline=0&portrait=0' . (isset($m[2]) ? '&h=' . $m[2] : '')];
        }
        if ($host === 'drive.google.com' && preg_match('#/file/d/([\w-]+)#', $path, $m)) {
            return ['tipo' => 'drive', 'src' => 'https://drive.google.com/file/d/' . $m[1] . '/preview'];
        }
        if ($host === 'docs.google.com') {
            if (preg_match('#^/presentation/d/(e/)?([\w-]+)#', $path, $m)) {
                return ['tipo' => 'slides', 'src' => 'https://docs.google.com/presentation/d/' . $m[1] . $m[2] . '/embed?start=false&loop=false&delayms=3000'];
            }
            if (preg_match('#^/document/d/([\w-]+)#', $path, $m)) {
                return ['tipo' => 'documento', 'src' => 'https://docs.google.com/document/d/' . $m[1] . '/preview'];
            }
        }
        if ($host === 'canva.com' && preg_match('#^/design/([\w-]+)/([\w-]+)/(?:view|watch)#', $path, $m)) {
            return ['tipo' => 'canva', 'src' => 'https://www.canva.com/design/' . $m[1] . '/' . $m[2] . '/view?embed'];
        }
        if ($host === 'figma.com' && preg_match('#^/(?:file|design|proto|board|slides)/#', $path)) {
            return ['tipo' => 'figma', 'src' => 'https://www.figma.com/embed?embed_host=portal&url=' . rawurlencode($url)];
        }
        if ($host === 'loom.com' && preg_match('#^/(?:share|embed)/(\w+)#', $path, $m)) {
            return ['tipo' => 'loom', 'src' => 'https://www.loom.com/embed/' . $m[1]];
        }
        return null;
    }

    public static function esVideo(?string $mime): bool
    {
        return in_array((string) $mime, ['video/mp4', 'video/quicktime'], true);
    }
}

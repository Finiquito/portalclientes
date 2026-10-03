<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * Importar una grilla de contenidos (Word o texto pegado) a una entrega.
 *
 * 1. dividir(): corta el texto en una pieza por encabezado («POST 3», «Reel 2»…), sin IA.
 *    Lo que va antes del primer encabezado son las indicaciones generales.
 * 2. Cada pieza se ordena con IA (IaService::analizarGrilla, en tandas chicas para no
 *    pasar el tiempo máximo del hosting) o, si no hay IA, con leer(), que entiende las
 *    etiquetas habituales (Tipo:, Objetivo:, Copy:, Lámina 2:, Slide 1:, Hashtags:, Nota:…).
 * 3. normalizar() valida lo que llega y marca si el copy NO aparece tal cual en el
 *    documento (la IA no debe reescribirlo): el equipo lo revisa en la vista previa.
 *
 * El trabajo en curso se guarda como JSON fuera del web-root (portal_uploads/.grillas).
 */
final class GrillaImport
{
    public const MAX_TEXTO   = 150000;
    public const MAX_PIEZAS  = 60;
    public const TIPOS       = ['post', 'reel', 'story', 'grafica', 'otro'];

    private const ENCABEZADO = '/^\s*(?:post|publicaci[oó]n|pieza|reel|story|historia|carrusel|video)\s*(?:n[°º.]?\s*)?\d+\b.*$/imu';

    // ---- 1. Dividir ---------------------------------------------------------

    /** @return array{general: string, bloques: array<int, string>} */
    public static function dividir(string $texto): array
    {
        $texto = trim(str_replace(["\r\n", "\r"], "\n", mb_substr($texto, 0, self::MAX_TEXTO)));
        if (preg_match_all(self::ENCABEZADO, $texto, $m, PREG_OFFSET_CAPTURE) === 0) {
            return ['general' => '', 'bloques' => $texto !== '' ? [$texto] : []];
        }
        $cortes = array_map(fn($x) => (int) $x[1], $m[0]);
        $general = trim(substr($texto, 0, $cortes[0]));
        $bloques = [];
        foreach ($cortes as $i => $ini) {
            $fin = $cortes[$i + 1] ?? strlen($texto);
            $b = trim(substr($texto, $ini, $fin - $ini));
            // El título de la sección siguiente («PILAR 2 — …») queda al final del bloque anterior: fuera.
            $b = trim(preg_replace('/(?:\n\s*pilar\s+\d+\b[^\n]*)+\s*$/iu', '', $b) ?? $b);
            if ($b !== '') {
                $bloques[] = $b;
            }
        }
        return ['general' => $general, 'bloques' => array_slice($bloques, 0, self::MAX_PIEZAS)];
    }

    /**
     * Agrupa los bloques en tandas que la IA responde rápido (pocas piezas, poco texto).
     * @param array<int, string> $bloques
     * @return array<int, array<int, int>> índices de bloque por tanda
     */
    public static function tandas(array $bloques, int $maxPiezas = 4, int $maxChars = 8000): array
    {
        $tandas = [];
        $actual = [];
        $largo = 0;
        foreach ($bloques as $i => $b) {
            $l = mb_strlen($b);
            if ($actual !== [] && (count($actual) >= $maxPiezas || $largo + $l > $maxChars)) {
                $tandas[] = $actual;
                $actual = [];
                $largo = 0;
            }
            $actual[] = $i;
            $largo += $l;
        }
        if ($actual !== []) {
            $tandas[] = $actual;
        }
        return $tandas;
    }

    // ---- 2b. Leer sin IA ----------------------------------------------------

    /** Etiqueta de línea => sección. */
    private const ETIQUETAS = [
        'tipo' => 'tipo', 'formato' => 'tipo',
        'pilar' => 'pilar',
        'objetivo' => 'objetivo', 'proposito' => 'objetivo',
        'idea en simple' => 'laminas', 'idea' => 'laminas', 'laminas' => 'laminas', 'instrucciones de imagen' => 'laminas',
        'instrucciones de imagen / carrusel' => 'laminas', 'instrucciones' => 'laminas', 'guion de video' => 'laminas', 'guion' => 'laminas',
        'texto sugerido en la imagen' => 'textos', 'texto en la imagen' => 'textos', 'texto en imagen' => 'textos',
        'copy' => 'copy', 'copy sugerido' => 'copy', 'caption' => 'copy', 'texto del post' => 'copy',
        'hashtags' => 'hashtags',
        'nota' => 'notas', 'notas' => 'notas', 'comentarios' => 'notas', 'comentario' => 'notas', 'observaciones' => 'notas', 'audio' => 'notas_linea',
    ];

    /**
     * Lee una pieza con las etiquetas habituales de una grilla. Sirve cuando no hay IA y
     * como respaldo si la IA falla en una tanda.
     * @return array<string, mixed>
     */
    public static function leer(string $bloque, int $n = 1): array
    {
        $lineas = explode("\n", str_replace("\r\n", "\n", $bloque));
        $encabezado = trim((string) array_shift($lineas));
        $sec = ['tipo' => [], 'pilar' => [], 'objetivo' => [], 'laminas' => [], 'textos' => [], 'copy' => [], 'hashtags' => [], 'notas' => [], 'sobra' => []];
        $actual = null;
        foreach ($lineas as $linea) {
            $limpia = trim(preg_replace('/^[•·\-–*]\s+/u', '', trim($linea)) ?? $linea);
            if (preg_match('/^([\p{L} \/]{3,40}?)\s*:\s*(.*)$/u', $limpia, $m) === 1) {
                $clave = self::sinTildes(mb_strtolower(trim($m[1])));
                if (isset(self::ETIQUETAS[$clave])) {
                    $actual = self::ETIQUETAS[$clave];
                    if ($actual === 'notas_linea') {
                        $sec['notas'][] = $limpia;
                        $actual = 'notas';
                        continue;
                    }
                    if (trim($m[2]) !== '') {
                        // «Nota: …» pierde la etiqueta (la caja ya se llama «Notas del equipo»); «Comentarios: …» y otras la conservan.
                        $sec[$actual][] = $actual === 'notas' && $clave !== 'notas' && $clave !== 'nota' ? $limpia : self::mayuscula(trim($m[2]));
                    }
                    continue;
                }
            }
            if ($actual === 'notas' && ($limpia === '' || self::pareceTitulo($linea))) {
                $actual = 'sobra';
            }
            if ($actual === null) {
                continue;
            }
            // El copy conserva sus líneas tal cual (incluidas las vacías); el resto, sin viñetas.
            $sec[$actual][] = $actual === 'copy' ? rtrim($linea) : $limpia;
        }

        $tipoTxt = implode(' ', $sec['tipo']) . ' ' . $encabezado;
        $laminas = TiposContenido::laminasDesdeTexto(implode("\n", array_filter($sec['laminas'], fn($l) => trim($l) !== '')));
        // Post único: todas las viñetas describen una sola imagen.
        if (preg_match('/\b(?:[uú]nic[oa]|single)\b/iu', $tipoTxt) === 1 && count($laminas) > 1) {
            $ideas = [];
            $textos = [];
            foreach ($sec['laminas'] as $l) {
                if (preg_match('/^texto[^:]{0,30}:\s*(.+)$/iu', trim($l), $mt) === 1) {
                    $textos[] = trim(preg_replace('/^[\s«"“]+|[\s»"”]+$/u', '', $mt[1]) ?? $mt[1]);
                } elseif (trim($l) !== '') {
                    $ideas[] = rtrim(trim($l), '.');
                }
            }
            $laminas = TiposContenido::laminas([['idea' => implode('. ', $ideas), 'texto' => implode(' / ', $textos)]]);
        }
        foreach (ImportadorContenidos::textosPorLamina(implode("\n", $sec['textos'])) as $i => $txt) {
            while (count($laminas) <= $i) {
                $laminas[] = ['idea' => '', 'texto' => ''];
            }
            $laminas[$i]['texto'] = $txt;
        }
        $copy = trim(implode("\n", $sec['copy']));
        $hashtags = trim(implode(' ', $sec['hashtags']));
        if ($hashtags !== '' && !str_contains($copy, $hashtags)) {
            $copy = trim($copy . "\n\n" . $hashtags);
        }
        $num = preg_match('/\d+/', $encabezado, $mn) === 1 ? (int) $mn[0] : $n;
        $primerTexto = '';
        foreach ($laminas as $l) {
            if ($l['texto'] !== '' && !str_starts_with($l['texto'], '[')) {
                $primerTexto = $l['texto'];
                break;
            }
        }
        if ($primerTexto === '' && $sec['objetivo'] !== []) {
            $primerTexto = (string) preg_replace('/[.:].*$/su', '', trim($sec['objetivo'][0]));
        }
        return [
            'n'        => $num,
            'incluir'  => true,
            'tipo'     => self::tipoDesde($tipoTxt),
            'titulo'   => 'Post ' . $num . ($primerTexto !== '' ? ' · ' . mb_strimwidth($primerTexto, 0, 60, '…') : ''),
            'pilar'    => trim(implode(' ', $sec['pilar'])),
            'objetivo' => trim(implode("\n", $sec['objetivo'])),
            'laminas'  => $laminas,
            'copy'     => $copy,
            'notas'    => trim(implode("\n", array_filter($sec['notas'], fn($l) => trim($l) !== ''))),
            'fecha'    => '',
            'anexo'    => trim(implode("\n", $sec['sobra'])),
        ];
    }

    private static function mayuscula(string $s): string
    {
        return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
    }

    /** Línea que parece el título de otra sección: corta, sin punto final ni dos puntos, sin viñeta. */
    private static function pareceTitulo(string $linea): bool
    {
        $l = trim($linea);
        return $l !== '' && mb_strlen($l) <= 80 && preg_match('/^[•·\-–*]|[.:;,!?»”"]$/u', $l) !== 1
            && preg_match('/^\p{Lu}/u', $l) === 1;
    }

    public static function tipoDesde(string $txt): string
    {
        $t = self::sinTildes(mb_strtolower($txt));
        return match (true) {
            str_contains($t, 'reel') || str_contains($t, 'video') => 'reel',
            preg_match('/\b(?:story|stories|historias?)\b/u', $t) === 1 => 'story',
            str_contains($t, 'grafica') || str_contains($t, 'afiche') || str_contains($t, 'flyer') => 'grafica',
            default => 'post',
        };
    }

    // ---- 3. Validar ---------------------------------------------------------

    /**
     * Deja una pieza (de la IA o de leer()) en la forma que espera la vista previa.
     * @param array<string, mixed> $p
     * @return array<string, mixed>
     */
    public static function normalizar(array $p, string $fuente, int $n): array
    {
        $txt = fn(string $k, int $max) => mb_substr(trim(str_replace("\r\n", "\n", is_scalar($p[$k] ?? null) ? (string) $p[$k] : '')), 0, $max);
        $tipo = (string) ($p['tipo'] ?? 'post');
        $tipo = in_array($tipo, self::TIPOS, true) ? $tipo : self::tipoDesde($tipo);
        $fecha = $txt('fecha', 10);
        $copy = $txt('copy', 4000);
        return [
            'n'        => $n,
            'incluir'  => ($p['incluir'] ?? true) !== false,
            'tipo'     => $tipo,
            'titulo'   => $txt('titulo', 120) ?: 'Post ' . $n,
            'pilar'    => $txt('pilar', 120),
            'objetivo' => $txt('objetivo', 2000),
            'laminas'  => TiposContenido::laminas($p['laminas'] ?? []),
            'copy'     => $copy,
            'notas'    => $txt('notas', 4000),
            'fecha'    => preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) === 1 ? $fecha : '',
            'anexo'    => $txt('anexo', 8000),
            'literal'  => self::esLiteral($copy, $fuente),
            'fuente'   => mb_substr($fuente, 0, 12000),
        ];
    }

    /**
     * ¿Cada párrafo del copy aparece tal cual en el documento? Se ignoran mayúsculas,
     * espacios, comillas tipográficas y las líneas de hashtags (pueden venir en otra etiqueta).
     */
    public static function esLiteral(string $copy, string $fuente): bool
    {
        if (trim($copy) === '') {
            return true;
        }
        $norm = function (string $s): string {
            $s = mb_strtolower(strtr($s, ['“' => '"', '”' => '"', '«' => '"', '»' => '"', '‘' => "'", '’' => "'", '…' => '...', "\u{00A0}" => ' ']));
            return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
        };
        $f = $norm($fuente);
        foreach (preg_split('/\n\s*\n/u', $copy) ?: [] as $parrafo) {
            $parrafo = trim($parrafo);
            if ($parrafo === '' || preg_match('/^(hashtags:\s*)?(#[\p{L}\p{N}_]+\s*)+$/iu', $parrafo) === 1) {
                continue;
            }
            foreach (explode("\n", $parrafo) as $linea) {
                $l = $norm($linea);
                if ($l !== '' && !str_contains($f, $l)) {
                    return false;
                }
            }
        }
        return true;
    }

    private static function sinTildes(string $s): string
    {
        return strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    }

    // ---- Trabajo en curso ---------------------------------------------------

    public function __construct(private readonly string $dir) {}

    public static function tokenValido(string $t): bool
    {
        return preg_match('/^[a-f0-9]{32}$/', $t) === 1;
    }

    /** @param array<string, mixed> $datos */
    public function guardar(string $token, array $datos): void
    {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0700, true);
            @file_put_contents($this->dir . '/.htaccess', "Require all denied\nDeny from all\n");
        }
        file_put_contents($this->dir . '/' . $token . '.json', json_encode($datos, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    /** @return array<string, mixed>|null */
    public function leerTrabajo(string $token): ?array
    {
        if (!self::tokenValido($token) || !is_file($this->dir . '/' . $token . '.json')) {
            return null;
        }
        $d = json_decode((string) file_get_contents($this->dir . '/' . $token . '.json'), true);
        return is_array($d) ? $d : null;
    }

    public function borrar(string $token): void
    {
        if (self::tokenValido($token)) {
            @unlink($this->dir . '/' . $token . '.json');
        }
    }

    /** Borra trabajos de más de dos días (importaciones que nadie terminó). */
    public function limpiar(): void
    {
        foreach (glob($this->dir . '/*.json') ?: [] as $f) {
            if (filemtime($f) < time() - 172800) {
                @unlink($f);
            }
        }
    }
}

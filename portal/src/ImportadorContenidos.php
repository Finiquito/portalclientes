<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * Lee una planilla CSV (Excel → "CSV UTF-8") y la convierte en filas de contenidos.
 *
 * Columnas reconocidas (el orden da lo mismo, mayúsculas y tildes también):
 *   tipo, titulo, cuenta, fecha_publicacion, copy, enlace
 * Brief de la pieza (lo ve el cliente junto al contenido):
 *   pilar, objetivo, instrucciones_imagen + texto_en_imagen (→ láminas), notas
 *
 * El separador (; , o tab), la codificación (UTF-8 o Windows-1252) y las comillas
 * con saltos de línea adentro se detectan solos.
 */
final class ImportadorContenidos
{
    public const MAX_FILAS = 200;
    public const MAX_BYTES = 1048576;

    public const COLUMNAS = ['tipo', 'titulo', 'cuenta', 'fecha_publicacion', 'copy', 'enlace', 'pilar', 'objetivo', 'instrucciones_imagen', 'texto_en_imagen', 'notas'];

    private const ALIAS = [
        'titulo' => ['titulo', 'nombre', 'post', 'pieza'],
        'tipo' => ['tipo', 'formato'],
        'cuenta' => ['cuenta', 'red', 'perfil', 'usuario'],
        'fecha_publicacion' => ['fecha_publicacion', 'fecha', 'publicacion', 'fecha_de_publicacion'],
        'copy' => ['copy', 'texto', 'caption', 'descripcion', 'copy_sugerido'],
        'enlace' => ['enlace', 'link', 'url', 'video'],
        'pilar' => ['pilar'],
        'objetivo' => ['objetivo', 'proposito'],
        'instrucciones_imagen' => ['instrucciones_imagen', 'instrucciones', 'instrucciones_de_imagen', 'instrucciones_de_imagen_carrusel'],
        'texto_en_imagen' => ['texto_en_imagen', 'texto_imagen', 'texto_sugerido_en_la_imagen'],
        'notas' => ['notas', 'nota', 'comentarios', 'comentario', 'observaciones'],
    ];

    /** Palabras sueltas que la gente escribe en la columna «tipo». */
    private const TIPOS_ALIAS = [
        'post' => 'post', 'carrusel' => 'post', 'foto' => 'post', 'single' => 'post', 'imagen' => 'post', 'post_carrusel' => 'post',
        'reel' => 'reel', 'video' => 'reel', 'story' => 'story', 'historia' => 'story',
        'grafica' => 'grafica', 'pieza_grafica' => 'grafica', 'afiche' => 'grafica', 'logo' => 'logo', 'identidad' => 'logo',
        'mockup' => 'mockup', 'brandbook' => 'brandbook', 'documento' => 'brandbook', 'presentacion' => 'presentacion',
        'propuesta' => 'presentacion', 'web' => 'web', 'sitio' => 'web', 'landing' => 'web', 'otro' => 'otro',
    ];

    public static function normalizar(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
        $s = preg_replace('/[^a-z0-9]+/', '_', $s) ?? $s;
        return trim($s, '_');
    }

    /**
     * @return array{filas: array<int, array<string, string>>, errores: string[]}
     */
    public static function leer(string $ruta): array
    {
        $errores = [];
        if (!is_file($ruta)) {
            return ['filas' => [], 'errores' => ['No se pudo leer el archivo.']];
        }
        if (filesize($ruta) > self::MAX_BYTES) {
            return ['filas' => [], 'errores' => ['La planilla pesa más de 1 MB.']];
        }
        $texto = (string) file_get_contents($ruta);
        $texto = preg_replace('/^\xEF\xBB\xBF/', '', $texto) ?? $texto;
        if (!mb_check_encoding($texto, 'UTF-8')) {
            $texto = mb_convert_encoding($texto, 'UTF-8', 'Windows-1252');
        }
        $texto = str_replace("\r\n", "\n", $texto);

        $sep = self::separador($texto);
        $fh = fopen('php://memory', 'r+');
        if ($fh === false) {
            return ['filas' => [], 'errores' => ['No se pudo leer el archivo.']];
        }
        fwrite($fh, $texto);
        rewind($fh);

        $cab = fgetcsv($fh, 0, $sep, '"', '');
        if (!is_array($cab)) {
            return ['filas' => [], 'errores' => ['La planilla está vacía.']];
        }
        // Columna de cada campo, según los encabezados.
        $mapa = [];
        foreach ($cab as $i => $h) {
            $n = self::normalizar((string) $h);
            foreach (self::ALIAS as $campo => $alias) {
                if (in_array($n, $alias, true) && !isset($mapa[$campo])) {
                    $mapa[$campo] = $i;
                    break;
                }
            }
        }
        if (!isset($mapa['titulo'])) {
            return ['filas' => [], 'errores' => ['Falta la columna «titulo» en la primera fila.']];
        }

        $filas = [];
        $n = 1;
        while (($r = fgetcsv($fh, 0, $sep, '"', '')) !== false) {
            $n++;
            if ($r === [null] || implode('', array_map('strval', $r)) === '') {
                continue;
            }
            $f = array_fill_keys(self::COLUMNAS, '');
            foreach ($mapa as $campo => $i) {
                $f[$campo] = trim((string) ($r[$i] ?? ''));
            }
            if ($f['titulo'] === '') {
                $errores[] = "Fila {$n}: sin título, se omitió.";
                continue;
            }
            if (count($filas) >= self::MAX_FILAS) {
                $errores[] = 'Se importaron las primeras ' . self::MAX_FILAS . ' filas; el resto se omitió.';
                break;
            }

            $tipoTxt = self::normalizar($f['tipo']);
            $f['tipo'] = $tipoTxt === '' ? 'post' : (TiposContenido::valido($tipoTxt) ? $tipoTxt : (self::TIPOS_ALIAS[$tipoTxt] ?? ''));
            if ($f['tipo'] === '') {
                $errores[] = "Fila {$n} («{$f['titulo']}»): tipo desconocido, se importó como Post.";
                $f['tipo'] = 'post';
            }
            if ($f['enlace'] !== '' && TiposContenido::enlaceSeguro($f['enlace']) === '') {
                $errores[] = "Fila {$n} («{$f['titulo']}»): el link debe empezar con http:// o https://, se dejó vacío.";
                $f['enlace'] = '';
            }
            if ($f['fecha_publicacion'] !== '') {
                $fecha = self::fecha($f['fecha_publicacion']);
                if ($fecha === null) {
                    $errores[] = "Fila {$n} («{$f['titulo']}»): fecha no válida (usa AAAA-MM-DD o DD/MM/AAAA), se dejó vacía.";
                }
                $f['fecha_publicacion'] = $fecha ?? '';
            }
            $f['laminas'] = self::laminas($f['instrucciones_imagen'], $f['texto_en_imagen']);
            $filas[] = $f;
        }
        fclose($fh);

        if ($filas === [] && $errores === []) {
            $errores[] = 'La planilla no tiene filas con contenido.';
        }
        return ['filas' => $filas, 'errores' => $errores];
    }

    /**
     * Instrucciones de imagen + texto en imagen → láminas.
     * «1. Foto. 2. Cifras.» en una sola celda también se separa. El texto en imagen
     * se asigna por número («Slide 3: …») o, si no trae números, en orden.
     * @return array<int, array{idea: string, texto: string}>
     */
    public static function laminas(string $instrucciones, string $textoImagen): array
    {
        $partir = function (string $t): array {
            $t = trim(str_replace("\r\n", "\n", $t));
            if ($t === '') {
                return [];
            }
            if (!str_contains($t, "\n") && preg_match_all('/(?:^|\s)\d+[.)]\s/u', $t) >= 2) {
                $t = preg_replace('/\s+(?=\d+[.)]\s)/u', "\n", $t) ?? $t;
            }
            if (!str_contains($t, "\n") && preg_match_all('/(?:slide|l[aá]mina)\s*\d+\s*:/iu', $t) >= 2) {
                $t = preg_replace('/\s+(?=(?:slide|l[aá]mina)\s*\d+\s*:)/iu', "\n", $t) ?? $t;
            }
            return array_values(array_filter(array_map('trim', preg_split('/\R/u', $t) ?: [])));
        };
        $out = [];
        foreach ($partir($instrucciones) as $linea) {
            $out[] = ['idea' => preg_replace('/^\d+[.)]\s*/u', '', $linea) ?? $linea, 'texto' => ''];
        }
        foreach (self::textosPorLamina($textoImagen) as $i => $txt) {
            while (count($out) <= $i) {
                $out[] = ['idea' => '', 'texto' => ''];
            }
            $out[$i]['texto'] = $txt;
        }
        return TiposContenido::laminas($out);
    }

    /**
     * «Slide 1: …», «Lámina 3: …» o líneas sueltas (en orden) → [índice de lámina => texto].
     * @return array<int, string>
     */
    public static function textosPorLamina(string $t): array
    {
        $t = trim(str_replace("\r\n", "\n", $t));
        if ($t === '') {
            return [];
        }
        if (!str_contains($t, "\n") && preg_match_all('/(?:slide|l[aá]mina)\s*\d+\s*:/iu', $t) >= 2) {
            $t = preg_replace('/\s+(?=(?:slide|l[aá]mina)\s*\d+\s*:)/iu', "\n", $t) ?? $t;
        }
        $out = [];
        $sueltos = 0;
        foreach (preg_split('/\R/u', $t) ?: [] as $linea) {
            $linea = trim(preg_replace('/^[•·\-–*]\s+/u', '', trim($linea)) ?? $linea);
            if ($linea === '') {
                continue;
            }
            if (preg_match('/^(?:slide|l[aá]mina)?\s*(\d+)\s*[:.)]\s*(.+)$/iu', $linea, $m) === 1) {
                $i = max(0, (int) $m[1] - 1);
                $txt = $m[2];
            } else {
                $i = $sueltos++;
                $txt = $linea;
            }
            $out[$i] = trim(preg_replace('/^[\s«"“]+|[\s»"”]+$/u', '', $txt) ?? $txt);
        }
        return $out;
    }

    private static function separador(string $texto): string
    {
        $primera = strtok($texto, "\n") ?: '';
        $mejor = ',';
        $max = -1;
        foreach ([';', ',', "\t"] as $s) {
            $c = substr_count($primera, $s);
            if ($c > $max) {
                $max = $c;
                $mejor = $s;
            }
        }
        return $mejor;
    }

    /** AAAA-MM-DD, DD/MM/AAAA o DD-MM-AAAA (formato chileno) → AAAA-MM-DD. */
    private static function fecha(string $s): ?string
    {
        $s = trim($s);
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $s, $m) === 1) {
            [$a, $mes, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{4})$#', $s, $m) === 1) {
            [$d, $mes, $a] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }
        return checkdate($mes, $d, $a) ? sprintf('%04d-%02d-%02d', $a, $mes, $d) : null;
    }

    /** Plantilla descargable: encabezados y una fila de ejemplo. Punto y coma + BOM para que Excel en español la abra bien. */
    public static function plantilla(): string
    {
        $filas = [
            self::COLUMNAS,
            ['post', 'Post 1 · Ejemplo de carrusel', '@tumarca', '2026-10-15', "Texto del post.\n\n👉 Llamado a la acción.\n\n#hashtag1 #hashtag2", '', 'Pilar del contenido', 'Para qué existe este post', "1. Foto del equipo.\n2. Cifras del año.\n3. Cierre con logo.", "Slide 1: Un año juntos\nSlide 3: Gracias", 'Nota visible para el cliente (ej.: los datos están por confirmar)'],
        ];
        $fh = fopen('php://memory', 'r+');
        foreach ($filas as $f) {
            fputcsv($fh, $f, ';', '"', '');
        }
        rewind($fh);
        return "\xEF\xBB\xBF" . (string) stream_get_contents($fh);
    }
}

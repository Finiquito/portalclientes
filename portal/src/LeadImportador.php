<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * Lee planillas de contactos ganados: la descarga de Meta Lead Ads (UTF-16, separada por
 * tabulador, teléfonos con «p:»), la de formularios de Google Ads, o cualquier CSV con
 * encabezados reconocibles (nombre, correo, teléfono…). Devuelve las filas ya pasadas a
 * nuestros campos, sin guardar nada: primero se muestra una vista previa.
 */
final class LeadImportador
{
    public const MAX_BYTES = 2_000_000;
    public const MAX_FILAS = 2000;

    /** Encabezados propios de Google Ads que no conoce el webhook. */
    private const ALIAS_EXTRA = [
        'user_email' => 'correo', 'user_phone' => 'telefono', 'user_name' => 'nombre', 'phone_number_e164' => 'telefono',
        'work_email' => 'correo', 'work_phone' => 'telefono', 'campaign_id' => null, 'nombre_de_la_campana' => 'campana',
    ];

    /** Pasa el texto a UTF-8 (Meta exporta en UTF-16 con BOM; Excel a veces en Windows-1252). */
    public static function aUtf8(string $t): string
    {
        if (str_starts_with($t, "\xFF\xFE")) {
            return (string) mb_convert_encoding(substr($t, 2), 'UTF-8', 'UTF-16LE');
        }
        if (str_starts_with($t, "\xFE\xFF")) {
            return (string) mb_convert_encoding(substr($t, 2), 'UTF-8', 'UTF-16BE');
        }
        // UTF-16 sin BOM: muchos bytes nulos.
        if (strlen($t) > 4 && substr_count(substr($t, 0, 400), "\x00") > 40) {
            return (string) mb_convert_encoding($t, 'UTF-8', $t[0] === "\x00" ? 'UTF-16BE' : 'UTF-16LE');
        }
        $t = (string) preg_replace('/^\xEF\xBB\xBF/', '', $t);
        return mb_check_encoding($t, 'UTF-8') ? $t : (string) mb_convert_encoding($t, 'UTF-8', 'Windows-1252');
    }

    private static function separador(string $primera): string
    {
        $n = ["\t" => substr_count($primera, "\t"), ';' => substr_count($primera, ';'), ',' => substr_count($primera, ',')];
        arsort($n);
        return (string) array_key_first($n);
    }

    /** «Meta Lead Ads», «Google Ads» u otra planilla, según los encabezados. */
    public static function origen(array $cabeceras): string
    {
        $c = array_map(fn($h) => ImportadorContenidos::normalizar((string) $h), $cabeceras);
        if (array_intersect($c, ['ad_name', 'adset_name', 'form_name', 'is_organic', 'platform']) !== [] || in_array('full_name', $c, true) && in_array('campaign_name', $c, true)) {
            return 'csv_meta';
        }
        if (array_intersect($c, ['member_rating', 'optin_time', 'confirm_time', 'leid', 'euid']) !== []) {
            return 'csv_mailchimp';
        }
        if (array_intersect($c, ['user_email', 'user_phone', 'gcl_id', 'gclid', 'lead_id', 'form_id']) !== []) {
            return 'csv_google';
        }
        return 'csv';
    }

    /**
     * @return array{filas: array<int, array<string, string>>, errores: string[], origen: string, creado: array<int, string>}
     */
    public static function leerTexto(string $texto): array
    {
        $vacio = ['filas' => [], 'errores' => [], 'origen' => 'csv', 'creado' => []];
        if (strlen($texto) > self::MAX_BYTES) {
            return ['errores' => ['La planilla pesa más de 2 MB. Pártela en dos.']] + $vacio;
        }
        $texto = str_replace(["\r\n", "\r"], "\n", self::aUtf8($texto));
        if (trim($texto) === '') {
            return ['errores' => ['La planilla está vacía.']] + $vacio;
        }
        $sep = self::separador(strtok($texto, "\n") ?: '');
        $fh = fopen('php://memory', 'r+');
        if ($fh === false) {
            return ['errores' => ['No se pudo leer el archivo.']] + $vacio;
        }
        fwrite($fh, $texto);
        rewind($fh);
        $cab = fgetcsv($fh, 0, $sep, '"', '');
        if (!is_array($cab) || count(array_filter($cab, fn($h) => trim((string) $h) !== '')) === 0) {
            fclose($fh);
            return ['errores' => ['La planilla no tiene encabezados en la primera fila.']] + $vacio;
        }
        $origen = self::origen($cab);
        $cab = array_map(function ($h): string {
            $n = ImportadorContenidos::normalizar((string) $h);
            return array_key_exists($n, self::ALIAS_EXTRA) ? (string) (self::ALIAS_EXTRA[$n] ?? '_') : (string) $h;
        }, $cab);

        $filas = $creado = $errores = [];
        $n = 1;
        $reconoce = false;
        while (($r = fgetcsv($fh, 0, $sep, '"', '')) !== false) {
            $n++;
            if ($r === [null] || trim(implode('', array_map('strval', $r))) === '') {
                continue;
            }
            if (count($filas) >= self::MAX_FILAS) {
                $errores[] = 'Se leyeron las primeras ' . self::MAX_FILAS . ' filas; el resto se omitió.';
                break;
            }
            $par = [];
            foreach ($cab as $i => $h) {
                $par[$h] = (string) ($r[$i] ?? '');
            }
            $d = LeadFuenteService::mapear($par);
            if (isset($d['telefono'])) {
                $d['telefono'] = (string) preg_replace('/^p:\s*/i', '', $d['telefono']);   // Meta: «p:+569…»
            }
            if ($d !== []) {
                $reconoce = true;
            }
            $d['_fila'] = (string) $n;
            $filas[] = $d;
            $fecha = trim((string) ($par['created_time'] ?? $par['Created time'] ?? $par['fecha'] ?? $par['Fecha'] ?? ''));
            $optin = trim((string) ($par['OPTIN_TIME'] ?? '')) ?: trim((string) ($par['CONFIRM_TIME'] ?? ''));
            if ($fecha === '' && $optin !== '') {
                $fecha = $optin . ' UTC';   // Mailchimp exporta en UTC
            }
            // Clic de Google Ads (gclid) sin UTM: al menos se sabe que vino de Google.
            if (trim((string) ($par['gclid'] ?? $par['GCLID'] ?? '')) !== '' && !isset($d['utm_source'])) {
                $d['utm_source'] = 'google';
                $d['utm_medium'] ??= 'cpc';
            }
            $filas[array_key_last($filas)] = $d;
            $creado[] = $fecha;
        }
        fclose($fh);
        if (!$reconoce) {
            return ['errores' => ['No reconocimos las columnas. Necesitamos al menos «nombre», «correo» o «teléfono» (o los de Meta y Google: full_name, email, phone_number).']] + $vacio;
        }
        return ['filas' => $filas, 'errores' => $errores, 'origen' => $origen, 'creado' => $creado];
    }
}

<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * Plantilla única de los correos del portal (HTML a prueba de clientes de correo: tablas y estilos
 * en línea) más su versión en texto plano. Todos los correos comparten estructura:
 *
 *   cabecera suave (color del cliente → blanco) con el logo del equipo
 *   [contexto: logo + cliente + proyecto, sólo en los avisos para el equipo]
 *   etiqueta + título claro con una parte destacada
 *   cuerpo (saludo, párrafos, listas, tarjetas, cita) + botón
 *   pie discreto
 *
 * Datos aceptados en $d (todo opcional salvo 'titulo'):
 *   agencia   ['nombre' => string, 'logo' => url absoluta|'']
 *   color     '#rrggbb' del portal del cliente
 *   etiqueta  texto corto sobre el título (p. ej. «Revisión»)
 *   titulo, resaltado (fragmento del título que se destaca)
 *   preheader texto que muestran las bandejas junto al asunto
 *   saludo    «Hola Ana,»
 *   bloques   [['p' => ..], ['lista' => [..]], ['tarjetas' => [['titulo','detalle','chip','url']]], ['cita' => ..], ['datos' => [[etiqueta, valor]]],
 *              ['seccion' => ['titulo' => .., 'color' => '#rrggbb']] (subtítulo con punto de color, p. ej. un proyecto),
 *              ['enlaces' => [['texto' => .., 'url' => ..]]] (botones secundarios, p. ej. «Agregar a Google Calendar»)]
 *   boton     ['texto' => .., 'url' => ..]
 *   contexto  ['cliente' => .., 'logo' => url|'', 'proyecto' => ..]
 *   pie       [líneas]
 *   compacto  true → cabecera más baja para avisos cortos
 */
final class CorreoPlantilla
{
    private const TINTA   = '#0f172a';
    private const TEXTO   = '#334155';
    private const SUAVE   = '#64748b';
    private const LINEA   = '#e2e8f0';
    private const FUENTE  = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif";

    /** @param array<string, mixed> $d @return array{0: string, 1: string} [html, texto] */
    public static function render(array $d): array
    {
        return [self::html($d), self::texto($d)];
    }

    // ---- Color -------------------------------------------------------------

    /** @return array{0:int,1:int,2:int} */
    private static function rgb(string $hex): array
    {
        $hex = ltrim(AjustesService::colorValido($hex), '#');
        return [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))];
    }

    /** Mezcla $hex con $otro (0 = $hex, 1 = $otro). */
    private static function mezclar(string $hex, string $otro, float $t): string
    {
        $a = self::rgb($hex);
        $b = self::rgb($otro);
        $o = '';
        foreach ([0, 1, 2] as $i) {
            $o .= str_pad(dechex((int) round($a[$i] + ($b[$i] - $a[$i]) * $t)), 2, '0', STR_PAD_LEFT);
        }
        return '#' . $o;
    }

    /** Color del texto destacado: el de marca, oscurecido lo justo para leerse sobre fondo casi blanco. */
    private static function acento(string $hex): string
    {
        $c = self::mezclar($hex, '#000000', 0.12);
        [$r, $g, $b] = self::rgb($c);
        $lum = (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) / 255;
        return $lum > 0.55 ? self::mezclar($c, '#000000', 0.35) : $c;
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function parrafo(string $s): string
    {
        return nl2br(self::e($s), false);
    }

    // ---- HTML ----------------------------------------------------------------

    private static function html(array $d): string
    {
        $color   = AjustesService::colorValido((string) ($d['color'] ?? ''), '#6d5df6');
        $tinte   = self::mezclar($color, '#ffffff', 0.86);
        $acento  = self::acento($color);
        $textoBt = AjustesService::colorTexto($color);
        $ag      = (array) ($d['agencia'] ?? []);
        $agNombre = (string) ($ag['nombre'] ?? '') !== '' ? (string) $ag['nombre'] : 'Equipo';
        $compacto = !empty($d['compacto']);
        $f = self::FUENTE;

        // Título con una parte destacada
        $titulo = (string) ($d['titulo'] ?? '');
        $res    = (string) ($d['resaltado'] ?? '');
        $tituloHtml = self::e($titulo);
        if ($res !== '' && ($pos = mb_strpos($titulo, $res)) !== false) {
            $tituloHtml = self::e(mb_substr($titulo, 0, $pos))
                . '<span style="color:' . $acento . ';">' . self::e($res) . '</span>'
                . self::e(mb_substr($titulo, $pos + mb_strlen($res)));
        }

        // Logo del equipo (o su nombre si no hay logo)
        $logo = (string) ($ag['logo'] ?? '');
        $marca = $logo !== ''
            ? '<img src="' . self::e($logo) . '" alt="' . self::e($agNombre) . '" height="34" style="display:block;height:34px;max-width:180px;width:auto;border:0;outline:none;font:800 18px/34px ' . $f . ';color:' . self::TINTA . ';">'
            : '<span style="font:800 18px/1 ' . $f . ';color:' . self::TINTA . ';letter-spacing:-.3px;">' . self::e($agNombre) . '</span>';

        $etiqueta = (string) ($d['etiqueta'] ?? '');
        $chipEt = $etiqueta !== ''
            ? '<p style="margin:0 0 10px;"><span style="display:inline-block;padding:4px 11px;border-radius:99px;background:#ffffff;border:1px solid ' . self::mezclar($color, '#ffffff', 0.65)
              . ';font:700 11px/1.4 ' . $f . ';letter-spacing:.06em;text-transform:uppercase;color:' . $acento . ';">' . self::e($etiqueta) . '</span></p>'
            : '';

        $tamTitulo = $compacto ? '24px' : '30px';
        $padCab    = $compacto ? '24px 32px 22px' : '30px 32px 30px';

        $cabecera = '<tr><td class="cab" bgcolor="' . $tinte . '" style="padding:' . $padCab . ';background-color:' . $tinte
            . ';background-image:linear-gradient(160deg,' . $tinte . ' 0%,#ffffff 100%);">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td style="padding:0 0 ' . ($compacto ? '16px' : '26px') . ';">' . $marca . '</td></tr></table>'
            . $chipEt
            . '<h1 style="margin:0;font:800 ' . $tamTitulo . '/1.18 ' . $f . ';letter-spacing:-.6px;color:' . self::TINTA . ';">' . $tituloHtml . '</h1>'
            . '</td></tr>';

        // Contexto (avisos para el equipo): cliente + proyecto
        $contexto = '';
        if (!empty($d['contexto']) && is_array($d['contexto'])) {
            $cx = $d['contexto'];
            $cliente = (string) ($cx['cliente'] ?? '');
            $proy    = (string) ($cx['proyecto'] ?? '');
            $logoC   = (string) ($cx['logo'] ?? '');
            $ini     = mb_strtoupper(mb_substr(trim($cliente), 0, 1)) ?: '·';
            $avatar  = $logoC !== ''
                ? '<img src="' . self::e($logoC) . '" alt="' . self::e($cliente) . '" width="44" height="44" style="display:block;width:44px;height:44px;border-radius:10px;object-fit:contain;background:#ffffff;border:1px solid ' . self::LINEA . ';">'
                : '<div style="width:44px;height:44px;border-radius:10px;background:' . $tinte . ';color:' . $acento . ';font:800 18px/44px ' . $f . ';text-align:center;">' . self::e($ini) . '</div>';
            $contexto = '<tr><td class="cu" style="padding:16px 32px 0;">'
                . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid ' . self::LINEA . ';border-radius:12px;background:#f8fafc;"><tr>'
                . '<td width="44" style="padding:12px 0 12px 14px;" valign="middle">' . $avatar . '</td>'
                . '<td style="padding:12px 16px;" valign="middle">'
                . '<div style="font:700 11px/1.3 ' . $f . ';letter-spacing:.06em;text-transform:uppercase;color:' . self::SUAVE . ';">Cliente' . ($proy !== '' ? ' · Proyecto' : '') . '</div>'
                . '<div style="font:800 16px/1.35 ' . $f . ';color:' . self::TINTA . ';">' . self::e($cliente) . ($proy !== '' ? ' <span style="color:' . self::SUAVE . ';font-weight:600;">·</span> <span style="color:' . $acento . ';">' . self::e($proy) . '</span>' : '') . '</div>'
                . '</td></tr></table></td></tr>';
        }

        // Cuerpo
        $cuerpo = '';
        if ((string) ($d['saludo'] ?? '') !== '') {
            $cuerpo .= '<p style="margin:0 0 14px;font:700 16px/1.5 ' . $f . ';color:' . self::TINTA . ';">' . self::e((string) $d['saludo']) . '</p>';
        }
        foreach ((array) ($d['bloques'] ?? []) as $b) {
            if (isset($b['p'])) {
                $cuerpo .= '<p style="margin:0 0 14px;font:400 15px/1.65 ' . $f . ';color:' . self::TEXTO . ';">' . self::parrafo((string) $b['p']) . '</p>';
            } elseif (isset($b['lista'])) {
                $li = '';
                foreach ((array) $b['lista'] as $x) {
                    $li .= '<tr><td width="20" valign="top" style="padding:3px 0;font:700 15px/1.5 ' . $f . ';color:' . $acento . ';">•</td>'
                        . '<td style="padding:3px 0;font:400 15px/1.55 ' . $f . ';color:' . self::TEXTO . ';">' . self::parrafo((string) $x) . '</td></tr>';
                }
                $cuerpo .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 14px;">' . $li . '</table>';
            } elseif (isset($b['tarjetas'])) {
                foreach ((array) $b['tarjetas'] as $t) {
                    $chip = (string) ($t['chip'] ?? '') !== ''
                        ? '<td align="right" valign="top" style="padding:14px 16px 0 0;white-space:nowrap;"><span style="display:inline-block;padding:3px 10px;border-radius:99px;background:' . $tinte . ';font:700 11px/1.5 ' . $f . ';color:' . $acento . ';">' . self::e((string) $t['chip']) . '</span></td>' : '';
                    $det = (string) ($t['detalle'] ?? '') !== ''
                        ? '<div style="margin-top:3px;font:400 13px/1.5 ' . $f . ';color:' . self::SUAVE . ';">' . self::parrafo((string) $t['detalle']) . '</div>' : '';
                    $tit = self::e((string) ($t['titulo'] ?? ''));
                    if ((string) ($t['url'] ?? '') !== '') {
                        $tit = '<a href="' . self::e((string) $t['url']) . '" style="color:' . self::TINTA . ';text-decoration:none;">' . $tit . '</a>';
                    }
                    $cuerpo .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 10px;border:1px solid ' . self::LINEA . ';border-radius:12px;background:#ffffff;"><tr>'
                        . '<td style="padding:13px 16px;"><div style="font:700 15px/1.4 ' . $f . ';color:' . self::TINTA . ';">' . $tit . '</div>' . $det . '</td>' . $chip . '</tr></table>';
                }
                $cuerpo .= '<div style="height:4px;line-height:4px;">&nbsp;</div>';
            } elseif (isset($b['seccion'])) {
                $sc = (array) $b['seccion'];
                $punto = AjustesService::colorValido((string) ($sc['color'] ?? ''), self::SUAVE);
                $cuerpo .= '<div style="margin:18px 0 10px;font:800 13px/1.4 ' . $f . ';letter-spacing:.04em;text-transform:uppercase;color:' . self::SUAVE . ';">'
                    . '<span style="display:inline-block;width:9px;height:9px;margin-right:8px;border-radius:99px;background:' . $punto . ';vertical-align:1px;"></span>'
                    . self::e((string) ($sc['titulo'] ?? '')) . '</div>';
            } elseif (isset($b['enlaces'])) {
                $celdas = '';
                foreach ((array) $b['enlaces'] as $en) {
                    $celdas .= '<td style="padding:0 8px 8px 0;"><a href="' . self::e((string) ($en['url'] ?? '')) . '" style="display:inline-block;padding:10px 16px;border:1px solid ' . self::LINEA
                        . ';border-radius:10px;font:700 13px/1 ' . $f . ';color:' . $acento . ';text-decoration:none;background:#ffffff;">' . self::e((string) ($en['texto'] ?? '')) . '</a></td>';
                }
                $cuerpo .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 10px;"><tr>' . $celdas . '</tr></table>';
            } elseif (isset($b['codigo'])) {
                $cuerpo .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 18px;"><tr><td style="padding:16px 30px;border-radius:14px;background:' . $tinte . ';border:1px solid ' . self::mezclar($color, '#ffffff', 0.7)
                    . ';font:800 34px/1 \'SFMono-Regular\',Consolas,Menlo,monospace;letter-spacing:.32em;color:' . self::TINTA . ';">' . self::e((string) $b['codigo']) . '</td></tr></table>';
            } elseif (isset($b['cita'])) {
                $cuerpo .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px;"><tr>'
                    . '<td width="4" style="background:' . self::mezclar($color, '#ffffff', 0.35) . ';border-radius:4px;">&nbsp;</td>'
                    . '<td style="padding:6px 0 6px 16px;font:italic 400 15px/1.65 ' . $f . ';color:' . self::TEXTO . ';">' . self::parrafo((string) $b['cita']) . '</td></tr></table>';
            } elseif (isset($b['datos'])) {
                $filas = '';
                foreach (array_values((array) $b['datos']) as $i => $par) {
                    $bt = $i === 0 ? 'none' : '1px solid ' . self::LINEA;
                    $filas .= '<tr><td style="padding:9px 14px;border-top:' . $bt . ';font:600 13px/1.4 ' . $f . ';color:' . self::SUAVE . ';width:38%;">' . self::e((string) $par[0]) . '</td>'
                        . '<td style="padding:9px 14px;border-top:' . $bt . ';font:700 14px/1.4 ' . $f . ';color:' . self::TINTA . ';">' . self::e((string) $par[1]) . '</td></tr>';
                }
                $cuerpo .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px;border:1px solid ' . self::LINEA . ';border-radius:12px;background:#f8fafc;">' . $filas . '</table>';
            }
        }
        if (!empty($d['boton']['url'])) {
            $cuerpo .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 6px;"><tr>'
                . '<td bgcolor="' . $color . '" style="border-radius:12px;background:' . $color . ';">'
                . '<a href="' . self::e((string) $d['boton']['url']) . '" style="display:inline-block;padding:14px 26px;font:700 15px/1 ' . $f . ';color:' . $textoBt . ';text-decoration:none;border-radius:12px;">'
                . self::e((string) ($d['boton']['texto'] ?? 'Abrir el portal')) . '</a></td></tr></table>';
        }

        // Pie
        $pie = '';
        foreach ((array) ($d['pie'] ?? []) as $l) {
            $pie .= '<p style="margin:0 0 6px;font:400 12px/1.6 ' . $f . ';color:' . self::SUAVE . ';">' . self::parrafo((string) $l) . '</p>';
        }
        $pie .= '<p style="margin:10px 0 0;font:600 12px/1.6 ' . $f . ';color:#94a3b8;">' . self::e($agNombre) . '</p>';

        $pre = (string) ($d['preheader'] ?? '');
        $preHtml = $pre !== '' ? '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">' . self::e($pre) . '&#8199;&#847;&#8199;&#847;&#8199;&#847;</div>' : '';

        return '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light"><title>' . self::e($titulo) . '</title>'
            . '<style>@media (max-width:620px){.caja{width:100%!important;border-radius:0!important}.cab{padding-left:22px!important;padding-right:22px!important}.cu{padding-left:22px!important;padding-right:22px!important}h1{font-size:25px!important}}</style>'
            . '</head><body style="margin:0;padding:0;background:#f1f3f8;">' . $preHtml
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f1f3f8" style="background:#f1f3f8;"><tr><td align="center" style="padding:28px 12px;">'
            . '<table role="presentation" class="caja" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;background:#ffffff;border-radius:18px;overflow:hidden;">'
            . $cabecera . $contexto
            . '<tr><td class="cu" style="padding:' . ($contexto !== '' ? '22px' : '20px') . ' 32px 30px;">' . $cuerpo . '</td></tr>'
            . '<tr><td class="cu" style="padding:18px 32px 26px;border-top:1px solid ' . self::LINEA . ';background:#fafbfd;">' . $pie . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    // ---- Texto plano -----------------------------------------------------------

    private static function texto(array $d): string
    {
        $o = [];
        if (!empty($d['contexto']) && is_array($d['contexto'])) {
            $o[] = 'CLIENTE: ' . ($d['contexto']['cliente'] ?? '') . (($d['contexto']['proyecto'] ?? '') !== '' ? ' · PROYECTO: ' . $d['contexto']['proyecto'] : '');
            $o[] = '';
        }
        $o[] = (string) ($d['titulo'] ?? '');
        $o[] = str_repeat('=', min(60, max(8, mb_strlen((string) ($d['titulo'] ?? '')))));
        $o[] = '';
        if ((string) ($d['saludo'] ?? '') !== '') {
            $o[] = (string) $d['saludo'];
            $o[] = '';
        }
        foreach ((array) ($d['bloques'] ?? []) as $b) {
            if (isset($b['p'])) {
                $o[] = (string) $b['p'];
            } elseif (isset($b['lista'])) {
                foreach ((array) $b['lista'] as $x) {
                    $o[] = '• ' . $x;
                }
            } elseif (isset($b['tarjetas'])) {
                foreach ((array) $b['tarjetas'] as $t) {
                    $o[] = '• ' . ($t['titulo'] ?? '') . ((string) ($t['chip'] ?? '') !== '' ? ' [' . $t['chip'] . ']' : '') . ((string) ($t['detalle'] ?? '') !== '' ? ' — ' . $t['detalle'] : '')
                        . ((string) ($t['url'] ?? '') !== '' ? ' → ' . $t['url'] : '');
                }
            } elseif (isset($b['seccion'])) {
                $o[] = mb_strtoupper((string) ($b['seccion']['titulo'] ?? ''));
            } elseif (isset($b['enlaces'])) {
                foreach ((array) $b['enlaces'] as $en) {
                    $o[] = ($en['texto'] ?? '') . ': ' . ($en['url'] ?? '');
                }
            } elseif (isset($b['codigo'])) {
                $o[] = '    ' . $b['codigo'];
            } elseif (isset($b['cita'])) {
                $o[] = '  « ' . $b['cita'] . ' »';
            } elseif (isset($b['datos'])) {
                foreach ((array) $b['datos'] as $par) {
                    $o[] = $par[0] . ': ' . $par[1];
                }
            }
            $o[] = '';
        }
        if (!empty($d['boton']['url'])) {
            $o[] = ($d['boton']['texto'] ?? 'Abrir el portal') . ': ' . $d['boton']['url'];
            $o[] = '';
        }
        foreach ((array) ($d['pie'] ?? []) as $l) {
            $o[] = (string) $l;
        }
        $ag = (string) ($d['agencia']['nombre'] ?? '');
        if ($ag !== '') {
            $o[] = '— ' . $ag;
        }
        return rtrim(implode("\n", $o)) . "\n";
    }
}

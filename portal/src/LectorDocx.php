<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * Saca el texto de un .docx (Word, o un Google Doc descargado como .docx) sin librerías:
 * un .docx es un zip con word/document.xml. Se respeta el orden de párrafos y tablas,
 * los saltos de línea y las listas numeradas de Word («1.», «2.»…), que no vienen como
 * texto sino como formato. Las imágenes que traiga el documento se ignoran.
 */
final class LectorDocx
{
    public const MAX_BYTES = 15728640;   // 15 MB (los docx con fotos pesan; el texto es poco)

    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /** @throws \RuntimeException con un mensaje listo para mostrar */
    public static function texto(string $ruta): string
    {
        if (!class_exists(\ZipArchive::class) || !class_exists(\DOMDocument::class)) {
            throw new \RuntimeException('Este hosting no puede leer archivos .docx (faltan las extensiones zip o dom de PHP). Pega el texto de la grilla.');
        }
        if (!is_file($ruta) || filesize($ruta) > self::MAX_BYTES) {
            throw new \RuntimeException('El archivo no se pudo leer o pesa más de 15 MB.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($ruta) !== true) {
            throw new \RuntimeException('Ese archivo no es un .docx válido. En Google Docs: Archivo → Descargar → Microsoft Word (.docx).');
        }
        $xml = $zip->getFromName('word/document.xml');
        $numeracion = $zip->getFromName('word/numbering.xml');
        $zip->close();
        if (!is_string($xml) || $xml === '') {
            throw new \RuntimeException('Ese .docx no tiene texto que leer.');
        }

        $doc = self::cargar($xml);
        if ($doc === null) {
            throw new \RuntimeException('No se pudo leer el contenido del .docx.');
        }
        $formatos = is_string($numeracion) ? self::formatosNumeracion($numeracion) : [];
        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('w', self::W);

        $lineas = [];
        $contadores = [];
        $body = $xp->query('/w:document/w:body')->item(0);
        if ($body === null) {
            return '';
        }
        foreach ($body->childNodes as $nodo) {
            if (!$nodo instanceof \DOMElement) {
                continue;
            }
            if ($nodo->localName === 'p') {
                $lineas[] = self::parrafo($xp, $nodo, $formatos, $contadores);
            } elseif ($nodo->localName === 'tbl') {
                foreach ($xp->query('./w:tr', $nodo) as $fila) {
                    $celdas = [];
                    foreach ($xp->query('./w:tc', $fila) as $celda) {
                        $ps = [];
                        foreach ($xp->query('.//w:p', $celda) as $p) {
                            $t = trim(self::parrafo($xp, $p, $formatos, $contadores));
                            if ($t !== '') {
                                $ps[] = $t;
                            }
                        }
                        $celdas[] = implode("\n", $ps);
                    }
                    $lineas[] = implode("\n", array_filter($celdas, fn($c) => $c !== ''));
                }
            }
        }
        $txt = implode("\n", $lineas);
        $txt = preg_replace("/[ \t]+\n/u", "\n", $txt) ?? $txt;
        $txt = preg_replace("/\n{3,}/u", "\n\n", $txt) ?? $txt;
        return trim($txt);
    }

    private static function cargar(string $xml): ?\DOMDocument
    {
        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        // Sin red ni entidades externas: el archivo viene de afuera.
        $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return $ok ? $doc : null;
    }

    /**
     * @param array<string, array<int, string>> $formatos numId => [nivel => numFmt]
     * @param array<string, array<int, int>> $contadores
     */
    private static function parrafo(\DOMXPath $xp, \DOMElement $p, array $formatos, array &$contadores): string
    {
        $txt = '';
        foreach ($xp->query('.//w:t|.//w:tab|.//w:br|.//w:cr', $p) as $n) {
            /** @var \DOMElement $n */
            // Lo borrado con control de cambios no cuenta.
            if ($xp->query('ancestor::w:del', $n)->length > 0) {
                continue;
            }
            $txt .= match ($n->localName) {
                't' => $n->textContent,
                'tab' => "\t",
                default => "\n",
            };
        }
        $num = $xp->query('./w:pPr/w:numPr', $p)->item(0);
        if ($num !== null && trim($txt) !== '') {
            $id = (string) ($xp->query('./w:numId/@w:val', $num)->item(0)?->nodeValue ?? '');
            $nivel = (int) ($xp->query('./w:ilvl/@w:val', $num)->item(0)?->nodeValue ?? 0);
            $fmt = $formatos[$id][$nivel] ?? 'bullet';
            if ($id !== '' && $id !== '0') {
                $contadores[$id][$nivel] = ($contadores[$id][$nivel] ?? 0) + 1;
                foreach (array_keys($contadores[$id]) as $k) {
                    if ($k > $nivel) {
                        unset($contadores[$id][$k]);
                    }
                }
                $n = $contadores[$id][$nivel];
                $prefijo = match ($fmt) {
                    'decimal', 'decimalZero' => $n . '. ',
                    'lowerLetter' => chr(96 + min($n, 26)) . ') ',
                    'upperLetter' => chr(64 + min($n, 26)) . ') ',
                    default => '• ',
                };
                $txt = str_repeat('  ', $nivel) . $prefijo . ltrim($txt);
            }
        }
        return $txt;
    }

    /** @return array<string, array<int, string>> numId => [nivel => numFmt] */
    private static function formatosNumeracion(string $xml): array
    {
        $doc = self::cargar($xml);
        if ($doc === null) {
            return [];
        }
        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('w', self::W);
        $abstractos = [];
        foreach ($xp->query('//w:abstractNum') as $a) {
            /** @var \DOMElement $a */
            $aid = $a->getAttributeNS(self::W, 'abstractNumId');
            foreach ($xp->query('./w:lvl', $a) as $lvl) {
                /** @var \DOMElement $lvl */
                $abstractos[$aid][(int) $lvl->getAttributeNS(self::W, 'ilvl')] = (string) ($xp->query('./w:numFmt/@w:val', $lvl)->item(0)?->nodeValue ?? 'bullet');
            }
        }
        $out = [];
        foreach ($xp->query('//w:num') as $n) {
            /** @var \DOMElement $n */
            $aid = (string) ($xp->query('./w:abstractNumId/@w:val', $n)->item(0)?->nodeValue ?? '');
            $out[$n->getAttributeNS(self::W, 'numId')] = $abstractos[$aid] ?? [];
        }
        return $out;
    }
}

<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

use Latte\Compiler\Node;
use Latte\Compiler\NodeTraverser;
use Latte\Compiler\Nodes\Html\AttributeNode;
use Latte\Compiler\Nodes\Html\ElementNode;
use Latte\Compiler\Nodes\TemplateNode;
use Latte\Compiler\Nodes\TextNode;
use Latte\Compiler\PrintContext;
use Latte\Extension;

/**
 * Traduce las plantillas sin tener que editarlas una por una: al compilar,
 * cada texto fijo (y los atributos placeholder, title, aria-label y alt) pasa
 * por Idioma::tx(). En español devuelve el mismo texto, así que las plantillas
 * se siguen leyendo y editando en español.
 *
 * Para frases con valores dentro (que cambian de orden según el idioma) se usa
 * la etiqueta {_'Hola, %s', $nombre} (la trae Latte; Idioma::t hace la traducción).
 */
final class IdiomaExtension extends Extension
{
    /** Deja el motor listo: textos fijos automáticos y {_'…'} para frases con valores. */
    public static function instalar(\Latte\Engine $motor): void
    {
        foreach ($motor->getExtensions() as $e) {
            if ($e instanceof self) {
                return;
            }
        }
        $motor->addExtension(new \Latte\Essential\TranslatorExtension(Idioma::t(...)));
        $motor->addExtension(new self());
    }

    private const SALTAR = ['script', 'style', 'textarea', 'code', 'pre'];
    private const ATRIBUTOS = ['placeholder', 'title', 'aria-label', 'alt', 'aria-description'];

    /** @return array<string, callable> */
    public function getPasses(): array
    {
        return ['portalIdioma' => self::pase(...)];
    }

    public function getCacheKey(\Latte\Engine $engine): mixed
    {
        return 'idioma1';
    }

    public static function pase(TemplateNode $plantilla): void
    {
        (new NodeTraverser())->traverse($plantilla, function (Node $n) {
            if ($n instanceof ElementNode) {
                if (in_array(strtolower($n->name), self::SALTAR, true)) {
                    // Sus atributos sí se traducen; su contenido no.
                    self::atributos($n);
                    return NodeTraverser::DontTraverseChildren;
                }
                self::atributos($n);
                return null;
            }
            if ($n instanceof AttributeNode) {
                return NodeTraverser::DontTraverseChildren;   // los atributos se tratan arriba, uno por uno
            }
            if ($n instanceof TextoTraducible) {
                return null;
            }
            if ($n instanceof TextNode && self::traducible($n->content)) {
                return new TextoTraducible($n->content, $n->position, $n->end);
            }
            return null;
        });
    }

    private static function atributos(ElementNode $el): void
    {
        $esBoton = false;
        $tipo = $el->getAttribute('type');
        if (is_string($tipo) && in_array(strtolower($tipo), ['submit', 'button'], true) && strtolower($el->name) === 'input') {
            $esBoton = true;
        }
        foreach ($el->attributes->children as $hijo) {
            if (!$hijo instanceof AttributeNode || !$hijo->name instanceof TextNode || !$hijo->value instanceof TextNode) {
                continue;
            }
            $nombre = strtolower($hijo->name->content);
            if ((in_array($nombre, self::ATRIBUTOS, true) || ($esBoton && $nombre === 'value')) && self::traducible($hijo->value->content)) {
                $hijo->value = new TextoTraducible($hijo->value->content, $hijo->value->position, $hijo->value->end);
            }
        }
    }

    /** Lleva al menos una letra (fuera de entidades como &middot;) y no es un script ni un marcador. */
    public static function traducible(string $texto): bool
    {
        $sin = preg_replace('/&#?\w+;/', '', $texto) ?? $texto;
        return (bool) preg_match('/\p{L}/u', $sin);
    }
}

/** Texto fijo de plantilla que se traduce al imprimirse. */
final class TextoTraducible extends TextNode
{
    public function print(PrintContext $context): string
    {
        return $this->content === ''
            ? ''
            : 'echo \\' . Idioma::class . '::tx(' . var_export($this->content, true) . ");\n";
    }
}

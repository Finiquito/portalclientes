<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

use Latte\Compiler\Node;
use Latte\Compiler\NodeTraverser;
use Latte\Compiler\Nodes\Html\AttributeNode;
use Latte\Compiler\Nodes\Html\BogusTagNode;
use Latte\Compiler\Nodes\Html\CommentNode;
use Latte\Compiler\Nodes\Html\ElementNode;
use Latte\Compiler\Nodes\Php\ArgumentNode;
use Latte\Compiler\Nodes\Php\Expression\ArrayNode;
use Latte\Compiler\Nodes\Php\Expression\BinaryOpNode;
use Latte\Compiler\Nodes\Php\Expression\FunctionCallNode;
use Latte\Compiler\Nodes\Php\Expression\StaticMethodCallNode;
use Latte\Compiler\Nodes\Php\Expression\TernaryNode;
use Latte\Compiler\Nodes\Php\ExpressionNode;
use Latte\Compiler\Nodes\Php\IdentifierNode;
use Latte\Compiler\Nodes\Php\NameNode;
use Latte\Compiler\Nodes\Php\Scalar\StringNode;
use Latte\Compiler\Nodes\PrintNode;
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

    /** @return array<string, callable> t('Texto %s', $valor) dentro de expresiones de plantilla */
    public function getFunctions(): array
    {
        return ['t' => fn(string $texto, string|int|float ...$args): string => Idioma::t($texto, ...$args)];
    }

    public function getCacheKey(\Latte\Engine $engine): mixed
    {
        return 'idioma7';
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
            if ($n instanceof BogusTagNode || $n instanceof CommentNode) {
                return NodeTraverser::DontTraverseChildren;   // <!DOCTYPE …> y <!-- comentarios -->
            }
            if ($n instanceof AttributeNode) {
                return NodeTraverser::DontTraverseChildren;   // los atributos se tratan arriba, uno por uno
            }
            if ($n instanceof PrintNode) {
                self::literales($n);
                return null;
            }
            if ($n instanceof ArrayNode) {   // [['auto', 'Automático'], …]: las etiquetas de listas escritas en la plantilla
                foreach ($n->items as $item) {
                    if ($item->value instanceof StringNode && self::pareceTexto($item->value->value)) {
                        $item->value = self::llamarT($item->value);
                    } elseif ($item->value instanceof TernaryNode) {   // ['x', 'Y', $a ? 'Proyectos' : 'Proyecto']
                        foreach (['if', 'else'] as $rama) {
                            if ($item->value->$rama instanceof StringNode && self::pareceTexto($item->value->$rama->value)) {
                                $item->value->$rama = self::llamarT($item->value->$rama);
                            }
                        }
                    }
                }
                return null;
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

    /**
     * {$x ? 'Lista' : 'Próxima'}: los textos que se eligen dentro de una expresión que se imprime
     * (ramas de ?: y ??, y piezas de una concatenación) pasan por Idioma::t(). Sólo los que parecen
     * texto para personas: empiezan con mayúscula, llevan espacio o acento. Las palabras sueltas en
     * minúscula ('pieza', 'aprobadas') se marcan a mano con t().
     */
    private static function literales(PrintNode $impresion): void
    {
        foreach ($impresion->modifier->filters as $filtro) {
            if ($filtro->name->name === 'translate') {
                return;   // {_'…'}: ya se traduce con su filtro
            }
        }
        $envolver = fn(?ExpressionNode $e) => $e instanceof StringNode && self::pareceTexto($e->value) ? self::llamarT($e) : $e;
        (new NodeTraverser())->traverse($impresion->expression, function (Node $h) use ($envolver) {
            if ($h instanceof TernaryNode) {
                $h->if = $envolver($h->if);
                $h->else = $envolver($h->else);
            } elseif ($h instanceof BinaryOpNode && in_array($h->operator, ['??', '.'], true)) {
                $h->right = $envolver($h->right);
                if ($h->operator === '.') {
                    $h->left = $envolver($h->left);
                }
            } elseif ($h instanceof StaticMethodCallNode || $h instanceof FunctionCallNode) {
                return NodeTraverser::DontTraverseChildren;
            }
            return null;
        });
        // la expresión completa: {'Texto'} o {$a ?: 'Texto'}
        $impresion->expression = $envolver($impresion->expression);
    }

    private static function llamarT(StringNode $texto): StaticMethodCallNode
    {
        return new StaticMethodCallNode(
            new NameNode('\\' . Idioma::class, NameNode::KindFullyQualified),
            new IdentifierNode('t'),
            [new ArgumentNode($texto)],
        );
    }

    public static function pareceTexto(string $v): bool
    {
        return (bool) preg_match('/\p{L}{3}/u', $v)
            && !preg_match('/[-\/.#_:\d]/', $v)
            && (bool) preg_match('/^[\p{Lu}¿¡]|\s|[áéíóúñÁÉÍÓÚÑ]/u', $v);
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

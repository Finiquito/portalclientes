<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * Idioma de la pantalla. El texto en español es la clave: si falta la
 * traducción se muestra el español (nunca una pantalla en blanco).
 *
 * El idioma es de cada persona (contacto o integrante del equipo) y se guarda
 * en portal_ajustes ('idioma'). Si no eligió, vale el del cliente, luego el
 * de la agencia y al final el español.
 *
 * Las plantillas se traducen solas (ver IdiomaExtension); en PHP se usa
 * Idioma::t('Texto %s', $valor).
 */
final class Idioma
{
    public const DEFECTO = 'es';

    /** código => nombre en su propio idioma */
    public const DISPONIBLES = ['es' => 'Español', 'en' => 'English', 'fr' => 'Français', 'it' => 'Italiano'];

    public const COOKIE = 'portal_idioma';

    /** Cookie en uso: el panel del equipo tiene la suya, para que lo que se vea como cliente en el mismo navegador no le cambie el idioma. */
    private static string $nombreCookie = self::COOKIE;

    public static function cookieDelEquipo(): void
    {
        self::$nombreCookie = self::COOKIE . '_equipo';
    }

    private static string $actual = self::DEFECTO;

    /** @var array<string, array<string, string>> */
    private static array $tablas = [];

    /** @var array<string, true> frases pedidas que no están traducidas (para pruebas y para el detector) */
    private static array $faltas = [];

    public static bool $registrarFaltas = false;

    public static function valido(string $codigo): bool
    {
        return isset(self::DISPONIBLES[$codigo]);
    }

    public static function actual(): string
    {
        return self::$actual;
    }

    public static function fijar(string $codigo): void
    {
        self::$actual = self::valido($codigo) ? $codigo : self::DEFECTO;
    }

    /** Ejecuta $fn con otro idioma y vuelve al anterior (correos que salen a otra persona). */
    public static function con(string $codigo, callable $fn): mixed
    {
        $antes = self::$actual;
        self::fijar($codigo);
        try {
            return $fn();
        } finally {
            self::$actual = $antes;
        }
    }

    /**
     * Elige el idioma de una persona: el suyo, si no el del cliente, si no el de la agencia.
     */
    public static function de(AjustesService $aj, string $ownerTipo, string $ownerId, string $clienteId = '', bool $conCookie = false): string
    {
        $v = $aj->get($ownerTipo, $ownerId, 'idioma');
        if (!self::valido($v) && $conCookie) {
            $v = (string) ($_COOKIE[self::$nombreCookie] ?? '');   // lo que eligió en la pantalla de ingreso (sólo para quien está en esta visita)
        }
        if (!self::valido($v) && $clienteId !== '') {
            $v = $aj->get('cliente', $clienteId, 'idioma');
        }
        if (!self::valido($v)) {
            $v = $aj->get('global', 'portal', 'idioma');
        }
        return self::valido($v) ? $v : self::DEFECTO;
    }

    /**
     * Para las pantallas sin sesión (ingreso): la cookie (lo último que usó o eligió esa persona en este equipo), luego
     * —salvo que $navegador sea false— el idioma del navegador, y al final el de la agencia.
     * El ingreso del equipo no mira el navegador: sus personas usan el idioma de la agencia hasta que eligen otro.
     */
    public static function delVisitante(string $deLaAgencia = self::DEFECTO, bool $navegador = true): string
    {
        $c = (string) ($_COOKIE[self::$nombreCookie] ?? '');
        if (self::valido($c)) {
            return $c;
        }
        $h = $navegador ? strtolower((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) : '';
        foreach (explode(',', $h) as $parte) {
            $cod = substr(trim($parte), 0, 2);
            if (self::valido($cod)) {
                return $cod;
            }
        }
        return self::valido($deLaAgencia) ? $deLaAgencia : self::DEFECTO;
    }

    /** Idioma de quien aún no ingresó; si viene ?idioma=en lo recuerda para las próximas pantallas. */
    public static function visitante(string $deLaAgencia = self::DEFECTO, bool $navegador = true): string
    {
        $elegido = (string) ($_GET['idioma'] ?? '');
        if (self::valido($elegido)) {
            self::recordar($elegido);
            $_COOKIE[self::$nombreCookie] = $elegido;
        }
        return self::delVisitante($deLaAgencia, $navegador);
    }

    /** Deja la cookie con la elección (sirve para el ingreso y los correos de acceso). */
    public static function recordar(string $codigo): void
    {
        if (self::valido($codigo) && !headers_sent()) {
            setcookie(self::$nombreCookie, $codigo, [
                'expires'  => time() + 86400 * 365,
                'path'     => '/',
                'secure'   => !empty($_SERVER['HTTPS']),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }

    /**
     * Guarda el idioma elegido en un formulario del equipo (campo «idioma»): vacío = hereda el del cliente o la agencia.
     * No toca nada si el formulario no trae el campo.
     */
    public static function guardarDePost(AjustesService $aj, string $ownerTipo, string $ownerId): void
    {
        if (!isset($_POST['idioma'])) {
            return;
        }
        $v = (string) $_POST['idioma'];
        if (self::valido($v)) {
            $aj->set($ownerTipo, $ownerId, 'idioma', $v);
        } elseif ($v === '') {
            $aj->borrar($ownerTipo, $ownerId, 'idioma');
        }
    }

    /** Una frase que se traduce al momento de leerla (ver Frase): Idioma::f('%s completó «%s»', $quien, $titulo). */
    public static function f(string $texto, string|int|float|\Stringable ...$valores): Frase
    {
        return new Frase($texto, $valores);
    }

    /** Un texto calculado al leerlo, en el idioma que esté activo en ese momento. */
    public static function perezoso(callable $f): \Stringable
    {
        return new class($f) implements \Stringable {
            public function __construct(private readonly mixed $f) {}
            public function __toString(): string { return (string) ($this->f)(); }
        };
    }

    /** Pasa a texto lo que sea Stringable, también dentro de listas. */
    public static function resolver(mixed $v): mixed
    {
        if ($v instanceof \Stringable) {
            return (string) $v;
        }
        if (is_array($v)) {
            foreach ($v as $k => $x) {
                $v[$k] = self::resolver($x);
            }
        }
        return $v;
    }

    /** Normaliza una frase de plantilla para usarla de clave (espacios y saltos de línea en uno). */
    public static function clave(string $texto): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $texto));
    }

    /**
     * Traduce. Con argumentos usa sprintf (%s, %d) para que el orden de las palabras
     * pueda cambiar de un idioma a otro: «Hola, %s» → «Hello, %s».
     */
    public static function t(string $texto, string|int|float ...$args): string
    {
        $out = self::buscar($texto);
        return $args === [] ? $out : vsprintf($out, $args);
    }

    /** Traducción de un texto de plantilla: conserva los espacios de los bordes. */
    public static function tx(string $texto): string
    {
        if (self::$actual === self::DEFECTO && !self::$registrarFaltas) {
            return $texto;
        }
        if (!preg_match('/^(\s*)(.*?)(\s*)$/su', $texto, $m)) {
            return $texto;
        }
        return $m[1] . self::buscar(self::clave($m[2])) . $m[3];
    }

    /**
     * Traduce los textos de una tabla de etiquetas (clave => texto, o clave => [texto, …]).
     * Lo que no está en la tabla de traducciones (íconos, tonos, claves) queda igual.
     *
     * @template T of array
     * @param T $tabla
     * @return T
     */
    public static function lista(array $tabla): array
    {
        if (self::$actual === self::DEFECTO && !self::$registrarFaltas) {
            return $tabla;
        }
        foreach ($tabla as $k => $v) {
            $tabla[$k] = is_array($v) ? self::lista($v) : (is_string($v) ? self::buscarSinRegistro($v) : $v);
        }
        return $tabla;
    }

    /**
     * Antepone al JS las traducciones de los textos marcados con T('…'). En español no agrega nada.
     */
    public static function conTraducciones(string $js, string ...$otros): string
    {
        if (self::$actual === self::DEFECTO || !preg_match_all("/\\bT\\('((?:[^'\\\\]|\\\\.)*)'\\)/u", $js . "\n" . implode("\n", $otros), $m)) {
            return $js;
        }
        $tabla = [];
        foreach (array_unique($m[1]) as $lit) {
            $k = stripcslashes($lit);
            $v = self::tabla(self::$actual)[$k] ?? null;
            if ($v !== null) {
                $tabla[$k] = $v;
            }
        }
        return 'window.PORTAL_T=' . json_encode($tabla, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . ";\n" . $js;
    }

    private static function buscarSinRegistro(string $texto): string
    {
        return self::$actual === self::DEFECTO ? $texto : (self::tabla(self::$actual)[$texto] ?? $texto);
    }

    private static function buscar(string $texto): string
    {
        if (self::$actual === self::DEFECTO) {
            return $texto;
        }
        $tabla = self::tabla(self::$actual);
        if (isset($tabla[$texto])) {
            return $tabla[$texto];
        }
        if (self::$registrarFaltas) {
            self::$faltas[$texto] = true;
        }
        return $texto;
    }

    /** @return array<string, string> */
    public static function tabla(string $codigo): array
    {
        if (!isset(self::$tablas[$codigo])) {
            // lang/en/*.php: un archivo por zona (cliente, equipo, admin, correos…), todos se suman
            $t = [];
            foreach (glob(dirname(__DIR__) . '/lang/' . $codigo . '/*.php') ?: [] as $f) {
                $parte = include $f;
                if (is_array($parte)) {
                    $t = $parte + $t;
                }
            }
            self::$tablas[$codigo] = $t;
        }
        return self::$tablas[$codigo];
    }

    /** Para pruebas: pone una tabla de traducciones a mano. @param array<string, string> $tabla */
    public static function registrar(string $codigo, array $tabla): void
    {
        self::$tablas[$codigo] = $tabla;
    }

    /** Para pruebas: vuelve a leer los archivos de lang/ y deja el idioma en español. */
    public static function reiniciar(): void
    {
        self::$tablas = [];
        self::$faltas = [];
        self::$actual = self::DEFECTO;
        self::$registrarFaltas = false;
        self::$nombreCookie = self::COOKIE;
    }

    /** @return list<string> */
    public static function faltas(): array
    {
        return array_keys(self::$faltas);
    }

    public static function limpiarFaltas(): void
    {
        self::$faltas = [];
    }
}

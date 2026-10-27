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
    public const DISPONIBLES = ['es' => 'Español', 'en' => 'English'];

    public const COOKIE = 'portal_idioma';

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
    public static function de(AjustesService $aj, string $ownerTipo, string $ownerId, string $clienteId = ''): string
    {
        $v = $aj->get($ownerTipo, $ownerId, 'idioma');
        if (!self::valido($v)) {
            $v = (string) ($_COOKIE[self::COOKIE] ?? '');   // lo que eligió en la pantalla de ingreso
        }
        if (!self::valido($v) && $clienteId !== '') {
            $v = $aj->get('cliente', $clienteId, 'idioma');
        }
        if (!self::valido($v)) {
            $v = $aj->get('global', 'portal', 'idioma');
        }
        return self::valido($v) ? $v : self::DEFECTO;
    }

    /** Para las pantallas sin sesión (ingreso): cookie, luego el idioma del navegador, luego el de la agencia. */
    public static function delVisitante(string $deLaAgencia = self::DEFECTO): string
    {
        $c = (string) ($_COOKIE[self::COOKIE] ?? '');
        if (self::valido($c)) {
            return $c;
        }
        $h = strtolower((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
        foreach (explode(',', $h) as $parte) {
            $cod = substr(trim($parte), 0, 2);
            if (self::valido($cod)) {
                return $cod;
            }
        }
        return self::valido($deLaAgencia) ? $deLaAgencia : self::DEFECTO;
    }

    /** Idioma de quien aún no ingresó; si viene ?idioma=en lo recuerda para las próximas pantallas. */
    public static function visitante(string $deLaAgencia = self::DEFECTO): string
    {
        $elegido = (string) ($_GET['idioma'] ?? '');
        if (self::valido($elegido)) {
            self::recordar($elegido);
            $_COOKIE[self::COOKIE] = $elegido;
        }
        return self::delVisitante($deLaAgencia);
    }

    /** Deja la cookie con la elección (sirve para el ingreso y los correos de acceso). */
    public static function recordar(string $codigo): void
    {
        if (self::valido($codigo) && !headers_sent()) {
            setcookie(self::COOKIE, $codigo, [
                'expires'  => time() + 86400 * 365,
                'path'     => '/',
                'secure'   => !empty($_SERVER['HTTPS']),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
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
    public static function conTraducciones(string $js): string
    {
        if (self::$actual === self::DEFECTO || !preg_match_all("/\\bT\\('((?:[^'\\\\]|\\\\.)*)'\\)/u", $js, $m)) {
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

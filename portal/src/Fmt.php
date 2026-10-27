<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * Helpers de presentación en español de Chile, sin depender de locales del
 * servidor (en hosting compartido `setlocale` casi nunca tiene es_CL).
 * Las plantillas lo reciben como $fmt.
 */
final class Fmt
{
    public const ZONA = 'America/Santiago';

    private const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    private const DIAS  = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
    private const MESES_EN = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    private const DIAS_EN  = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    /** ¿Las fechas van en inglés? (mes primero: «Oct 12»). Los demás idiomas usan el orden del español. */
    private static function en(): bool
    {
        return Idioma::actual() === 'en';
    }

    private static function mes(\DateTimeImmutable $d): string
    {
        return (self::en() ? self::MESES_EN : self::MESES)[(int) $d->format('n') - 1];
    }

    private static function dia3(\DateTimeImmutable $d): string
    {
        return mb_substr((self::en() ? self::DIAS_EN : self::DIAS)[(int) $d->format('w')], 0, 3);
    }

    /** estado => [etiqueta, tono]. Los tonos son clases .chip-* de portal.css */
    public const ESTADOS = [
        'pendiente'   => ['Pendiente', 'muted'],
        'en_progreso' => ['En progreso', 'info'],
        'entregada'   => ['En revisión', 'warn'],
        'cambios'     => ['Cambios pedidos', 'danger'],
        'hecha'       => ['Lista', 'ok'],
    ];

    public const TIPOS = [
        'tarea'    => 'Tarea',
        'archivo'  => 'Pedir archivos',
        'revision' => 'Revisar y aprobar',
    ];

    private \DateTimeImmutable $ahora;

    public function __construct(?\DateTimeImmutable $ahora = null)
    {
        $this->ahora = $ahora ?? new \DateTimeImmutable();
    }

    private function parse(?string $s): ?\DateTimeImmutable
    {
        $s = trim((string) $s);
        if ($s === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($s);
        } catch (\Throwable) {
            return null;
        }
    }

    /** "12 oct" o "12 oct 2025" si no es del año en curso. */
    /** Primera letra en mayúscula («revisar logo» → «Revisar logo»; respeta «¿» y comillas al inicio). */
    public static function mayusculaInicial(string $s): string
    {
        return preg_replace_callback('/^([^\p{L}\p{N}]*)(\p{Ll})/u', fn($m) => $m[1] . mb_strtoupper($m[2]), $s) ?? $s;
    }

    public function fecha(?string $s): string
    {
        $d = $this->parse($s);
        if ($d === null) {
            return '—';
        }
        $anio = $d->format('Y') !== $this->ahora->format('Y');
        if (self::en()) {
            return self::mes($d) . ' ' . $d->format('j') . ($anio ? ', ' . $d->format('Y') : '');
        }
        $txt = $d->format('j') . ' ' . self::mes($d);
        return $anio ? $txt . ' ' . $d->format('Y') : $txt;
    }

    /** "martes 29 sep" */
    public function fechaLarga(?string $s = null): string
    {
        $d = $this->parse($s) ?? $this->ahora->setTimezone(new \DateTimeZone(Zona::agencia()));
        if (self::en()) {
            return self::DIAS_EN[(int) $d->format('w')] . ', ' . self::mes($d) . ' ' . $d->format('j');
        }
        return self::DIAS[(int) $d->format('w')] . ' ' . $d->format('j') . ' ' . self::mes($d);
    }

    /** "vie 2 oct" */
    public function fechaCorta(?string $s): string
    {
        $d = $this->parse($s);
        if ($d === null) {
            return '—';
        }
        return self::en()
            ? self::dia3($d) . ', ' . self::mes($d) . ' ' . $d->format('j')
            : self::dia3($d) . ' ' . $d->format('j') . ' ' . self::mes($d);
    }

    /** Rango "lun 5 al jue 8 oct" (o "vie 30 sep al mar 4 oct" si cambia el mes). */
    public function rangoFechas(string $desde, string $hasta): string
    {
        $a = $this->parse($desde);
        $b = $this->parse($hasta);
        if ($a === null || $b === null) {
            return '';
        }
        if ($a->format('Y-m-d') === $b->format('Y-m-d')) {
            return $this->fechaCorta($desde);
        }
        $ini = self::dia3($a) . ' ' . $a->format('j');
        if ($a->format('n') !== $b->format('n')) {
            $ini = self::en() ? self::dia3($a) . ', ' . self::mes($a) . ' ' . $a->format('j') : $ini . ' ' . self::mes($a);
        }
        return $ini . (self::en() ? ' – ' : ' al ') . $this->fechaCorta($hasta);
    }

    /** Bandera (emoji) de un código de país de dos letras. */
    public static function bandera(string $pais): string
    {
        $pais = strtoupper(trim($pais));
        if (preg_match('/^[A-Z]{2}$/', $pais) !== 1) {
            return '';
        }
        return mb_chr(0x1F1E6 + ord($pais[0]) - 65) . mb_chr(0x1F1E6 + ord($pais[1]) - 65);
    }

    public function dia(?string $s): string
    {
        $d = $this->parse($s);
        return $d === null ? '' : $d->format('j');
    }

    public function mesCorto(?string $s): string
    {
        $d = $this->parse($s);
        return $d === null ? '' : self::mes($d);
    }

    public function hora(?string $s): string
    {
        $d = $this->parse($s);
        if ($d === null || !str_contains((string) $s, ':')) {
            return '';
        }
        return self::en() ? strtolower($d->format('g:i a')) : $d->format('H:i');
    }

    /** "hace 5 min", "hace 3 h", "ayer", "12 oct". */
    public function rel(?string $s): string
    {
        $d = $this->parse($s);
        if ($d === null) {
            return '';
        }
        $seg = $this->ahora->getTimestamp() - $d->getTimestamp();
        if ($seg < 0) {
            return $this->fecha($s);
        }
        if ($seg < 60) {
            return Idioma::t('ahora');
        }
        if ($seg < 3600) {
            return Idioma::t('hace %d min', intdiv($seg, 60));
        }
        if ($seg < 86400) {
            return Idioma::t('hace %d h', intdiv($seg, 3600));
        }
        if ($seg < 172800) {
            return Idioma::t('ayer');
        }
        if ($seg < 7 * 86400) {
            return Idioma::t('hace %d días', intdiv($seg, 86400));
        }
        return $this->fecha($s);
    }

    /**
     * Estado de un vencimiento para pintar el chip.
     * @return array{texto: string, tono: string}
     */
    public function vence(?string $s, string $estado = 'pendiente'): array
    {
        $d = $this->parse($s);
        if ($d === null) {
            return ['texto' => Idioma::t('Sin fecha'), 'tono' => 'muted'];
        }
        if ($estado === 'hecha') {
            return ['texto' => $this->fecha($s), 'tono' => 'muted'];
        }
        $hoy  = $this->ahora->setTime(0, 0);
        $dias = (int) $hoy->diff($d->setTime(0, 0))->format('%r%a');
        if ($dias < 0) {
            return ['texto' => Idioma::t('Vencida') . ' · ' . $this->fecha($s), 'tono' => 'danger'];
        }
        if ($dias === 0) {
            return ['texto' => Idioma::t('Vence hoy'), 'tono' => 'warn'];
        }
        if ($dias === 1) {
            return ['texto' => Idioma::t('Vence mañana'), 'tono' => 'warn'];
        }
        if ($dias <= 7) {
            return ['texto' => Idioma::t('Vence en %d días', $dias), 'tono' => 'info'];
        }
        return ['texto' => Idioma::t('Vence el %s', $this->fecha($s)), 'tono' => 'muted'];
    }

    /** @return array{0: string, 1: string} */
    public function estado(string $estado): array
    {
        $e = self::ESTADOS[$estado] ?? [ucfirst($estado), 'muted'];
        return [Idioma::t($e[0]), $e[1]];
    }

    public function tipo(string $tipo): string
    {
        return Idioma::t(self::TIPOS[$tipo] ?? 'Tarea');
    }

    /** Paleta para distinguir proyectos (la del primero es la de marca por defecto). */
    public const COLORES_PROYECTO = ['#6366f1', '#f59e0b', '#10b981', '#ec4899', '#0ea5e9', '#ef4444', '#8b5cf6', '#14b8a6', '#f97316', '#84cc16'];

    /** Matiz (0–360) y saturación (0–1) de un #rrggbb; null si no es un color válido. */
    private static function matiz(string $hex): ?array
    {
        if (!preg_match('/^#?([0-9a-f]{6})$/i', $hex, $m)) {
            return null;
        }
        [$r, $g, $b] = array_map(fn($x) => hexdec($x) / 255, str_split($m[1], 2));
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $d = $max - $min;
        if ($d < 0.0001) {
            return [0.0, 0.0];
        }
        $h = match (true) {
            $max === $r => fmod(($g - $b) / $d, 6),
            $max === $g => ($b - $r) / $d + 2,
            default     => ($r - $g) / $d + 4,
        } * 60;
        $l = ($max + $min) / 2;
        return [$h < 0 ? $h + 360 : $h, $d / (1 - abs(2 * $l - 1))];
    }

    /**
     * Un color estable por proyecto, asignado por orden de creación dentro del cliente: dos proyectos
     * del mismo cliente nunca comparten color (hasta 10). Se evitan los tonos parecidos al color de
     * marca del cliente, para que el punto no se confunda con el resto de la interfaz.
     *
     * @param array<int, array<string, mixed>> $proyectos filas con 'id', ya ordenadas
     * @return array<string, string> id => #rrggbb
     */
    public static function coloresProyectos(array $proyectos, string $marca = ''): array
    {
        $paleta = self::COLORES_PROYECTO;
        $mh = self::matiz($marca);
        if ($mh !== null && $mh[1] > 0.15) {   // una marca casi gris no compite con ningún tono
            $lejos = array_values(array_filter($paleta, function ($c) use ($mh) {
                $ch = self::matiz($c);
                $dif = abs($ch[0] - $mh[0]);
                return min($dif, 360 - $dif) >= 35;
            }));
            if (count($lejos) >= 3) {
                $paleta = $lejos;
            }
        }
        $mapa = [];
        foreach (array_values($proyectos) as $i => $p) {
            $mapa[(string) $p['id']] = $paleta[$i % count($paleta)];
        }
        return $mapa;
    }

    /**
     * Color de todos los proyectos, calculado igual que en el portal de cada cliente
     * (por cliente, en orden de creación y lejos de su color de marca): el punto que ve
     * el equipo es el mismo que ve el cliente.
     *
     * @return array<string, string> proyecto_id => #rrggbb
     */
    public static function coloresTodos(\PDO $pdo): array
    {
        $porCliente = [];
        foreach ($pdo->query('SELECT id, cliente_id FROM portal_proyectos ORDER BY created_at, id')->fetchAll() as $p) {
            $porCliente[(string) $p['cliente_id']][] = $p;
        }
        $aj = new AjustesService($pdo);
        $mapa = [];
        foreach ($porCliente as $cid => $lista) {
            $mapa += self::coloresProyectos($lista, AjustesService::colorValido($aj->get('cliente', (string) $cid, 'color')));
        }
        return $mapa;
    }

    /** Paleta para clientes sin color de marca: tonos bien separados entre sí. */
    public const COLORES_CLIENTE = ['#2563eb', '#ea580c', '#16a34a', '#db2777', '#7c3aed', '#0891b2', '#ca8a04', '#dc2626', '#0d9488', '#9333ea', '#65a30d', '#c2410c', '#4f46e5', '#be185d'];

    /**
     * Identidad de cada cliente en las listas del equipo: su logo (si tiene), su color y sus
     * iniciales. El color es el de su marca; si no tiene, uno de la paleta, eligiendo el tono
     * más lejano a los que ya usan los demás (así dos clientes no quedan iguales).
     *
     * @return array<string, array{color: string, texto: string, logo: ?string}>
     */
    public static function marcasClientes(\PDO $pdo): array
    {
        $aj = new AjustesService($pdo);
        $clientes = $pdo->query('SELECT id FROM portal_clientes ORDER BY created_at, id')->fetchAll(\PDO::FETCH_COLUMN);
        $out = [];
        $usados = [];
        $sinMarca = [];
        foreach ($clientes as $cid) {
            $cfg = $aj->todos('cliente', (string) $cid);
            $logo = !empty($cfg['logo_id']) ? '/portal/marca/cliente/' . rawurlencode((string) $cid) : null;
            $color = AjustesService::colorValido($cfg['color'] ?? '');
            // Su color de marca, salvo que otro cliente ya tenga uno casi igual (ahí recibe uno de la paleta).
            $choca = false;
            foreach ($usados as $u) {
                $a = self::matiz($color);
                $b = self::matiz($u);
                if ($a !== null && $b !== null && min(abs($a[0] - $b[0]), 360 - abs($a[0] - $b[0])) < 14 && abs($a[1] - $b[1]) < 0.35) {
                    $choca = true;
                    break;
                }
            }
            if (!empty($cfg['color']) && self::matiz($color) !== null && !$choca) {
                $out[(string) $cid] = ['color' => $color, 'logo' => $logo];
                $usados[] = $color;
            } else {
                $sinMarca[(string) $cid] = $logo;
            }
        }
        foreach ($sinMarca as $cid => $logo) {
            $mejor = self::COLORES_CLIENTE[0];
            $mejorDist = -1.0;
            foreach (self::COLORES_CLIENTE as $c) {
                $h = self::matiz($c)[0];
                $dist = 360.0;
                foreach (array_slice($usados, -6) as $u) {   // basta con no repetir los más cercanos en la lista
                    $mu = self::matiz($u);
                    if ($mu !== null) {
                        $d = abs($h - $mu[0]);
                        $dist = min($dist, min($d, 360 - $d));
                    }
                }
                if (in_array($c, $usados, true)) {
                    $dist -= 360;
                }
                if ($dist > $mejorDist) {
                    [$mejor, $mejorDist] = [$c, $dist];
                }
            }
            $out[$cid] = ['color' => $mejor, 'logo' => $logo];
            $usados[] = $mejor;
        }
        foreach ($out as &$m) {
            $m['texto'] = Paleta::marca($m['color'])['texto'];
        }
        return $out;
    }

    public function iniciales(string $nombre): string
    {
        $partes = preg_split('/\s+/u', trim($nombre)) ?: [];
        $out = '';
        foreach (array_slice(array_filter($partes), 0, 2) as $p) {
            $out .= mb_strtoupper(mb_substr($p, 0, 1));
        }
        return $out !== '' ? $out : '·';
    }

    public function primerNombre(string $nombre): string
    {
        $p = preg_split('/\s+/u', trim($nombre)) ?: [];
        return $p[0] ?? $nombre;
    }

    public function tamano(int|string|null $bytes): string
    {
        $b = (float) $bytes;
        if ($b < 1024) {
            return (int) $b . ' B';
        }
        $coma = self::en() ? '.' : ',';
        if ($b < 1048576) {
            return number_format($b / 1024, 0) . ' KB';
        }
        if ($b < 1073741824) {
            return number_format($b / 1048576, 1, $coma, '') . ' MB';
        }
        return number_format($b / 1073741824, 2, $coma, '') . ' GB';
    }

    public function saludo(): string
    {
        $h = (int) $this->ahora->setTimezone(new \DateTimeZone(Zona::agencia()))->format('G');
        return Idioma::t($h < 6 ? 'Buenas noches' : ($h < 13 ? 'Buenos días' : ($h < 20 ? 'Buenas tardes' : 'Buenas noches')));
    }

    /** Día del año en Chile: elige la frase de bienvenida del día. */
    public function diaDelAnio(): int
    {
        return (int) $this->ahora->setTimezone(new \DateTimeZone(Zona::agencia()))->format('z');
    }

    /** Frase de actividad: "Ana comentó en «Logo»". */
    public function actividad(array $a, bool $vistaAdmin = false): string
    {
        $quien = $a['actor_tipo'] === 'equipo' ? ($vistaAdmin ? (string) $a['actor_nombre'] : Idioma::t('El equipo')) : $a['actor_nombre'];
        $que = match ($a['accion']) {
            'comento'       => 'comentó en',
            'subio_archivo' => 'subió un archivo en',
            'entrego'       => 'entregó',
            'completo'      => 'completó',
            'aprobo'        => 'aprobó',
            'pidio_cambios' => 'pidió cambios en',
            'asigno'        => $vistaAdmin ? 'asignó al cliente' : 'te pidió',
            'publico'       => $vistaAdmin ? 'publicó para el cliente' : 'te compartió',
            'subio_version' => 'subió una versión nueva de',
            'respondio'     => 'terminó la revisión de',
            'reunion'       => $vistaAdmin ? 'compartió novedades de la reunión' : 'compartió novedades de la reunión',
            'solicito'      => 'hizo una solicitud:',
            'atendio'       => $vistaAdmin ? 'atendió la solicitud' : 'respondió tu solicitud',
            'cotizo'        => $vistaAdmin ? 'envió la cotización' : 'te envió la cotización de',
            'decidio'       => 'respondió la cotización de',
            'estado'        => 'cambió el estado de',
            default         => 'actualizó',
        };
        return $quien . ' ' . Idioma::t($que);
    }

    /** Ícono (id del sprite) según la acción. */
    public function iconoActividad(string $accion): string
    {
        // Mismo ícono que la sección del menú cuando la acción es «de» esa sección.
        return match ($accion) {
            'comento'                  => 'i-chat',      // conversación
            'subio_archivo', 'entrego' => 'i-clip',      // archivos
            'completo', 'aprobo', 'estado', 'decidio' => 'i-check', // algo quedó listo o resuelto
            'pidio_cambios'            => 'i-edit',      // hay que corregir
            'asigno'                   => 'i-tasks',     // Tareas
            'publico', 'subio_version' => 'i-eye',       // Contenidos
            'respondio'                => 'i-send',      // el cliente envió su revisión
            'reunion'                  => 'i-calendar',  // Reuniones
            'solicito'                 => 'i-plus',      // Solicitudes
            'atendio', 'cotizo'        => 'i-inbox',     // respuesta a una solicitud
            default                    => 'i-edit',
        };
    }

    /** true si el mime es una imagen que podemos mostrar en <img>. */
    public function esImagen(?string $mime): bool
    {
        return in_array((string) $mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true);
    }

    public function extension(string $nombre): string
    {
        $e = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
        return $e !== '' ? $e : 'file';
    }
}

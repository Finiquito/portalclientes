<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * Las listas recuerdan su filtro, orden y página: al volver desde otra pantalla (por ejemplo,
 * después de editar un ítem) se ven como se dejaron.
 *
 * - Con parámetros en la URL: se guardan en la sesión para esa lista.
 * - Sin parámetros y llegando desde otra página del sitio: se vuelve a la última versión guardada.
 * - Sin parámetros desde la misma lista («Todas», «Quitar filtros»): se olvida lo guardado.
 * - Sin página de origen (URL escrita a mano, marcador): se muestra la lista tal cual.
 */
final class ListaRecordada
{
    private const SESION = 'portal_listas';

    /**
     * @param string $base ruta de la lista (puede traer dominio; se usa sólo la ruta)
     * @return string|null la query a la que hay que volver, o null si la lista se muestra tal cual
     */
    public static function restaurar(string $base): ?string
    {
        if (!isset($_SESSION) || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            return null;
        }
        $ruta = (string) parse_url($base, PHP_URL_PATH);
        if ($ruta === '') {
            return null;
        }
        if ($_GET !== []) {
            $_SESSION[self::SESION][$ruta] = http_build_query($_GET);
            return null;
        }
        $origen = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        if ($origen === '') {
            return null;
        }
        $desde = (string) parse_url($origen, PHP_URL_PATH);
        $host = (string) parse_url($origen, PHP_URL_HOST);
        if ($host !== '' && $host !== explode(':', (string) ($_SERVER['HTTP_HOST'] ?? $host))[0]) {
            return null;
        }
        if ($desde === $ruta) {
            unset($_SESSION[self::SESION][$ruta]);
            return null;
        }
        $q = (string) ($_SESSION[self::SESION][$ruta] ?? '');
        return $q !== '' ? $q : null;
    }

    /** Como restaurar(), pero devuelve la URL completa (ruta + query) a la que redirigir. */
    public static function volverA(string $base): ?string
    {
        $q = self::restaurar($base);
        return $q !== null ? strtok($base, '?') . '?' . $q : null;
    }
}

<?php
declare(strict_types=1);

/**
 * t('Texto %s', $valor): traduce al idioma activo. Las plantillas del portal la usan; está acá, como
 * función global, para que también la encuentren las pantallas de gestión cuando las dibuja el admin
 * de TypeDock (donde el idioma siempre es el español).
 */
if (!function_exists('t')) {
    function t(string $texto, string|int|float ...$valores): string
    {
        return \TypeDock\Plugin\Portal\Idioma::t($texto, ...$valores);
    }
}

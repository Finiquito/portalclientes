<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/** Código de acceso de los usuarios de agencia (front /equipo). */
class EquipoAuthService extends CodigoAccesoService
{
    protected const TABLA_CODIGOS  = 'portal_equipo_codigos';
    protected const TABLA_INTENTOS = 'portal_equipo_intentos';
    protected const COLUMNA        = 'usuario_id';
}

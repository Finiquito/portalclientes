<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/** Código de acceso de los contactos de cliente (portal /portal). */
class ContactoAuthService extends CodigoAccesoService
{
    protected const TABLA_CODIGOS  = 'portal_contacto_codigos';
    protected const TABLA_INTENTOS = 'portal_login_intentos';
    protected const COLUMNA        = 'contacto_id';
}

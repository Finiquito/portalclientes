<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * Pantallas del asistente de arranque (/equipo/bienvenida). Sólo para Coordinación general y mientras el
 * asistente esté activo (ver OnboardingService). Las llamadas de guardado, logo y término responden JSON.
 */
class EquipoOnboarding extends EquipoController
{
    private function servicio(): OnboardingService
    {
        return new OnboardingService($this->pdo());
    }

    /** @param array<string, mixed> $d */
    protected function json(array $d, int $codigo = 200): void
    {
        http_response_code($codigo);
        header('Content-Type: application/json; charset=utf-8');
        if (isset($d['error']) && is_string($d['error'])) {
            $d['error'] = Idioma::t($d['error']);   // los avisos se escriben en español y salen en el idioma de quien usa el asistente
        }
        echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->terminate();
    }

    /** @return array<string, mixed>|null el usuario, o null si ya se respondió (redirección o error JSON) */
    private function usuarioDelAsistente(bool $json): ?array
    {
        $u = $json ? $this->usuarioActual() : $this->requerirUsuario();
        if ($u === null) {
            $this->json(['ok' => false, 'error' => 'Tu sesión expiró. Vuelve a entrar.'], 401);
            return null;
        }
        if (($u['rol'] ?? '') !== 'coordinador' || !$this->servicio()->activo()) {
            if ($json) {
                $this->json(['ok' => false, 'error' => 'El asistente ya no está disponible.'], 403);
            } else {
                $this->redirectTo('/equipo');
            }
            return null;
        }
        if ($json && !PortalSession::csrfValido()) {
            $this->json(['ok' => false, 'error' => 'Tu sesión expiró. Recarga la página.'], 400);
            return null;
        }
        return $u;
    }

    public function pagina(): void
    {
        $u = $this->usuarioDelAsistente(false);
        if ($u === null) {
            return;
        }
        PortalSession::iniciar();
        unset($_SESSION['portal_onb_omitido']);   // volvió a propósito
        $svc = $this->servicio();
        $marca = $this->marca();
        $datos = [
            'estado'      => $svc->estado($u),
            'plantillas'  => $svc->plantillas(),
            'logo'        => $marca['logo'],
            'correoPrisma' => Compartido::correo() !== null,
            'iaPrueba'    => Compartido::ia() !== null,
            'horas'       => OnboardingService::INVITACION_HORAS,
            'colores'     => array_map(fn(array $c): array => [$c[0], Idioma::t($c[1])], self::COLORES),
            'idiomas'     => array_map(null, array_keys(Idioma::DISPONIBLES), array_values(Idioma::DISPONIBLES)),
            'idiomaAgencia' => Idioma::de($this->ajustes(), 'global', 'portal'),
            'idiomaActual' => Idioma::actual(),
            'condiciones' => [
                'version' => OnboardingService::CONDICIONES_VERSION,
                'fecha'   => OnboardingService::CONDICIONES_FECHA,
                'url'     => Compartido::entorno('PORTAL_URL_CONDICIONES') ?: OnboardingService::CONDICIONES_URL,
            ],
        ];
        $this->view('bienvenida.latte', [
            'csrf'      => PortalSession::csrf(),
            'datosJson' => json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
            'titulo'    => Idioma::t('Bienvenida a Prisma'),
        ]);
    }

    /** Dos filas de seis: la primera de la marca, la segunda Material. Todos pasan contraste (ver dev/maquetas/colores.html). */
    public const COLORES = [
        ['#d54318', 'Naranjo Prisma'], ['#2f5bea', 'Azul'], ['#038567', 'Verde'], ['#8a4fd6', 'Violeta'], ['#d4387b', 'Rosa'], ['#2b2f3a', 'Grafito'],
        ['#ffc107', 'Ámbar'], ['#2196f3', 'Azul claro'], ['#5d7a88', 'Gris azulado'], ['#795548', 'Café'], ['#00bcd4', 'Cian'], ['#30dfc4', 'Verde turquesa'],
    ];

    public function guardar(): void
    {
        $u = $this->usuarioDelAsistente(true);
        if ($u === null) {
            return;
        }
        $d = json_decode((string) ($_POST['datos'] ?? ''), true);
        if (!is_array($d)) {
            $this->json(['ok' => false, 'error' => 'No entendimos lo que enviaste.'], 400);
            return;
        }
        $this->json(['ok' => true, 'estado' => $this->servicio()->guardar($d, $u)]);
    }

    public function logo(): void
    {
        $u = $this->usuarioDelAsistente(true);
        if ($u === null) {
            return;
        }
        $f = $_FILES['logo'] ?? null;
        if (!is_array($f) || (int) ($f['error'] ?? 4) !== 0) {
            $this->json(['ok' => false, 'error' => 'No llegó el archivo.'], 400);
            return;
        }
        $m = new MarcaService($this->pdo());
        $err = $m->guardarLogoAgencia($f);
        if ($err !== null) {
            $this->json(['ok' => false, 'error' => $err], 422);
            return;
        }
        $r = $m->rutaLogoAgencia();
        $this->json(['ok' => true, 'url' => $r !== null ? '/portal/marca/agencia?v=' . (int) filemtime($r) : null]);
    }

    public function finalizar(): void
    {
        $u = $this->usuarioDelAsistente(true);
        if ($u === null) {
            return;
        }
        try {
            $r = $this->servicio()->finalizar($u, new Notifier($this->ctx, $this->pdo()));
        } catch (\Throwable $e) {
            error_log('[portal] onboarding: ' . $e->getMessage());
            $this->json(['ok' => false, 'error' => 'No pudimos terminar de armar todo. Inténtalo de nuevo en un momento.'], 500);
            return;
        }
        if (empty($r['ok'])) {
            $this->json($r, 422);
            return;
        }
        PortalSession::iniciar();
        unset($_SESSION['portal_onb_omitido']);
        $this->json($r + ['redirect' => '/equipo/clientes/' . $r['cliente_id']]);
    }

    /** «Guardar y salir»: el panel se abre y un aviso invita a continuar cuando quiera. */
    public function omitir(): void
    {
        $u = $this->usuarioDelAsistente(true);
        if ($u === null) {
            return;
        }
        PortalSession::iniciar();
        $_SESSION['portal_onb_omitido'] = 1;
        $this->json(['ok' => true, 'redirect' => '/equipo']);
    }
}

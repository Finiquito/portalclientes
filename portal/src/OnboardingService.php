<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * Asistente de arranque de una agencia beta (/equipo/bienvenida): agencia → cliente → proyecto → equipo → listo.
 *
 * - Sólo existe donde `prisma crear` dejó PORTAL_ONBOARDING=1 y hasta que se termina (ajuste «onb_completo»).
 * - Cada pantalla se guarda al tiro. Agencia (nombre y color) se aplica de inmediato; cliente, proyecto y equipo
 *   quedan en un borrador y se crean de una vez al terminar (idempotente: si algo falla se puede reintentar).
 * - La invitación al contacto sale por defecto en 6 horas (INVITACION_HORAS), para dar tiempo a cargar tareas y
 *   revisar el proyecto; se puede adelantar o cancelar desde la ficha del cliente.
 */
final class OnboardingService
{
    public const INVITACION_HORAS = 6;
    public const COLOR_DEFECTO = '#d54318';

    private const CLAVE_ESTADO = 'onb_estado';
    private const CLAVE_LISTO  = 'onb_completo';

    public function __construct(private readonly \PDO $pdo) {}

    private function ajustes(): AjustesService
    {
        return new AjustesService($this->pdo);
    }

    /** ¿Esta instalación usa el asistente y todavía no se terminó? */
    public function activo(): bool
    {
        if (Compartido::entorno('PORTAL_ONBOARDING') !== '1') {
            return false;
        }
        try {
            return $this->ajustes()->get('global', 'portal', self::CLAVE_LISTO) !== '1';
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    public function estado(array $usuario): array
    {
        $a = $this->ajustes();
        $g = json_decode($a->get('global', 'portal', self::CLAVE_ESTADO), true);
        $g = is_array($g) ? $g : [];
        $base = [
            'paso' => 1, 'max' => 1,
            'tuNombre' => (string) ($usuario['nombre'] ?? ''),
            'agencia' => trim($a->get('global', 'portal', 'nombre_equipo')),
            'color' => AjustesService::colorValido($a->get('global', 'portal', 'color_agencia'), self::COLOR_DEFECTO),
            'cliente' => '', 'contacto' => '', 'correo' => '', 'idiomaCliente' => '', 'proyecto' => '', 'plantilla' => '',
            'equipo' => [], 'invitar' => true, 'hechos' => [], 'idiomaElegido' => false,
        ];
        $e = array_merge($base, array_intersect_key($g, $base));
        // El idioma se pregunta primero: no se vuelve a preguntar si ya se eligió, si la agencia ya trae uno o si el asistente va avanzado (la agencia sí trae nombre desde `prisma crear`, por eso no cuenta).
        $e['idiomaElegido'] = !empty($e['idiomaElegido']) || Idioma::valido($a->get('global', 'portal', 'idioma')) || (int) $e['max'] > 1;
        $e['paso'] = max(1, min(5, (int) $e['paso']));
        $e['max'] = max($e['paso'], min(5, (int) $e['max']));
        if ($e['plantilla'] === '') {
            $e['plantilla'] = ($this->plantillas()[0]['id'] ?? 'blanco');
        }
        return $e;
    }

    /** Las plantillas de proyecto de la agencia, con sus etapas en una línea. @return list<array{id: string, nombre: string, etapas: string}> */
    public function plantillas(): array
    {
        $out = [];
        foreach ((new PlantillaService($this->pdo))->lista() as $p) {
            // Las tres plantillas de fábrica tienen traducción; las que crea la agencia salen como las escribió.
            $out[] = ['id' => (string) $p['id'], 'nombre' => Idioma::t((string) $p['nombre']),
                'etapas' => implode(' · ', array_map(fn($f) => (string) ($f['nombre'] ?? ''), $p['e']['fases']))];
        }
        return $out;
    }

    /**
     * Guarda lo que llegó de una pantalla. Devuelve el estado ya saneado.
     *
     * @param array<string, mixed> $d
     * @return array<string, mixed>
     */
    public function guardar(array $d, array $usuario): array
    {
        $e = $this->estado($usuario);
        $txt = static fn($v, int $max) => mb_substr(trim((string) $v), 0, $max);
        foreach (['tuNombre' => 120, 'agencia' => 120, 'cliente' => 160, 'contacto' => 160, 'proyecto' => 160] as $k => $max) {
            if (array_key_exists($k, $d)) {
                $e[$k] = $txt($d[$k], $max);
            }
        }
        if (array_key_exists('correo', $d)) {
            $e['correo'] = strtolower($txt($d['correo'], 190));
        }
        if (isset($d['idioma']) && Idioma::valido((string) $d['idioma'])) {
            // El idioma elegido al empezar es el de la agencia (lo que ven quienes no eligieron otro) y el de quien usa el asistente.
            $a0 = $this->ajustes();
            $a0->set('global', 'portal', 'idioma', (string) $d['idioma']);
            if (!empty($usuario['id'])) {
                $a0->set('equipo', (string) $usuario['id'], 'idioma', (string) $d['idioma']);
            }
            $e['idiomaElegido'] = true;
            Idioma::recordar((string) $d['idioma']);
        }
        if (array_key_exists('idiomaCliente', $d)) {
            // Vacío = el del cliente hereda el de la agencia; si no, es el que se elige para su portal y sus correos.
            $e['idiomaCliente'] = Idioma::valido((string) $d['idiomaCliente']) ? (string) $d['idiomaCliente'] : '';
        }
        if (array_key_exists('color', $d)) {
            $e['color'] = AjustesService::colorValido((string) $d['color'], $e['color']);
        }
        if (array_key_exists('plantilla', $d)) {
            $ids = array_column($this->plantillas(), 'id');
            $e['plantilla'] = in_array($d['plantilla'], $ids, true) || $d['plantilla'] === 'blanco' ? (string) $d['plantilla'] : $e['plantilla'];
        }
        if (array_key_exists('equipo', $d) && is_array($d['equipo'])) {
            $lista = [];
            foreach ($d['equipo'] as $m) {
                $m = strtolower($txt($m, 190));
                if (filter_var($m, FILTER_VALIDATE_EMAIL) !== false && !in_array($m, $lista, true) && $m !== strtolower((string) ($usuario['email'] ?? ''))) {
                    $lista[] = $m;
                }
                if (count($lista) >= 10) {
                    break;
                }
            }
            $e['equipo'] = $lista;
        }
        if (array_key_exists('invitar', $d)) {
            $e['invitar'] = filter_var($d['invitar'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($d['paso'])) {
            $e['paso'] = max(1, min(5, (int) $d['paso']));
            $e['max'] = max($e['max'], $e['paso']);
        }

        // La agencia se aplica de inmediato: el panel ya sale con su nombre y su color.
        $a = $this->ajustes();
        if ($e['agencia'] !== '') {
            $a->set('global', 'portal', 'nombre_equipo', $e['agencia']);
        }
        $a->set('global', 'portal', 'color_agencia', $e['color']);
        if ($e['tuNombre'] !== '' && $e['tuNombre'] !== (string) ($usuario['nombre'] ?? '') && !empty($usuario['id'])) {
            $this->pdo->prepare('UPDATE portal_equipo SET nombre = ?, updated_at = ? WHERE id = ?')
                ->execute([$e['tuNombre'], (new \DateTimeImmutable())->format('Y-m-d H:i:s'), $usuario['id']]);
        }
        $a->set('global', 'portal', self::CLAVE_ESTADO, json_encode($e, JSON_UNESCAPED_UNICODE));
        return $e;
    }

    /** ¿Qué falta para terminar? @return array{paso: int, campo: string, mensaje: string}|null */
    public function falta(array $e): ?array
    {
        if ($e['tuNombre'] === '') {
            return ['paso' => 1, 'campo' => 'tuNombre', 'mensaje' => Idioma::t('Escribe tu nombre.')];
        }
        if ($e['agencia'] === '') {
            return ['paso' => 1, 'campo' => 'agencia', 'mensaje' => Idioma::t('Escribe el nombre de tu agencia.')];
        }
        if ($e['cliente'] === '') {
            return ['paso' => 2, 'campo' => 'cliente', 'mensaje' => Idioma::t('Escribe el nombre del cliente.')];
        }
        if ($e['contacto'] === '') {
            return ['paso' => 2, 'campo' => 'contacto', 'mensaje' => Idioma::t('Escribe el nombre del contacto.')];
        }
        if (filter_var($e['correo'], FILTER_VALIDATE_EMAIL) === false) {
            return ['paso' => 2, 'campo' => 'correo', 'mensaje' => Idioma::t('Escribe un correo válido.')];
        }
        if ($e['proyecto'] === '') {
            return ['paso' => 3, 'campo' => 'proyecto', 'mensaje' => Idioma::t('Escribe el nombre del proyecto.')];
        }
        return null;
    }

    /**
     * Crea cliente, contacto, proyecto (con su plantilla), equipo e invitación. Idempotente.
     *
     * @return array{ok: bool, error?: string, paso?: int, campo?: string, cliente_id?: string, invitacion?: ?string}
     */
    public function finalizar(array $usuario, Notifier $notificador): array
    {
        $e = $this->estado($usuario);
        if (($f = $this->falta($e)) !== null) {
            return ['ok' => false, 'error' => $f['mensaje'], 'paso' => $f['paso'], 'campo' => $f['campo']];
        }
        $eqSvc = new EquipoService($this->pdo);
        // El correo del contacto no puede ser de una persona de la agencia (una casilla no entra a los dos portales).
        if ($eqSvc->findByEmail($e['correo']) !== null) {
            return ['ok' => false, 'error' => Idioma::t('Ese correo es de una persona de tu agencia. Usa el del contacto del cliente.'), 'paso' => 2, 'campo' => 'correo'];
        }
        $existente = (new ContactoService($this->pdo))->findByEmail($e['correo']);
        $h = is_array($e['hechos']) ? $e['hechos'] : [];
        if ($existente !== null && ($h['contacto_id'] ?? '') !== $existente['id']) {
            return ['ok' => false, 'error' => Idioma::t('Ya hay un contacto con ese correo.'), 'paso' => 2, 'campo' => 'correo'];
        }

        $uid = (string) $usuario['id'];
        if (empty($h['cliente_id'])) {
            $h['cliente_id'] = (new ClienteService($this->pdo))->create(['nombre' => $e['cliente'], 'empresa' => $e['cliente'], 'pais' => Zona::pais(), 'responsable_id' => $uid]);
        }
        $cid = (string) $h['cliente_id'];
        // El idioma del cliente se fija antes de programar su invitación: así ese correo, y todos los que le lleguen, salen en él.
        if ($e['idiomaCliente'] !== '') {
            $this->ajustes()->set('cliente', $cid, 'idioma', $e['idiomaCliente']);
        }
        if (empty($h['contacto_id'])) {
            $h['contacto_id'] = (new ContactoService($this->pdo))->create(['cliente_id' => $cid, 'nombre' => $e['contacto'], 'email' => $e['correo'], 'rol' => 'aprobador']);
        }
        if (empty($h['proyecto_id'])) {
            $h['proyecto_id'] = (new ProyectoService($this->pdo))->create(['cliente_id' => $cid, 'nombre' => $e['proyecto'], 'estado' => 'activo']);
            if ($e['plantilla'] !== '' && $e['plantilla'] !== 'blanco') {
                (new PlantillaService($this->pdo))->aplicar($e['plantilla'], (string) $h['proyecto_id'], Zona::hoy());
            }
        }
        $pid = (string) $h['proyecto_id'];

        // Equipo: cada persona queda dada de alta, asignada al cliente e invitada al panel (si el correo falla, igual queda).
        $enviados = is_array($h['equipo'] ?? null) ? $h['equipo'] : [];
        foreach ($e['equipo'] as $mail) {
            if (in_array($mail, $enviados, true) || $eqSvc->findByEmail($mail) !== null) {
                continue;
            }
            $datos = ['nombre' => ucfirst(trim((string) preg_replace('/[._\-+\d]+/', ' ', explode('@', $mail)[0]))), 'email' => $mail, 'rol' => 'equipo', 'activo' => 1];
            if ($eqSvc->error($datos) !== null) {
                continue;
            }
            $pid2 = $eqSvc->create($datos);
            $eqSvc->asignar($pid2, $cid);
            try {
                $notificador->invitacionEquipo($eqSvc->find($pid2) ?? $datos);
            } catch (\Throwable) {
            }
            $enviados[] = $mail;
        }
        $h['equipo'] = $enviados;

        $cuando = null;
        if ($e['invitar'] && empty($h['invitacion'])) {
            $contacto = (new ContactoService($this->pdo))->find((string) $h['contacto_id']);
            if ($contacto !== null) {
                try {
                    $cuando = $notificador->programarInvitacionCliente($contacto, self::INVITACION_HORAS);
                    $h['invitacion'] = $cuando;
                } catch (\Throwable) {
                }
            }
        }

        $e['hechos'] = $h;
        $e['paso'] = 5;
        $a = $this->ajustes();
        $a->set('global', 'portal', self::CLAVE_ESTADO, json_encode($e, JSON_UNESCAPED_UNICODE));
        $a->set('global', 'portal', self::CLAVE_LISTO, '1');
        return ['ok' => true, 'cliente_id' => $cid, 'invitacion' => $h['invitacion'] ?? null];
    }
}

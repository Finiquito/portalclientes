<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * Usuarios de agencia (front /equipo) y sus asignaciones de clientes/proyectos.
 * Los administra un admin de TypeDock en Portal · Equipo.
 */
class EquipoService
{
    public const ROLES = [
        'equipo'      => 'Equipo (sólo lo asignado)',
        'coordinador' => 'Coordinación (todos los clientes)',
    ];

    public function __construct(private readonly \PDO $pdo) {}

    private static function ahora(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }

    /** @return array<int, array<string, mixed>> */
    public function listAll(): array
    {
        return $this->pdo->query(
            'SELECT e.*, (SELECT COUNT(*) FROM portal_equipo_asignaciones a WHERE a.usuario_id = e.id) AS asignaciones
             FROM portal_equipo e ORDER BY e.activo DESC, e.nombre'
        )->fetchAll();
    }

    /**
     * Qué ve cada persona, en palabras: «Panadería Ruiz · Clínica Dental Sur: Sitio web».
     * @return array<string, string> usuarioId => texto
     */
    public function resumenAsignaciones(): array
    {
        $filas = $this->pdo->query(
            'SELECT a.usuario_id, c.nombre AS cliente, p.nombre AS proyecto
             FROM portal_equipo_asignaciones a
             JOIN portal_clientes c ON c.id = a.cliente_id
             LEFT JOIN portal_proyectos p ON p.id = a.proyecto_id
             ORDER BY c.nombre, p.nombre'
        )->fetchAll();
        $por = [];
        foreach ($filas as $f) {
            $u = (string) $f['usuario_id'];
            $c = (string) $f['cliente'];
            if ($f['proyecto'] === null) {
                $por[$u][$c] = null;          // cliente completo
            } elseif (!array_key_exists($c, $por[$u] ?? []) || $por[$u][$c] !== null) {
                $por[$u][$c][] = (string) $f['proyecto'];
            }
        }
        $out = [];
        foreach ($por as $u => $clientes) {
            $out[$u] = implode(' · ', array_map(
                fn($c, $ps) => $ps === null ? $c : $c . ': ' . implode(', ', $ps),
                array_keys($clientes), $clientes
            ));
        }
        return $out;
    }

    /** Suma una asignación (cliente completo si $proyectoId es null) sin tocar las demás. */
    public function asignar(string $usuarioId, string $clienteId, ?string $proyectoId = null): void
    {
        $ya = $this->pdo->prepare('SELECT COUNT(*) FROM portal_equipo_asignaciones WHERE usuario_id = ? AND cliente_id = ? AND (proyecto_id IS NULL OR proyecto_id = ?)');
        $ya->execute([$usuarioId, $clienteId, (string) $proyectoId]);
        if ((int) $ya->fetchColumn() > 0) {
            return;
        }
        if ($proyectoId === null) {
            // El cliente completo reemplaza los proyectos sueltos de ese cliente.
            $this->pdo->prepare('DELETE FROM portal_equipo_asignaciones WHERE usuario_id = ? AND cliente_id = ?')->execute([$usuarioId, $clienteId]);
        }
        $this->pdo->prepare('INSERT INTO portal_equipo_asignaciones (id, usuario_id, cliente_id, proyecto_id, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([typedock_uuid7(), $usuarioId, $clienteId, $proyectoId, self::ahora()]);
    }

    /** Quita una asignación puntual (el cliente completo o un proyecto suelto). */
    public function desasignar(string $usuarioId, string $clienteId, ?string $proyectoId = null): void
    {
        if ($proyectoId === null) {
            $this->pdo->prepare('DELETE FROM portal_equipo_asignaciones WHERE usuario_id = ? AND cliente_id = ?')->execute([$usuarioId, $clienteId]);
            return;
        }
        $this->pdo->prepare('DELETE FROM portal_equipo_asignaciones WHERE usuario_id = ? AND cliente_id = ? AND proyecto_id = ?')->execute([$usuarioId, $clienteId, $proyectoId]);
    }

    /**
     * Quién trabaja en un cliente: personas (no Coordinación) con el cliente completo o algún proyecto.
     * @return array<int, array<string, mixed>> con 'completo' (bool) y 'proyectos' (nombres)
     */
    public function delCliente(string $clienteId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT e.id, e.nombre, e.cargo, e.email, a.proyecto_id, p.nombre AS proyecto
             FROM portal_equipo_asignaciones a
             JOIN portal_equipo e ON e.id = a.usuario_id AND e.activo = 1 AND e.rol <> 'coordinador'
             LEFT JOIN portal_proyectos p ON p.id = a.proyecto_id
             WHERE a.cliente_id = ? ORDER BY e.nombre"
        );
        $stmt->execute([$clienteId]);
        $out = [];
        foreach ($stmt->fetchAll() as $f) {
            $id = (string) $f['id'];
            $out[$id] ??= ['id' => $id, 'nombre' => $f['nombre'], 'cargo' => $f['cargo'], 'email' => $f['email'], 'completo' => false, 'proyectos' => [], 'proyecto_ids' => []];
            if ($f['proyecto_id'] === null) {
                $out[$id]['completo'] = true;
            } else {
                $out[$id]['proyectos'][] = (string) $f['proyecto'];
                $out[$id]['proyecto_ids'][] = (string) $f['proyecto_id'];
            }
        }
        return array_values($out);
    }

    /** @return array<int, array<string, mixed>> Usuarios activos (para elegir responsables). */
    /** Id de la única persona activa del equipo, o null si hay ninguna o más de una. */
    public function unico(): ?string
    {
        $act = $this->activos();
        return count($act) === 1 ? (string) $act[0]['id'] : null;
    }

    public function activos(): array
    {
        return $this->pdo->query('SELECT id, nombre, email, cargo, rol FROM portal_equipo WHERE activo = 1 ORDER BY nombre')->fetchAll();
    }

    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM portal_equipo WHERE id = ?');
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        return $r !== false ? $r : null;
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM portal_equipo WHERE email = ?');
        $stmt->execute([self::email($email)]);
        $r = $stmt->fetch();
        return $r !== false ? $r : null;
    }

    public static function email(string $e): string
    {
        return mb_substr(trim(strtolower($e)), 0, 255);
    }

    /**
     * @param array<string, mixed> $p
     * @return array<string, mixed>
     */
    private function normalizar(array $p): array
    {
        return [
            'nombre' => mb_substr(trim((string) ($p['nombre'] ?? '')), 0, 255),
            'email'  => self::email((string) ($p['email'] ?? '')),
            'cargo'  => mb_substr(trim((string) ($p['cargo'] ?? '')), 0, 120),
            'rol'    => isset(self::ROLES[$p['rol'] ?? '']) ? (string) $p['rol'] : 'equipo',
            'activo' => !empty($p['activo']) ? 1 : 0,
        ];
    }

    /**
     * Valida nombre, email y que el email no esté tomado (por otro usuario de agencia
     * o por un contacto de cliente: una misma casilla no puede entrar a los dos portales).
     *
     * @param array<string, mixed> $p
     */
    public function error(array $p, ?string $id = null): ?string
    {
        $d = $this->normalizar($p);
        if ($d['nombre'] === '') {
            return 'Falta el nombre.';
        }
        if (filter_var($d['email'], FILTER_VALIDATE_EMAIL) === false) {
            return 'El correo no es válido.';
        }
        $otro = $this->findByEmail($d['email']);
        if ($otro !== null && $otro['id'] !== $id) {
            return 'Ya existe un usuario de agencia con ese correo.';
        }
        $c = $this->pdo->prepare('SELECT COUNT(*) FROM portal_contactos WHERE email = ?');
        $c->execute([$d['email']]);
        if ((int) $c->fetchColumn() > 0) {
            return 'Ese correo es de un contacto de cliente. Usa otro para la agencia.';
        }
        return null;
    }

    /** @param array<string, mixed> $p */
    public function create(array $p): string
    {
        $d  = $this->normalizar($p);
        $id = typedock_uuid7();
        $this->pdo->prepare(
            'INSERT INTO portal_equipo (id, nombre, email, cargo, rol, activo, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$id, $d['nombre'], $d['email'], $d['cargo'], $d['rol'], $d['activo'], self::ahora(), self::ahora()]);
        return $id;
    }

    /** @param array<string, mixed> $p */
    public function update(string $id, array $p): void
    {
        $d = $this->normalizar($p);
        $this->pdo->prepare(
            'UPDATE portal_equipo SET nombre = ?, email = ?, cargo = ?, rol = ?, activo = ?, updated_at = ? WHERE id = ?'
        )->execute([$d['nombre'], $d['email'], $d['cargo'], $d['rol'], $d['activo'], self::ahora(), $id]);
    }

    public function delete(string $id): void
    {
        $this->pdo->prepare('DELETE FROM portal_equipo_asignaciones WHERE usuario_id = ?')->execute([$id]);
        $this->pdo->prepare('DELETE FROM portal_equipo_codigos WHERE usuario_id = ?')->execute([$id]);
        $this->pdo->prepare('DELETE FROM portal_equipo_intentos WHERE usuario_id = ?')->execute([$id]);
        $this->pdo->prepare('DELETE FROM portal_equipo WHERE id = ?')->execute([$id]);
    }

    public function marcarAcceso(string $id): void
    {
        $this->pdo->prepare('UPDATE portal_equipo SET ultimo_acceso = ? WHERE id = ?')->execute([self::ahora(), $id]);
    }

    // ---- Asignaciones ------------------------------------------------------------

    /** @return array<int, array<string, mixed>> */
    public function asignaciones(string $usuarioId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM portal_equipo_asignaciones WHERE usuario_id = ?');
        $stmt->execute([$usuarioId]);
        return $stmt->fetchAll();
    }

    /**
     * Reemplaza las asignaciones. Un cliente en $clientes = el cliente completo
     * (proyectos actuales y futuros); si no, se guardan sólo los proyectos marcados.
     *
     * @param array<int, string> $clientes
     * @param array<int, string> $proyectos
     */
    public function guardarAsignaciones(string $usuarioId, array $clientes, array $proyectos): void
    {
        $clientes  = array_values(array_unique(array_map('strval', $clientes)));
        $proyectos = array_values(array_unique(array_map('strval', $proyectos)));

        $this->pdo->prepare('DELETE FROM portal_equipo_asignaciones WHERE usuario_id = ?')->execute([$usuarioId]);
        $ins = $this->pdo->prepare('INSERT INTO portal_equipo_asignaciones (id, usuario_id, cliente_id, proyecto_id, created_at) VALUES (?, ?, ?, ?, ?)');

        $existeCliente = $this->pdo->prepare('SELECT id FROM portal_clientes WHERE id = ?');
        foreach ($clientes as $cid) {
            $existeCliente->execute([$cid]);
            if ($existeCliente->fetchColumn() !== false) {
                $ins->execute([typedock_uuid7(), $usuarioId, $cid, null, self::ahora()]);
            }
        }
        $proy = $this->pdo->prepare('SELECT cliente_id FROM portal_proyectos WHERE id = ?');
        foreach ($proyectos as $pid) {
            $proy->execute([$pid]);
            $cid = $proy->fetchColumn();
            if ($cid !== false && !in_array((string) $cid, $clientes, true)) {
                $ins->execute([typedock_uuid7(), $usuarioId, (string) $cid, $pid, self::ahora()]);
            }
        }
    }

    /**
     * A quién del equipo avisar por correo cuando el cliente hace algo: los usuarios activos
     * que ven ese proyecto (o ese cliente) y no desactivaron sus avisos en «Mis ajustes».
     *
     * @return array<int, array<string, mixed>>
     */
    public function destinatariosAvisos(?string $proyectoId, ?string $clienteId): array
    {
        if ($proyectoId !== null && $proyectoId !== '') {
            $lista = $this->delProyecto($proyectoId);
        } elseif ($clienteId !== null && $clienteId !== '') {
            $stmt = $this->pdo->prepare(
                "SELECT DISTINCT e.id, e.nombre, e.email, e.cargo FROM portal_equipo e
                 LEFT JOIN portal_equipo_asignaciones a ON a.usuario_id = e.id AND a.cliente_id = ?
                 WHERE e.activo = 1 AND (e.rol = 'coordinador' OR a.id IS NOT NULL) ORDER BY e.nombre"
            );
            $stmt->execute([$clienteId]);
            $lista = $stmt->fetchAll();
        } else {
            return [];
        }
        $aj = new AjustesService($this->pdo);
        return array_values(array_filter($lista, fn(array $u): bool => $aj->get('equipo', (string) $u['id'], 'avisos', '1') !== '0'));
    }

    /** @return array<int, array<string, mixed>> Usuarios asignados a un proyecto (directo o por cliente completo). */
    public function delProyecto(string $proyectoId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT e.id, e.nombre, e.email, e.cargo FROM portal_equipo e
             JOIN portal_proyectos p ON p.id = ?
             LEFT JOIN portal_equipo_asignaciones a ON a.usuario_id = e.id
               AND a.cliente_id = p.cliente_id AND (a.proyecto_id IS NULL OR a.proyecto_id = p.id)
             WHERE e.activo = 1 AND (e.rol = 'coordinador' OR a.id IS NOT NULL)
             ORDER BY e.nombre"
        );
        $stmt->execute([$proyectoId]);
        return $stmt->fetchAll();
    }
}

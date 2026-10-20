<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * Línea de tiempo de cada contacto ganado: la llegada, los cambios de estado, las notas (con su dueño:
 * agencia o cliente) y los próximos pasos. Cada evento guarda cuándo ocurrió (se puede corregir a mano y
 * ordena la línea) y cuándo se registró (no cambia).
 *
 * Todo se pide con el lead ya verificado por quien llama (el portal lo busca acotado a su cliente), y cada
 * evento se busca siempre junto con su lead: un id de evento ajeno no se puede tocar.
 */
class LeadLinea
{
    public const TABLE = 'portal_lead_eventos';
    public const TIPOS = ['llegada', 'estado', 'nota', 'proximo_paso'];
    public const AUTORES = ['agencia' => 'Agencia', 'cliente' => 'Cliente', 'sistema' => 'Sistema'];
    public const MAX_TEXTO = 5000;

    /** Orden de las etapas del embudo (perdido queda fuera: se puede perder en cualquiera). */
    public const ORDEN = ['nuevo' => 0, 'contactado' => 1, 'calificado' => 2, 'ganado' => 3];

    public function __construct(private readonly \PDO $pdo) {}

    public static function ahora(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }

    /**
     * «2026-10-20T10:42», «2026-10-20 10:42:00» o «2026-10-20» → «Y-m-d H:i:s», o null si no es una fecha
     * o es de más de un día en el futuro (los relojes de cada persona no siempre cuadran).
     */
    public static function fecha(string $s): ?string
    {
        $s = trim(str_replace('T', ' ', $s));
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?: (\d{2}):(\d{2})(?::(\d{2}))?)?$/', $s, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        $h = (int) ($m[4] ?? 0);
        $i = (int) ($m[5] ?? 0);
        if ($h > 23 || $i > 59) {
            return null;
        }
        $f = sprintf('%04d-%02d-%02d %02d:%02d:%02d', (int) $m[1], (int) $m[2], (int) $m[3], $h, $i, (int) ($m[6] ?? 0));
        return $f > (new \DateTimeImmutable('+1 day'))->format('Y-m-d H:i:s') ? null : $f;
    }

    /** Texto de una nota: sin caracteres de control (salvo saltos de línea y tabs), cortado a su largo. */
    public static function texto(string $t): string
    {
        $t = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $t);
        return mb_substr(trim($t), 0, self::MAX_TEXTO);
    }

    /** @return array<int, array<string, mixed>> lo más reciente primero (por cuándo ocurrió) */
    public function eventos(string $leadId): array
    {
        $st = $this->pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE lead_id = ? ORDER BY ocurrio_en DESC, registrado_en DESC, id DESC');
        $st->execute([$leadId]);
        return $st->fetchAll();
    }

    /**
     * Eventos de llegada y de estado de un cliente (o sólo de algunos proyectos): lo justo para el embudo,
     * los tiempos y los gráficos.
     *
     * @param array<int, string> $proyectoIds vacío = todos los del cliente
     * @return array<int, array<string, mixed>>
     */
    public function deEstado(string $clienteId, array $proyectoIds = []): array
    {
        $sql = 'SELECT lead_id, tipo, a_estado, ocurrio_en FROM ' . self::TABLE . " WHERE cliente_id = ? AND tipo IN ('llegada', 'estado')";
        $args = [$clienteId];
        if ($proyectoIds !== []) {
            $sql .= ' AND proyecto_id IN (' . implode(',', array_fill(0, count($proyectoIds), '?')) . ')';
            array_push($args, ...array_values($proyectoIds));
        }
        $st = $this->pdo->prepare($sql . ' ORDER BY ocurrio_en');
        $st->execute($args);
        return $st->fetchAll();
    }

    /**
     * Agrega un evento a la línea. $d: de, a, texto, ocurrio (Y-m-d H:i:s), autor, autor_tipo.
     *
     * @param array<string, mixed> $lead id, cliente_id, proyecto_id
     * @param array<string, mixed> $d
     */
    public function registrar(array $lead, string $tipo, array $d): string
    {
        $id = typedock_uuid7();
        $ahora = self::ahora();
        $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . ' (id, lead_id, cliente_id, proyecto_id, tipo, de_estado, a_estado, texto, ocurrio_en, registrado_en, autor_tipo, autor, ajustada, legado)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0)'
        )->execute([
            $id, (string) $lead['id'], (string) $lead['cliente_id'], (string) $lead['proyecto_id'], $tipo,
            $d['de'] ?? null, $d['a'] ?? null, ($d['texto'] ?? '') !== '' ? $d['texto'] : null,
            $d['ocurrio'] ?? $ahora, $ahora, isset(self::AUTORES[$d['autor_tipo'] ?? '']) ? $d['autor_tipo'] : '', mb_substr((string) ($d['autor'] ?? ''), 0, 255),
        ]);
        return $id;
    }

    /** La llegada del contacto (la crea LeadService al guardarlo). */
    public function llegada(array $lead, string $cuando, string $autor, string $autorTipo): void
    {
        $this->registrar($lead, 'llegada', ['ocurrio' => $cuando, 'autor' => $autor, 'autor_tipo' => $autorTipo]);
    }

    /**
     * Lo que manda el cuadro «Agregar a la línea de tiempo»: una nota, un cambio de estado o las dos cosas,
     * con la fecha en que ocurrió (vacía = ahora). @param array<string, mixed> $post @return string|null error
     */
    public function agregar(array $lead, array $post, string $autor, string $autorTipo): ?string
    {
        $texto = self::texto((string) ($post['texto'] ?? ''));
        $estado = (string) ($post['estado'] ?? '');
        $crudo = trim((string) ($post['ocurrio_en'] ?? ''));
        $cuando = $crudo === '' ? null : self::fecha($crudo);
        if ($crudo !== '' && $cuando === null) {
            return 'La fecha no es válida (y no puede ser futura).';
        }
        $cambia = $estado !== '' && $estado !== (string) $lead['estado'];
        if ($texto === '' && !$cambia) {
            return 'Escribe una nota o cambia el estado.';
        }
        if ($cambia && ($e = $this->cambiarEstado($lead, $estado, $cuando, $autor, $autorTipo)) !== null) {
            return $e;
        }
        return $texto !== '' ? $this->nota($lead, $texto, $cuando, $autor, $autorTipo) : null;
    }

    /** @return string|null error */
    public function nota(array $lead, string $texto, ?string $ocurrio, string $autor, string $autorTipo): ?string
    {
        $texto = self::texto($texto);
        if ($texto === '') {
            return 'Escribe la nota.';
        }
        if (!empty($lead['anonimizado_en'])) {
            return 'Este contacto fue anonimizado: ya no se puede editar.';
        }
        $this->registrar($lead, 'nota', ['texto' => $texto, 'ocurrio' => $ocurrio, 'autor' => $autor, 'autor_tipo' => $autorTipo]);
        return null;
    }

    /** Cambia el estado del contacto y lo deja en la línea. @return string|null error */
    public function cambiarEstado(array $lead, string $nuevo, ?string $ocurrio, string $autor, string $autorTipo): ?string
    {
        if (!isset(LeadService::ESTADOS[$nuevo])) {
            return 'Ese estado no existe.';
        }
        if (!empty($lead['anonimizado_en'])) {
            return 'Este contacto fue anonimizado: ya no se puede editar.';
        }
        if ($nuevo === (string) $lead['estado']) {
            return null;
        }
        $cuando = $ocurrio ?? self::ahora();
        $this->pdo->prepare('UPDATE ' . LeadService::TABLE . ' SET estado = ?, estado_en = ?, estado_por = ?, updated_at = ? WHERE id = ?')
            ->execute([$nuevo, $cuando, mb_substr($autor, 0, 255), self::ahora(), (string) $lead['id']]);
        $this->registrar($lead, 'estado', ['de' => (string) $lead['estado'], 'a' => $nuevo, 'ocurrio' => $cuando, 'autor' => $autor, 'autor_tipo' => $autorTipo]);
        return null;
    }

    /** Deja constancia de un cambio de próximo paso. */
    public function proximoPaso(array $lead, string $paso, ?string $fecha, string $autor, string $autorTipo): void
    {
        $texto = $paso !== '' ? $paso . ($fecha ? ' · ' . LeadStats::etiqueta(new \DateTimeImmutable($fecha)) : '') : 'Quitó el próximo paso';
        $this->registrar($lead, 'proximo_paso', ['texto' => $texto, 'autor' => $autor, 'autor_tipo' => $autorTipo]);
    }

    /**
     * Cambia a mano cuándo ocurrió un evento; la línea se reordena sola. La llegada también mueve la fecha
     * del contacto (la usan los gráficos y las listas). @return string|null error
     */
    public function cambiarFecha(array $lead, string $eventoId, string $nueva): ?string
    {
        $f = self::fecha($nueva);
        if ($f === null) {
            return 'La fecha no es válida (y no puede ser futura).';
        }
        $st = $this->pdo->prepare('SELECT id, tipo, ocurrio_en FROM ' . self::TABLE . ' WHERE id = ? AND lead_id = ?');
        $st->execute([$eventoId, (string) $lead['id']]);
        $e = $st->fetch();
        if ($e === false) {
            return 'No encontramos ese movimiento.';
        }
        if ($f === (string) $e['ocurrio_en']) {
            return null;
        }
        $this->pdo->prepare('UPDATE ' . self::TABLE . ' SET ocurrio_en = ?, ajustada = 1 WHERE id = ?')->execute([$f, $eventoId]);
        if ((string) $e['tipo'] === 'llegada') {
            $this->pdo->prepare('UPDATE ' . LeadService::TABLE . ' SET created_at = ? WHERE id = ?')->execute([$f, (string) $lead['id']]);
        } elseif ((string) $e['tipo'] === 'estado') {
            // El «último cambio de estado» que se muestra sigue al movimiento más reciente.
            $u = $this->pdo->prepare('SELECT ocurrio_en FROM ' . self::TABLE . " WHERE lead_id = ? AND tipo = 'estado' ORDER BY ocurrio_en DESC, registrado_en DESC LIMIT 1");
            $u->execute([(string) $lead['id']]);
            $ult = $u->fetchColumn();
            if ($ult !== false) {
                $this->pdo->prepare('UPDATE ' . LeadService::TABLE . ' SET estado_en = ? WHERE id = ?')->execute([(string) $ult, (string) $lead['id']]);
            }
        }
        return null;
    }

    /** Anonimizar: las notas pueden tener datos personales; los cambios de estado quedan para los conteos. */
    public function borrarNotas(string $leadId): void
    {
        $this->pdo->prepare('DELETE FROM ' . self::TABLE . " WHERE lead_id = ? AND tipo IN ('nota', 'proximo_paso')")->execute([$leadId]);
    }

    public function borrarDeLead(string $leadId): void
    {
        $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE lead_id = ?')->execute([$leadId]);
    }

    public function borrarDeProyecto(string $proyectoId): void
    {
        $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE proyecto_id = ?')->execute([$proyectoId]);
    }

    public function borrarDeCliente(string $clienteId): void
    {
        $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE cliente_id = ?')->execute([$clienteId]);
    }
}

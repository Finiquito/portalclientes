<?php
declare(strict_types=1);

namespace Prisma;

/** Versiones del plugin en releases/ y qué versión usa cada canal. */
final class Versiones
{
    public function __construct(private readonly Casa $casa, private readonly \Closure $dec) {}

    private function d(string $t): void
    {
        ($this->dec)($t);
    }

    /** `prisma version agregar <zip>`: descomprime el zip del plugin en releases/portal-<versión>. */
    public function agregar(string $zip, bool $simular): int
    {
        if (!is_file($zip)) {
            $this->d("✗ No existe el archivo {$zip}");
            return 1;
        }
        $tmp = $this->casa->ruta('releases', '.tmp-' . bin2hex(random_bytes(4)));
        mkdir($tmp, 0755, true);
        try {
            if (class_exists(\ZipArchive::class)) {
                $z = new \ZipArchive();
                if ($z->open($zip) !== true || !$z->extractTo($tmp)) {
                    throw new \RuntimeException('El zip no se pudo abrir o está dañado.');
                }
                $z->close();
            } else {
                [$c, , $e] = Proc::correr(['unzip', '-q', $zip, '-d', $tmp]);
                if ($c !== 0) {
                    throw new \RuntimeException('No pude descomprimir (falta la extensión zip y el comando unzip falló): ' . trim($e));
                }
            }
            $plugin = $tmp . '/portal';
            $j = is_file($plugin . '/plugin.json') ? json_decode((string) file_get_contents($plugin . '/plugin.json'), true) : null;
            if (!is_array($j) || ($j['slug'] ?? '') !== 'portal' || empty($j['version']) || !is_file($plugin . '/src/PortalPlugin.php')) {
                throw new \RuntimeException('El zip no parece el plugin portal (falta portal/plugin.json o portal/src/PortalPlugin.php).');
            }
            if (preg_match('/^[0-9A-Za-z.\-+]+$/', (string) $j['version']) !== 1) {
                throw new \RuntimeException('La versión del plugin tiene caracteres raros.');
            }
            $nombre = 'portal-' . $j['version'];
            $dest = $this->casa->ruta('releases', $nombre);
            if (file_exists($dest)) {
                throw new \RuntimeException("Ya existe {$nombre} en releases/. Las versiones no se pisan: sube el número.");
            }
            if ($simular) {
                $this->d("[simulación] Agregaría releases/{$nombre}");
                return 0;
            }
            rename($plugin, $dest);
            $this->d("✓ Agregada releases/{$nombre}. Para usarla: prisma actualizar --canal=canary --a={$nombre}");
            return 0;
        } catch (\RuntimeException $e) {
            $this->d('✗ ' . $e->getMessage());
            return 1;
        } finally {
            Casa::borrar($tmp);
        }
    }

    /** @return list<string> */
    public function lista(): array
    {
        $dir = $this->casa->ruta('releases');
        $r = array_values(array_filter(scandir($dir) ?: [], fn($e) => $e[0] !== '.' && is_dir($dir . '/' . $e)));
        sort($r, SORT_NATURAL);
        return $r;
    }

    public function actual(string $canal): ?string
    {
        $l = $this->casa->canal($canal);
        return is_link($l) ? basename((string) readlink($l)) : null;
    }

    /** `prisma actualizar --canal=… --a=<release>`: mueve el canal y deja cada instancia lista. */
    public function actualizar(string $canal, string $nombre, bool $simular, bool $sinHttp): int
    {
        if (!in_array($canal, Casa::CANALES, true)) {
            $this->d('✗ El canal debe ser canary o estable.');
            return 1;
        }
        $dir = $this->casa->ruta('releases', $nombre);
        $version = is_dir($dir) && basename($nombre) === $nombre ? Casa::versionDe($dir) : null;
        if ($version === null) {
            $this->d("✗ releases/{$nombre} no existe o no es un plugin válido. Disponibles: " . (implode(', ', $this->lista()) ?: '(ninguna)'));
            return 1;
        }
        $previa = $this->actual($canal);
        $instancias = array_filter($this->casa->instancias(), fn($i) => ($i['canal'] ?? '') === $canal && ($i['estado'] ?? '') === 'activa');
        $this->d(($simular ? '[simulación] ' : '') . "Canal «{$canal}»: " . ($previa ?? '(sin versión)') . " → {$nombre} ({$version}); instancias afectadas: " . (implode(', ', array_keys($instancias)) ?: 'ninguna'));
        if ($simular) {
            return 0;
        }

        $enlace = $this->casa->canal($canal);
        if (!is_dir(dirname($enlace))) {
            mkdir(dirname($enlace), 0755, true);
        }
        $tmp = $enlace . '.nuevo';
        @unlink($tmp);
        if (!symlink('../releases/' . $nombre, $tmp) || !rename($tmp, $enlace)) {
            $this->d('✗ No pude mover el enlace del canal (¿el hosting permite symlink?).');
            return 1;
        }

        $malas = 0;
        foreach ($instancias as $slug => $i) {
            try {
                Proc::instancia('assets', ['root' => $i['typedock'], 'public' => $i['docroot']]);
                $this->casa->actualizarRegistro(function (array $reg) use ($slug, $version): array {
                    if (isset($reg[$slug])) {
                        $reg[$slug]['version'] = $version;
                        $reg[$slug]['actualizada'] = gmdate('c');
                    }
                    return $reg;
                });
                $ok = $sinHttp || $this->responde((string) $i['url'] . '/portal');
                $this->d(($ok ? '  ✓ ' : '  ! ') . $slug . ($ok ? '' : ': el portal no responde bien tras actualizar'));
                $malas += $ok ? 0 : 1;
            } catch (\Throwable $e) {
                $this->d("  ✗ {$slug}: " . $e->getMessage());
                $malas++;
            }
        }
        if ($malas > 0 && $previa !== null) {
            $this->d("Algo falló. Para volver atrás: prisma actualizar --canal={$canal} --a={$previa}");
        }
        $this->d('Nota: PHP puede tardar un par de minutos en ver el código nuevo (caché de rutas).');
        return $malas > 0 ? 1 : 0;
    }

    private function responde(string $url): bool
    {
        $ctx = stream_context_create(['http' => ['timeout' => 15, 'ignore_errors' => true]]);
        $r = @file_get_contents($url, false, $ctx);
        $codigo = 0;
        foreach ((array) ($http_response_header ?? []) as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $codigo = (int) $m[1];
            }
        }
        return $r !== false && $codigo > 0 && $codigo < 500;
    }
}

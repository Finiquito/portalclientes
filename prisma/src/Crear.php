<?php
declare(strict_types=1);

namespace Prisma;

/** `prisma crear <slug>`: levanta una agencia nueva, de la distribución limpia de TypeDock al enlace de primer ingreso. */
final class Crear
{
    private const PLACEHOLDERS = ['index.html', 'default.php', '.htaccess', 'cgi-bin', '.well-known'];

    /** @var list<string> */
    private array $deshacer = [];

    public function __construct(private readonly Casa $casa, private readonly \Closure $dec) {}

    private function d(string $t): void
    {
        ($this->dec)($t);
    }

    /** @param array<string, mixed> $o host, docroot, correo, nombre, canal, simular, sin_http, reemplazar, zona, url */
    public function ejecutar(string $slug, array $o): int
    {
        $simular = !empty($o['simular']);
        try {
            $plan = $this->validar($slug, $o);
        } catch (\RuntimeException $e) {
            $this->d('✗ ' . $e->getMessage());
            return 1;
        }
        $this->d(($simular ? '[simulación] ' : '') . "Voy a crear «{$slug}» ({$plan['url']}):");
        $this->d("  · TypeDock en        {$plan['typedock']}");
        $this->d("  · sitio público en   {$plan['docroot']}");
        $this->d("  · plugin             enlace a canal «{$plan['canal']}» (" . ($plan['version'] ?? '?') . ')');
        $this->d("  · base de datos      {$plan['db_resumen']}");
        $this->d("  · usuario 0          {$o['correo']}");
        $this->d('  · correo de Prisma   ' . ($plan['correo'] !== null ? $plan['correo']['desde'] : 'NO configurado (usará el correo del núcleo)'));
        if ($simular) {
            $this->d('Sin cambios: era una simulación.');
            return 0;
        }

        try {
            $this->d('1/6 Copiando TypeDock…');
            Casa::copiar($this->casa->distribucion() . '/typedock', $plan['typedock']);
            $this->deshacer[] = $plan['instancia'];

            $this->d('2/6 Copiando el sitio público y apuntándolo a su TypeDock…');
            $this->copiarPublico($plan);

            $this->d('3/6 Enlazando el plugin del canal…');
            $this->enlazarPlugin($plan);

            $this->d('4/6 Instalando (migraciones, usuario 0, assets)…');
            $r = Proc::instancia('instalar', [
                'root' => $plan['typedock'], 'public' => $plan['docroot'],
                'config' => $plan['config'], 'admin' => ['email' => $o['correo'], 'name' => $o['nombre']], 'dias_enlace' => 7,
            ]);

            $this->d('5/6 Registrando la instancia…');
            $this->casa->actualizarRegistro(function (array $reg) use ($slug, $plan, $o, $r): array {
                $reg[$slug] = [
                    'host' => $plan['host'], 'url' => $plan['url'], 'docroot' => $plan['docroot'], 'typedock' => $plan['typedock'],
                    'canal' => $plan['canal'], 'version' => $plan['version'], 'nucleo' => $r['nucleo'] ?? '',
                    'correo' => $o['correo'], 'nombre' => $o['nombre'], 'estado' => 'activa', 'creada' => gmdate('c'),
                ];
                return $reg;
            });
        } catch (\Throwable $e) {
            $this->d('✗ Falló: ' . $e->getMessage());
            $this->revertir($plan);
            return 1;
        }

        $this->d('6/6 Prueba de humo…');
        $avisos = !empty($o['sin_http']) ? ['(omitida)'] : $this->humo($plan['url']);

        $this->d('');
        $this->d("✓ «{$slug}» creada.");
        $this->d("  Sitio:  {$plan['url']}/portal   ·   Admin: {$plan['url']}/admin/login");
        $this->d("  Enlace de primer ingreso (vence en {$r['vence_dias']} días; se manda al usuario 0, no se guarda):");
        $this->d('  ' . $r['enlace']);
        foreach ($avisos as $a) {
            $this->d(($a === '(omitida)' || str_starts_with($a, 'Prueba de humo') ? '  ✓ ' : '  ! ') . $a);
        }
        return 0;
    }

    /** @return array<string, mixed> */
    private function validar(string $slug, array $o): array
    {
        if (!Casa::esSlug($slug)) {
            throw new \RuntimeException('El nombre corto debe ser minúsculas, números y guiones (2 a 31 caracteres, parte con letra): «' . $slug . '».');
        }
        if (isset($this->casa->instancias()[$slug])) {
            throw new \RuntimeException("Ya existe una instancia «{$slug}» en el registro.");
        }
        foreach (['host', 'docroot', 'correo'] as $k) {
            if (empty($o[$k])) {
                throw new \RuntimeException("Falta --{$k}=…");
            }
        }
        if (!filter_var($o['correo'], FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('El correo del usuario 0 no es válido.');
        }
        if (preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/i', (string) $o['host']) !== 1) {
            throw new \RuntimeException('El host no parece válido (ej.: prueba1.usaprisma.com).');
        }
        if (PHP_VERSION_ID < 80200) {
            throw new \RuntimeException('TypeDock pide PHP 8.2 o superior (aquí hay ' . PHP_VERSION . ').');
        }
        if (($faltan = Casa::funcionesFaltantes()) !== []) {
            throw new \RuntimeException('El hosting tiene deshabilitadas funciones de PHP que la herramienta necesita: ' . implode(', ', $faltan) . '.');
        }
        foreach (['pdo', 'mbstring', 'openssl', 'json'] as $ext) {
            if (!extension_loaded($ext)) {
                throw new \RuntimeException("Falta la extensión PHP «{$ext}».");
            }
        }
        $canal = (string) ($o['canal'] ?? 'estable');
        if (!in_array($canal, Casa::CANALES, true)) {
            throw new \RuntimeException('El canal debe ser canary o estable.');
        }
        $enlace = $this->casa->canal($canal);
        $version = is_link($enlace) || is_dir($enlace) ? Casa::versionDe($enlace) : null;
        if ($version === null) {
            throw new \RuntimeException("El canal «{$canal}» no apunta a ninguna versión del plugin. Agrega una con: prisma version agregar <zip> y luego prisma actualizar --canal={$canal} --a=<nombre>.");
        }
        $dist = $this->casa->distribucion();
        if (!is_file($dist . '/typedock/vendor/autoload.php') || !is_file($dist . '/public_html/index.php')) {
            throw new \RuntimeException("No encuentro la distribución de TypeDock en {$dist} (debe tener typedock/ con vendor/ y public_html/). Descomprime ahí typedock-shared.zip.");
        }

        $docroot = rtrim((string) $o['docroot'], '/');
        if (!is_dir($docroot)) {
            throw new \RuntimeException("La carpeta del subdominio no existe: {$docroot}. Crea el subdominio en hPanel primero.");
        }
        if (empty($o['reemplazar'])) {
            $raros = array_values(array_diff(scandir($docroot) ?: [], ['.', '..'], self::PLACEHOLDERS));
            if ($raros !== []) {
                throw new \RuntimeException("La carpeta {$docroot} no está vacía (" . implode(', ', array_slice($raros, 0, 5)) . '). Usa --reemplazar-docroot si estás seguro.');
            }
        }

        $instancia = $this->casa->ruta('instancias', $slug);
        if (file_exists($instancia)) {
            throw new \RuntimeException("Ya existe {$instancia} (¿una creación a medias?). Revísalo y bórralo a mano.");
        }
        $typedock = $instancia . '/typedock';

        $db = $this->leerBd($slug);
        $nombre = trim((string) ($o['nombre'] ?? '')) ?: $slug;
        $url = rtrim((string) ($o['url'] ?? ('https://' . $o['host'])), '/');
        $correo = $this->leerCorreo();

        $config = [
            'APP_NAME' => $nombre, 'APP_URL' => $url, 'APP_DEBUG' => 'false', 'APP_LOCALE' => 'es',
            'APP_TIMEZONE' => (string) ($o['zona'] ?? 'America/Santiago'),
            'SELF_UPDATE_ENABLED' => 'false',
            'PLUGIN_PORTAL' => 'true',
            'PORTAL_UPLOAD_DIR' => $typedock . '/storage/portal_uploads',
            'PORTAL_SECRETOS_DIR' => $this->casa->carpetaCompartida(),
        ] + $db['config'];
        if ($correo !== null) {
            $config += [
                'MAIL_MAILER' => 'smtp', 'MAIL_FROM_ADDRESS' => $correo['desde'], 'MAIL_FROM_NAME' => $nombre . ' vía Prisma',
                'MAIL_HOST' => $correo['host'], 'MAIL_PORT' => (string) $correo['puerto'], 'MAIL_USERNAME' => $correo['usuario'],
                'MAIL_PASSWORD' => $correo['clave'], 'MAIL_ENCRYPTION' => $correo['seg'] === 'ninguna' ? '' : $correo['seg'],
            ];
        }
        return [
            'slug' => $slug, 'host' => (string) $o['host'], 'url' => $url, 'docroot' => $docroot, 'instancia' => $instancia, 'typedock' => $typedock,
            'canal' => $canal, 'version' => $version, 'config' => $config, 'correo' => $correo, 'db_resumen' => $db['resumen'],
            'preexistentes' => scandir($docroot) ?: [],
        ];
    }

    /** @return array{config: array<string, string>, resumen: string} */
    private function leerBd(string $slug): array
    {
        $s = $this->casa->leerSecreto('instancias/' . $slug);
        if ($s === null) {
            throw new \RuntimeException("Falta secretos/instancias/{$slug}.json con los datos de la base de datos (créalo con: prisma secreto {$slug}).");
        }
        if ($this->casa->secretoExpuesto('instancias/' . $slug)) {
            throw new \RuntimeException("secretos/instancias/{$slug}.json es legible por otros usuarios. Corre: chmod 600 " . $this->casa->ruta('secretos', 'instancias', $slug . '.json'));
        }
        $b = (array) ($s['db'] ?? []);
        $driver = (string) ($b['driver'] ?? 'mysql');
        if ($driver === 'sqlite') {
            $ruta = (string) ($b['ruta'] ?? '');
            if ($ruta === '') {
                throw new \RuntimeException('Para sqlite falta "ruta" en el archivo de secretos.');
            }
            return ['config' => ['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $ruta], 'resumen' => "sqlite {$ruta}"];
        }
        foreach (['host', 'database', 'username', 'password'] as $k) {
            if (trim((string) ($b[$k] ?? '')) === '') {
                throw new \RuntimeException("Falta db.{$k} en secretos/instancias/{$slug}.json.");
            }
        }
        try {
            $pdo = new \PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $b['host'], (int) ($b['port'] ?? 3306), $b['database']), (string) $b['username'], (string) $b['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 8]);
            $tablas = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
        } catch (\PDOException $e) {
            throw new \RuntimeException('No pude conectar a la base de datos: ' . $e->getMessage());
        }
        if ($tablas > 0) {
            throw new \RuntimeException("La base «{$b['database']}» ya tiene {$tablas} tablas. Usa una base vacía.");
        }
        return [
            'config' => ['DB_DRIVER' => 'mysql', 'DB_HOST' => (string) $b['host'], 'DB_PORT' => (string) ($b['port'] ?? 3306), 'DB_DATABASE' => (string) $b['database'], 'DB_USERNAME' => (string) $b['username'], 'DB_PASSWORD' => (string) $b['password']],
            'resumen' => "mysql {$b['database']}@{$b['host']} (vacía)",
        ];
    }

    /** @return array{host: string, puerto: int, seg: string, usuario: string, clave: string, desde: string}|null */
    private function leerCorreo(): ?array
    {
        $f = $this->casa->carpetaCompartida() . '/correo.json';
        $j = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
        if (!is_array($j) || trim((string) ($j['host'] ?? '')) === '' || (string) ($j['clave'] ?? '') === '' || !filter_var($j['desde'] ?? '', FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        return [
            'host' => (string) $j['host'], 'puerto' => (int) ($j['puerto'] ?? 465), 'seg' => (string) ($j['seg'] ?? 'ssl'),
            'usuario' => (string) ($j['usuario'] ?? $j['desde']), 'clave' => (string) $j['clave'], 'desde' => (string) $j['desde'],
        ];
    }

    /** @param array<string, mixed> $plan */
    private function copiarPublico(array $plan): void
    {
        $pub = $this->casa->distribucion() . '/public_html';
        foreach (array_diff(scandir($pub) ?: [], ['.', '..']) as $e) {
            if (!in_array($e, $plan['preexistentes'], true)) {
                $this->deshacer[] = $plan['docroot'] . '/' . $e;
            }
        }
        Casa::copiar($pub, $plan['docroot']);
        foreach (['index.php', 'install.php'] as $f) {
            $ruta = $plan['docroot'] . '/' . $f;
            $src = is_file($ruta) ? (string) file_get_contents($ruta) : '';
            $nuevo = preg_replace('#dirname\(__DIR__\)\s*\.\s*([\'"])/typedock\1#', addcslashes(var_export($plan['typedock'], true), '\\$'), $src, -1, $n);
            if ($n < 1 || $nuevo === null) {
                throw new \RuntimeException("No encontré dónde apunta TYPEDOCK_ROOT en {$f}: la distribución cambió de formato.");
            }
            file_put_contents($ruta, $nuevo);
        }
    }

    /** @param array<string, mixed> $plan */
    private function enlazarPlugin(array $plan): void
    {
        $dir = $plan['typedock'] . '/plugins';
        $destino = $dir . '/portal';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        Casa::borrar($destino);
        Casa::enlazar($this->casa->canal($plan['canal']), $destino);
    }

    /** @return list<string> avisos */
    private function humo(string $url): array
    {
        $avisos = [];
        foreach (['/' => 'el inicio', '/portal' => 'el portal', '/admin/login' => 'el admin'] as $ruta => $que) {
            $ctx = stream_context_create(['http' => ['timeout' => 15, 'ignore_errors' => true, 'follow_location' => 1]]);
            $r = @file_get_contents($url . $ruta, false, $ctx);
            $codigo = 0;
            foreach ((array) ($http_response_header ?? []) as $h) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                    $codigo = (int) $m[1];
                }
            }
            if ($r === false || $codigo === 0 || $codigo >= 500) {
                $avisos[] = "No pude abrir {$que} ({$url}{$ruta}" . ($codigo ? ", HTTP {$codigo}" : '') . '). Si el DNS o el SSL del subdominio recién se crearon, espera unos minutos y reintenta.';
            }
        }
        return $avisos === [] ? ['Prueba de humo: el inicio, el portal y el admin responden.'] : $avisos;
    }

    /** @param array<string, mixed> $plan */
    private function revertir(array $plan): void
    {
        $this->d('Deshaciendo lo copiado…');
        foreach (array_reverse($this->deshacer) as $ruta) {
            Casa::borrar($ruta);
        }
        $this->d("La base de datos puede haber quedado con tablas: bórralas o recrea la base antes de reintentar.");
    }
}

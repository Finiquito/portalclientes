# TypeDock: cómo lo usamos, cómo se estructura y cómo se instala

Guía para cualquier chat de Claude Code o proyecto nuevo que trabaje con TypeDock en nuestro hosting de Hostinger. Junta lo que sabemos por el código, por las instalaciones reales y por los errores que ya resolvimos.

> **Cómo leer esta guía.** Lo marcado como **(confirmado)** se vio en el servidor o en el código del núcleo. Lo marcado como **(supuesto)** se dedujo y conviene verificarlo antes de depender de ello.

---

## 1. Qué es TypeDock

- **CMS en PHP**, liviano, pensado para hosting compartido. Es la base de los sitios y del producto **Prisma** (portal de clientes para agencias pequeñas y freelancers).
- **Repositorio oficial:** <https://github.com/typedock/core>. Las versiones están en <https://github.com/typedock/core/releases>.
- **Stack del núcleo:**
  - PHP 8.2 o superior.
  - **Latte 3.1** para plantillas. El plugin está probado con Latte 3.1.6.
  - **Flight** para rutas.
  - MySQL o MariaDB vía PDO.
- **Se extiende de dos formas:**
  - **Temas**: cambian la gráfica del sitio público.
  - **Plugins**: agregan rutas, pantallas de admin, tablas y lógica.
- **Paquetes de descarga:**
  - **`typedock-shared-X.zip`**: el que se usa en Hostinger. Viene compilado con `vendor/` incluido y no necesita Composer, Node ni compilación. **(confirmado)**
  - **Código fuente** (`core-main`, o «Download ZIP» del botón verde): es para programar el núcleo. No trae `vendor/` y necesita Composer, Node y `esbuild`. **No sirve para instalar.**

---

## 2. Estructura en el servidor

Cada dominio de Hostinger queda así **(confirmado)**:

```
~/domains/<dominio>/
├── public_html/              ← raíz web (lo único público)
│   ├── index.php             ← entrada de TypeDock
│   ├── .htaccess
│   ├── themes/<tema>/assets/ ← COPIA publicada de los assets del tema activo
│   ├── robots.txt, sitemap.xml, llms.txt (si se suben a mano)
│   └── error_log             ← errores de PHP (a veces)
└── typedock/                 ← núcleo, FUERA de la raíz web
    ├── src/                  ← código del núcleo (Core/Router.php, Install/Installer.php,
    │                            Theme/ThemeLoader.php, Admin/, Frontend/…)
    ├── vendor/               ← Latte, Flight… (ya viene en el paquete shared)
    ├── plugins/<slug>/       ← un plugin por carpeta
    ├── themes/<tema>/        ← temas (default, kinari, prisma…)
    ├── storage/              ← escribible: caché, logs, subidas de plugins
    │   ├── logs/             ← (supuesto) registro de errores del núcleo
    │   └── portal_uploads/   ← archivos del plugin Portal (fuera del web-root)
    └── config.php            ← datos de la base y app.url (supuesto: nombre exacto)
```

**Reglas que ya nos costaron un error:**
- Los **temas van en `typedock/themes/`**, nunca en `public_html/themes/`. El `ThemeLoader` sólo lista lo que está en `themesDir`. **(confirmado)**
- `typedock/storage/` necesita permisos de escritura: 755, o 775 si el instalador reclama.
- `app.url`, en la configuración o en los ajustes del sitio, tiene que ser el dominio definitivo (por ejemplo `https://usaprisma.com`). Si no, el SEO y los enlaces salen con otro dominio.

---

## 3. Instalar TypeDock en un dominio nuevo

1. **Hostinger → Bases de datos MySQL:** crea la base y su usuario. Anota los datos, pero no los pegues en ningún chat.
2. Descomprime `typedock-shared-X.zip` en tu computador. Quedan dos carpetas: `public_html/` y `typedock/`.
3. **Administrador de archivos de Hostinger → `domains/<dominio>/`:**
   - sube **el contenido** de `public_html/` a `public_html/`;
   - sube la carpeta `typedock/` completa **al lado** de `public_html/`, no dentro.
4. Dale permisos de escritura a `typedock/storage/` (clic derecho → Permisos → 755 o 775).
5. Abre el dominio en el navegador. El instalador pide los datos de la base y crea el usuario admin.
6. Revisa que `vendor/latte/latte/src/Latte/Engine.php` diga `Version = '3.1…'`.
7. Sube los plugins y el tema (secciones 4 y 5).

**Para clonar una instalación existente**, por ejemplo para otra agencia:
1. Copia `public_html` y `typedock`.
2. Deja fuera `typedock/plugins/`, `typedock/storage/` y el archivo de configuración con la base.
3. Usa el instalador para que parta limpio.

---

## 4. Plugins

### 4.1 Forma de un plugin

```
<slug>/
├── plugin.json        ← manifiesto
├── src/               ← PSR-4: TypeDock\Plugin\<Nombre>\
│   └── <Nombre>Plugin.php   ← clase principal (implements PluginInterface)
├── migrations/        ← 0001_algo.sql, 0002_… (se corren en orden, en cada carga)
├── templates/         ← Latte
└── assets/            ← CSS, JS e imágenes (el núcleo los sirve en /plugins/<slug>/assets/)
```

`plugin.json`:

```json
{
    "slug": "portal",
    "name": "Portal de Clientes",
    "version": "0.36.1",
    "description": "…",
    "main_class": "TypeDock\\Plugin\\Portal\\PortalPlugin",
    "min_core_version": "0.1",
    "provides": [],
    "autoload": { "psr-4": { "TypeDock\\Plugin\\Portal\\": "src/" } }
}
```

### 4.2 Contrato y API del núcleo que usamos

```php
namespace TypeDock\Contract;
interface PluginInterface {
    public function register(PluginContext $ctx): void;   // se llama en CADA request
    public function getName(): string;
    public function getVersion(): string;                  // mantener igual que plugin.json
    public function provides(): array;
}
```

`TypeDock\Core\PluginContext` (lo que usan nuestros plugins):

| Método | Para qué |
|---|---|
| `migrate($dir)` | Corre los `.sql` de la carpeta en orden. Se llama en cada carga, así que el SQL debe ser **idempotente** (`CREATE TABLE IF NOT EXISTS`). |
| `db()->pdo()` | El `PDO` del sitio. |
| `registerAdminRoute($método, $ruta, $handler)` | Ruta bajo `/admin/<slug>/<ruta>`. Parámetros con `@id`. |
| `addAdminMenuItem($etiqueta, $ruta)` | Ítem en el menú del admin. |
| `adminUrl($ruta)` | URL absoluta del admin del plugin. |
| `latte()`, `view($tpl, $datos)`, `renderView(...)` | Plantillas Latte. El admin recibe `$plugin_ui_layout`, `$admin_url()`, `$csrf_token` y `$admin_menu`. |
| `redirect($url, $mensaje, $tipo)` / `getFlash($tipo)` | Redirección con aviso «flash». |
| `mail()->send($para, $asunto, $html)` | Correo del núcleo. El Portal además tiene su SMTP propio. |

Funciones globales: `typedock_uuid7()` (ids) y `typedock_session_start()`.

**Rutas públicas:** se registran directo con Flight dentro de `register()`, por ejemplo `\Flight::route('GET /portal', [...])` o `\Flight::route('POST /invitacion', [...])`.

### 4.3 Instalar o actualizar un plugin

1. En el repo: `bash scripts/zip.sh` → `dist/portal-plugin-vX.Y.Z.zip`. Este script compila el CSS y **no arma el zip si las pruebas fallan**.
2. **Admin de TypeDock → Plugins → subir zip**, que reemplaza al anterior.
3. Las migraciones y `Schema::asegurar()` corren solas en la primera visita. **Nunca borran datos.**
4. Si el sitio se cae después de actualizar:
   - renombra `typedock/plugins/<slug>` a `<slug>-off` por FTP o SSH y recarga;
   - después borra el plugin y súbelo de nuevo desde cero. No subas el zip sobre una carpeta a medias.

### 4.4 Convenciones de nuestros plugins (seguirlas en los nuevos)

- **Versión en tres lugares:** `plugin.json`, `getVersion()` y la sección «Cambios» del README.
- **Tablas con prefijo** (`portal_*`, `invitaciones*`) y SQL portable: SQLite para las pruebas y MariaDB para producción.
- **Ids UUIDv7** (`typedock_uuid7()`).
- **Columnas nuevas** van en `Schema::asegurar()`, que revisa y agrega, en vez de un `ALTER` suelto en una migración.
- **Archivos subidos fuera del web-root** (`typedock/storage/<plugin>_uploads/`), servidos por PHP con permiso y validados por su tipo real.
- **Secretos** (SMTP, IA, Mailchimp):
  - se ingresan en el admin y se guardan cifrados con AES-256-GCM;
  - la clave de cifrado vive en un archivo aparte, `storage/portal_uploads/.ia_secret`, no en la base;
  - **nunca** van en el repo ni en un chat.
- **CSRF** en todos los POST.
- **JS de páginas públicas inline** en la plantilla: no está confirmado que el núcleo sirva `.js` desde `/plugins/<slug>/assets/`, aunque el `.css` sí lo sirve.
- **CSS con `?v=N`** en el `<link>`. Hay que subir el número cuando cambia.

---

## 5. Temas

```
typedock/themes/<tema>/
├── theme.json
├── layouts/      ← base.latte, home.latte, page.latte, single.latte, archive.latte,
│                    author.latte, search.latte, 403/404/500.latte, source-*.latte
├── partials/     ← header, logo, etc.
├── components/   ← (opcional)
└── assets/       ← css, js, img, fonts, screenshot.png (miniatura en la lista de temas)
```

`theme.json` mínimo, con un layout elegible por página:

```json
{
  "name": "Prisma", "version": "1.5.0", "description": "…", "author": "Estudio RichGT",
  "menus": {}, "slots": {},
  "layouts": { "landing": { "label": "Landing Prisma", "description": "…" } }
}
```

- `menus` declara zonas de menú y `slots` zonas de widgets, con componentes por defecto como `search_form` o `latest_posts`. El tema `default` trae un ejemplo completo.
- **Los layouts declarados** aparecen en el selector «Layout» del editor de páginas.
- **Página de inicio:** la dibuja `resolveHome`, o el layout que tenga asignado la página elegida como inicio.

**Trampa importante (confirmado):** TypeDock **no sirve** los assets desde `typedock/themes/<tema>/assets/`. Los sirve desde una **copia** en `public_html/themes/<tema>/assets/`, que se renueva sólo al **activar** el tema o abrir su **Vista previa**.
- Después de subir un tema nuevo, entra a **Apariencia → Temas → Vista previa**. Así se copian de nuevo los assets y se limpia la caché de plantillas.
- Si no hay botón, copia `assets/` a mano a `public_html/themes/<tema>/assets/`.
- Cambiar el `?v=` no basta: el archivo viejo sigue ahí.

**Latte en temas y plugins:**
- Al compilar, el núcleo convierte los avisos de PHP en error. Las constantes de PHP van con `\` inicial: `{json_encode($x, \JSON_UNESCAPED_UNICODE)}`.
- Evita leer `$_GET` o `$_SERVER` desde la plantilla, porque es probable que haya sandbox **(supuesto)**. Pasa los datos desde PHP o usa JS.
- Para JS o CSS con llaves dentro de la plantilla, usa `<script n:syntax="off">`.

**Archivos sueltos de la raíz** (`robots.txt`, `sitemap.xml`, `llms.txt`): se suben a mano a `public_html/`. Si existen ahí, el servidor los entrega en lugar de los que genere TypeDock.

---

## 6. Entorno Hostinger

- **Tipo:** hosting compartido, administrado desde **hPanel**. Dejamos Directus justamente porque era muy pesado ahí.
- **Sitios actuales:**

  | Dominio | Qué tiene |
  |---|---|
  | `proyectos.richgt.com` | TypeDock + plugin **Portal de Clientes** (`/portal`, `/equipo`, `/admin/portal/...`) |
  | `usaprisma.com` | TypeDock + tema **Prisma** (landing) + plugin **Invitaciones** (lista de espera → Mailchimp) |
- **Rutas:** `~/domains/<dominio>/public_html` y `~/domains/<dominio>/typedock`.
- **PHP:** 8.2 o superior. La versión se elige por dominio en hPanel → Avanzado → Configuración de PHP. `curl` tiene que estar habilitado para la IA y Mailchimp.

### 6.1 SSH

- **Se activa en hPanel → Avanzado → Acceso SSH.** Ahí salen el host, el usuario y el puerto; en Hostinger el puerto suele ser **65002**.
- **Uso típico:**

  ```bash
  ssh -p 65002 usuario@host
  cd ~/domains/proyectos.richgt.com
  find . -name theme.json -not -path "*/node_modules/*"      # dónde están los temas
  grep -rn "theme.json" --include=*.php typedock/src | head   # leer el núcleo
  tail -50 public_html/error_log                              # últimos errores
  mv typedock/plugins/portal typedock/plugins/portal-off      # apagar un plugin roto
  ```

- **Claude no se puede conectar por SSH.** El entorno en la nube sólo sale por HTTPS y no tiene cliente SSH. Además, **nunca** hay que pegar claves, llaves ni contraseñas en el chat. El flujo es:
  - Claude te da los comandos;
  - tú los corres;
  - le pegas la salida, revisando que no traiga secretos.
- **Si algún día se automatiza el despliegue**, hay dos opciones:
  - **GitHub Actions por SFTP**: los secretos `SFTP_HOST`, `SFTP_PORT`, `SFTP_USER`, `SFTP_PASSWORD`/`SFTP_KEY` y `SFTP_RUTA` van en *Settings → Secrets and variables → Actions*, y el flujo se lanza con botón manual.
  - **hPanel → Avanzado → Git**: copia el repo completo, no sólo el plugin, así que habría que ajustar rutas.

  Hoy los zips se suben **a mano**.

### 6.2 Cron (tareas programadas)

- **Dónde:** hPanel → Avanzado → **Cron Jobs**, cada **15 minutos**.
- **Comando del Portal:** sale listo para copiar en **Portal · Ajustes → Tarea programada**:

  ```
  */15 * * * * curl -s "https://proyectos.richgt.com/portal/cron/correos?k=CLAVE" > /dev/null
  ```

- **Qué hace:** envía la cola de correos que respeta el horario hábil del país del cliente, los avisos agrupados, el resumen de la mañana y los vencimientos.
- **Sin cron, todo funciona igual**, pero:
  - esas tareas corren cuando alguien entra al portal o al panel, como mucho cada 5 minutos;
  - el resumen de la mañana puede llegar tarde.
- **Ajustes muestra «Última vez que corrió».** Así se confirma que el cron anda.
- **La clave va en la URL**, así que queda en los logs de acceso. Se puede regenerar en Ajustes, pero entonces hay que actualizar el comando del cron.

### 6.3 Bases de datos

- Se administran en hPanel → **Bases de datos MySQL** y en **phpMyAdmin**.
- **Antes de borrar algo a mano, respalda:** phpMyAdmin → Exportar.
- **Hostinger corta las conexiones inactivas** («MySQL server has gone away»). Los procesos largos, como las llamadas a la IA, hacen un `SELECT 1` periódico y respetan `max_execution_time`.

### 6.4 Correo

- **SMTP propio del Portal** (Ajustes → Correos): con el correo del dominio desde hPanel → Correos. La contraseña se ingresa en el admin y se guarda cifrada.
- **Sin SMTP**, se usa el correo del núcleo, con menos garantía de entrega.

### 6.5 Cosas del servidor que ya nos pasaron

| Síntoma | Causa | Arreglo |
|---|---|---|
| El tema no aparece en la lista | Quedó en `public_html/themes/` | Moverlo a `typedock/themes/` |
| Cambié el CSS del tema y no se ve | Copia vieja en `public_html/themes/<tema>/assets` | Apariencia → Temas → **Vista previa** |
| El micrófono (dictado) está bloqueado | Cabecera `Permissions-Policy: microphone=()` | En el `.htaccess` de `public_html`, cambiar a `microphone=(self)` |
| Todo el sitio muestra error 500 tras actualizar | Plugin a medias | Renombrar la carpeta del plugin a `-off` y subirlo de nuevo |
| Una página se queda colgada después de guardar | Correos enviados en la misma visita, sin cron | Configurar el cron |
| «MySQL server has gone away» con la IA | Conexión inactiva cortada | Ya está cubierto en el plugin; si se repite, usar un modelo más rápido |
| Se necesita ver un error | — | `public_html/error_log`, hPanel → Avanzado → **Registros de errores de PHP**, o `typedock/storage/logs/` |

---

## 7. Desarrollo local (este repo)

No usamos el núcleo real. `dev/` trae un **núcleo simulado** con `PluginContext`, `PluginInterface`, un layout de admin y Latte + Flight vía Composer.

```bash
cd dev && composer install && cd ..
npm install                                   # Tailwind 4, sólo para compilar CSS
php dev/seed.php                              # o php dev/seed-demo.php
php -S 127.0.0.1:8080 -t dev/public dev/public/index.php
```

- **Entrar sin código:**
  - equipo: `/dev/equipo?email=camila@estudiopampa.cl`;
  - cliente: `/dev/cliente?email=josefa@panaderiaruiz.cl`.
- **Pruebas:**
  - SQLite: `php tests/run.php` (también compila todas las plantillas);
  - MariaDB: `docker start mdb` y luego `PORTAL_DB_DSN="mysql:host=127.0.0.1;dbname=portal_test;charset=utf8mb4" PORTAL_DB_USER=root PORTAL_DB_PASS=root php tests/run.php`.
- **Zips:** `bash scripts/zip.sh` (plugin) y `bash scripts/zip-landing.sh` (tema + invitaciones).
- **Si el simulador no basta:** cuando algo depende del núcleo real (cómo publica assets o qué variables entrega a un layout), pídele al usuario que corra un `grep` por SSH en `typedock/src` y pegue la salida.

---

## 8. Qué se puede hacer con TypeDock (lo que ya hicimos)

- **Sitio o landing con tema propio:**
  - layouts por página y SEO;
  - Google Analytics (G-TLZCJ1N0XE en usaprisma.com) con eventos (`Formulario_Lleno`);
  - JSON-LD;
  - robots, sitemap y llms.txt.
- **Plugin de formulario + servicio externo:** Invitaciones recibe `POST /invitacion`, guarda una copia local y sincroniza con Mailchimp.
- **Aplicación completa encima del CMS (Portal de Clientes):**
  - portal del cliente con login sin contraseña por código;
  - panel del equipo (`/equipo`) con permisos por cliente;
  - admin en `/admin/portal/...`;
  - proyectos, fases y Gantt, tareas, contenidos con versiones y aprobaciones;
  - reuniones con IA (Anthropic, OpenAI o Gemini), solicitudes, contactos ganados (mini-CRM), mensajes;
  - correos con diseño, cola y horario hábil por país;
  - cron e invitaciones de calendario (.ics).
- **Posibles a futuro, ya conversados:**
  - beta multi-agencia en subdominios (una instalación por agencia, con un script por SSH para crearlas y actualizarlas);
  - despliegue automático por SFTP.

---

## 9. Reglas para trabajar con el usuario

- Español de Chile, con «tú». Nunca voseo.
- Las instalaciones las hace él a mano en Hostinger. Entrégale **zips y pasos claros**: dónde va cada archivo y qué apretar en el admin.
- No inventes estilos: muestra una maqueta antes de cambiar lo que se ve.
- Nunca subas secretos al repo ni le pidas que los pegue en el chat.
- Después de cada cambio: zip (si aplica), capturas, commit y push.
- Más contexto del proyecto en `CLAUDE.md` y `README.md`.

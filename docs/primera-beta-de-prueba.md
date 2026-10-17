# Primera beta de prueba: qué hacer en Hostinger

Objetivo: tener **una** agencia de mentira funcionando (`prueba1.usaprisma.com`) para probar sincronizar versiones, correo de Prisma, prueba de IA y el onboarding, antes de invitar a nadie real.

Nombre sugerido: `prueba1.usaprisma.com` (no `beta.`: ese nombre conviene dejarlo para el hub o la página del programa).

## 0. Qué no hace falta
- **No me des acceso SSH ni claves.** Yo trabajo en un contenedor sin acceso a tu hosting. Tú ejecutas los comandos y me pegas **solo la salida** (que no incluya claves). Yo escribo los scripts y los pruebo aquí con la simulación.

## 1. Reconocimiento (10 min, solo lectura)
En hPanel → Avanzado → **Acceso SSH** (actívalo), conéctate y ejecuta:

```bash
php -v
php -m | grep -i -E "sodium|openssl|pdo_mysql|curl|zip|mbstring|intl"
php -i | grep -E "memory_limit|max_execution_time|upload_max_filesize|post_max_size|disable_functions"
df -h ~ | tail -2
which mysql mysqldump composer unzip rsync
cd ~ && pwd && ls
# estructura de tu portal actual (2 niveles, sin entrar a storage):
ls -la ~/domains/*/public_html 2>/dev/null | head -30
# ¿se permiten enlaces simbólicos?
mkdir -p ~/prueba_ln/a && ln -s ~/prueba_ln/a ~/prueba_ln/b && ls -l ~/prueba_ln && rm -r ~/prueba_ln
crontab -l 2>&1 | head -3
```
Y dime: **cómo instalaste TypeDock en proyectos.richgt.com** (asistente web en el navegador o por archivos), y en qué carpeta quedó `plugins/portal`.

## 2. En hPanel (manual, ~10 min)
1. **Subdominio** `prueba1.usaprisma.com` (carpeta raíz: la que use TypeDock, normalmente `public_html/prueba1` o su `public/`).
2. **Base de datos MySQL** + usuario para esa instancia. Hostinger les antepone un prefijo (`u123456789_…`). Anota nombre, usuario y clave **solo en tu gestor de claves**.
3. **SSL** del subdominio (se activa solo; confirma el candado).
4. **Correo** `notificaciones@usaprisma.com` y `dmarc@usaprisma.com`. Luego SPF/DKIM/DMARC según `docs/protocolo-beta.md` §8.

## 3. Instalar la agencia de prueba (a mano; será la «imagen dorada»)

Cómo viene TypeDock (README de la distribución para hosting compartido): dos carpetas, `public_html/` (lo público) y `typedock/` (el código, **fuera** del webroot), y al abrir el sitio te lleva solo al instalador. El mismo README dice cómo ubicar `typedock/` en otro lado: editar `TYPEDOCK_ROOT` en `public_html/index.php` y en `install.php`.

Eso es justo lo que necesitamos para varias agencias: **cada una lleva su propia carpeta `typedock/`** (no puede ser `~/typedock` para todas):

```
~/prisma/instancias/prueba1/typedock/     ← código, config, storage y plugins de ESA agencia
<raíz del subdominio>/index.php, install.php   ← con TYPEDOCK_ROOT apuntando a la carpeta de arriba
```

1. Sube el contenido de `public_html/` de la distribución a la raíz del subdominio `prueba1` y `typedock/` a `~/prisma/instancias/prueba1/typedock/`.
2. Edita `TYPEDOCK_ROOT` en `index.php` e `install.php` para que apunte a esa ruta.
3. Abre `https://prueba1.usaprisma.com` → instalador → BD nueva y usuario administrador.
4. Sube `portal-plugin-v0.10.0-beta.zip` con el zip-uploader del admin y comprueba `/admin/portal/...`.
5. Pégame el árbol resultante (`ls -R` a 3 niveles, sin `storage/`) y el contenido de `index.php`.

## 4. Probar el código compartido (enlace simbólico)
Con lo que me pegues, preparo los comandos exactos. En esencia:
```bash
mkdir -p ~/prisma/releases ~/prisma/secretos
# mover el plugin de prueba1 a ~/prisma/releases/portal-beta-0.10 y dejar un enlace en su lugar
```
y en la instancia: `SetEnv PORTAL_UPLOAD_DIR …` y `SetEnv PORTAL_SECRETOS_DIR …` (en `.htaccess`).
Tú creas a mano `~/prisma/secretos/correo.json` e `ia.json` (permisos 600) con tus claves; yo te doy la plantilla sin claves.

## 5. Qué probamos
- [ ] La instancia abre con el plugin enlazado.
- [ ] Archivos subidos van a la carpeta de **esa** agencia (no a `releases/`).
- [ ] Correo: la invitación a un contacto llega desde `notificaciones@usaprisma.com` con «… vía Prisma», sin caer en spam; SPF/DKIM/DMARC en PASS.
- [ ] IA: funciona sin clave propia, y se apaga al pasar la fecha.
- [ ] Cambiar el enlace a otra versión y volver atrás sin perder datos.

## 6. Lo que construyo yo mientras tanto
- `bin/prisma` (CLI en PHP): `estado`, `crear`, `actualizar`, `respaldar`, con modo `--simular` para probarlo sin tocar nada.
- `scripts/release.sh`: arma el tar de la versión y el `manifest.json`.
- Entrada `bootstrap` del plugin para crear al usuario 0 con enlace de primer ingreso.

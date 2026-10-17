# prisma: administrar las agencias beta

Herramienta de línea de comandos (PHP, sin dependencias) para el hosting compartido. Se usa por SSH.

## Instalación única en el servidor

```bash
mkdir -p ~/prisma && cd ~/prisma
# sube prisma-herramienta.zip aquí (File Manager o scp) y:
unzip prisma-herramienta.zip -d herramienta
echo 'alias prisma="php ~/prisma/herramienta/prisma/bin/prisma"' >> ~/.bashrc && source ~/.bashrc

# la distribución limpia de TypeDock (typedock-shared.zip), descomprimida así:
mkdir -p distribucion && unzip typedock-shared.zip -d distribucion   # el zip ya trae la carpeta typedock-shared/
#   → distribucion/typedock-shared/typedock/   y   distribucion/typedock-shared/public_html/

# el SMTP y la clave de IA de la beta, que ven todas las agencias (permisos 600, a mano, nunca en el chat):
mkdir -p secretos/compartido && chmod 700 secretos secretos/compartido
nano secretos/compartido/correo.json   # ver docs/protocolo-beta.md §9
nano secretos/compartido/ia.json
chmod 600 secretos/compartido/*.json

# la primera versión del plugin:
prisma version agregar ~/portal-plugin-v0.10.0-beta.zip
prisma actualizar --canal=canary --a=portal-0.10.0-beta --sin-http
```

## Crear una agencia

1. En hPanel: crea el subdominio (anota su carpeta), la base de datos vacía y su usuario.
2. `prisma secreto prueba1` y completa la base de datos en `secretos/instancias/prueba1.json`.
3. Simula primero:
   ```bash
   prisma crear prueba1 --host=prueba1.usaprisma.com --docroot=$HOME/domains/prueba1.usaprisma.com/public_html \
           --correo=DUENA@agencia.cl --nombre="Estudio Uno" --canal=canary --simular
   ```
   `--correo` es el de **la dueña de la agencia**, no el tuyo. El admin de respaldo queda a nombre de soporte (`--admin-correo=…`, por defecto `soporte@usaprisma.com`).
4. Quita `--simular`. Al terminar:
   - **Para la dueña:** entra a `https://prueba1.usaprisma.com/equipo/entrar`, escribe su correo, recibe un código y el **asistente de bienvenida** la lleva por 5 pantallas (agencia, primer cliente, primer proyecto, equipo). No ve nunca el admin de TypeDock.
   - **Para ti (soporte):** una clave temporal del admin de respaldo, que se muestra una sola vez y no se le da a la agencia.
5. **Una vez en todo tu hosting:** un cron en hPanel cada 10 minutos con `php ~/prisma/herramienta/prisma/bin/prisma cola`. Sirve para que salga sola la invitación al cliente a las 6 horas y los demás avisos.

Si algo falla a medias, deshace lo copiado (la base de datos hay que vaciarla a mano).

## Si alguien no puede entrar

```bash
prisma acceso prueba1        # código de ingreso de emergencia al panel de la dueña (vale 10 minutos)
prisma clave prueba1         # clave temporal nueva del admin de respaldo (soporte)
```
`acceso` va en este orden: la persona escribe su correo en `/equipo/entrar` (genera un código y, si el correo falla, no le llega) y **después** corres el comando y le das el código. Nada se pega en chats.

## Cambiar un valor del config.php de una agencia

```bash
prisma config prueba1 PORTAL_RAIZ=login      # la raíz del sitio lleva al ingreso de clientes
```
Para activar el asistente en una agencia creada antes de la beta 0.11:
```bash
prisma config prueba1 PORTAL_ONBOARDING=1 PORTAL_PRIMER_COORDINADOR=duena@agencia.cl PORTAL_AGENCIA_NOMBRE="Estudio Uno" PORTAL_CRON_TOKEN=$(php -r 'echo bin2hex(random_bytes(16));')
```
Conserva el resto de los valores y los permisos 0600. Las agencias nuevas ya nacen con `PORTAL_RAIZ=login`.

## Actualizar

```bash
prisma version agregar ~/portal-plugin-v0.11.0-beta.zip
prisma actualizar --canal=canary --a=portal-0.11.0-beta     # primero el canary
prisma actualizar --canal=estable --a=portal-0.11.0-beta    # después el resto
prisma actualizar --canal=estable --a=portal-0.10.0-beta    # volver atrás
prisma estado
```

Las migraciones del plugin corren solas con la primera visita a cada instancia. `actualizar` además republica los assets de cada una y comprueba que `/portal` responda.

## Cómo está armado

| Qué | Dónde |
|---|---|
| Plugin (una carpeta por versión) | `~/prisma/releases/portal-<versión>/` |
| Qué versión usa cada canal | `~/prisma/canales/{canary,estable}` (enlaces) |
| TypeDock de cada agencia | `~/prisma/instancias/<slug>/typedock/` (con `plugins/portal` → canal) |
| Sitio público de cada agencia | la carpeta del subdominio, con `TYPEDOCK_ROOT` apuntando a su `typedock/` |
| Registro | `~/prisma/instancias.json` |

Cada `config.php` (0600) lleva sus claves propias, `SELF_UPDATE_ENABLED=false` (el núcleo lo actualizas tú), `PORTAL_UPLOAD_DIR` propio y `PORTAL_SECRETOS_DIR` (correo e IA compartidos).

## Pruebas

```bash
TYPEDOCK_CORE_DIR=/ruta/al/clon/de/typedock-core php tests/prisma.php
```
Instala una agencia real con SQLite sobre una distribución armada al vuelo y levanta el servidor de PHP para comprobar que el plugin enlazado arranca. **MySQL no está cubierto por estas pruebas**: lo primero que se prueba en Hostinger es esa parte.

# Pruebas de la primera beta (`prueba1.usaprisma.com`)

Objetivo: tener **una agencia de mentira** funcionando en tu Hostinger para probar, de punta a punta, lo que usarán las betas reales: crear una agencia con un comando, correo de Prisma, prueba de IA y actualizar o volver atrás de versión. Nada de esto toca tu portal `proyectos.richgt.com`.

> Qué NO me das: acceso SSH, claves ni archivos de secretos. Tú corres los comandos y me pegas **solo la salida**. Antes de pegar, revisa que no lleve claves.

## 1. Reconocimiento (10 min, solo lectura)

hPanel → Avanzado → **Acceso SSH** (actívalo), conéctate y corre:

```bash
php -v
php -m | grep -i -E "sodium|openssl|pdo_mysql|curl|zip|mbstring|intl"
php -i | grep -E "memory_limit|max_execution_time|upload_max_filesize|post_max_size|disable_functions"
df -h ~ | tail -2
which mysql mysqldump unzip zip cp
cd ~ && pwd && ls
ls -d ~/domains/*/public_html 2>/dev/null
# ¿se permiten enlaces simbólicos? (lo más importante)
mkdir -p ~/prueba_ln/a && ln -s ~/prueba_ln/a ~/prueba_ln/b && ls -l ~/prueba_ln && rm -r ~/prueba_ln
```

Me pegas la salida. Lo que más me importa: **PHP ≥ 8.2**, que **`ln -s` funcione**, la ruta exacta de `public_html` (algo como `/home/u123456789/domains/usaprisma.com/public_html`) y que `disable_functions` **no** incluya `proc_open` ni `symlink`.

## 2. En hPanel (≈10 min)

| Qué | Detalle |
|---|---|
| Subdominio | `prueba1.usaprisma.com`. Anota la **carpeta** que Hostinger le asigna (normalmente `…/public_html/prueba1`) |
| Base de datos | MySQL **vacía** + usuario. Hostinger les pone prefijo (`u123456789_…`). Anótalos solo en tu gestor de claves |
| SSL | Se activa solo; confirma el candado |
| Correo | `notificaciones@usaprisma.com` ya creado; en DNS: un solo `_dmarc`, SPF y DKIM (ver `docs/protocolo-beta.md` §8) |

## 3. Subir tres archivos a `~/prisma`

Por el File Manager o `scp`, a la carpeta `~/prisma` (créala si no existe):

1. `prisma-herramienta.zip` (te lo envié en el chat).
2. `typedock-shared.zip`: el mismo de TypeDock que usaste para instalar tu portal.
3. `portal-plugin-v0.10.0-beta.zip` (te lo envié en el chat).

## 4. Preparar `~/prisma` (una sola vez)

```bash
cd ~/prisma
unzip -q prisma-herramienta.zip -d herramienta
echo 'alias prisma="php ~/prisma/herramienta/prisma/bin/prisma"' >> ~/.bashrc && source ~/.bashrc

mkdir -p distribucion && unzip -q typedock-shared.zip -d distribucion/typedock-shared
ls distribucion/typedock-shared          # debe mostrar: public_html  typedock

prisma version agregar ~/prisma/portal-plugin-v0.10.0-beta.zip
prisma actualizar --canal=canary --a=portal-0.10.0-beta --sin-http
prisma estado
```

**Secretos compartidos** (a mano, en el servidor; nunca en el chat ni en el repo):

```bash
mkdir -p ~/prisma/secretos/compartido && chmod 700 ~/prisma/secretos ~/prisma/secretos/compartido
nano ~/prisma/secretos/compartido/correo.json
nano ~/prisma/secretos/compartido/ia.json
chmod 600 ~/prisma/secretos/compartido/*.json
```

`correo.json` (la clave es la del buzón `notificaciones@`):
```json
{ "host": "smtp.hostinger.com", "puerto": 465, "seg": "ssl",
  "usuario": "notificaciones@usaprisma.com", "clave": "…",
  "desde": "notificaciones@usaprisma.com", "tope_diario": 100 }
```

`ia.json` (una clave dedicada a la beta, con tope de gasto mensual en la consola del proveedor):
```json
{ "proveedor": "anthropic", "clave": "…", "dias_prueba": 30, "tope_diario": 60 }
```

## 5. Crear la agencia

```bash
prisma secreto prueba1
nano ~/prisma/secretos/instancias/prueba1.json     # completa base de datos, usuario y clave de hPanel

# 1) simulación: no toca nada, muestra lo que haría
prisma crear prueba1 --host=prueba1.usaprisma.com \
  --docroot=/home/u123456789/domains/usaprisma.com/public_html/prueba1 \
  --correo=TU_CORREO --nombre="Estudio de Prueba" --canal=canary --simular

# 2) de verdad (mismo comando sin --simular)
```

Al final entrega un **enlace de primer ingreso** (vale 7 días). Ábrelo, define tu clave y entra a `https://prueba1.usaprisma.com/admin`. Si algo falla a medias, deshace lo copiado; solo hay que **vaciar la base de datos** antes de reintentar.

## 6. Lista de pruebas

Marca cada una. Si una falla, pégame el mensaje.

**Instalación**
- [ ] `prisma crear` termina sin errores y la prueba de humo dice que inicio, portal y admin responden.
- [ ] Entras con el enlace de primer ingreso y `/admin/portal/...` abre.
- [ ] El enlace de primer ingreso **no sirve una segunda vez** después de usarlo.

**Aislamiento** (lo que prometes en la landing)
- [ ] Sube un archivo a un cliente de prueba. Luego en SSH:
  `ls ~/prisma/instancias/prueba1/typedock/storage/portal_uploads` → el archivo está ahí.
  `ls -a ~/prisma/releases/portal-0.10.0-beta` → **no** hay `storage`, `portal_uploads` ni `.ia_secret`.
- [ ] `ls -l ~/prisma/instancias/prueba1/typedock/config.php` → permisos `-rw-------`.

**Correo de Prisma**
- [ ] Invita a un contacto con tu Gmail: llega desde `notificaciones@usaprisma.com`, nombre «Estudio de Prueba vía Prisma».
- [ ] Gmail → ⋮ → *Mostrar original*: `SPF: PASS`, `DKIM: PASS`, `DMARC: PASS`. No cae en spam.
- [ ] Al responder ese correo, la respuesta va al correo de la agencia (Reply-To), no a `notificaciones@`.

**IA (prueba de 30 días)**
- [ ] Sin poner clave propia, analiza una reunión con IA y funciona.
- [ ] Simula el vencimiento (phpMyAdmin, base de prueba1):
  `UPDATE portal_ajustes SET valor='2020-01-01' WHERE owner_tipo='global' AND owner_id='portal' AND clave='ia_prueba_hasta';`
  → la IA queda apagada. Pon tu propia clave en Ajustes y vuelve a funcionar.

**Versiones**
- [ ] Cuando exista una versión nueva: `prisma version agregar …`, `prisma actualizar --canal=canary --a=portal-<nueva>` y el portal sigue abriendo sin perder datos.
- [ ] Volver atrás: `prisma actualizar --canal=canary --a=portal-0.10.0-beta` y todo sigue bien.
- [ ] `prisma estado` muestra la versión correcta de la instancia.

**Cierre**
- [ ] Anota cuánto demoró `prisma crear` y cuánto espacio ocupa la instancia (`du -sh ~/prisma/instancias/prueba1`). Con 10 agencias, ese número ×10 es lo que usarás de disco.

## 7. Qué pegarme en el chat

Sí: la salida de los comandos de §1, de `prisma crear --simular`, de `prisma estado`, y los mensajes de error.
No: `correo.json`, `ia.json`, `secretos/instancias/*.json`, el contenido de `config.php`, claves de la base de datos ni el enlace de primer ingreso.

## 8. Qué está probado y qué no

La herramienta `prisma` está probada con un TypeDock real sobre **SQLite**. **MySQL de Hostinger, los permisos del hosting y la ruta real de `public_html` no están probados**: son justamente el objetivo de esta guía.

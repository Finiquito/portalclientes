# Protocolo beta de Prisma (usaprisma.com)

Borrador de diseño. Nada de esto está construido todavía: es la base para decidir y luego armar por etapas.
Supuesto de partida: **una instalación de TypeDock por agencia beta**, cada una en su subdominio (`agencia.usaprisma.com`), todas en tu hosting compartido de Hostinger.

> Lo que no pude verificar desde aquí (confírmalo en tu hPanel antes de construir): si tu plan tiene **SSH**, **cron**, cuántas **bases de datos** permite, **subdominios ilimitados** y **SSL automático por subdominio**. Todo el protocolo está pensado para funcionar con "SSH + cron + PHP CLI"; si no hay SSH, hay una variante (ver §1.4).

---

## 0. Mapa general

```
usaprisma.com  (TypeDock + tema Prisma + plugin «beta-hub»)   ← el centro de mando
  ├─ /api/beta/manifest.json     versión vigente, sha256, notas, canal
  ├─ /api/beta/heartbeat         las instancias avisan "estoy vivo, versión X"
  ├─ /api/beta/tickets           las instancias envían reportes y propuestas
  └─ /admin/beta                 registro de instancias, tickets, novedades

~/prisma/                         (fuera de public_html, en tu cuenta Hostinger)
  ├─ releases/portal-0.36.0/ … 0.37.0/    código del plugin, una carpeta por versión
  ├─ current → releases/portal-0.37.0     enlace simbólico: lo que usan todos
  ├─ base/                        "imagen dorada": TypeDock limpio + volcado SQL vacío
  ├─ instancias.json              registro (subdominio, carpeta, BD, versión, estado)
  ├─ bin/prisma                   herramienta CLI en PHP (crear, actualizar, estado, respaldar)
  └─ secretos/                    credenciales de BD por instancia (permisos 600, nunca en el repo)

public_html/
  ├─ (usaprisma.com)
  ├─ agencia1/   TypeDock de la agencia 1   → plugins/portal  →  ~/prisma/current
  └─ agencia2/   …
```

---

## 1. Sincronizar versiones entre subdominios

### 1.1 Recomendación: código compartido por enlace simbólico

Hoy cada instancia tendría su propia copia del plugin, y actualizar 10 betas = 10 subidas manuales de zip. En vez de eso:

- El plugin vive **una sola vez** en `~/prisma/releases/portal-X.Y.Z/`.
- En cada instancia, `typedock/plugins/portal` es un **enlace simbólico** a `~/prisma/current`.
- **Actualizar a todos = cambiar un enlace** (`ln -sfn releases/portal-0.37.0 current`). Instantáneo y atómico.
- **Volver atrás = apuntar el enlace a la versión anterior.** Sin descargas.
- Las migraciones ya corren solas al primer request (`$ctx->migrate()` + `Schema::asegurar`, idempotentes y que no borran datos). Cada instancia se migra sola la primera vez que alguien la abre.

**Regla de oro para que el rollback sea seguro:** las migraciones solo **agregan** (tablas/columnas nuevas, nunca renombrar ni borrar en la misma versión). Es lo que ya hace `Schema.php`. Si una versión sale mala, vuelves el enlace atrás y la BD sigue sirviendo.

### 1.2 ⚠ Trampa a corregir antes: rutas relativas al plugin

Con enlace simbólico, PHP resuelve `__DIR__` a la carpeta **real** (`~/prisma/releases/...`). Hoy `ArchivoService.php:59` hace `dirname(__DIR__, 3) . '/storage/portal_uploads'` y `Cifrado` guarda `.ia_secret` en ese mismo directorio. Con el enlace, **los archivos de los clientes y la clave AES de todas las agencias caerían en una carpeta compartida**. Eso rompería el aislamiento que prometes en la landing.

Arreglo (pequeño, va en la v0.37): que el plugin reciba el directorio de datos desde la instancia (constante `PORTAL_STORAGE` o `$ctx`), y que solo use `dirname(__DIR__, 3)` como último recurso. Con prueba que falle si el storage queda dentro del directorio del código. **Este es el primer cambio de código del plan.**

### 1.3 Canales y orden de salida

| Canal | Quién | Para qué |
|---|---|---|
| `canary` | tu propia instancia (`proyectos.richgt.com`) + 1 beta amiga | probar primero, 24–48 h |
| `estable` | el resto de las betas | después del canary |

Flujo de una versión:
1. `scripts/zip.sh` (ya existe) → pasa pruebas → `scripts/release.sh` (nuevo): genera `portal-X.Y.Z.tar.gz`, `sha256` y un `manifest.json` con versión, notas en lenguaje simple, `min_core`, canal.
2. `prisma actualizar --canal canary` → mueve el enlace de las instancias canary.
3. Esperas, miras el heartbeat/errores. Si está bien: `prisma actualizar --canal estable`.
4. El hub publica la novedad ("Lo que pediste → listo en v0.37") y avisa a las agencias.

Con enlace único, canary y estable necesitan **dos enlaces** (`current-canary`, `current-estable`) y cada instancia apunta a uno. Simple.

### 1.4 Variantes según lo que permita tu plan

| Situación | Cómo sincronizar |
|---|---|
| SSH + cron (ideal) | enlaces simbólicos + `bin/prisma` como arriba |
| Sin SSH, con cron y PHP CLI | el cron corre `php ~/prisma/bin/prisma actualizar`; los enlaces se crean por PHP (`symlink()`) |
| Sin enlaces simbólicos permitidos | **pull**: cada instancia revisa el `manifest.json` una vez al día, descarga el tar, verifica sha256 + firma (libsodium viene en PHP 8.2), copia a una carpeta nueva y cambia el nombre. Más lento y con más piezas, pero funciona en cualquier hosting |
| Betas en otras cuentas de hosting | solo funciona el modo **pull** |

**Aviso en el producto:** en el admin de cada agencia, un banner "Hay una versión nueva · qué cambia" (lee el manifest). Hoy lo empujas tú; más adelante podrían elegir cuándo.

### 1.5 El núcleo de TypeDock también debe versionarse

Cada instancia lleva su copia del núcleo. Mantén **una versión de núcleo soportada** por tanda de betas (campo `min_core_version` ya existe en `plugin.json`) y trátalo como una versión de la imagen dorada: cuando subas el núcleo, creas una imagen nueva y migras las instancias una por una, con respaldo. No lo ates a los enlaces del plugin al principio: es el cambio más riesgoso.

### 1.6 Registro y salud

`instancias.json` (o tabla en el hub) con: subdominio, carpeta, nombre de la BD, canal, versión actual, fecha de alta, estado (`activa`, `pausada`, `baja`), correo del usuario 0.

**Heartbeat diario** (cron de cada instancia → `POST usaprisma.com/api/beta/heartbeat`), firmado con un secreto propio de la instancia, con solo agregados:
`{instancia, version, php, nº clientes, nº proyectos, última actividad}`.
Nunca nombres de los clientes de la agencia ni contenido. Se avisa en los términos de la beta. Te da: quién usa de verdad el producto, quién quedó con versión vieja, quién se cayó.

---

## 2. Sistema de tickets (reportar problemas y proponer mejoras)

### 2.1 Idea

Un botón fijo **«Ayuda · Reportar o proponer»** dentro del admin y del panel de equipo de cada instancia. Abre un formulario corto. Lo que se envía llega al hub en usaprisma.com y se vuelve un ticket tuyo.

**Formulario (3 campos, no más):** tipo (🐞 Algo falló · 💡 Propongo una mejora · ❓ Una duda), qué pasó / qué necesitas, y opcionalmente una captura.

**Contexto que se adjunta solo** (la agencia no tiene que saber explicarlo): versión del plugin, URL de la pantalla, rol del usuario, navegador, versión de PHP y las últimas líneas del log de errores **con datos personales filtrados**. Esto es lo que más tiempo te ahorra.

### 2.2 Dónde vive

**Plugin `beta-hub` en usaprisma.com** (reutiliza el mismo TypeDock/plugin que ya dominas):

- Tablas: `tickets`, `ticket_mensajes`, `ticket_votos`, `instancias`.
- Estados: `nuevo → en revisión → planificado → en curso → listo (vX.Y.Z)` · `descartado` (con motivo).
- Cada instancia tiene un **token propio** (HMAC) al crearse; así nadie puede inventar tickets "desde" otra agencia.
- La agencia ve **sus** tickets y el estado dentro de su propio panel; recibe correo cuando cambia (SMTP del hub).
- **Votos** en propuestas ("a mí también me sirve"), para priorizar con evidencia y no con el último que gritó.
- **Página pública `usaprisma.com/novedades`**: «Lo que pediste → ya está». Es marketing gratis y mantiene motivados a los betas.

### 2.3 Para tu flujo de trabajo (con Claude)

El hub puede **espejar cada ticket como un Issue** en el repo (etiquetas `bug` / `mejora`, versión, instancia). Así abres un chat y dices "resuelve el issue 14" sin copiar nada, y al cerrar el issue con el commit, el ticket pasa a «listo en vX». El repo deja de ser solo código: es tu backlog.

### 2.4 Por etapas (para no sobreconstruir)

1. **MVP (1–2 días):** formulario → un POST al hub que (a) te manda un correo y (b) crea el issue. Sin tablero, sin votos.
2. **Tablero y estados** en `/admin/beta` + correo de cambio de estado.
3. **Votos + novedades públicas.**

Si el hub cae, el botón guarda el reporte en una cola local y reintenta (el plugin ya tiene `portal_correos_cola` como patrón).

---

## 3. Onboarding de una agencia beta

### 3.1 Qué haces tú a mano (cada vez, unos 10–15 min) y qué hace el script

| Paso | Quién | Cómo |
|---|---|---|
| 1. Crear subdominio `agencia.usaprisma.com` | **Tú**, hPanel | apuntando a `public_html/agencia` |
| 2. Crear base de datos + usuario | **Tú**, hPanel | anotas nombre y clave en `~/prisma/secretos/agencia.json` (nunca en el repo ni en el chat) |
| 3. Activar SSL del subdominio | Tú (o automático de Hostinger) | |
| 4. Copiar la "imagen dorada" de TypeDock a la carpeta | **Script** | `prisma crear agencia --correo=…` |
| 5. Escribir `.env`/config con la BD, URL, claves | **Script** | genera claves nuevas **por instancia** (AES, token de heartbeat) |
| 6. Importar el esquema (volcado limpio) y correr migraciones | **Script** | |
| 7. Enlazar `plugins/portal → current` y activar el plugin | **Script** | |
| 8. Crear el **usuario 0** (admin de esa agencia) con un enlace de primer ingreso | **Script** | |
| 9. Registrar la instancia en `instancias.json` / hub | **Script** | |
| 10. Prueba de humo (abre `/portal` y `/equipo`, espera 200) | **Script** | |
| 11. Mandarle el correo de bienvenida con el enlace | **Script**, tú lo revisas | |
| 12. Poner su clave de IA y SMTP | **Él/ella** en el admin (o tú, nunca por chat) | |

### 3.2 La "imagen dorada" (la pieza clave)

No intentes automatizar el instalador gráfico de TypeDock cada vez. Haz **una instalación perfecta a mano** y congélala:

- Carpeta de TypeDock instalado, **sin** `.env`, sin storage, sin cachés.
- Volcado SQL con el esquema del núcleo, **sin** usuarios ni datos.
- Un archivo `base/VERSION` con la versión del núcleo.

`prisma crear` solo clona esa imagen y personaliza. Esto es repetible, testeable en tu máquina (con el `dev/` que ya tienes) y reduce el riesgo de que dos betas queden distintas. Cuando cambies de versión del núcleo, rehaces la imagen una vez.

### 3.3 Usuario 0 y primer recorrido (esto sí va en el plugin)

**Enlace de primer ingreso:** token de un solo uso, guardado como hash, vence en 7 días. El usuario 0 define su clave y entra. No se manda ninguna contraseña por correo.

**Asistente de arranque** (primera vez que entra, saltable, retomable):
1. **Tu agencia:** nombre, logo, color (usa la personalización que ya existe).
2. **Tu equipo:** invitar a 1–3 personas (opcional).
3. **Tu primer cliente:** nombre y la persona de contacto.
4. **Su primer proyecto:** elegir una **plantilla de línea de tiempo** (el plugin ya las tiene).
5. **Invitar al contacto** del cliente (el correo de invitación ya existe).

**Después:** una tarjeta **«Primeros pasos»** en el inicio, con avance calculado a partir de los datos (¿hay ≥1 cliente? ¿≥1 proyecto? ¿se invitó a alguien? ¿el cliente entró?), no con banderas manuales. Se oculta sola al completarse.

**Ejemplo opcional:** botón «Cargar un cliente de ejemplo» (usa la misma lógica de `dev/seed-demo.php`) que se puede **borrar de una vez** con otro botón. Mucha gente entiende mejor un portal lleno que uno vacío.

**Cómo conecta con el script:** el script no habla HTTP con la instancia. Llama una entrada CLI del plugin (`php typedock/plugins/portal/bin/bootstrap.php --correo=… --agencia=…`) que crea el usuario 0 y el token. Así no hay endpoints "secretos" expuestos en producción.

### 3.4 Datos de ciclo de vida

- **Respaldo:** `prisma respaldar agencia` (volcado de BD en PHP + zip del storage) antes de cada actualización de núcleo y semanal. Guarda en `~/prisma/respaldos/`, fuera del web-root. Ojo: el espacio compartido se llena rápido con archivos de clientes.
- **Baja:** `prisma pausar` (pone una página de mantención), `prisma exportar` (le entregas sus datos) y `prisma eliminar` (con doble confirmación). Define en los términos qué pasa con los datos al terminar la beta.
- **Límites:** cuota de almacenamiento por agencia, para que una no consuma toda la cuenta compartida.

---

## 4. Riesgos propios del hosting compartido

- **Un solo lugar de falla.** Si la cuenta se suspende o se llena el disco, caen todas las betas a la vez. Mantén respaldos *fuera* de Hostinger (descarga semanal a Drive).
- **Límite de procesos/CPU.** Varias instancias con IA y envíos de correo pueden toparse. `Diferido`/cola de correos ayuda; monitorea con el heartbeat.
- **Sin root, sin Docker, sin colas.** Todo con PHP + cron. Por eso el diseño es "archivos + enlaces + CLI".
- **Cron mínimo:** suele permitirse cada 1–5 min; basta para cola de correos, heartbeat y recordatorios.
- **Seguridad:** BD y claves *por instancia*, nunca compartidas; el hub solo recibe agregados; `secretos/` fuera de `public_html`; los archivos de los clientes no van cifrados dentro del servidor (sigue siendo cierto: no lo prometas).
- **Cuándo cambiar de modelo:** pasadas ~15–25 betas activas, o si alguien exige aislamiento fuerte, mirar un VPS barato. Con este diseño (CLI, imagen, registro) la migración es copiar y apuntar. Una sola instalación multi-agencia (multi-tenant) es otro proyecto: el esquema hoy no tiene `agencia_id` y no lo recomiendo para la beta.

---

## 5. Orden sugerido de construcción

| # | Qué | Por qué primero | Esfuerzo |
|---|---|---|---|
| 1 | Sacar el storage/clave del `__DIR__` (§1.2) + prueba | Sin esto el enlace compartido filtra datos entre agencias | Chico |
| 2 | Imagen dorada + `prisma crear` a mano en una beta de prueba | Valida todo el flujo con un caso real | Mediano |
| 3 | Botón de ayuda → correo + issue (tickets MVP) | Ya sirve desde la primera beta | Chico |
| 4 | Asistente de arranque + «Primeros pasos» + enlace de primer ingreso | Lo que ve la agencia el día 1 | Mediano |
| 5 | `release.sh`, manifest, canal canary/estable, `prisma actualizar` | Cuando haya 3+ betas | Mediano |
| 6 | Heartbeat + registro en el hub | Para ver la flota | Chico |
| 7 | Tablero de tickets, votos, página de novedades | Cuando haya volumen | Mediano |
| 8 | Respaldo/baja/cuotas | Antes de la beta pública | Mediano |

## 6. Decisiones que necesito de ti

1. ¿Tu plan de Hostinger tiene **SSH y cron**? (define si usamos enlaces o el modo pull)
2. ¿Todas las betas irán **dentro de la misma cuenta**?
3. ¿Qué incluyes en la **clave de IA**: cada agencia pone la suya, o tú pones una con tope de gasto?
4. ¿Aceptas el **heartbeat con agregados** (se avisa en los términos)?
5. ¿Los tickets: partimos con **correo + issue** o directo al tablero en el hub?

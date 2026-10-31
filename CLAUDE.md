# Contexto para Claude

## Quién y cómo
- El usuario es chileno, frontend con 15 años de experiencia, algo de backend y mucho diseño. Trabaja como freelancer.
- **Escribe siempre en español de Chile con «tú».** Nunca voseo («querés», «pensá»).
- **Diseño:** no inventes estilos; si un cambio altera lo que se ve, muestra antes una maqueta, lámina o idea y pregunta.
- **Instalaciones:** las hace él a mano en Hostinger. Entrégale zips y pasos claros: dónde va cada archivo y qué apretar en el admin.
- **Después de cada cambio:** zip (si aplica), capturas, commit y push. Envía los archivos con SendUserFile.
- **Secretos:** nunca subas al repo claves, tokens ni contraseñas, y nunca le pidas que los pegue en el chat. Las claves se ingresan en el admin.
- **No abras PR** salvo que lo pida.
- **Portal del cliente:** está bien como está; cambia lo del lado de la agencia y toca el del cliente solo si es necesario.

## Ramas (ver `docs/ramas.md`)
- **Personal** (`claude/youthful-gates-tjxgp0`, la rama por defecto): su portal en `proyectos.richgt.com`, versiones `0.36.x`.
- **Beta** (`beta`): portal para las agencias beta, versiones `0.N.M-beta` (se dice «beta 0.N»; hoy **beta 0.14**). Protocolo y decisiones en `docs/protocolo-beta.md`.
- `prisma/` (solo en `beta`): herramienta CLI para crear y actualizar agencias en el hosting. Pruebas: `php tests/prisma.php` (necesita un clon de `typedock/core` con `composer install`; ver `prisma/README.md`). Zip: `bash scripts/zip-prisma.sh`.
- Asistente de bienvenida de las agencias beta: `portal/templates/equipo/bienvenida.latte` es **generado**; no se edita a mano. Se rehace con `python3 dev/maquetas/armar-latte.py` (usa la maqueta aprobada `dev/maquetas/onboarding-agencia.src.html` y `dev/maquetas/js-onboarding.js`). Recorrido real en navegador: `dev/maquetas/e2e-onboarding.js`. Contraste de colores: `portal/src/ColorMarca.php` y `dev/maquetas/colores.html`.
- Antes de trabajar, confirma en qué rama estás. Lo personal se hace en la personal; lo de las agencias, en `beta`. Las mejoras pasan de la personal a la beta con merge, nunca al revés sin preguntar.

## Qué hay en el repo
- `portal/`: plugin **Portal de Clientes** para TypeDock (PHP 8.2+, Latte 3.1, Flight). Se instala en `proyectos.richgt.com`. Tiene el portal del cliente (`/portal`), el panel del equipo (`/equipo`) y el admin (`/admin/portal/...`). Detalles y lista de cambios en `README.md`.
- `theme/prisma/`: tema del landing **Prisma** (usaprisma.com), con Google Analytics G-TLZCJ1N0XE, el evento `Formulario_Lleno` y datos estructurados. `theme/raiz-usaprisma/` tiene robots.txt, sitemap.xml y llms.txt, que van en `public_html/`.
- `invitaciones/`: plugin de lista de espera (POST `/invitacion` → Mailchimp).
- `dev/`: núcleo de TypeDock simulado para desarrollar. `dev/videos/`: videos de recorrido (ver abajo).
- `entregas/` (en .gitignore): zips, videos y láminas que se le entregan.

## Flujo de trabajo
- **Pruebas:** `php tests/run.php` (SQLite). Para MariaDB: `docker start mdb` y luego `PORTAL_DB_DSN="mysql:host=127.0.0.1;dbname=portal_test;charset=utf8mb4" PORTAL_DB_USER=root PORTAL_DB_PASS=root php tests/run.php`. Las pruebas compilan todas las plantillas: Latte 3.1 convierte los avisos en error (en las plantillas, las constantes de PHP van con `\` inicial).
- **Zip del plugin:** sube la versión en `portal/plugin.json`, en `PortalPlugin::getVersion()` y en «Cambios» del README; después `bash scripts/zip.sh` → `dist/portal-plugin-vX.zip`, que se copia a `entregas/`.
- **Zips del landing:** `bash scripts/zip-landing.sh`. Al actualizar el tema en el servidor hay que apretar **Apariencia → Temas → Vista previa**, para que TypeDock vuelva a copiar los assets a `public_html/themes/prisma/assets/`.
- **Servidor de desarrollo:** `php -S 127.0.0.1:8080 -t dev/public dev/public/index.php`. Para entrar sin código: `/dev/equipo?email=camila@estudiopampa.cl` y `/dev/cliente?email=josefa@panaderiaruiz.cl`.

## Videos de recorrido (`dev/videos/`)
- Hay 7 guiones (`v1`…`v7`), en 1080×1350, con portada de gancho en primera persona. Cierran con dos frases que se alternan:
  - videos impares: «Hizo más rápido mi trabajo diario. Pruébalo y me cuentas cómo te va.»
  - videos pares: «Es simple y fácil de implementar. Si me funciona a mí, te puede funcionar a ti.»
  - y en todos, el botón «Prueba la beta gratis → usaprisma.com» con «Cupos limitados».
- Cómo grabar: ver `dev/videos/README.md` (datos con `preparar.sh`, servidor en el puerto 8082 con `PORTAL_IA_DEMO=1`, IA simulada). `node dev/videos/laminas.js <carpeta>` deja la vista previa de portadas y cierres para que él las apruebe antes de grabar.
- **Narración del video 1:** guion por bloques con tiempos en `dev/videos/narracion-v1.md`. Él genera un MP3 por bloque en ElevenLabs (`01.mp3` … `10.mp3`). Después hay que montar cada clip en su segundo sobre el video con ffmpeg (`pip install imageio-ffmpeg`).

## Marketing (ya hecho)
- Post de LinkedIn en primera persona (freelancer, caos de WhatsApp y correo, beta gratis a cambio de comentarios), imágenes para el post y para la story.
- Sobre la seguridad, decir solo lo que es cierto: los datos de cada cliente están separados, todo viaja por HTTPS y las claves van cifradas con AES-256. Los archivos de los clientes NO están cifrados dentro del servidor.

# Archivos de la raíz de usaprisma.com

Van directo en `public_html/` (al lado de `index.php`): `robots.txt`, `sitemap.xml` y `llms.txt`. No son parte del tema; se suben a mano.

## `condiciones/`

La página pública de **condiciones de uso** (`https://usaprisma.com/condiciones/`). Se copia la carpeta `condiciones/` entera a `public_html/`. Es un **borrador**: completa los textos `[COMPLETAR: …]`, haz que lo revise un abogado y borra el comentario de arriba. El asistente de bienvenida de las agencias enlaza a esta dirección (`OnboardingService::CONDICIONES_URL`, o la variable `PORTAL_URL_CONDICIONES`). Si cambias el texto, sube la versión en la página y en `OnboardingService::CONDICIONES_VERSION`: así cada agencia vuelve a aceptar.

## `privacidad/` y `cookies/`

Otras dos páginas públicas (borradores, mismas reglas que `condiciones/`): `https://usaprisma.com/privacidad/` (la que enlaza el pie del ingreso del portal: quién responde por los datos de los contactos y qué cookies usa el portal) y `https://usaprisma.com/cookies/` (Google Analytics del landing y cómo cambiar la elección). Copia las carpetas enteras a `public_html/`. La de cookies usa `window.prismaCookies`, que define el tema Prisma (inline en `layouts/base.latte`): al actualizar el tema, apreta **Apariencia → Temas → Vista previa**.

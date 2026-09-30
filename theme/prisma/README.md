# Tema Prisma: landing de prismahub.com

Landing de una página para Prisma, el portal de clientes ofrecido a estudios, agencias chicas y freelancers de Chile. Su objetivo es una sola acción: **pedir una invitación**. Las inscripciones las recibe el plugin `invitaciones/`, que las guarda y las envía a Mailchimp.

## Instalación en TypeDock

1. Sube la carpeta `prisma` a `typedock/themes/prisma` (no a `public_html/themes`: el núcleo publica solo los `assets` en `/themes/prisma/assets/`).
2. En el admin, ve a **Apariencia → Temas** y activa **Prisma**.
3. Crea una página (por ejemplo «Inicio»), elige el layout **Landing Prisma** en el editor y déjala como página de inicio (**Ajustes → Sitio → Inicio: página**). Si el inicio está en modo «archivo», también se ve el landing: `layouts/home.latte` es la misma página.
4. Instala el plugin `invitaciones` y, en **Invitaciones**, guarda la clave completa de Mailchimp (termina en `-us…`), la audiencia `fc1f3c0eb6` y el campo del tamaño (`TAMANO` o `MERGE7`). Aprieta «Probar conexión».

## Estructura

```
theme.json                         nombre, versión y el layout «Landing Prisma» para el editor
layouts/base.latte                 <head> (usa el SEO del núcleo), barra y pie
layouts/home.latte                 el landing completo
layouts/landing.latte              el mismo landing, elegible en cualquier página
layouts/page|single|archive|…      plantillas mínimas que exige el núcleo, con la misma gráfica
layouts/403|404|500.latte          páginas de error
partials/logo.latte                wordmark «Prism» + marca triangular
partials/texto.latte               bloque de texto de las plantillas mínimas
partials/despiece-*.latte          ilustraciones SVG en línea (generadas; no editar a mano)
assets/css/prisma.css              estilos: colores, tipos y medidas como tokens en :root
assets/js/prisma.js                despiece animado y envío del formulario (sin dependencias)
assets/fonts/                      Schibsted Grotesk (OFL), alojada en el tema: sin CDN externo
assets/img/                        capturas reales del portal y del panel (datos de demo)
assets/screenshot.png              miniatura para la lista de temas
tools/despiece.py                  genera las ilustraciones isométricas
```

## Variables

- `$accion`: adónde se envía el formulario (por defecto `/invitacion`, la ruta del plugin).
- `$seo`, `$site`: si el núcleo las entrega, el `<head>` las usa; si no, hay título y descripción propios.

## Ilustraciones

```bash
python3 theme/prisma/tools/despiece.py
```

Las piezas, sus colores, sus textos y cuánto se separan en el despiece están en la lista `PIEZAS` del script. El grano es un filtro SVG (`feTurbulence`), sin imágenes.

## Decisiones de diseño

Se siguieron las skills `hallmark` y `anti-slop-ui-workflow` (en `.claude/skills/`):

- **Estructura «Split Studio»:** dípticos de texto y prueba que alternan de lado. No hay hero centrado ni tres tarjetas con íconos.
- **Una sola grotesca en varios pesos:** es la decisión suiza. Se evitó Inter.
- **`h1` de 60 px como máximo.** No se usan cursivas en títulos.
- **Color:**
  - tinta en el azul marino del logo;
  - rojo sólo para acciones;
  - la paleta de la ilustración de referencia sólo en las piezas;
  - no se usa negro ni blanco puro.
- **Contenido honesto:** capturas reales del producto. No hay métricas ni testimonios inventados.
- **Sin numeración en las secciones:** las dos skills la marcan como típica de páginas hechas por IA. La guía suiza original la pedía.
- **Verificado sin desborde horizontal** a 320, 375, 414, 768 y 1366 px. Respeta «reducir movimiento».

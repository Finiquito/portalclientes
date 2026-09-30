# Tema Prisma: landing de prismahub.com

Landing de una página para Prisma, el portal de clientes ofrecido a estudios, agencias chicas y freelancers de Chile. Su objetivo es una sola acción: **pedir una invitación**. Las inscripciones las recibe el plugin `invitaciones/`, que las guarda y las envía a Mailchimp.

## Estructura

```
templates/home.latte                 la página completa (Latte)
templates/partials/logo.latte        wordmark «Prism» + marca triangular
templates/partials/despiece-*.latte  ilustraciones SVG en línea (generadas; no editar a mano)
assets/css/prisma.css                estilos: todos los colores, tipos y medidas como tokens en :root
assets/js/prisma.js                  despiece animado y envío del formulario (sin dependencias)
assets/fonts/                        Schibsted Grotesk (OFL), alojada en el tema: sin CDN externo
assets/img/                          capturas reales del portal y del panel (datos de demo)
tools/despiece.py                    genera las ilustraciones isométricas
```

## Variables de la plantilla

Las dos tienen valor por defecto, así la plantilla funciona sola:

- `$assets`: URL base de los archivos del tema (por defecto `/themes/prisma/assets`).
- `$accion`: adónde se envía el formulario (por defecto `/invitacion`, la ruta del plugin).

## Pendiente para instalarlo en TypeDock

El zip de ejemplo (`kinari`) sólo traía la carpeta `assets`, así que todavía falta saber:

- cómo se declara un tema (¿`theme.json`?);
- qué plantilla usa el home y qué variables le pasa el núcleo;
- en qué URL se sirven los `assets` del tema.

Con un tema completo de ejemplo se ajustan el nombre del archivo y `$assets`. El resto no cambia.

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

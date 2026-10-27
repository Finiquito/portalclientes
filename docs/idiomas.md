# Idiomas (v0.47)

Español (por defecto) e inglés. El idioma es **de cada persona**: contactos del cliente y personas del equipo.

## Qué idioma se usa

1. El que la persona eligió (Ajustes del portal / Mis ajustes del panel). Se guarda en `portal_ajustes` (`idioma`).
2. Si eligió uno en la pantalla de ingreso (cookie `portal_idioma`) y aún no tenía, se le guarda al entrar.
3. El del cliente (ficha del cliente → Personalización).
4. El de la agencia (Portal · Ajustes → Idioma por defecto).
5. Español.

Los correos y avisos usan el idioma **de quien los recibe** (`Idioma::de()` sin cookie).

## Cómo se traduce

- **El texto en español es la clave.** Si falta la traducción se ve el español, nunca una pantalla vacía.
- **Plantillas (Latte)**: no se editan. `IdiomaExtension` traduce al compilar los textos fijos, los atributos `placeholder`, `title`, `aria-label` y `alt`, los textos que van dentro de `?:`/`??`/listas y las etiquetas de las listas escritas en la plantilla. Para frases con valores dentro: `{t('Hola, %s', $nombre)}` (no se usa `{_…}`: el núcleo de TypeDock ya la ocupa).
- **PHP**: `Idioma::t('Texto %s', $valor)` (usa sprintf, así el orden de las palabras puede cambiar). Los avisos que alguien arma para que otra persona los lea llevan `Idioma::f(...)` (se traducen al guardarse para cada destinatario) o `Idioma::perezoso(fn() => …)`.
- **Tablas de etiquetas** (estados, tipos…): se pasan por `Idioma::lista(...)`.
- **JS**: `T('texto')` en `portal.js` y `pdfviewer.js`; el servidor antepone `window.PORTAL_T` con las traducciones.
- **Fechas y horas**: `Fmt` (mes primero y 12 horas en inglés).

## Traducciones

`portal/lang/<código>/*.php`: cada archivo devuelve `['texto en español' => 'English']`. Se suman todos los de la carpeta.

## Herramientas (dev)

```bash
php dev/idiomas.php en public .          # frases de plantillas sin traducir (también: equipo, admin; --contexto muestra la línea)
php dev/idiomas.php --literales public   # textos escritos dentro de expresiones que la pasada automática no toma
php dev/faltan-php.php en                # frases del PHP (Idioma::t/f/lista, flash, redirect) sin traducir
php dev/aplicar-traducciones.php pend.txt trad.txt salida.php   # arma un archivo desde «N|texto»
php tests/run.php                        # recorre el portal y el panel en inglés y falla si hay frases sin traducir
```

## Sumar un idioma (por ejemplo portugués)

1. Agregar `'pt' => 'Português'` en `Idioma::DISPONIBLES`.
2. Copiar `portal/lang/en/` a `portal/lang/pt/` y traducir los valores.
3. Si el idioma ordena distinto las fechas, ajustar `Fmt` (hoy sólo `en` cambia el orden).
4. Para ver qué falta: `php dev/idiomas.php pt public .`.

## Fuera de alcance por ahora

- Ajustes técnicos del admin (SMTP, IA, cron) y las pantallas de «Contactos ganados» de la agencia (el cliente sí las ve traducidas).
- Textos que escribe la gente (títulos, comentarios, descripciones) no se traducen.
- El detalle de la actividad guardada en la base («2 archivo(s)») queda tal como se registró.

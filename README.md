# Portal de Clientes — plugin para TypeDock

Plugin drop-in de TypeDock que se instala en `proyectos.richgt.com`. Tiene dos caras:

- **Portal del cliente** (`/portal`): los contactos del cliente siguen su proyecto, revisan contenidos, suben archivos y aprueban.
- **Panel de equipo** (`/equipo`): el front de la agencia, con la misma gráfica que el portal del cliente. Cada usuario ve sólo los clientes o proyectos que tiene asignados.

La configuración de fondo (IA, SMTP, correos, usuarios de agencia) queda en el admin de TypeDock, en los menús **Portal · …**.

## Landing Prisma (usaprisma.com)

- `theme/prisma/`: tema del home. Es una página, con ilustraciones isométricas con grano y capturas reales del producto. Detalles en `theme/prisma/README.md`.
- `invitaciones/`: plugin de lista de espera.
  - Recibe `POST /invitacion` y guarda una copia local.
  - Sincroniza cada inscripción con una audiencia de Mailchimp: tamaño del equipo en el campo que elijas (`TAMANO`, `MERGE7`…), doble confirmación y etiquetas.
  - Se configura en el admin → **Invitaciones**. La clave de la API se guarda cifrada, o se toma de la variable de entorno `MAILCHIMP_API_KEY`.
- En local: `php dev/seed-demo.php` (datos de demo creíbles) y luego `dev/serve.sh`. La maqueta queda en <http://127.0.0.1:8080/landing> y el admin del plugin en <http://127.0.0.1:8080/admin/invitaciones>.
- Pruebas: `php tests/invitaciones.php`, que simula Mailchimp.
- Zips: `scripts/zip-landing.sh`.
- Skills de diseño guardadas en el repo: `.claude/skills/hallmark` y `.claude/skills/anti-slop-ui-workflow`.

## Estructura

```
portal/          el plugin, tal como se instala (zip → admin de TypeDock)
  src/           servicios y controladores (PHP 8.2+, sin dependencias)
  templates/     Latte: admin/ (back), public/ (cliente), equipo/ (agencia), _iconos.latte
  migrations/    SQL (CREATE TABLE IF NOT EXISTS); Schema.php garantiza columnas y tablas nuevas
  assets/        CSS compilado, JS, visor PDF
  assets-src/    fuente Tailwind 4 del CSS
dev/             núcleo de TypeDock simulado para desarrollar y probar en local
tests/run.php    pruebas (SQLite o MySQL/MariaDB)
scripts/zip.sh   compila el CSS, corre las pruebas y arma dist/portal-plugin-vX.Y.Z.zip
```

## Desarrollo local

```bash
cd dev && composer install && cd ..      # Latte 3.0.20 + Flight
npm install                              # Tailwind 4.3.3 (sólo para compilar el CSS)
php dev/seed.php                         # datos de ejemplo en dev/storage/dev.sqlite
php -S 127.0.0.1:8080 -t dev/public dev/public/index.php
```

- Cliente: <http://127.0.0.1:8080/dev/cliente?email=maria@cafealtura.cl>
- Equipo: <http://127.0.0.1:8080/dev/equipo?email=ana@richgt.com> (sólo lo asignado) o `richard@richgt.com` (coordinación)
- Admin: <http://127.0.0.1:8080/admin/portal/equipo> (en local se ve sin los estilos del núcleo)

Los atajos `/dev/...` existen sólo en el servidor local, no en el plugin.

**CSS:** después de tocar clases en las plantillas, compila desde la carpeta del plugin:

```bash
cd portal && ../node_modules/.bin/tailwindcss -i assets-src/portal.src.css -o assets/portal.css --minify
```

## Pruebas

```bash
php tests/run.php                                    # SQLite en memoria

# MySQL/MariaDB (borra las tablas portal_* de esa base):
docker run -d --name mdb -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=portal_test -p 3306:3306 mariadb:11.4
PORTAL_DB_DSN="mysql:host=127.0.0.1;dbname=portal_test;charset=utf8mb4" PORTAL_DB_USER=root PORTAL_DB_PASS=root php tests/run.php
```

## Publicar

```bash
scripts/zip.sh     # → dist/portal-plugin-v0.7.0.zip
```

Luego súbelo con el zip-uploader del admin de TypeDock. Las migraciones y `Schema::asegurar` corren solas y no borran datos.

## Cambios

### v0.47.3: Français
- Tercer idioma: **francés** (`portal/lang/fr/`, las mismas 2.588 frases que el inglés). Sale en el selector de idioma de todas partes (ingreso, Ajustes del portal, Mis ajustes del panel, fichas de contacto, cliente y persona del equipo) y también en los correos.
- Fechas en francés (`lundi 12 oct.`, día primero, reloj de 24 horas, «au» en los rangos) y dictado por voz en `fr-FR`.
- Pruebas: el francés cubre las mismas frases que el inglés y conserva todos los `%s`/`%d`.

### v0.47.2: Revisión del inglés
- Se aplicó la revisión completa de las traducciones al inglés (592 frases ajustadas): «batch» para las entregas, «dashboard» para el panel, «content calendar» para la grilla, «caption» para el copy, y comillas curvas “ ” en vez de « ».

### v0.47.1: Ajustes de la agencia en inglés
- «Ajustes del portal» (en el panel del equipo: Agencia, Avisos, Clientes, IA, Correos y Tarea programada) ya sale completo en el idioma de la persona: textos, ayudas, nombres de colores, países y modelos de IA, y los textos de ejemplo de los campos. Antes quedaba mezclado con español.
- Quedan sólo en español el admin de TypeDock y el módulo «Contactos ganados» (leads).

### v0.47.0: Idiomas (español e inglés)
- **Cada persona elige su idioma**: los contactos del cliente en «Ajustes» del portal y las personas del equipo en «Mis ajustes». Si no eligen, vale el del cliente (se fija en su ficha), luego el de la agencia (Portal · Ajustes) y al final el español. En la pantalla de ingreso hay un selector «Español · English» que se recuerda.
- **Todo lo que ve el cliente sale en su idioma**: pantallas, fechas y horas (`Oct 12`, `2:30 pm`), avisos, el JS (dictado, subida de archivos, visor de PDF) y los correos: código de acceso, invitación, avisos agrupados, resumen de la mañana, recordatorios y las invitaciones de reunión (con su `.ics`). Cada correo se escribe en el idioma de **quien lo recibe**, no de quien lo provocó.
- **El panel del equipo y las pantallas de gestión también** (tareas, entregas, reuniones, solicitudes, clientes, contactos, proyectos…). Queda sólo en español el módulo «Contactos ganados» del lado de la agencia (los ajustes de la agencia se tradujeron en la 0.47.1).
- En los formularios de contacto, cliente, persona del equipo y ajustes de la agencia hay un selector de idioma (la invitación sale en el idioma que se elija ahí).
- Cómo funciona: el texto en español es la clave y las plantillas se traducen solas al compilarse (`IdiomaExtension`); en PHP se usa `Idioma::t()` y, para avisos que otra persona va a leer, `Idioma::f()`. Las traducciones están en `portal/lang/en/*.php`. Para sumar un idioma: copiar esa carpeta, traducir y agregar el código en `Idioma::DISPONIBLES`. Detalles y herramientas en `docs/idiomas.md`.
- Pruebas: el portal del cliente y el panel completos en inglés sin frases sin traducir, y los correos en cada idioma.

### v0.46.3: Mensajes sin animación al cargar
- Se quitó la animación de entrada en Mensajes: al recargar o responder, el bloque ya no «sube» ni parpadea; se ve la pantalla estable.

### v0.46.2: Mensajes, la conversación abierta no se pierde de la lista
- La conversación que tienes abierta queda siempre fija en la lista de la izquierda, aunque ya no calce con la pestaña o los filtros (por ejemplo, al responder desde Archivadas); en ese caso aparece con la marca «Abierta · en Activas/Archivadas/Destacados».
- Una conversación terminada ya no se desvanece de Activas mientras la tienes abierta.

### v0.46.1: Mensajes, ajustes de estilo
- Las reacciones y su selector usan contornos finos y claros; la reacción propia va sobre fondo blanco (antes el color de fondo no dejaba ver bien el ícono) y los íconos son más grandes.
- Activas, Archivadas y Destacados caben en una sola línea (la columna de la lista es un poco más ancha).

### v0.46.0: Mensajes con filtros, conversaciones destacadas y reacciones
- **Dos desplegables que se complementan** arriba de la lista: **Proyecto** (en el panel del equipo, **Cliente**) y **Tipo** (tareas, contenidos, solicitudes). Cada uno solo ofrece lo que existe con el otro elegido y trae cuántas conversaciones tiene. Cada conversación muestra el proyecto con su color y un ícono del tipo; se quitaron las demás etiquetas.
- **Destacar una conversación (★):** botón en la cabecera de cada conversación. Una destacada **no pasa a Archivadas** aunque la tarea termine y se ve en la nueva pestaña **Destacados**. La estrella es de cada persona (el equipo no ve la del cliente).
- **Reacciones a los mensajes:** pack de 12 (👍 👏 ❤️ 🎉 ✅ 🙏 😄 👀 😮 😕 😢 👎), incluidas las menos positivas. Aparecen bajo el mensaje con su contador; tocar la propia la quita. No envían correo ni marcan la conversación como sin leer.
- Tablas nuevas `portal_mensaje_destacadas` y `portal_mensaje_reacciones` (migración 0015; también las crea Schema.php).

### v0.45.2: Resumen del cliente sin manchas vacías y con contactos ganados
- En el Resumen, el bloque de avance se estiraba hasta igualar la altura de «Lo que te toca» y quedaba una mancha vacía. Ahora el Resumen va en dos columnas independientes (cada tarjeta mide lo que necesita) y, en el celular, en una sola, con «Lo que te toca» justo después del avance. En el panel del equipo, «Requiere atención» tampoco se estira.
- Si el proyecto tiene **contactos ganados** activos, el Resumen del cliente los muestra: totales, llegadas por semana y la tabla «esta semana / semana pasada / toda la campaña».

### v0.45.1: la página «Proyecto» del cliente con tres vistas
- La página **Proyecto** (portal del cliente) tiene pestañas: **Resumen** (la inicial), **Calendario** y **Línea de tiempo** (la de siempre). Las tarjetas de «Tus proyectos» del Inicio abren el Resumen.
- **Resumen:** avance y etapa en curso, **lo que te toca** (tareas y piezas por revisar), etapas como barras, contenidos por estado y reuniones (la próxima y los acuerdos de la última, si el resumen está publicado).
- **Calendario:** en escritorio, el mes en grilla (de lunes a domingo, con mes anterior, siguiente y «Hoy»); en el **celular, la semana como agenda** (de lunes a domingo, con los títulos completos). Muestra tareas del cliente y del equipo, reuniones, hitos y piezas para revisar. No muestra publicaciones de redes.

### v0.45.0: panel de control del proyecto y «Tus proyectos»
- **Panel del equipo (página de un proyecto):** cabecera con semáforo (En plazo / En riesgo / Atrasado / Completo) y fechas; cuatro números (avance, esperando al cliente, tareas vencidas, próxima reunión); fases como barras con la marca de «hoy»; **Contenidos** del proyecto con una barra por estado (borrador, esperan al cliente, cambios pedidos, aprobadas) y lo que se publica esta semana; **Requiere atención** (tareas vencidas, revisiones sin respuesta desde hace 3 días o más, solicitudes por atender); turno del equipo y del cliente; **Reuniones** con la próxima, la última y cuántas tareas salieron de ella para el equipo y para el cliente; ritmo del equipo (tareas cerradas por semana y proyección); solicitudes y archivos recientes; actividad.
- **Contactos ganados en el panel:** si el proyecto los tiene activos, un resumen con las llegadas por semana y una tabla «esta semana / semana pasada / toda la campaña» (llegaron, calificados, ganados, perdidos).
- **Portal del cliente (Inicio):** «Avance del proyecto» con pestañas se reemplaza por **«Tus proyectos»**: una tarjeta por proyecto con su avance, la etapa en curso, lo que le toca a la persona (tareas y piezas para revisar), la entrega estimada y la próxima reunión. Donde más le toca actuar va primero, en el bloque destacado. Si ve contactos ganados, una tarjeta con el mismo resumen semanal. El cliente no ve el semáforo ni el ritmo del equipo.
- Estilos nuevos en `assets/panel.css`; los bloques oscuros usan `.bloque-azul` (en modo oscuro se invierten).

### v0.44.9: «Perdido» cuenta como contactado en el gráfico y el embudo
- Un contacto marcado «Perdido» sin haber pasado por «Contactado» no se contaba como contactado: el gráfico mostraba 39 de 41 aunque el cliente los había contactado a todos. Ahora cuenta como contactado desde el día en que se marcó perdido (no se pierde a alguien con quien nunca se habló). Los «Nuevo» siguen sin contar.
- Los tiempos promedio entre etapas siguen usando solo los movimientos reales de «Contactado».

### v0.44.8: el cliente ve su hora en la línea de tiempo de Contactos ganados
- Si la agencia y el servidor están en otro país (por ejemplo Francia) y el cliente en Chile, la línea de tiempo de cada contacto mostraba las horas de la agencia. Ahora el cliente las ve en su hora (la de su empresa o, si vive en otro país, la suya), y la hora que escribe al agregar una nota o corregir una fecha se entiende en su hora. El equipo sigue viendo la hora de la agencia.
- Las reuniones ya se mostraban en la hora del cliente; las demás fechas relativas («hace 3 h») no dependen de la zona.

### v0.44.7: corrige el error al abrir Ajustes
- Ajustes daba error porque la hora del servidor se pasaba con el nombre `relojes`, que el panel del equipo ya usa para «Hora de tus clientes» y que le ganaba. Ahora se llama distinto, y hay una prueba que dibuja Ajustes con los avisos de plantilla convertidos en error, como el TypeDock real.

### v0.44.6: ver qué hora ve cada persona
- **Contactos**: nueva columna **Hora que ve**, con el país efectivo de cada persona y si es el de su empresa o uno propio (que pesa más). Sirve para encontrar a quien ve otra hora que su empresa.
- **Ajustes → País de la agencia**: muestra la hora actual de la agencia y la del servidor de PHP, con su zona; avisa si son zonas distintas (lo que se registra solo —llegada de contactos, comentarios— queda en la hora del servidor).

### v0.44.5: Contactos ganados del cliente: orden a la vista y «Invertir orden»
- Arriba de la lista (escritorio y celular) hay un selector **Ordenar por**: cuándo llegó, nombre, estado o próximo paso, más un botón **Invertir orden** que da vuelta la columna elegida. Los encabezados de la tabla siguen ordenando con un clic.
- «Próximo paso» pone arriba lo más urgente (lo vencido primero, en rojo); lo que no tiene fecha, y lo ya ganado o perdido, va al final.

### v0.44.4: la tarjeta destacada se lee al pasar el cursor
- Al pasar el mouse por la tarjeta destacada del Inicio (equipo y cliente), el fondo se ponía gris claro y el texto blanco no se veía. Ahora conserva su color y solo se oscurece un poco.

### v0.44.3: la copia de todos los avisos también sale agrupada
- El correo de «Copia de todos los avisos» (Ajustes) mandaba un correo por cada aviso, al tiro. Ahora se junta y sale igual que el resto: a las 10:00, 13:00 y 16:00 (o antes si se juntan 10), un solo correo con enlaces al admin.

### v0.44.2: resumen de la mañana sin horas falsas ni repetidos
- En «Y esto llegó mientras no estabas», los avisos programados (vence mañana, se atrasó, recordatorio de reunión) ya no muestran una hora: «00:00» era la hora en que el cron los generó, no la de la tarea. Los demás avisos conservan la hora en que ocurrieron.
- Si una tarea ya aparece en «Atrasadas» o «Vencen hoy», no se repite como novedad.

### v0.44.1: el comando del cron en Ajustes va sin el horario
- Ajustes mostraba la línea completa (`*/15 * * * * curl …`) y al pegarla entera en Hostinger fallaba («failed to run command '*/15'»). Ahora muestra solo el comando y, aparte, cómo llenar los selectores de horario.

### v0.44.0: avisos siempre agrupados y diagnóstico del cron
- **No hay correos sueltos.** Los avisos al equipo y al cliente se juntan y salen en un solo correo a horas fijas: **10:00, 13:00 y 16:00** (hora de quien recibe), de lunes a viernes y dentro del horario hábil. Salen antes si se juntan **10** (equipo) o **6** (cliente); el correo muestra hasta ese número y resume el resto («Y N novedades más»). Lo que llega después de una hora fija espera a la siguiente.
- Antes de salir, cada aviso se revisa: si ya se resolvió (la tarea se completó, el contenido se aprobó), se descarta; si no queda nada, no se manda correo.
- Se quitó **«Al instante»** de «Mis ajustes» del equipo. Una **urgencia** adelanta el envío a la próxima pasada del cron (máx. 15 minutos) y sale junto con lo demás pendiente, con su motivo.
- Siguen saliendo al tiro: el código de acceso, las invitaciones, cancelaciones y recordatorios de reuniones (llevan el calendario) y lo que la persona está esperando (p. ej. la confirmación de una reunión pedida).
- Contactos ganados: el aviso «tienes N contactos nuevos» se actualiza mientras espera, en vez de sumar correos.
- **Cron:** el comando de Ajustes usa `curl -sL -m 60` (sigue redirecciones); el horario correcto es `*/15 * * * *`. Ajustes muestra si el cron funciona, si lleva más de 1 hora sin correr y si llegó una llamada con la clave incorrecta.
- El resumen de la mañana (08:30) sigue igual y se lleva lo que llegó en la noche.

### v0.43.3: gráfico «Por día» y público sin repetir
- El gráfico de volumen tiene un selector **Acumulado | Por día** (**Por semana** en rangos largos): barras suaves de los que llegaron cada día y línea azul de los contactados. Recuerda tu última elección. Está en Contactos ganados del equipo y del cliente; el gráfico del Inicio queda como estaba.
- El filtro **Todos / B2C / B2B** solo aparece si lo que miras mezcla los dos públicos. Si es de un solo tipo, se muestra únicamente esa etiqueta.

### v0.43.2: el gráfico separa el volumen de los hitos
- **Volumen** (arriba): dos líneas acumuladas, **Llegaron** y **Contactados** (cuenta a todos los que llegaron a esa etapa o más lejos), con **un punto por día** (por semana si el rango es largo). Al pasar el cursor, o tocar, el globo muestra los dos valores y los hitos de ese día.
- **Hitos** (abajo, alineados por fecha): **calificados** (rombo ámbar), **ganados** (círculo verde con check) y **perdidos** (círculo rojo con ×), cada uno en su **carril**, marcados en el día en que ocurrieron. No son una línea: son hechos puntuales. Si hay varios el mismo día (o la misma semana), la marca crece y lleva el número. La fecha es la del movimiento de la línea de tiempo, así que si se corrige con el lápiz, la marca se mueve.
- Un resumen arriba con los totales y los mismos símbolos (llegaron, contactados, calificados, ganados, perdidos).
- **Versión simple en el celular:** sin puntos en las líneas (salvo al tocar), 4 fechas, los nombres de los carriles pasan a una leyenda con sus totales y los textos largos se acortan.
- Fechas rotuladas sólo cada tantos días y una línea vertical suave en esas fechas (antes había una por día).
- Se quitaron los botones Ganados/Perdidos del gráfico: ya no son líneas que encender.
- `leads.css` sube a `?v=6`, `graficos.js` a `?v=3` y `portal.css` a `?v=40`.

### v0.43.1: contactos ganados, ajustes de la lista del cliente
- **«Origen» en vez de «Campaña»** en la lista del cliente (y en el filtro): la campaña es el proyecto; lo que trae cada contacto (WhatsApp, Instagram, formulario…) es su origen. Si no trae uno, se muestra cómo llegó (Formulario, Ingreso manual…). La columna «Llegó» ya no repite el origen.
- **B2C / B2B fuera de cada fila.** Ahora se ve sólo en los datos de la campaña: arriba de la lista del cliente (una tarjeta por campaña, con su etiqueta y su total, que además filtra), en la ficha junto a «Campaña», en las tarjetas de proyecto y al elegir cliente en el equipo.
- Si el cliente tiene más de un proyecto, la lista suma una columna «Proyecto».
- **«Agregar contacto» siempre se ve** en la lista del cliente. En vista previa (cuando la agencia mira como cliente) aparece desactivado, con la explicación, en vez de desaparecer.
- `leads.css` sube a `?v=3`.

### v0.43.0: contactos ganados con línea de tiempo, campañas B2C/B2B y evolución
Reúne las tres etapas que estaban planeadas (v0.41, v0.42 y v0.43).
- **Elegir cliente primero (panel del equipo y admin).** `/equipo/leads` es ahora una pantalla con una tarjeta por cliente (total, barra por estado, B2C/B2B y cuántos llegaron esta semana). Al entrar, **sólo se ven los proyectos y contactos de ese cliente**: nunca se mezclan. Quien lleva un solo cliente entra directo. Los enlaces antiguos con `?proyecto=` siguen funcionando.
- **Línea de tiempo de cada contacto (agencia y cliente).** Cada contacto tiene su llegada, cada cambio de estado, sus notas (con dueño: **Agencia** o **Cliente**) y los próximos pasos. Un solo cuadro agrega una nota, cambia el estado, o las dos cosas, con la fecha en que ocurrió (viene con la de ahora y se puede ajustar). Cada movimiento tiene un **lápiz para corregir su fecha**; la línea se reordena sola y queda «fecha corregida» (se guarda también cuándo se registró). Lo ven y lo pueden corregir la agencia y el cliente. Los contactos que ya existían copian su llegada, su estado actual y su nota única (como «Nota anterior»); las etapas por las que pasaron antes no se guardaron nunca y no se pueden reconstruir. Anonimizar borra las notas y deja los cambios de estado para los conteos.
- **A quién le habla cada proyecto: B2C, B2B o los dos** (casillas en el formulario del proyecto). Aparece como etiqueta en las tarjetas, las listas y la ficha, y filtra el gráfico y la lista.
- **Evolución del cliente** (equipo y portal del cliente, en Contactos ganados): línea de **contactos acumulados** con puntos, filtros por público y período, botones para ver ganados y perdidos, embudo («de una etapa a la siguiente», con el % entre etapas) y tiempos promedio entre etapas.
- **Gráfico en el Inicio del cliente**, con el nombre del proyecto: aparece cuando la campaña está activa y lleva **14 días o más** recibiendo contactos (contados desde el primero). Sólo números, sin nombres de personas, y sólo para quien ya ve contactos ganados.
- **Gráficos con Chart.js 4.5.1** (licencia MIT, `assets/chart.umd.js`, incluido en el plugin; sin CDN). Los colores siguen al color de cada cliente y al modo claro u oscuro.
- Estados con un color propio en todo el panel: Nuevo gris, Contactado azul, Calificado ámbar, Ganado verde, Perdido rojo.
- Datos: migración `0014_lead_linea.sql` (tabla `portal_lead_eventos`) y columna `portal_proyectos.publico`; también las garantiza `Schema.php`. No se borra nada.
- `portal.css` sube a `?v=38`; `leads.css` es nuevo (`?v=2`).

### v0.40.2: «Novedades» siempre en el color opuesto
- El bloque de **Novedades** va **siempre en el opuesto del fondo**: en modo claro, azul del sistema (`#14204d`) con texto claro; en modo oscuro, **gris clarito** con texto azul. Los estados (aprobado, en revisión…) y los íconos usan sus tonos de modo claro dentro del gris para leerse bien.
- `portal.css` sube a `?v=37`.

### v0.40.1: paleta de 12 colores y arreglos de «Azul firme»
- **Colores de marca elegibles (agencia y cada cliente).** El selector libre se cambia por 12 colores: Naranjo Prisma, Azul, Verde, Violeta, Rosa, Grafito, Ámbar, Azul claro, Gris azulado, Café, Cian y Verde turquesa. Un portal con un color antiguo lo conserva, y aparece como «Actual» hasta que se elija uno de la lista.
- **Normas de contraste, en los dos portales y en los correos.** El texto del botón llega a 4,5:1 como mínimo y el aro de foco a 3:1, en claro y en oscuro. Naranjo Prisma, Verde, Rosa y Gris azulado se oscurecen un poco para aguantar texto blanco; Ámbar, Azul claro, Cian y Verde turquesa llevan texto oscuro (`#10131f`). Lo que se guarda es el color elegido: el ajuste se aplica al pintar.
- **Aro de foco** en el tono de acento legible (claro en oscuro, oscuro en claro), no en el color crudo de la marca.
- **«Novedades»** es siempre el azul del sistema (`#14204d`), también en modo oscuro, y al pasar el cursor la fila se lee (antes quedaba blanco sobre claro).
- **Inicio del equipo:** se marca con el color del cliente la tarjeta **«Asignadas a ti»** (cuando hay tareas). «Vencidas» ya no se rellena: su cifra va en rojo si hay alguna.
- `portal.css` sube a `?v=36` y `portal-admin.css` a `?v=17`.

### v0.40.0: «Azul firme», un look más plano y firme en todo el portal
- **Estilo nuevo, el mismo orden.** Sin sombras ni degradados de fondo, esquinas más chicas y parejas (8 px en tarjetas, 6 px en botones, campos y chips), líneas finas en lugar de sombras y títulos de sección con una división suave.
- **Una sola tinta, un solo azul.** Textos, íconos, fechas y la pestaña activa del menú van en un azul oscuro común a todos los portales. El acento sigue siendo el color de cada cliente (botones, enlaces, foco, avance).
- **Chips de borde grueso**, sin relleno tintado: el color intenso va en el borde y el texto. Sobre una imagen mantienen un fondo sólido para leerse.
- **Íconos sin recuadro de color** y fechas con borde azul; la de hoy va rellena.
- **Un bloque lleno con el color del cliente**: «Te toca a ti» en el portal del cliente y «Vencidas» en el panel del equipo, sólo cuando hay algo pendiente.
- **«Novedades» en azul invertido.** En el portal del cliente ahora va **debajo de «Próximas reuniones»**, al pie de la primera pantalla (en escritorio; en el celular sigue el orden de lectura). En el panel del equipo queda al final de la columna.
- **Contenidos de una entrega**: primero el estado y las piezas; el formulario con los datos de la entrega pasa al final. Al crear una entrega nueva, el formulario sigue arriba.
- `portal.css` sube a `?v=35` para que el navegador tome los estilos.

### v0.36.1: arreglo del repositorio
- Sin cambios en lo que hace el plugin. El `.gitignore` ignoraba cualquier carpeta llamada `entregas/` y dejó `_tapas.js.latte` y `_subida.js.latte` fuera del repo; ahora solo ignora `/entregas/` de la raíz y los dos archivos están versionados. Así un clon limpio corre las pruebas y arma el zip completo.

### v0.36.0: después de la reunión, un solo «Guardar»
- **Tareas como tarjetas, más grandes y fáciles de leer.** Arriba van las **creadas**, que ahora se pueden seguir editando desde la reunión (título, detalle, responsable, vence y «Lo ve el cliente»); el cambio pasa a la tarea real y se marca «Cambio sin guardar» hasta que guardas. Si la tarea se borró desde Tareas, la reunión lo dice.
- **Sugeridas: ✓ Crear o ✕ Descartar**, una sola decisión por tarea (otro clic la deshace). Las que no decides quedan pendientes para otro día. La que escribes a mano se crea al guardar.
- **«Qué ve el cliente»**: un solo panel al final con la reunión, el resumen y los acuerdos, cuántas tareas ve, la próxima reunión (también se puede ocultar después de agendarla) y el aviso por correo. Debajo, «Al guardar» dice exactamente lo que va a pasar.
- **Un solo botón «Guardar»** reemplaza a «Crear lo marcado y aplicar» y «Guardar sin aplicar». El mensaje final cuenta lo que se hizo; si pediste correo y no había nada nuevo para el cliente, lo avisa en vez de mandarlo.
- Una tarea creada que recién pasa a «Lo ve el cliente» cuenta como novedad para el correo.

### v0.35.1: las listas recuerdan cómo las dejaste y Mensajes avisa antes de archivar
- **Filtro, orden y página se mantienen** al volver a una lista desde otra pantalla, por ejemplo después de abrir o editar un ítem. Aplica a los contactos ganados del cliente (incluido el orden por «Próximo paso»), las tareas, revisiones, reuniones, solicitudes y mensajes del portal, y a las listas del equipo (tareas, contenidos, contactos ganados, reuniones, solicitudes, actividad y mensajes). «Todas» o «Quitar filtros» dentro de la misma lista siguen limpiando.
- **Mensajes**: una conversación terminada que acabas de leer ya no desaparece al tiro. Se queda en «Activas» con el aviso «Pasa a Archivadas» y se desvanece a los 10 segundos; los contadores se actualizan solos.

### v0.35.0: tapas y vista previa por tipo (panel de la agencia)
- **«Así lo verá el cliente» según el tipo**: post y carrusel en 4:5 (con contador y puntos), reel y story en 9:16, documento con su página 1 y el número de páginas, logo sobre fondo blanco, oscuro y transparente, y web con la tarjeta del link.
- **Tapa de PDF y video**: el navegador arma la página 1 del PDF o un cuadro del video y se guarda como su miniatura. En un video se elige el cuadro con una barra o se sube otra imagen. Los PDF y videos que ya estaban subidos sin tapa se completan solos al abrir la entrega.
- **Reel**: la tapa elegida pasa a ser su Portada si todavía no tiene una. En la ficha hay «Usar un cuadro del video» para cambiarla.
- **«Cambiar tapa»** en cada PDF o video de la ficha del contenido.
- **Tarjeta del link**: YouTube muestra su miniatura; Vimeo y otros sitios, el título y la imagen que declaran para compartir. La consulta sólo va a direcciones públicas, sin redirecciones, con 4 s y 600 KB como máximo.
- El portal del cliente no cambia: ve la Portada del reel como siempre.

### v0.34.1
- **Arreglo**: «Nueva entrega» y abrir una entrega daban «Algo falló de nuestro lado» en TypeDock. La plantilla usaba una constante de PHP sin barra inicial (`JSON_UNESCAPED_UNICODE`); Latte 3.1 lo marca como obsoleto y TypeDock convierte ese aviso en error. Se quitó.
- El entorno de desarrollo pasa a Latte 3.1.6 y las pruebas compilan todas las plantillas y fallan ante cualquier aviso, para que esto no vuelva a pasar.

### v0.34.0: subir contenidos a una entrega (panel de la agencia)
- **La lista muestra lo que falta**: cada pieza trae sus láminas en miniatura y avisos como «Faltan 2 de 5 láminas», «Sin imágenes», «Sin copy», «Sin archivo» o «Sin link», según su tipo.
- **Una sola zona para agregar** en vez de las cuatro cajas: se arrastran (o eligen) imágenes, videos, PDF, la grilla en Word o una planilla .csv, y antes de crear nada aparece **«Revisa antes de crear»**: cada archivo con su miniatura, lo que reconocimos (por ejemplo «Post 3 · lámina 2» por el nombre `3-2.jpg`) y qué hacer con él: sumarlo a una pieza, crear un contenido nuevo, juntarlo con otros en un carrusel nuevo o no subirlo. La grilla en Word va directo a su vista previa de siempre. Sin JavaScript el servidor decide solo con el número del nombre.
- **Soltar sobre una pieza**: arrastrar archivos encima de una pieza de la lista (o «+ Archivos») se los suma y vuelve a la misma pieza.
- **Crear uno a mano empieza por el tipo**: tarjetas de tipo y un formulario que pide sólo lo de ese tipo (un PDF pide archivo y título; un post, láminas, copy, cuenta y fecha). Lo opcional va en «Más detalles», y al lado se ve cómo lo verá el cliente.
- El portal del cliente no cambia.

### v0.33.1: el cliente se reconoce de un vistazo
- En las listas del equipo (Tareas, Contenidos, Reuniones, Solicitudes, Contactos ganados, Formularios y Mensajes) la columna pasa a ser **«Cliente · proyecto»**: mini logo del cliente (o sus iniciales sobre su color) con el nombre del cliente, y debajo el proyecto. Además, una **barrita del color del cliente** al borde de cada fila.
- Cada cliente tiene un color propio: el de su marca; si no tiene, o si otro cliente ya tiene uno casi igual, uno de una paleta de 14 tonos, eligiendo el más distinto a los que ya se usan. Antes el punto era por proyecto y se repetía entre clientes (el primer proyecto de todos salía morado).
- En el portal de cada cliente sigue el punto de color por proyecto (ahí sí distingue sus proyectos).

### v0.33.0: paginación
- **Listas paginadas** de a 20, con selector para ver 30 o 50. «Mostrando 21–40 de 134», Anterior / 1 2 3 … / Siguiente. Los filtros y la búsqueda se mantienen al cambiar de página. Cuántos por página se recuerda por persona.
- **Panel del equipo y admin**: Tareas, Contenidos, Reuniones, Solicitudes, Contactos ganados y Actividad (ahora hasta 2.000 movimientos, antes 120). Cada lista conserva su «Ordenar por» y suma **«Invertir orden»** para verla al revés.
- **Portal del cliente**: Contactos ganados (además, clic en los encabezados Contacto, Estado, Llegó y Próximo paso para ordenar; otro clic lo da vuelta), Tareas (por fecha de entrega, más nuevas o más antiguas), Revisiones, Reuniones anteriores, Solicitudes cerradas y Mensajes.
- Clientes y Proyectos del panel no se paginan: van agrupados por cuenta y con búsqueda instantánea en la página.

### v0.32.0: Mensajes
- **Mensajes** en el menú del portal del cliente y del panel del equipo: una conversación por cada tarea, contenido o solicitud con comentarios. Se ve el último mensaje, quién lo dejó, el estado de la cosa y cuántos mensajes nuevos hay. Al hacer clic se abre el hilo al lado y se responde ahí mismo (en el celular, el hilo ocupa la pantalla y hay un botón para volver).
- **Sin leer**: el número del menú cuenta las conversaciones donde la otra parte escribió algo que esa persona no ha leído (el cliente ve lo del equipo y el equipo lo de los clientes). Se marca leído al abrir la conversación o la tarea / contenido / solicitud. En el celular, Mensajes está en el encabezado con su número.
- **Activas / Archivadas**: cuando la tarea queda lista, el contenido aprobado o la solicitud cerrada, y no queda nada por leer, la conversación pasa sola a Archivadas. Si alguien vuelve a escribir, vuelve a Activas.
- Responder desde la bandeja usa las mismas acciones de siempre (actividad, avisos por correo; en el panel, con la casilla «Avisar al cliente»). El cliente sólo ve tareas visibles y contenidos de revisiones publicadas; en el panel, cada persona ve las conversaciones de sus clientes y Coordinación ve todas.

### v0.31.1
- **Contactos ganados del cliente en tabla** (pantallas grandes), igual que la del panel del equipo: Contacto, Campaña, Estado, Mailchimp (estado y «9 correos · abrió 6»), Llegó (cuándo y por dónde) y Próximo paso al final (en rojo si venció). Toda la fila abre la ficha.
- En celular y tablet siguen las tarjetas, ahora con el texto del próximo paso además de la fecha.

### v0.31.0: qué es nuevo en Solicitudes y Novedades más clara
- **Número de Solicitudes** en la barra del cliente: ahora cuenta las solicitudes que le toca revisar o aprobar (cotización lista, o la tarea que salió de la solicitud está «En revisión») y las que tienen respuestas o cambios del equipo que esa persona todavía no ha visto. Al abrirla deja de ser nueva; si le toca revisar, sigue contando hasta que se resuelva.
- **Lista de solicitudes**: punto en las que tienen algo nuevo y estado más claro («Te toca revisar», «Aplicando tus cambios», «Lista», con «· nueva» cuando corresponde). La ficha también dice «Te toca revisar».
- **Novedades en Inicio**: una línea por cosa (no por evento), separada en Solicitudes, Revisiones, Tareas y Reuniones, con su estado actual y un resumen («Cambió de estado · te respondimos · 1 archivo»). Las tareas que nacieron de una solicitud aparecen dentro de la solicitud. Punto en lo que no ha visto desde que lo abrió.
- Lo visto es por persona (tabla `portal_vistos`, migración 0013, se crea sola). Mirar el portal «como el cliente» desde el panel no marca nada como visto.

### v0.30.3
- **Correos de Mailchimp en la lista de contactos ganados**, para verlo de un vistazo: en el portal del cliente y en el panel cada contacto muestra «9 correos · abrió 6» junto a su estado en Mailchimp.
- **«Revisar en Mailchimp»** (lista del panel) ahora trae también la actividad de cada contacto, para que la lista tenga esos números. Revisa de a 20 por vez; si quedan más, se aprieta de nuevo.

### v0.30.2
- **Mailchimp en la ficha de cada contacto**: ahora dice cuántos correos distintos ha recibido (campañas y automatizaciones, como la bienvenida), cuántos abrió (con el porcentaje) y en cuántos hizo clic. Abrir el mismo correo varias veces cuenta una vez. Se calcula con la actividad que guarda Mailchimp; si la cuenta sólo entrega lo más reciente, dice «al menos».

### v0.30.1
- **Logo en la barra lateral** del portal y del panel: más alto (64 px en vez de 40) y puede usar todo el ancho de la barra, así los logos cuadrados o con bajada se leen. En el encabezado del celular pasa de 36 a 44 px.

### v0.30.0: nuevas pantallas de ingreso y favicon
- **Ingreso al portal del cliente**:
  - En grises, con una trama isométrica suave.
  - Arriba, la pieza de la agencia (blanca, con su logo) junto a un hueco punteado.
  - Textos: «Portal de Clientes · Bienvenido · Este espacio es para ti».
- **Al pedir el código**, si el correo es de un cliente:
  - Su pieza sube y encaja.
  - Un **corazón prismado** (naranjo de Prisma) cae sobre la unión y después baja a su lugar en el título «Agencia ♦ Cliente».
  - Aparece una expresión al azar («¡Pulento!», «¡Chévere!», «¡Bacano!»…).
  - La animación sale una sola vez; al recargar queda armado. Con «reducir movimiento» aparece armado sin moverse.
  - Si el cliente no tiene logo, se usan sus iniciales.
  - Si el correo no es de un cliente, queda el hueco y el mensaje de siempre.
- **Ingreso del panel del equipo**: el mismo estilo, solo con el logo de la agencia.
- **Pie**: «Portal de clientes hecho con Prisma · proyectos.richgt.com».
- **Favicon**: se sube en Ajustes de la agencia (PNG cuadrado, de 32 a 512 px). Se usa en el portal, el panel y las pantallas de ingreso. No se aceptan SVG, porque podrían llevar código.
- Las cuatro pantallas comparten `templates/_ingreso.latte`. Las piezas están en `templates/public/_piezas.latte`.

### v0.29.1
- **Importar CSV** ahora reconoce la exportación de audiencias de **Mailchimp** en español:
  - Encabezados como «Dirección de correo electrónico» o «Número de teléfono».
  - Teléfonos que Excel o Mailchimp exportan con apóstrofo (`'+569…`).
  - La fecha de inscripción (`OPTIN_TIME`).
  - La columna «Origen», que se usa como campaña.
  - El `gclid`, que marca la fuente como Google.
- Un teléfono con menos de 8 dígitos (por ejemplo, solo «+56») se descarta. Si el contacto trae correo, entra igual.

### v0.29.0: contactos ganados contrastados con Mailchimp (solo consulta)
- **Qué hace**: revisa si los contactos de una campaña están en una audiencia de Mailchimp del cliente. Muestra:
  - El estado: suscrito, sin confirmar, se dio de baja, correo rebotado o no está.
  - La fecha de inscripción y la de baja, con el motivo si la persona lo indicó.
  - La calificación de Mailchimp (estrellas) y el promedio de aperturas y clics.
  - Las etiquetas.
  - Los **últimos correos** que recibió, abrió o en los que hizo clic, con su nombre (por ejemplo, la bienvenida).
- **Qué no hace**: no trae a nadie de Mailchimp ni cambia nada allá. Solo se consulta por los correos que ya están en el CRM.
- **Conexión**: en «Formularios, webhook y Mailchimp», quien lleva la cuenta pega la clave de la API del cliente.
  - La clave se guarda cifrada con `Cifrado` y no se vuelve a mostrar.
  - Después se elige la audiencia de cada campaña.
  - Quitar la clave desconecta todas las campañas de ese cliente.
- **Cuándo se consulta**: al abrir la ficha, si la última revisión tiene más de 24 horas, o con «Revisar ahora».
  - En la lista de la agencia, «Revisar en Mailchimp» revisa la campaña en lotes de 40, sin la actividad, que se trae al abrir cada ficha.
  - Las listas marcan «En Mailchimp», «Se dio de baja» o «Correo rebotado».
- **Datos**: se guarda solo el resumen (`mc_estado`, `mc_datos`, `mc_revisado_en`). Se borra al anonimizar, al cambiar el correo o al cambiar la audiencia.
- **Por verificar con una cuenta real**: cómo aparecen los correos de automatizaciones (bienvenida, recorridos). Se usa el registro de actividad de Mailchimp y, si la cuenta no lo entrega, la actividad clásica. Mailchimp solo guarda lo reciente.
- **Arreglo**: `Schema` crea las tablas de contactos antes de agregarles columnas, para instalaciones nuevas sin migración previa.

### v0.28.0: contactos ganados (etapa 3), planillas, exportar y datos personales
- **Importar CSV** (agencia, en «Contactos ganados › Importar CSV»):
  - Lee la descarga de **Meta Lead Ads** (UTF-16, con tabuladores, teléfonos con `p:`), la de **formularios de Google Ads** y cualquier planilla con columnas de nombre, correo o teléfono, sea con «,», «;» o tabulador, en UTF-8 o Windows-1252.
  - Antes de guardar muestra una **vista previa** con los nuevos, los que ya estaban (en la campaña o repetidos en el archivo) y las filas sin datos.
  - Conserva la fecha en que llegó cada contacto. Una fecha futura no se acepta.
  - Volver a subir la misma planilla no duplica nada, aunque los contactos sean antiguos.
  - Envía un solo aviso al cliente por toda la importación, y se puede desmarcar.
  - Lo hace Coordinación general o quien lleva la cuenta.
- **Exportar CSV**:
  - El cliente (colaboradores) y la agencia descargan con los filtros que tengan puestos. La agencia ve además la columna de cliente.
  - El archivo va en UTF-8 con BOM y separado por «;», para que Excel en español lo abra bien.
  - Una celda que empieza con `=`, `+`, `-` o `@` se escribe como texto, para que Excel no la ejecute como fórmula.
- **Datos personales**, en la ficha de cada contacto:
  - **Anonimizar** borra nombre, correo, teléfono, empresa, notas y próximo paso, pero el contacto sigue en los conteos de la campaña.
  - **Eliminar** lo borra por completo. Las dos acciones piden confirmación.
  - En el portal las hacen los colaboradores; en la agencia, quien lleva la cuenta.
- **Arreglo**: un contacto recién creado ya no muestra «Último cambio» con el nombre de quien lo agregó; esa línea aparece solo cuando alguien cambia el estado.

### v0.27.0: contactos ganados (etapa 2), formularios y webhook (Make)
- **Formularios y webhook** (en «Contactos ganados»): cada campaña puede tener varios formularios, cada uno con su token.
  - El token se muestra una sola vez; en la base queda solo su huella (SHA-256).
  - Se puede revocar o cambiar por uno nuevo, y se ve cuántos contactos trajo y cuándo se usó por última vez.
  - Los maneja Coordinación general o quien lleva la cuenta del cliente.
- **Endpoint público**:
  - `POST /leads/f` con la cabecera `X-Portal-Token`: es la forma recomendada para Make y Zapier.
  - `POST /leads/f/{token}`: para el formulario directo de la landing.
  - Acepta JSON o un formulario normal. Responde `201`, `422`, `404`, `413` o `429`. Si viene de un formulario, lleva a la página de gracias de la fuente o a una propia.
  - Entiende los nombres de campo en español y en inglés (`name`, `email`, `phone_number`, `full_name`, `campaign_name`, `first_name` y `last_name`…). Lo que no reconoce no se guarda.
- **Contra el spam**:
  - Campo trampa `sitio_web` y tiempo mínimo de llenado de 2 s. A los bots se les responde «gracias» sin guardar nada.
  - Tope de 10 formularios por IP por hora; no aplica al webhook, porque Make comparte IPs.
  - Tope de 500 contactos por fuente al día.
  - Límite de 20 KB por envío.
  - Registro corto de intentos, que se borra a los 2 días.
- **Aviso al cliente**:
  - Les llega a los colaboradores, en su horario hábil, sin datos de la persona.
  - Si llegan varios seguidos, se agrupan en un solo correo con el total (como mucho uno por hora).
  - Si los atienden antes de que salga, el correo no se envía.
  - Cada persona puede apagarlo en Ajustes («Avisarme cuando llegue un contacto nuevo»).
- **Arreglo**: la hora de llegada de los contactos ahora se guarda como en el resto del portal.

### v0.26.0: contactos ganados (etapa 1)
- **Contactos ganados (leads)**: un mini-CRM por campaña con las personas que llegan a los clientes de la agencia. Usa una tabla propia (`portal_leads`) y no tiene relación con los contactos del portal.
- **Interruptor por proyecto**: «Con contactos ganados» en el formulario del proyecto. Al apagarlo, el cliente deja de ver los contactos, pero quedan guardados.
- **Portal del cliente** (`/portal/contactos-ganados`, solo para colaboradores):
  - Lista con pestañas por estado, búsqueda y filtro por campaña.
  - Ficha con estado, notas y próximo paso con fecha. Queda registrado quién hizo el último cambio de estado.
  - Ingreso manual.
  - En el celular se entra desde una tarjeta en Inicio.
- **Agencia** (admin «Portal · Contactos ganados» y panel `/equipo/leads`):
  - Consolidado por proyecto y campaña, con el conteo por estado.
  - Lista, ficha e ingreso manual.
  - Cada persona ve solo los proyectos que tiene asignados.
- **Datos**:
  - Solo nombre, correo, teléfono y empresa, más el origen y los UTM.
  - No se duplica el mismo correo o teléfono en la misma campaña dentro de 30 días.
  - Toda consulta va acotada por cliente o proyecto.
- **Migración `0012_leads.sql`**: también la garantiza `Schema.php`. Incluye las tablas de fuentes y de intentos para la etapa 2 (webhook).

### v0.16 a v0.25 (resumen)
- **v0.25.0**:
  - Rol **Coordinación de cuentas**: lleva dos o tres clientes con sus equipos y es responsable de cada cuenta.
  - Listas de clientes y proyectos agrupadas por cuenta, con buscador y filtros.
  - Cuadro «Cuentas» en Inicio.
  - Revisión de errores de pantallas.
- **v0.24**:
  - País por persona: un contacto puede vivir en otro país que su empresa, y sus correos y horarios usan su hora.
  - Con rol Coordinación ya no se muestran las asignaciones.
- **v0.23**:
  - Aviso «Guardando…» y «guardado» visible en todos los formularios.
  - Las piezas con versión nueva (v2 o más) quedan marcadas y se avisa al cliente.
  - Clientes en China.
- **v0.22**:
  - Plantillas de proyecto (Sitio web, Branding, Campaña digital).
  - En la línea de tiempo se distinguen las tareas del cliente y las de la agencia.
  - Sección «Proyecto» en el portal del cliente, con etapas y línea de tiempo.
- **v0.21**:
  - Línea de tiempo más fácil de armar.
  - Fases con sus hitos, reuniones y entregas.
  - La lista de proyectos se ordena por el último movimiento.
- **v0.20**: línea de tiempo del proyecto (Gantt) con hitos, dependencias y fechas estimadas.
- **v0.19**:
  - Editor de láminas con una fila por lámina.
  - Portada en los reels.
  - Los correos en espera salen después de entregar la página.
  - Los avisos que ya no hacen falta no se envían.
- **v0.18**:
  - Avisos por persona: agrupados, resumen de la mañana y reuniones con convocados.
  - Ajustes ordenados.
  - Importar grilla con IA desde el panel.
- **v0.17**: gestión de la agencia desde el panel `/equipo`.
- **v0.16**: brief de cada pieza (objetivo, láminas, notas), importar grillas con IA y pines sobre imágenes y PDF.

### v0.15.1

- **La revisión de contenidos se envía sola:** cuando el cliente aprueba o pide cambios en la última pieza pendiente, la entrega pasa a «Aprobada» o «Respondida» y el equipo recibe un solo aviso con el resumen. Con una pieza pasa de inmediato; con varias, al decidir la última. Mientras queden piezas por revisar, el cliente puede cambiar sus decisiones. El botón «Enviar revisión» se mantiene solo para entregas que hayan quedado completas desde antes.

### v0.15.0: reuniones pasadas y archivadas

- Una reunión **ya pasó** cuando terminó (fecha más duración) o cuando ya tiene resumen o análisis. Antes contaba solo el día, así que una reunión de las 13:30 seguía como «próxima» toda la tarde.
- **Para el cliente:**
  - Las pasadas se ven apagadas: la fecha con solo el borde de color, sin botón de Meet y con la marca «Ya pasó».
  - Orden: las próximas de la más cercana a la más lejana; las anteriores de la más reciente a la más antigua.
  - Las de más de 20 días pasan solas a **«Archivadas»**, plegadas al final.
  - El detalle de una reunión pasada también se ve apagado.
- **En el panel y el admin:** filtro nuevo **«Archivadas»** junto a Próximas, Pasadas y Todas, con la misma regla.
- **Logo del cliente en modo oscuro:** va sobre un fondo claro para que se vea aunque sea oscuro.
- **Pruebas:** 228 comprobaciones que pasan en SQLite y MariaDB.

### v0.14.1

- En los primeros pasos, «Entraste a tu portal» se marca solo cuando la persona entró de verdad. Antes aparecía marcado siempre, también en «Ver como cliente».
- Cambia la versión de `portal.css` para que el navegador tome los estilos nuevos (el círculo verde de los pasos listos).

### v0.14.0: ver como cliente

- **«Ver su portal»** en la ficha del cliente del panel (entra como su primer colaborador), un ícono de ojo junto a cada contacto y **«Ver como»** en la lista de contactos (admin y panel). Abre el portal tal como lo ve esa persona, con su marca, sus permisos y su hora.
- **Solo lectura:** un aviso fijo arriba dice «Vista previa: así ve el portal Josefa Ruiz». Se puede recorrer todo, pero nada se envía ni se guarda: comentarios, archivos, aprobaciones, solicitudes, ajustes y tema quedan bloqueados, y mirar no le marca los primeros pasos al cliente. «Salir de la vista previa» devuelve a la ficha desde donde entraste.
- Desde el panel solo se puede ver el portal de los clientes asignados.
- **Pruebas:** 220 comprobaciones que pasan en SQLite y MariaDB.

### v0.13.2

- **Reuniones:** la hora se escribe en la de la agencia, que es la que se guarda. Debajo se ve la del cliente: «Para el cliente: mié 7 oct, 13:00 en Chile 🇨🇱». Si tiene la misma hora, lo dice. El cliente sigue viendo la reunión en su hora.
- **Hora de tus clientes** (barra del panel): ahora es una tarjeta, con «Tú» arriba y la diferencia horaria de cada país («−5 h»). Solo aparecen los países con una hora distinta a la tuya: España y Francia, por ejemplo, no se muestran.

### v0.13.1

- Francia (hora de París) en la lista de países, para la agencia y para los clientes.

### v0.13.0: reuniones en la hora del cliente

- **País de la agencia** en Portal · Ajustes. Define la zona horaria de la agencia, que antes estaba fija en Chile. Las reuniones se guardan en esa hora y la usan los «hoy» del sistema y la IA.
- **Al crear o editar una reunión** (admin y `/equipo`), la hora se escribe en la del país del cliente del proyecto. La etiqueta lo indica, por ejemplo «Hora (España 🇪🇸)», y debajo se ve en vivo la equivalencia: «= mié 7 oct, 11:00 en Chile (hora de la agencia)». Lo mismo para la próxima reunión.
- **El cliente ve sus reuniones en su hora** en el inicio, en «Reuniones» y en el detalle, y también en el correo de novedades. El archivo de calendario (.ics) ya iba en UTC, así que cada calendario lo muestra bien.
- Nueva clase `Zona` en reemplazo de las constantes fijas de Chile.
- **Pruebas:** 212 comprobaciones que pasan en SQLite y MariaDB.

### v0.12.0: bienvenida del cliente

- **Invitación por correo** a cada contacto. No es automática: al crear el contacto hay una casilla «Enviar invitación ahora», y en su ficha un botón para enviarla o reenviarla, con un **mensaje personal** opcional firmado por quien invita. El correo explica qué es el portal, qué va a encontrar, cómo entrar sin contraseña y, si ya tiene algo pendiente, le destaca su primera tarea. El botón lleva al login con su correo ya escrito.
- **Estado de cada contacto** en la lista, en su ficha y en la ficha del cliente del panel: sin invitar, invitado pero sin entrar, o ya entró (primer y último acceso). Columnas nuevas en `portal_contactos`: `invitado_en`, `primer_acceso` y `ultimo_acceso`.
- **Primeros pasos** en el inicio del cliente: entraste, revisa tu primera tarea, lee cómo funciona, conoce cómo pedirnos algo y elige tus avisos. Los pasos se marcan solos. La tarjeta desaparece al completarla, con «Ya lo entendí» o después de 45 días.
- **Página «¿Cómo funciona?»** (`/portal/ayuda`): cómo entrar, qué hay en cada sección, qué hacer cuando algo le toca, qué significa cada estado y cómo pedir algo, con los plazos. Está en el menú y en el encabezado del celular.
- **Pruebas:** 207 comprobaciones que pasan en SQLite y MariaDB.

### v0.11.1

- Los presupuestos ya no pueden ser «Urgente»: son para algo nuevo y no compiten con el trabajo en curso. Solo se elige «Prioritario» o «Sin apuro», y no cuentan para el tope de urgencias.
- Arreglo: el título de la ficha de solicitud mostraba el código del reloj.

### v0.11.0: plazos claros, horarios por país, proyecto nuevo y dictado

- **«Esta semana» pasa a ser «Prioritario»**, con un plazo de máximo 5 días hábiles. El cliente ve el rango de fechas (por ejemplo, un jueves 1: «lun 5 al jue 8 oct»). «Urgente» muestra el próximo día hábil. La fecha sugerida para la tarea es el último día del rango.
- **Reuniones en la hora del cliente:**
  - el cliente propone los horarios en la hora de su país, y el formulario se lo indica con el nombre del país y la bandera;
  - en la bandeja, el equipo ve cada horario en la hora del cliente y en la de la agencia, y al agendar elige directamente la hora convertida;
  - la reunión confirmada se le muestra al cliente en su hora.
- **Presupuesto para un proyecto nuevo:** el cliente puede elegir «Un proyecto nuevo». Al aceptar, el equipo elige un proyecto existente o crea uno con el nombre que indique.
- **Dictado por voz:** el detalle de una solicitud se puede dictar (máximo 2 minutos). Usa el reconocimiento de voz del navegador (Chrome, Edge y Safari), así que no tiene costo; en Firefox el botón no aparece.
- **Si el dictado dice que el micrófono está bloqueado por «Permissions-Policy»:** el servidor manda `microphone=()`. El plugin lo cambia a `microphone=(self)` sólo en el formulario de solicitud, pero si la cabecera la pone Apache (`.htaccess` o el panel del hosting), hay que cambiar ahí `microphone=()` por `microphone=(self)`.
- **«Ordenar con IA»:** si la agencia tiene una clave de IA, ordena el texto dictado sin inventar datos. El cliente lo revisa y puede deshacer. Hay un tope de 10 usos por persona por hora.
- **Hora de tus clientes:** en la barra del panel `/equipo` aparece la bandera y la hora en vivo de cada país donde hay clientes, si alguno está en otra zona horaria. También aparece en la ficha del cliente y en la de cada solicitud.
- **Pruebas:** 190 comprobaciones que pasan en SQLite y MariaDB.

### v0.10.0: solicitudes del cliente

- **El cliente puede pedir cosas desde su portal** (nueva sección «Solicitudes»; en el celular, la pestaña «Pedir»). Cualquier contacto del cliente puede hacerlas. Hay cuatro tipos:
  - **Un pedido:** algo nuevo que necesita.
  - **Un presupuesto:** el equipo cotiza (valor, validez, qué incluye y un PDF) y el cliente lo aprueba con un clic.
  - **Una reunión:** propone hasta tres horarios y la modalidad; el equipo confirma uno y la reunión queda creada y publicada.
  - **Reportar un problema:** entra siempre como urgente.
- Cada solicitud tiene proyecto, detalle, adjuntos opcionales y una conversación propia.
- **Urgencias:** para el cliente todo es urgente, así que:
  - al marcar «Urgente» se le avisa que puede mover sus otras entregas y se le pide el motivo;
  - cada cliente puede tener una sola urgencia abierta a la vez (se cambia en Portal · Ajustes; 0 = sin límite). Los problemas no cuentan;
  - la urgencia se libera cuando la tarea que salió de ella queda lista.
- **Bandeja del equipo** (Portal · Solicitudes y `/equipo/solicitudes`), con filtros Por atender, Esperando al cliente, En curso, Cerradas y Todas, más tipo y proyecto. Las urgentes van primero. Desde la ficha se puede:
  - **aceptar:** crea la tarea con responsable y fecha sugerida (urgente = próximo día hábil, prioritario = máximo 5 días hábiles, sin apuro = sin fecha), y los adjuntos pasan a la tarea;
  - **cotizar**, **agendar**, **responder y cerrar** o **no tomarla**, siempre con un mensaje para el cliente.
- **Avisos:** cada solicitud nueva le llega por correo al equipo asignado al proyecto y al correo de avisos, con «[URGENTE]» en el asunto cuando corresponde. El cliente recibe un correo cuando su solicitud se acepta, se cotiza, se agenda o se responde.
- Nueva tabla `portal_solicitudes` (migración 0008, también garantizada por `Schema::asegurar`).
- **Pruebas:** 175 comprobaciones que pasan en SQLite y MariaDB.

### v0.9.0: filtros, archivado, color por proyecto y modo oscuro

- **Tareas** (admin y panel):
  - **Filtros por estado:** Abiertas, Pendientes, En progreso, En revisión, Cambios pedidos, Listas, Todas y, al final y en gris, **Archivadas**. Cada una muestra cuántas tareas tiene.
  - **Filtros por proyecto y por «le toca a»:** equipo, cliente o *asignadas a mí*.
  - **Orden** por fecha límite, le toca a, cliente y proyecto, estado o más recientes.
  - **Acciones en lote:** se marcan varias tareas y se pueden marcar como listas, archivar o devolver a la lista. Con el filtro «Listas» aparece el botón **«Archivar todas las listas»**.
  - Las archivadas salen de la bandeja, de los contadores y de la vista de proyecto. El cliente las sigue viendo como terminadas.
  - Nueva columna `portal_tareas.archivada`, agregada por `Schema::asegurar`.
- **Contenidos y reuniones:** la misma barra de filtros.
  - Contenidos por estado: activas, borradores, esperando al cliente, respondidas, aprobadas y todas.
  - Reuniones por próximas, pasadas o todas, más un filtro de estado: tareas por revisar, sin resumen, resumen sin publicar, publicado u oculta.
  - Ambas se pueden filtrar por proyecto.
- **Color por proyecto:** el mismo punto de color que ve el cliente en su portal (`Fmt::coloresTodos`) ahora aparece en las listas del admin y del panel, en la bandeja, en la agenda, en «Esperando al cliente», en las novedades y en las tarjetas de proyecto.
- **Bloque «Analizar con IA» reordenado:** título y explicación a la izquierda, botón a la derecha, y los datos (tiempo, proveedor, última propuesta) en una línea aparte.
- **Modo oscuro:**
  - `portal-admin.css` ya no tiene colores fijos de modo claro: usa tintes transparentes y colores mezclados con el del texto. Sirve igual en el admin claro y en el oscuro, sin bloques blancos ni textos lavados.
  - En el portal y el panel, el modo oscuro tiene más contraste: textos secundarios más claros, superficies más separadas del fondo y colores de estado más legibles.
- **Arreglo CSS:** los estilos del panel para las pantallas compartidas estaban dentro de una capa (`@layer`) y `portal-admin.css` les ganaba. Ahora van fuera de la capa.
- **Pruebas:** 138 comprobaciones que pasan en SQLite y MariaDB. Cubren los filtros, el archivado en lote (sin tocar tareas ajenas ni redirigir a otros sitios), las archivadas fuera de la bandeja y los colores.

### v0.8.0: gestión completa desde el panel de equipo

- **Todo lo del admin, ahora en `/equipo`.** El panel tiene las mismas pantallas y acciones que el admin de TypeDock, con la gráfica del panel:
  - clientes, proyectos, fases y contactos;
  - **tareas**: crear, editar, cambiar estado, responsable, comentarios, subir y borrar archivos, avisar al cliente;
  - **contenidos**: entregas, contenidos uno a uno o subida masiva/CSV, versiones, comentarios, publicar o volver a borrador;
  - **reuniones**: agendar, Meet y Calendar, pegar la transcripción o notas, **Analizar con IA**, tareas propuestas, próxima reunión, publicar y avisar.
- **Mismos controladores para el admin y el panel.** Una capa nueva, `Pantalla`, separa qué hace cada acción de dónde se muestra (`PantallaAdmin` y `PantallaEquipo`). Lo que se arregle o mejore en una aplicación queda en las dos.
- **Permisos.** En el panel, cada acción pasa por `EquipoGestion`, que revisa en este orden:
  1. sesión de agencia activa;
  2. CSRF en todo POST;
  3. que el registro de la URL (tarea, entrega, contenido, reunión, fase, contacto, archivo, proyecto o cliente) sea visible para el usuario;
  4. que los `proyecto_id`, `cliente_id` y `reunion_origen_id` del formulario también lo sean, para que nada se pueda mover a un proyecto ajeno.

  Crear, editar o borrar clientes y borrar proyectos queda sólo para Coordinación. Las listas y selectores muestran sólo lo asignado.
- **Firma real.** Comentarios, archivos y actividad hechos desde el panel quedan con el nombre y el id de la persona, y el cliente ve quién le escribió. Desde el admin se sigue firmando con el nombre del equipo.
- **Responsables de tareas.** Se puede elegir a los usuarios de agencia. Los usuarios del admin siguen disponibles, marcados «(admin)», para no perder tareas antiguas.
- **Avisos por correo al equipo asignado.** Cuando un cliente comenta, entrega, aprueba, pide cambios o envía una revisión:
  - el aviso sigue llegando al correo de Ajustes, con enlace al admin;
  - **además** le llega a cada persona que tiene asignado ese proyecto, con enlace directo al panel y sin repetir destinatarios.

  Cada persona puede apagar sus avisos en **Mis ajustes**, página nueva del panel que también guarda el tema.
- **Accesos directos.** La vista de proyecto tiene botones de Nueva tarea, Nueva entrega, Agendar reunión, Nueva fase y Editar proyecto; la ficha de cliente, Nuevo proyecto, Nuevo contacto y Editar cliente y su portal. Los formularios nuevos llegan con el proyecto o cliente ya elegido (`?proyecto_id=` o `?cliente_id=`). La bandeja lleva directo a la tarea, la entrega o la reunión.
- **Logo de la agencia.**
  - Se cambiaba en Ajustes pero seguía viéndose el anterior. Era la caché del navegador (1 día): ahora la URL lleva la versión del archivo.
  - La etiqueta aclara que es el mismo logo para los correos y el panel.
  - En modo oscuro el logo se muestra sobre una placa clara.
- **Pruebas:** 115 comprobaciones, que pasan en SQLite y MariaDB. Cubren permisos del panel, CSRF, firma, avisos y que el admin siga viendo todo.

### v0.7.0 — Panel de equipo (fase 1) y arreglo para MySQL

- **Usuarios de agencia.** Nuevo menú **Portal · Equipo** en el admin, para crear, editar, desactivar y eliminar usuarios.
  - Cada usuario tiene nombre, correo, cargo y rol.
  - Hay dos roles: *Equipo* ve sólo lo asignado; *Coordinación* ve todo.
  - A cada usuario se le asigna un **cliente completo** (incluye los proyectos futuros) o **proyectos sueltos**.
  - Al crear un usuario se puede enviar una invitación por correo, y se puede reenviar desde su ficha.
  - Un correo de agencia no puede ser también el de un contacto de cliente.
- **Panel `/equipo`.** Tiene la misma gráfica que el portal del cliente (sidebar en escritorio, barra inferior en móvil, modo claro y oscuro por usuario) y usa el color de la agencia.
  - **Entrar:** correo y código de 6 dígitos, con los mismos límites que el cliente (5 códigos y 5 fallos cada 10 minutos) y CSRF. La respuesta no revela si el correo existe. Un usuario desactivado pierde la sesión.
  - **Inicio (bandeja):** respuestas del cliente (archivos entregados, cambios pedidos, revisiones enviadas), tareas asignadas a ti, turno del equipo, vencidas, próximas reuniones, contenidos esperando al cliente y novedades.
  - **Clientes:** tarjetas con cada proyecto y su avance, más pendientes del equipo, del cliente y vencidos.
  - **Ficha de cliente:** proyectos, contactos (con su rol) y actividad.
  - **Proyecto:** fases, avance, personas del equipo, tareas agrupadas por turno, contenidos y reuniones, con enlace directo a Meet.
- **Ajustes:** nuevo campo «Color del panel de equipo».
- **Código de acceso:** la lógica se generalizó en `CodigoAccesoService`, y la usan tanto `ContactoAuthService` como `EquipoAuthService`. El comportamiento del portal del cliente no cambia.
- **MySQL/MariaDB (bug):** una transcripción de reunión de más de unos 64 KB (1 hora o más de reunión) fallaba al guardarse, porque una columna `TEXT` de MySQL tiene ese tope. `Schema::asegurar` ahora amplía una sola vez a `MEDIUMTEXT` estas columnas:
  - `portal_reuniones.transcripcion`, `resumen` y `analisis`
  - `portal_correos_cola.texto` y `html`
  - `portal_tareas.descripcion`

  Se probó en MariaDB 11.4.
- **Estructura:** los íconos SVG pasaron a `templates/_iconos.latte`, compartido por ambos portales. `portal.js` lee la URL para guardar el tema desde `<meta name="tema-url">`.
- **Datos:** migración `0007_equipo_agencia.sql`, que crea `portal_equipo`, `portal_equipo_asignaciones`, `portal_equipo_codigos` y `portal_equipo_intentos`. `Schema.php` también las garantiza.
- **Pruebas:** 81 comprobaciones que pasan en SQLite y en MariaDB 11.4.

### v0.6.1 y anteriores

- v0.6.1: SMTP propio con HTML (`SmtpCliente`).
- v0.6.0: correos rediseñados y horario hábil por país, con cola.
- v0.5.x: IA con Anthropic, OpenAI o Gemini; color por proyecto; indicador de espera; `SELECT 1` para que MySQL no corte la conexión durante la espera.
- v0.4.x: reuniones con Meet, resumen e IA.
- v0.3.x: entregas de contenido con visores, subida masiva o CSV y visor de PDF propio.
- Fase 1: seguimiento con clientes, contactos, proyectos, fases, tareas, comentarios, archivos y login por código.

## Ideas siguientes para el panel

- Tareas en tablero (kanban).
- Archivar también entregas y reuniones antiguas.
- Vista previa del contenido con los mismos visores que ve el cliente (mockup de Instagram, reel, PDF por páginas).
- Actividad filtrada por lo asignado.

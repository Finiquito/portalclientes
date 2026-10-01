# Portal de Clientes — plugin para TypeDock

Plugin drop-in de TypeDock que se instala en `proyectos.richgt.com`. Tiene dos caras:

- **Portal del cliente** (`/portal`): los contactos del cliente siguen su proyecto, revisan contenidos, suben archivos y aprueban.
- **Panel de equipo** (`/equipo`): el front de la agencia, con la misma gráfica que el portal del cliente. Cada usuario ve sólo los clientes o proyectos que tiene asignados.

La configuración de fondo (IA, SMTP, correos, usuarios de agencia) queda en el admin de TypeDock, en los menús **Portal · …**.

## Landing Prisma (prismahub.com)

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
- «Ver como cliente»: abrir el portal de un cliente tal como él lo ve.
- Actividad filtrada por lo asignado.

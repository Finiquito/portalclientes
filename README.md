# Portal de Clientes — plugin para TypeDock

Plugin drop-in de TypeDock que se instala en `proyectos.richgt.com`. Tiene dos caras:

- **Portal del cliente** (`/portal`): los contactos del cliente siguen su proyecto, revisan contenidos, suben archivos y aprueban.
- **Panel de equipo** (`/equipo`): el front de la agencia, con la misma gráfica que el portal del cliente. Cada usuario ve sólo los clientes o proyectos que tiene asignados.

La configuración de fondo (IA, SMTP, correos, usuarios de agencia) queda en el admin de TypeDock, en los menús **Portal · …**.

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

- Tareas en tablero (kanban) y filtros por cliente, responsable y estado.
- Vista previa del contenido con los mismos visores que ve el cliente (mockup de Instagram, reel, PDF por páginas).
- «Ver como cliente»: abrir el portal de un cliente tal como él lo ve.
- Actividad filtrada por lo asignado.

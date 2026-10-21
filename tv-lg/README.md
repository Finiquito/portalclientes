# Tiempo de TV

Anota qué app tiene abierta cada aparato de la casa (una **TV LG webOS** y una **tablet Fire**) y muestra, en una
página web con una pestaña por aparato, los minutos de cada día por app (calendario, barras por app, línea de hora
y últimos 14 días). Todo queda en el computador de la casa.

## Qué necesitas
- Python 3.9 o más y la librería `websockets` (si ya instalaste `bscpylgtv`, ya la tienes; si no: `pip3 install websockets`).
- La TV en la misma red que el computador, y un permiso aceptado una vez (ver abajo).

## Primera vez
1. Abre `config.json`: ahí está la lista de `dispositivos` (la TV y la tablet), cada uno con su **IP**, y el título de la página.
   Para apagar uno, pon `"activo": false`.
2. En el Terminal:
   ```bash
   cd tv-lg
   python3 tiempo_tv.py
   ```
3. Si es la primera vez, la TV muestra un cuadro de permiso: acéptalo con **OK** del control.
   La clave queda en `~/.lg_tv_key` (fuera de esta carpeta; no la compartas ni la subas a ningún lado).
   Si ya emparejaste con `lg.py`, reutiliza esa misma clave.
4. Abre **http://localhost:8090**. Desde otro equipo o celular de la casa: `http://IP-DEL-COMPUTADOR:8090`
   (el script la muestra al partir).

Para detenerlo: `Ctrl + C` en el Terminal.

## La tablet Fire (por ADB)
La tablet se lee con **ADB** (el programa de Android para controlar dispositivos por red):
1. En la tablet: Ajustes → Opciones del dispositivo → Acerca de → toca 7 veces el *Número de serie* → Opciones de desarrollador → activa *Depuración ADB*.
2. Descarga las herramientas de Google a `~/platform-tools` (https://developer.android.com/tools/releases/platform-tools). Si las dejas en otra carpeta, cambia `adb` en `config.json`.
3. **Una vez, con cable USB** (acepta el cuadro «¿Permitir depuración USB?» en la tablet): `~/platform-tools/adb tcpip 5555`. Después se puede desconectar el cable.
4. Desde ahí el script se conecta solo por WiFi a la IP de la tablet (`ip` en `config.json`).

Cuenta la app que está en primer plano **mientras la pantalla está encendida**. Con la pantalla apagada o en reposo no cuenta.
La consulta se hace cada 10 segundos (`intervalo`): el consumo de batería es mínimo.
Si la tablet se reinicia, el puerto 5555 se cierra: el script lo avisa en el registro y sigue intentando; hay que repetir el paso 3 con cable.
Los nombres de las apps salen de un diccionario (YouTube, Netflix, Roblox…); las que no están se muestran con una versión legible del nombre del paquete.
En la tablet las apps no tienen ícono (letra de color).

## Dejarlo siempre corriendo (Mac)
```bash
cd tv-lg
bash instalar_arranque.sh
```
La carpeta **no** puede estar en Descargas, Documentos ni Escritorio (macOS bloquea los programas en segundo plano ahí): déjala, por ejemplo, en `~/tv-lg`.
Parte solo al iniciar sesión y se levanta solo si se cae. El registro de lo que pasa queda en `datos/tiempo_tv.log`.
Para quitarlo: `bash desinstalar_arranque.sh`.

## Si el computador se congela o se apaga
Parte de nuevo (o se levanta solo si instalaste el arranque) y **sigue sumando**. Los datos están en `datos/tv.sqlite`.
Lo que estuvo abierto cuando se cortó queda cerrado en la última hora en que se vio la TV, no en la mañana.
El rato en que el script no estuvo corriendo no se cuenta.

## Qué cuenta y qué no
- Cuenta el tiempo de cada app en pantalla: YouTube, Netflix, Live TV, entradas HDMI, etc. Dentro de una app no sabe qué se ve.
- Cada aparato lleva sus propios datos y su propia pestaña; las apps con el mismo nombre en la TV y en la tablet no se mezclan.
- El **protector de pantalla** no cuenta (`excluir_apps` en `config.json`; agrega otros ids si quieres).
- Si la TV se apaga, la conexión se corta y se toma como «TV apagada».
- Los cambios de app de menos de 5 segundos se descartan (parpadeos al cambiar de entrada).
- Si dejas la TV con la pantalla encendida y nadie mirando, igual cuenta.

## Opciones
```bash
python3 tiempo_tv.py --ip 192.168.1.20      # otra IP
python3 tiempo_tv.py --puerto-web 9000      # otro puerto
python3 tiempo_tv.py --solo-local           # la web solo se abre desde este computador
```

## Privacidad
La página no tiene clave y se ve desde cualquier equipo de tu red WiFi (muestra qué ve la familia).
Si no quieres eso, usa `--solo-local`. La base de datos no se puede descargar desde la web.

## Respaldo
Copia la carpeta `datos/` (o solo `datos/tv.sqlite`) de vez en cuando.

## Actualizar a una versión nueva
Descomprime el zip nuevo en otra carpeta y copia sus archivos sobre `~/tv-lg`, **sin tocar la carpeta `datos/`**
(ahí están tus minutos). Si el arranque automático está instalado, reinícialo:
```bash
launchctl kickstart -k gui/$(id -u)/cl.gonzalezarias.tiempotv
```
La primera vez que abre una base de la versión anterior, la migra sola: lo registrado hasta ese día queda en la TV.

## Pruebas
```bash
python3 tests/test_tv.py          # cálculo por día, corte de medianoche, recuperación tras caída, migración, lectura de la tablet
python3 tests/probar_simulado.py  # de punta a punta con una TV falsa (no toca tu TV)
python3 tests/probar_tablet.py    # de punta a punta con un adb falso (no toca tu tablet)
```

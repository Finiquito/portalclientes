# Tiempo de TV

Anota qué app tiene abierta una **LG webOS** y muestra, en una página web, los minutos de cada día por app
(calendario, barras por app, línea de hora y últimos 14 días). Todo queda en el computador de la casa.

## Qué necesitas
- Python 3.9 o más y la librería `websockets` (si ya instalaste `bscpylgtv`, ya la tienes; si no: `pip3 install websockets`).
- La TV en la misma red que el computador, y un permiso aceptado una vez (ver abajo).

## Primera vez
1. Abre `config.json` y revisa la **IP de la TV** (`ip`) y el título de la página.
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

## Dejarlo siempre corriendo (Mac)
```bash
cd tv-lg
bash instalar_arranque.sh
```
Parte solo al iniciar sesión y se levanta solo si se cae. El registro de lo que pasa queda en `datos/tiempo_tv.log`.
Para quitarlo: `bash desinstalar_arranque.sh`.

## Si el computador se congela o se apaga
Parte de nuevo (o se levanta solo si instalaste el arranque) y **sigue sumando**. Los datos están en `datos/tv.sqlite`.
Lo que estuvo abierto cuando se cortó queda cerrado en la última hora en que se vio la TV, no en la mañana.
El rato en que el script no estuvo corriendo no se cuenta.

## Qué cuenta y qué no
- Cuenta el tiempo de cada app en pantalla: YouTube, Netflix, Live TV, entradas HDMI, etc. Dentro de una app no sabe qué se ve.
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

## Pruebas
```bash
python3 tests/test_tv.py          # cálculo por día, corte de medianoche, recuperación tras caída
python3 tests/probar_simulado.py  # de punta a punta con una TV falsa (no toca tu TV)
```

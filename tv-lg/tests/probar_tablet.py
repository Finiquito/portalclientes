#!/usr/bin/env python3
"""Prueba de punta a punta de la tablet con un «adb» falso (no toca tu tablet real).

El adb falso lee tests/.estado_tablet (una línea: «Awake com.paquete», «Asleep» o «caido») y responde como
respondería Android. Uso: python3 tests/probar_tablet.py
"""
import json
import os
import stat
import subprocess
import sys
import tempfile
import time
import urllib.request
from pathlib import Path

RAIZ = Path(__file__).resolve().parent.parent
PUERTO_WEB = 38190

ADB_FALSO = r'''#!/usr/bin/env bash
# adb de mentira: según el archivo de estado responde lo que respondería Android
estado="$(cat "$ESTADO_TABLET")"
if [ "$1" = "connect" ]; then echo "connected to $2"; exit 0; fi
if [ "$estado" = "caido" ]; then echo "error: device '192.168.1.14:5555' not found"; exit 1; fi
if [ "$estado" = "mudo" ]; then sleep 30; exit 0; fi
set -- $estado
echo "  mWakefulness=$1"
if [ -n "$2" ]; then echo "  mCurrentFocus=Window{9f1c2d3 u0 $2/$2.MainActivity}"; fi
'''


def pedir(ruta):
    with urllib.request.urlopen("http://127.0.0.1:%d%s" % (PUERTO_WEB, ruta), timeout=5) as r:
        return json.loads(r.read())


def ok(cond, msg):
    if not cond:
        print("FALLA:", msg)
        sys.exit(1)
    print("ok  ", msg)


def main():
    datos = tempfile.mkdtemp()
    adb = os.path.join(datos, "adb")
    estado = os.path.join(datos, "estado")
    Path(adb).write_text(ADB_FALSO)
    os.chmod(adb, os.stat(adb).st_mode | stat.S_IEXEC)
    Path(estado).write_text("Asleep")
    cfg = {"titulo": "Prueba", "dispositivos": [
        {"id": "tablet", "tipo": "fire", "nombre": "Tablet", "ip": "192.168.1.14", "adb": adb, "intervalo": 1}]}
    cfg_ruta = Path(datos) / "config.json"
    cfg_ruta.write_text(json.dumps(cfg))
    env = dict(os.environ, TIEMPO_TV_DATOS=datos, TIEMPO_TV_MIN_SEG="1", TIEMPO_TV_INTERVALO="1",
               ESTADO_TABLET=estado, TIEMPO_TV_CONFIG=str(cfg_ruta))
    proc = subprocess.Popen([sys.executable, str(RAIZ / "tiempo_tv.py"), "--puerto-web", str(PUERTO_WEB), "--solo-local"],
                            env=env, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)

    def poner(texto, espera):
        Path(estado).write_text(texto)
        time.sleep(espera)

    try:
        time.sleep(2.5)
        e = pedir("/api/datos")["datos"]["tablet"]["estado"]
        ok(e["conectada"] and e["app"] == "", "pantalla apagada: conectada pero sin app")
        poner("Awake com.netflix.mediaclient", 4)
        e = pedir("/api/datos")["datos"]["tablet"]["estado"]
        ok(e["app"] == "Netflix", "pantalla encendida con Netflix: «Ahora: Netflix»")
        poner("Awake com.google.android.youtube", 4)
        poner("Asleep", 3)
        poner("Awake com.android.settings", 3)
        poner("caido", 6)
        d = pedir("/api/datos")["datos"]["tablet"]
        ok(not d["estado"]["conectada"], "adb caído: figura sin conexión")
        apps = d["dias"][next(iter(d["dias"]))]["apps"]
        print("segundos por app:", apps)
        ok(apps.get("com.netflix.mediaclient", 0) >= 3, "Netflix quedó registrado")
        ok(apps.get("com.google.android.youtube", 0) >= 3, "YouTube quedó registrado")
        ok("com.android.settings" in apps, "Ajustes quedó registrado")
        ok(d["apps"]["com.google.android.youtube"]["nombre"] == "YouTube", "el nombre legible viene del diccionario")
        ultimo = d["estado"]["ultimo"]
        time.sleep(3)
        ok(pedir("/api/datos")["datos"]["tablet"]["estado"]["ultimo"] == ultimo, "mientras está caída, «última vez» no avanza")
        poner("Awake com.netflix.mediaclient", 4)
        ok(pedir("/api/datos")["datos"]["tablet"]["estado"]["conectada"], "al volver adb, se reconecta sola")
    finally:
        proc.terminate()
        salida, _ = proc.communicate(timeout=10)
        print("\n--- salida del script ---\n" + salida)
    print("Todo bien.")


if __name__ == "__main__":
    main()

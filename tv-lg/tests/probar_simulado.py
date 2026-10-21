#!/usr/bin/env python3
"""Prueba de punta a punta con una TV simulada (sin tocar tu TV real).

Levanta una "TV" falsa con websockets, corre tiempo_tv.py contra ella y revisa lo que muestra la web.
Uso: python3 tests/probar_simulado.py
"""
import asyncio
import json
import os
import subprocess
import sys
import tempfile
import threading
import time
import urllib.error
import urllib.request
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path

import websockets

RAIZ = Path(__file__).resolve().parent.parent
PUERTO_TV, PUERTO_WEB, PUERTO_ICONOS = 38001, 38090, 38099
PNG = bytes.fromhex("89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4890000000d49444154789c6360000002000001e221bc330000000049454e44ae426082")

# la secuencia que va "viendo" la familia: (segundos de espera antes de cambiar, app)
GUION = [(1, "youtube.leanback.v4"), (3, "netflix"), (3, "com.webos.app.screensaver"), (2, "com.webos.app.home"), (2, None)]


class Iconos(BaseHTTPRequestHandler):
    def log_message(self, *a):
        pass

    def do_GET(self):
        self.send_response(200)
        self.send_header("Content-Type", "image/png")
        self.end_headers()
        self.wfile.write(PNG)


SIN_SUSCRIPCION = "--sin-suscripcion" in sys.argv   # la TV rechaza la suscripción: debe bastar el sondeo
actual = {"app": ""}


async def guion(ws, id_):
    for espera, app in GUION:
        await asyncio.sleep(espera)
        if app is None:
            await ws.close()
            return
        actual["app"] = app
        if not SIN_SUSCRIPCION:
            await ws.send(json.dumps({"type": "response", "id": id_, "payload": {"returnValue": True, "appId": app}}))


async def tv_falsa(ws):
    async for crudo in ws:
        m = json.loads(crudo)
        if m["type"] == "register":
            await ws.send(json.dumps({"type": "registered", "id": m["id"], "payload": {"client-key": "clave-de-prueba"}}))
        elif m.get("uri", "").endswith("listLaunchPoints"):
            icono = "http://127.0.0.1:%d/youtube.png" % PUERTO_ICONOS
            puntos = [{"id": "youtube.leanback.v4", "title": "YouTube", "largeIcon": icono},
                      {"id": "netflix", "title": "Netflix", "largeIcon": icono}]
            await ws.send(json.dumps({"type": "response", "id": m["id"], "payload": {"returnValue": True, "launchPoints": puntos}}))
        elif m.get("uri", "").endswith("getForegroundAppInfo"):
            if m["type"] == "subscribe":
                if SIN_SUSCRIPCION:
                    await ws.send(json.dumps({"type": "error", "id": m["id"], "error": "401 insufficient permissions"}))
                asyncio.ensure_future(guion(ws, m["id"]))
            elif actual["app"]:
                await ws.send(json.dumps({"type": "response", "id": m["id"], "payload": {"returnValue": True, "appId": actual["app"]}}))


def pedir(ruta):
    with urllib.request.urlopen("http://127.0.0.1:%d%s" % (PUERTO_WEB, ruta), timeout=5) as r:
        return r.status, r.headers.get("Content-Type"), r.read()


def ok(cond, msg):
    if not cond:
        print("FALLA:", msg)
        sys.exit(1)
    print("ok  ", msg)


async def servir():
    async with websockets.serve(tv_falsa, "127.0.0.1", PUERTO_TV):
        await asyncio.sleep(14)


def main():
    servidor_iconos = HTTPServer(("127.0.0.1", PUERTO_ICONOS), Iconos)
    threading.Thread(target=servidor_iconos.serve_forever, daemon=True).start()
    threading.Thread(target=lambda: asyncio.run(servir()), daemon=True).start()
    time.sleep(1)

    datos = tempfile.mkdtemp()
    env = dict(os.environ, TIEMPO_TV_DATOS=datos, TIEMPO_TV_LLAVE=os.path.join(datos, "llave"), TIEMPO_TV_MIN_SEG="1", TIEMPO_TV_REINTENTO="2", TIEMPO_TV_LATIDO="1")
    proc = subprocess.Popen([sys.executable, str(RAIZ / "tiempo_tv.py"), "--ip", "127.0.0.1", "--puerto-tv", str(PUERTO_TV),
                             "--sin-ssl", "--puerto-web", str(PUERTO_WEB), "--solo-local"],
                            env=env, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
    try:
        time.sleep(5)
        _, _, cuerpo = pedir("/api/datos")
        d = json.loads(cuerpo)
        ok(d["tv"]["conectada"] and d["tv"]["app"] in ("YouTube", "Netflix"), "a media sesión la TV figura encendida con una app")
        time.sleep(11)
        _, _, cuerpo = pedir("/api/datos")
        d = json.loads(cuerpo)
        hoy = next(iter(d["dias"]))
        apps = d["dias"][hoy]["apps"]
        print("segundos por app:", apps)
        ok("youtube.leanback.v4" in apps and "netflix" in apps, "quedaron registradas YouTube y Netflix")
        if not SIN_SUSCRIPCION:
            ok(abs(apps["youtube.leanback.v4"] - 3) <= 1 and abs(apps["netflix"] - 3) <= 1, "con suscripción, los segundos calzan con el guion (3 y 3)")
        ok("com.webos.app.screensaver" not in apps, "el protector de pantalla no cuenta")
        ok("com.webos.app.home" in apps, "el inicio sí aparece")
        ok(d["apps"]["youtube.leanback.v4"]["nombre"] == "YouTube", "el nombre viene de la TV")
        ok(d["apps"]["youtube.leanback.v4"]["icono"], "se bajó el ícono")
        ok(not d["tv"]["conectada"], "al cerrar la TV la conexión queda apagada")
        ultimo = d["tv"]["ultimo"]
        time.sleep(5)  # con la TV apagada el script reintenta varias veces; la hora de "última vez" no debe moverse
        d2 = json.loads(pedir("/api/datos")[2])
        ok(d2["tv"]["ultimo"] == ultimo, "mientras la TV está apagada, 'última vez' no avanza")
        estado, tipo, cuerpo = pedir("/")
        ok(estado == 200 and b"<title>" in cuerpo, "la página principal responde")
        estado, tipo, cuerpo = pedir("/iconos/" + d["apps"]["youtube.leanback.v4"]["icono"])
        ok(estado == 200 and tipo == "image/png", "el ícono se sirve")
        try:
            pedir("/iconos/..%2Ftv.sqlite")
            ok(False, "no se debe poder salir de la carpeta de íconos")
        except urllib.error.HTTPError as e:
            ok(e.code == 404, "ruta fuera de íconos rechazada (404)")
        try:
            pedir("/datos/tv.sqlite")
            ok(False, "la base no se sirve")
        except urllib.error.HTTPError as e:
            ok(e.code == 404, "la base de datos no se puede descargar (404)")
        ok(os.stat(os.path.join(datos, "llave")).st_mode & 0o777 == 0o600, "la clave se guarda con permiso 600")
    finally:
        proc.terminate()
        salida, _ = proc.communicate(timeout=10)
        print("\n--- salida del script ---\n" + salida)
    print("Todo bien.")


if __name__ == "__main__":
    main()

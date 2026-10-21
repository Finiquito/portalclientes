#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Tiempo de TV: anota qué app tiene abierta una LG webOS y muestra los minutos de cada día en una web local.

    python3 tiempo_tv.py                 → registra y sirve la web en http://localhost:8090
    python3 tiempo_tv.py --ip 192.168.1.20 --puerto-web 9000

Los datos quedan en datos/tv.sqlite (junto a este archivo). Si el script se cae o el computador
se congela, al partir de nuevo cierra la sesión que quedó abierta en la hora en que se vio por última vez.
"""
import argparse
import asyncio
import json
import os
import re
import signal
import socket
import sqlite3
import ssl
import sys
import threading
import time
import urllib.request
from datetime import datetime, time as hora, timedelta
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import urlparse

AQUI = Path(__file__).resolve().parent
DATOS = Path(os.environ.get("TIEMPO_TV_DATOS", str(AQUI / "datos")))
ICONOS = DATOS / "iconos"
LLAVE = Path(os.environ.get("TIEMPO_TV_LLAVE", str(Path.home() / ".lg_tv_key")))
MIN_SEG = float(os.environ.get("TIEMPO_TV_MIN_SEG", "5"))   # sesiones más cortas se descartan (parpadeos al cambiar de app)
LATIDO = float(os.environ.get("TIEMPO_TV_LATIDO", "15"))       # cada cuántos segundos pregunta a la TV y anota "visto por última vez"
REINTENTO = float(os.environ.get("TIEMPO_TV_REINTENTO", "15"))    # cada cuántos segundos reintenta si la TV está apagada

PERMISOS = ["LAUNCH", "CONTROL_POWER", "CONTROL_AUDIO", "CONTROL_DISPLAY",
            "CONTROL_INPUT_TV", "READ_APP_STATUS", "READ_RUNNING_APPS",
            "READ_INSTALLED_APPS", "READ_POWER_STATE", "READ_NETWORK_STATE",
            "WRITE_NOTIFICATION_TOAST"]

NOMBRES_FIJOS = {
    "com.webos.app.home": "Inicio",
    "com.webos.app.screensaver": "Protector de pantalla",
}

URI_APP_ABIERTA = "ssap://com.webos.applicationManager/getForegroundAppInfo"

ESQUEMA = """
CREATE TABLE IF NOT EXISTS sesiones(id INTEGER PRIMARY KEY, app_id TEXT NOT NULL, ini REAL NOT NULL, fin REAL);
CREATE TABLE IF NOT EXISTS apps(id TEXT PRIMARY KEY, nombre TEXT, icono TEXT);
CREATE TABLE IF NOT EXISTS estado(clave TEXT PRIMARY KEY, valor TEXT);
"""


def log(texto):
    print(datetime.now().strftime("%Y-%m-%d %H:%M:%S"), texto, flush=True)


# ---------- configuración ----------

def leer_config():
    cfg = {
        "titulo": "Tiempo de TV",
        "ip": "192.168.1.16",
        "puerto_web": 8090,
        "excluir_apps": ["com.webos.app.screensaver"],
    }
    ruta = AQUI / "config.json"
    if ruta.exists():
        cfg.update(json.loads(ruta.read_text(encoding="utf-8")))
    return cfg


# ---------- base de datos ----------

def abrir_db():
    DATOS.mkdir(parents=True, exist_ok=True)
    con = sqlite3.connect(str(DATOS / "tv.sqlite"), timeout=10)
    con.execute("PRAGMA journal_mode=WAL")
    con.executescript(ESQUEMA)
    return con


def poner(con, **valores):
    con.executemany("INSERT OR REPLACE INTO estado(clave, valor) VALUES(?, ?)",
                    [(k, str(v)) for k, v in valores.items()])
    con.commit()


def leer_estado(con):
    return dict(con.execute("SELECT clave, valor FROM estado").fetchall())


def cerrar_abiertas(con, fin):
    """Cierra las sesiones abiertas en `fin`. Las que duran menos de MIN_SEG se borran."""
    for id_, ini in con.execute("SELECT id, ini FROM sesiones WHERE fin IS NULL").fetchall():
        f = max(fin, ini)
        if f - ini < MIN_SEG:
            con.execute("DELETE FROM sesiones WHERE id = ?", (id_,))
        else:
            con.execute("UPDATE sesiones SET fin = ? WHERE id = ?", (f, id_))
    con.commit()


def recuperar(con):
    """Al partir: lo que quedó abierto (corte de luz, congelamiento) termina cuando se vio por última vez."""
    ultimo = float(leer_estado(con).get("ultimo_visto") or 0)
    cerrar_abiertas(con, ultimo)
    poner(con, conectada=0, app_actual="")


# ---------- cálculo por día ----------

def medianoche(d):
    return datetime.combine(d, hora.min).timestamp()


def repartir(ini, fin):
    """Parte un intervalo [ini, fin) en trozos por día local: (fecha 'YYYY-MM-DD', desde, hasta)."""
    d = datetime.fromtimestamp(ini).date()
    while True:
        sig = d + timedelta(days=1)
        a, b = max(ini, medianoche(d)), min(fin, medianoche(sig))
        if b > a:
            yield d.isoformat(), a, b
        d = sig
        if medianoche(d) >= fin:
            break


def minuto_del_dia(ts):
    t = datetime.fromtimestamp(ts)
    return t.hour * 60 + t.minute + t.second / 60.0


def datos_api(con, cfg, ahora=None):
    ahora = ahora or time.time()
    est = leer_estado(con)
    apps = {i: {"nombre": n, "icono": ic} for i, n, ic in con.execute("SELECT id, nombre, icono FROM apps")}
    dias = {}
    for app_id, ini, fin in con.execute("SELECT app_id, ini, COALESCE(fin, ?) FROM sesiones ORDER BY ini", (ahora,)):
        if app_id not in apps:
            apps[app_id] = {"nombre": NOMBRES_FIJOS.get(app_id, app_id), "icono": None}
        for fecha, a, b in repartir(ini, fin):
            dia = dias.setdefault(fecha, {"apps": {}, "segmentos": []})
            dia["apps"][app_id] = dia["apps"].get(app_id, 0) + (b - a)
            ini_min = minuto_del_dia(a)
            dia["segmentos"].append([app_id, round(ini_min, 2), round(min(1440.0, ini_min + (b - a) / 60.0), 2)])
    for dia in dias.values():
        dia["apps"] = {k: round(v) for k, v in dia["apps"].items()}
    ultimo = float(est.get("ultimo_visto") or 0)
    app_actual = est.get("app_actual") or ""
    return {
        "titulo": cfg.get("titulo", "Tiempo de TV"),
        "ahora": ahora,
        "tv": {
            "conectada": est.get("conectada") == "1" and ahora - ultimo < LATIDO * 3,
            "app": apps.get(app_actual, {}).get("nombre") or NOMBRES_FIJOS.get(app_actual, app_actual),
            "desde": float(est.get("desde") or 0),
            "ultimo": ultimo,
        },
        "apps": apps,
        "dias": dias,
        "primer_dia": min(dias) if dias else None,
    }


# ---------- web ----------

TIPOS = {".html": "text/html; charset=utf-8", ".png": "image/png", ".jpg": "image/jpeg",
         ".jpeg": "image/jpeg", ".svg": "image/svg+xml", ".webp": "image/webp"}


def crear_handler(cfg):
    class Handler(BaseHTTPRequestHandler):
        def log_message(self, *args):
            pass

        def responder(self, codigo, cuerpo, tipo, cache="no-store"):
            self.send_response(codigo)
            self.send_header("Content-Type", tipo)
            self.send_header("Content-Length", str(len(cuerpo)))
            self.send_header("Cache-Control", cache)
            self.send_header("X-Content-Type-Options", "nosniff")
            self.end_headers()
            self.wfile.write(cuerpo)

        def do_GET(self):
            ruta = urlparse(self.path).path
            if ruta in ("/", "/index.html"):
                self.responder(200, (AQUI / "web" / "index.html").read_bytes(), TIPOS[".html"])
            elif ruta == "/api/datos":
                con = abrir_db()
                try:
                    cuerpo = json.dumps(datos_api(con, cfg), ensure_ascii=False).encode("utf-8")
                finally:
                    con.close()
                self.responder(200, cuerpo, "application/json; charset=utf-8")
            elif ruta.startswith("/iconos/"):
                nombre = ruta[len("/iconos/"):]
                archivo = ICONOS / nombre
                if re.fullmatch(r"[A-Za-z0-9._-]+", nombre) and archivo.is_file():
                    tipo = TIPOS.get(archivo.suffix.lower(), "application/octet-stream")
                    self.responder(200, archivo.read_bytes(), tipo, "max-age=86400")
                else:
                    self.responder(404, b"No encontrado", "text/plain; charset=utf-8")
            else:
                self.responder(404, b"No encontrado", "text/plain; charset=utf-8")

    return Handler


def ip_local():
    try:
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        s.connect(("192.0.2.1", 9))
        ip = s.getsockname()[0]
        s.close()
        return ip
    except OSError:
        return "127.0.0.1"


def iniciar_web(cfg, host, puerto):
    servidor = ThreadingHTTPServer((host, puerto), crear_handler(cfg))
    servidor.daemon_threads = True
    threading.Thread(target=servidor.serve_forever, daemon=True).start()
    return servidor


# ---------- conexión con la TV ----------

def descargar(url, destino):
    ctx = ssl.create_default_context()
    ctx.check_hostname = False
    ctx.verify_mode = ssl.CERT_NONE
    with urllib.request.urlopen(url, context=ctx, timeout=10) as r:
        destino.write_bytes(r.read())


def nombre_archivo_icono(app_id, url):
    ext = Path(urlparse(url).path).suffix.lower()
    if ext not in TIPOS or ext == ".html":
        ext = ".png"
    return re.sub(r"[^A-Za-z0-9._-]", "_", app_id) + ext


class Colector:
    def __init__(self, cfg, con, puerto_tv, con_ssl):
        self.cfg, self.con = cfg, con
        self.ip, self.puerto, self.con_ssl = cfg["ip"], puerto_tv, con_ssl
        self.excluir = set(cfg.get("excluir_apps", []))
        self.actual = None
        self.avisado = False
        self.vivo = False   # hubo conexión registrada con la TV
        self.ultima_respuesta = 0.0
        self.errores_avisados = set()

    def cambiar(self, app_id):
        if app_id == self.actual:
            return
        ahora = time.time()
        cerrar_abiertas(self.con, ahora)
        self.actual = None
        if app_id in self.excluir:
            poner(self.con, app_actual="", desde=ahora, ultimo_visto=ahora)
            return
        self.con.execute("INSERT INTO sesiones(app_id, ini) VALUES(?, ?)", (app_id, ahora))
        self.con.commit()
        self.actual = app_id
        poner(self.con, app_actual=app_id, desde=ahora, ultimo_visto=ahora)

    def guardar_apps(self, puntos):
        for p in puntos:
            if not p.get("id"):
                continue
            nombre = p.get("title") or NOMBRES_FIJOS.get(p["id"]) or p["id"]
            self.con.execute(
                "INSERT INTO apps(id, nombre) VALUES(?, ?) ON CONFLICT(id) DO UPDATE SET nombre = excluded.nombre",
                (p["id"], nombre))
        self.con.commit()

    async def bajar_iconos(self, puntos):
        ICONOS.mkdir(parents=True, exist_ok=True)
        for p in puntos:
            url = p.get("largeIcon") or p.get("icon")
            if not p.get("id") or not url or not url.startswith("http"):
                continue
            archivo = nombre_archivo_icono(p["id"], url)
            if not (ICONOS / archivo).exists():
                try:
                    await asyncio.to_thread(descargar, url, ICONOS / archivo)
                except Exception as e:
                    log("No pude bajar el ícono de %s (%s)" % (p["id"], e.__class__.__name__))
                    continue
            self.con.execute("UPDATE apps SET icono = ? WHERE id = ?", (archivo, p["id"]))
        self.con.commit()

    async def latido(self, ws):
        """Cada LATIDO segundos: anota que la TV sigue ahí y le pregunta qué app tiene abierta.

        La suscripción avisa al tiro cuando cambia la app; esta pregunta es el respaldo, y si la TV deja de
        responder por completo se cierra la conexión para no seguir sumando tiempo que no existe."""
        while True:
            if time.time() - self.ultima_respuesta > LATIDO * 3:
                log("La TV dejó de responder.")
                await ws.close()
                return
            poner(self.con, ultimo_visto=time.time(), conectada=1)
            await ws.send(json.dumps({"type": "request", "id": "sondeo", "uri": URI_APP_ABIERTA}))
            await asyncio.sleep(LATIDO)

    async def sesion(self):
        import websockets
        url = ("wss" if self.con_ssl else "ws") + "://%s:%s" % (self.ip, self.puerto)
        ctx = None
        if self.con_ssl:
            ctx = ssl.create_default_context()
            ctx.check_hostname = False
            ctx.verify_mode = ssl.CERT_NONE
        async with websockets.connect(url, ssl=ctx, open_timeout=10, ping_interval=None) as ws:
            registro = {"pairingType": "PROMPT", "manifest": {"manifestVersion": 1, "permissions": PERMISOS}}
            if LLAVE.exists():
                registro["client-key"] = LLAVE.read_text().strip()
            await ws.send(json.dumps({"type": "register", "id": "reg0", "payload": registro}))
            avisado_permiso = False
            while True:
                msg = json.loads(await asyncio.wait_for(ws.recv(), 120))
                if msg.get("type") == "registered":
                    LLAVE.write_text(msg["payload"]["client-key"])
                    os.chmod(str(LLAVE), 0o600)
                    break
                if msg.get("type") == "error":
                    raise RuntimeError("la TV rechazó el registro: %s" % msg.get("error"))
                if not avisado_permiso:
                    log("Acepta el permiso en la TV con el botón OK del control...")
                    avisado_permiso = True
            self.avisado = False
            self.vivo = True
            self.ultima_respuesta = time.time()
            log("Conectado a la TV (%s)" % self.ip)
            poner(self.con, conectada=1, ultimo_visto=time.time())
            await ws.send(json.dumps({"type": "subscribe", "id": "fg", "uri": URI_APP_ABIERTA}))
            await ws.send(json.dumps({"type": "request", "id": "lista",
                                      "uri": "ssap://com.webos.applicationManager/listLaunchPoints"}))
            tarea = asyncio.ensure_future(self.latido(ws))
            try:
                async for crudo in ws:
                    msg = json.loads(crudo)
                    self.ultima_respuesta = time.time()
                    p = msg.get("payload") or {}
                    if msg.get("type") == "error" and msg.get("id") not in self.errores_avisados:
                        self.errores_avisados.add(msg.get("id"))
                        log("La TV respondió un error a «%s»: %s" % (msg.get("id"), msg.get("error")))
                    if msg.get("id") in ("fg", "sondeo") and p.get("appId"):
                        self.cambiar(p["appId"])
                    elif msg.get("id") == "lista" and p.get("launchPoints"):
                        self.guardar_apps(p["launchPoints"])
                        asyncio.ensure_future(self.bajar_iconos(p["launchPoints"]))
            finally:
                tarea.cancel()

    def desconectar(self):
        """La TV se apagó o se cortó la conexión: cierra la sesión y recuerda cuándo se vio por última vez."""
        ahora = time.time()
        cerrar_abiertas(self.con, ahora)
        self.actual = None
        poner(self.con, conectada=0, app_actual="")
        if self.vivo:
            poner(self.con, ultimo_visto=ahora)
            self.vivo = False

    async def correr(self):
        while True:
            try:
                await self.sesion()
            except asyncio.CancelledError:
                raise
            except Exception as e:
                if e.__class__.__name__.startswith("ConnectionClosed"):
                    log("La TV cerró la conexión (apagada).")
                elif not self.avisado:
                    log("Sin conexión con la TV (%s %s). Reintento cada %s s." % (e.__class__.__name__, e, REINTENTO))
                    self.avisado = True
            else:
                log("La TV cerró la conexión (apagada).")
            self.desconectar()
            await asyncio.sleep(REINTENTO)


def main():
    cfg = leer_config()
    ap = argparse.ArgumentParser(description="Registra el tiempo por app de una LG webOS y lo muestra en una web local.")
    ap.add_argument("--ip", default=cfg["ip"], help="IP de la TV (por defecto la de config.json)")
    ap.add_argument("--puerto-web", type=int, default=int(cfg["puerto_web"]))
    ap.add_argument("--solo-local", action="store_true", help="La web solo se abre desde este computador")
    ap.add_argument("--puerto-tv", type=int, default=3001)
    ap.add_argument("--sin-ssl", action="store_true", help="Conectar con ws:// (solo para pruebas)")
    args = ap.parse_args()
    cfg["ip"] = args.ip

    con = abrir_db()
    recuperar(con)
    host = "127.0.0.1" if args.solo_local else "0.0.0.0"
    iniciar_web(cfg, host, args.puerto_web)
    log("Web lista: http://localhost:%s" % args.puerto_web)
    if not args.solo_local:
        log("Desde otros equipos de la casa: http://%s:%s" % (ip_local(), args.puerto_web))

    signal.signal(signal.SIGTERM, lambda *a: sys.exit(0))
    colector = Colector(cfg, con, args.puerto_tv, not args.sin_ssl)
    try:
        asyncio.run(colector.correr())
    except KeyboardInterrupt:
        pass
    finally:
        colector.desconectar()
        log("Detenido.")


if __name__ == "__main__":
    main()

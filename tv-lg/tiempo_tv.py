#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Tiempo de pantalla de la casa: anota qué app tiene abierta cada aparato (TV LG webOS, tablet Fire por ADB)
y muestra los minutos de cada día en una web local, con una pestaña por aparato.

    python3 tiempo_tv.py                 → registra y sirve la web en http://localhost:8090
    python3 tiempo_tv.py --ip 192.168.1.20 --puerto-web 9000

Los dispositivos se definen en config.json. Los datos quedan en datos/tv.sqlite (junto a este archivo).
Si el script se cae o el computador se congela, al partir de nuevo cierra la sesión que quedó abierta
en la hora en que se vio por última vez.
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
LATIDO = float(os.environ.get("TIEMPO_TV_LATIDO", "15"))    # TV: cada cuántos segundos pregunta y anota "visto por última vez"
REINTENTO = float(os.environ.get("TIEMPO_TV_REINTENTO", "15"))   # TV: cada cuántos segundos reintenta si está apagada
INTERVALO_FIRE = os.environ.get("TIEMPO_TV_INTERVALO")      # tablet: segundos entre consultas (por defecto, el de config.json)

PERMISOS = ["LAUNCH", "CONTROL_POWER", "CONTROL_AUDIO", "CONTROL_DISPLAY",
            "CONTROL_INPUT_TV", "READ_APP_STATUS", "READ_RUNNING_APPS",
            "READ_INSTALLED_APPS", "READ_POWER_STATE", "READ_NETWORK_STATE",
            "WRITE_NOTIFICATION_TOAST"]

URI_APP_ABIERTA = "ssap://com.webos.applicationManager/getForegroundAppInfo"

NOMBRES_FIJOS = {
    "com.webos.app.home": "Inicio",
    "com.webos.app.screensaver": "Protector de pantalla",
}

# Nombres de apps de la tablet (el sistema solo entrega el paquete, no el nombre).
NOMBRES_PAQUETES = {
    "com.amazon.firelauncher": "Inicio",
    "com.amazon.cloud9": "Silk (navegador)",
    "com.amazon.avod": "Prime Video",
    "com.amazon.avod.thirdpartyclient": "Prime Video",
    "com.amazon.kindle": "Kindle",
    "com.amazon.venezia": "Appstore",
    "com.amazon.photos": "Fotos",
    "com.amazon.dee.app": "Alexa",
    "com.amazon.tahoe": "Amazon Kids",
    "com.amazon.imdb.tv.android.app": "Freevee",
    "com.android.settings": "Ajustes",
    "com.amazon.settings": "Ajustes",
    "com.google.android.youtube": "YouTube",
    "com.google.android.youtube.tv": "YouTube",
    "com.google.android.apps.youtube.kids": "YouTube Kids",
    "com.netflix.mediaclient": "Netflix",
    "com.disney.disneyplus": "Disney+",
    "com.spotify.music": "Spotify",
    "com.android.chrome": "Chrome",
    "com.whatsapp": "WhatsApp",
    "com.instagram.android": "Instagram",
    "com.facebook.katana": "Facebook",
    "com.zhiliaoapp.musically": "TikTok",
    "com.roblox.client": "Roblox",
    "com.mojang.minecraftpe": "Minecraft",
    "com.supercell.clashofclans": "Clash of Clans",
    "com.google.android.gm": "Gmail",
    "com.zoom.videomeetings": "Zoom",
}

ESQUEMA = """
CREATE TABLE IF NOT EXISTS sesiones(id INTEGER PRIMARY KEY, dispositivo TEXT NOT NULL DEFAULT 'tv', app_id TEXT NOT NULL, ini REAL NOT NULL, fin REAL);
CREATE TABLE IF NOT EXISTS apps(dispositivo TEXT NOT NULL, id TEXT NOT NULL, nombre TEXT, icono TEXT, PRIMARY KEY(dispositivo, id));
CREATE TABLE IF NOT EXISTS estado(clave TEXT PRIMARY KEY, valor TEXT);
"""


def log(texto):
    print(datetime.now().strftime("%Y-%m-%d %H:%M:%S"), texto, flush=True)


# ---------- configuración ----------

def normalizar_config(cfg):
    """Deja la config con la lista `dispositivos`. Si solo trae `ip` (versión antigua), arma la TV."""
    cfg.setdefault("titulo", "Tiempo de TV")
    cfg.setdefault("puerto_web", 8090)
    if not cfg.get("dispositivos"):
        cfg["dispositivos"] = [{
            "id": "tv", "tipo": "lg", "nombre": "TV", "subtitulo": "LG webOS",
            "ip": cfg.get("ip", "192.168.1.16"),
            "excluir_apps": cfg.get("excluir_apps", ["com.webos.app.screensaver"]),
        }]
    for d in cfg["dispositivos"]:
        d.setdefault("tipo", "lg")
        d.setdefault("nombre", d["id"])
        d.setdefault("subtitulo", "LG webOS" if d["tipo"] == "lg" else "Fire (ADB)")
        d.setdefault("excluir_apps", [])
        d.setdefault("activo", True)
    return cfg


def leer_config():
    cfg = {}
    ruta = Path(os.environ.get("TIEMPO_TV_CONFIG", str(AQUI / "config.json")))
    if ruta.exists():
        cfg = json.loads(ruta.read_text(encoding="utf-8"))
    return normalizar_config(cfg)


# ---------- base de datos ----------

def migrar(con):
    """La primera versión guardaba solo la TV. Pasa esos datos al esquema por dispositivo sin perder nada."""
    if con.execute("PRAGMA user_version").fetchone()[0] >= 2:
        return
    cols = [r[1] for r in con.execute("PRAGMA table_info(sesiones)")]
    if "dispositivo" not in cols:
        con.execute("ALTER TABLE sesiones ADD COLUMN dispositivo TEXT NOT NULL DEFAULT 'tv'")
    cols = [r[1] for r in con.execute("PRAGMA table_info(apps)")]
    if "dispositivo" not in cols:
        con.executescript("""
            ALTER TABLE apps RENAME TO apps_v1;
            CREATE TABLE apps(dispositivo TEXT NOT NULL, id TEXT NOT NULL, nombre TEXT, icono TEXT, PRIMARY KEY(dispositivo, id));
            INSERT INTO apps SELECT 'tv', id, nombre, icono FROM apps_v1;
            DROP TABLE apps_v1;
        """)
    con.execute("UPDATE estado SET clave = 'tv.' || clave WHERE clave NOT LIKE '%.%'")
    con.execute("PRAGMA user_version = 2")
    con.commit()


def abrir_db():
    DATOS.mkdir(parents=True, exist_ok=True)
    con = sqlite3.connect(str(DATOS / "tv.sqlite"), timeout=10)
    con.execute("PRAGMA journal_mode=WAL")
    con.executescript(ESQUEMA)
    migrar(con)
    return con


def poner(con, disp, **valores):
    con.executemany("INSERT OR REPLACE INTO estado(clave, valor) VALUES(?, ?)",
                    [("%s.%s" % (disp, k), str(v)) for k, v in valores.items()])
    con.commit()


def leer_estado(con, disp):
    pref = disp + "."
    return {c[len(pref):]: v for c, v in con.execute("SELECT clave, valor FROM estado") if c.startswith(pref)}


def cerrar_abiertas(con, disp, fin):
    """Cierra las sesiones abiertas de un dispositivo en `fin`. Las que duran menos de MIN_SEG se borran."""
    for id_, ini in con.execute("SELECT id, ini FROM sesiones WHERE fin IS NULL AND dispositivo = ?", (disp,)).fetchall():
        f = max(fin, ini)
        if f - ini < MIN_SEG:
            con.execute("DELETE FROM sesiones WHERE id = ?", (id_,))
        else:
            con.execute("UPDATE sesiones SET fin = ? WHERE id = ?", (f, id_))
    con.commit()


def recuperar(con, disp):
    """Al partir: lo que quedó abierto (corte de luz, congelamiento) termina cuando se vio por última vez."""
    ultimo = float(leer_estado(con, disp).get("ultimo_visto") or 0)
    cerrar_abiertas(con, disp, ultimo)
    poner(con, disp, conectada=0, app_actual="")


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


def nombre_bonito(paquete):
    """Nombre legible para un paquete de Android que no está en el diccionario."""
    if paquete in NOMBRES_PAQUETES:
        return NOMBRES_PAQUETES[paquete]
    partes = [p for p in paquete.split(".") if p not in ("com", "org", "net", "android", "app", "apps", "mobile", "client", "google", "amazon")]
    return (partes[-1] if partes else paquete).replace("_", " ").capitalize()


def nombre_por_defecto(disp, app_id):
    if disp.get("tipo") == "fire":
        return nombre_bonito(app_id)
    return NOMBRES_FIJOS.get(app_id, app_id)


def datos_dispositivo(con, disp, ahora):
    id_ = disp["id"]
    est = leer_estado(con, id_)
    apps = {i: {"nombre": n, "icono": ic}
            for i, n, ic in con.execute("SELECT id, nombre, icono FROM apps WHERE dispositivo = ?", (id_,))}
    dias = {}
    consulta = "SELECT app_id, ini, COALESCE(fin, ?) FROM sesiones WHERE dispositivo = ? ORDER BY ini"
    for app_id, ini, fin in con.execute(consulta, (ahora, id_)):
        if app_id not in apps:
            apps[app_id] = {"nombre": nombre_por_defecto(disp, app_id), "icono": None}
        for fecha, a, b in repartir(ini, fin):
            dia = dias.setdefault(fecha, {"apps": {}, "segmentos": []})
            dia["apps"][app_id] = dia["apps"].get(app_id, 0) + (b - a)
            ini_min = minuto_del_dia(a)
            dia["segmentos"].append([app_id, round(ini_min, 2), round(min(1440.0, ini_min + (b - a) / 60.0), 2)])
    for dia in dias.values():
        dia["apps"] = {k: round(v) for k, v in dia["apps"].items()}
    ultimo = float(est.get("ultimo_visto") or 0)
    app_actual = est.get("app_actual") or ""
    if disp.get("tipo") == "fire":
        espera = float(INTERVALO_FIRE or disp.get("intervalo", 10)) * 3
    else:
        espera = LATIDO * 3
    return {
        "estado": {
            "conectada": est.get("conectada") == "1" and ahora - ultimo < espera + 5,
            "app": apps.get(app_actual, {}).get("nombre") or (nombre_por_defecto(disp, app_actual) if app_actual else ""),
            "desde": float(est.get("desde") or 0),
            "ultimo": ultimo,
        },
        "apps": apps,
        "dias": dias,
        "primer_dia": min(dias) if dias else None,
    }


def datos_api(con, cfg, ahora=None):
    ahora = ahora or time.time()
    activos = [d for d in cfg["dispositivos"] if d.get("activo", True)]
    return {
        "titulo": cfg.get("titulo", "Tiempo de TV"),
        "ahora": ahora,
        "dispositivos": [{"id": d["id"], "nombre": d["nombre"], "subtitulo": d["subtitulo"], "tipo": d["tipo"]} for d in activos],
        "datos": {d["id"]: datos_dispositivo(con, d, ahora) for d in activos},
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


# ---------- colectores ----------

class Colector:
    """Lo común a cualquier aparato: abrir y cerrar sesiones y recordar si está conectado."""

    def __init__(self, disp, con):
        self.disp, self.con = disp, con
        self.id = disp["id"]
        self.excluir = set(disp.get("excluir_apps", []))
        self.actual = None
        self.avisado = False
        self.vivo = False   # hubo conexión con el aparato desde la última desconexión

    def poner(self, **valores):
        poner(self.con, self.id, **valores)

    def cambiar(self, app_id):
        """`app_id` None o excluida = no hay nada que contar (pantalla apagada, protector de pantalla...)."""
        if app_id in self.excluir:
            app_id = None
        if app_id == self.actual:
            return
        ahora = time.time()
        cerrar_abiertas(self.con, self.id, ahora)
        self.actual = None
        if app_id is None:
            self.poner(app_actual="", desde=ahora, ultimo_visto=ahora)
            return
        self.con.execute("INSERT OR IGNORE INTO apps(dispositivo, id, nombre) VALUES(?, ?, ?)",
                         (self.id, app_id, nombre_por_defecto(self.disp, app_id)))
        self.con.execute("INSERT INTO sesiones(dispositivo, app_id, ini) VALUES(?, ?, ?)", (self.id, app_id, ahora))
        self.con.commit()
        self.actual = app_id
        self.poner(app_actual=app_id, desde=ahora, ultimo_visto=ahora)

    def desconectar(self, fin=None):
        """Se apagó o se cortó la conexión: cierra la sesión (en `fin`, o ahora) y recuerda cuándo se vio por última vez."""
        ahora = time.time()
        cerrar_abiertas(self.con, self.id, fin or ahora)
        self.actual = None
        self.poner(conectada=0, app_actual="")
        if self.vivo:
            self.poner(ultimo_visto=fin or ahora)
            self.vivo = False


# ----- TV LG webOS -----

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


class ColectorLG(Colector):
    def __init__(self, disp, con, puerto, con_ssl):
        Colector.__init__(self, disp, con)
        self.ip, self.puerto, self.con_ssl = disp["ip"], puerto, con_ssl
        self.ultima_respuesta = 0.0
        self.errores_avisados = set()

    def guardar_apps(self, puntos):
        for p in puntos:
            if not p.get("id"):
                continue
            nombre = p.get("title") or NOMBRES_FIJOS.get(p["id"]) or p["id"]
            self.con.execute(
                "INSERT INTO apps(dispositivo, id, nombre) VALUES(?, ?, ?) "
                "ON CONFLICT(dispositivo, id) DO UPDATE SET nombre = excluded.nombre",
                (self.id, p["id"], nombre))
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
            self.con.execute("UPDATE apps SET icono = ? WHERE dispositivo = ? AND id = ?", (archivo, self.id, p["id"]))
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
            self.poner(ultimo_visto=time.time(), conectada=1)
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
            self.poner(conectada=1, ultimo_visto=time.time())
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


# ----- Tablet Fire (Android) por ADB -----

CONSULTA_FIRE = "dumpsys power | grep mWakefulness=; dumpsys window windows | grep mCurrentFocus"
RE_PANTALLA = re.compile(r"mWakefulness=(\w+)")
RE_FOCO = re.compile(r"mCurrentFocus=Window\{\S+ u\d+ ([\w.]+)/")


def leer_pantalla(salida):
    """Interpreta la respuesta de la tablet. None = no se pudo leer; '' = nada que contar; otro = paquete abierto."""
    m = RE_PANTALLA.search(salida)
    if not m:
        return None
    if m.group(1) != "Awake":
        return ""
    f = RE_FOCO.search(salida)
    return f.group(1) if f else ""


class ColectorFire(Colector):
    def __init__(self, disp, con):
        Colector.__init__(self, disp, con)
        self.adb = os.path.expanduser(disp.get("adb", "~/platform-tools/adb"))
        self.destino = "%s:%s" % (disp["ip"], disp.get("puerto", 5555))
        self.intervalo = float(INTERVALO_FIRE or disp.get("intervalo", 10))
        self.ultimo_ok = 0.0
        self.fallos = 0

    async def correr_adb(self, *args, timeout=10):
        proc = await asyncio.create_subprocess_exec(self.adb, *args, stdout=asyncio.subprocess.PIPE,
                                                    stderr=asyncio.subprocess.STDOUT)
        try:
            salida, _ = await asyncio.wait_for(proc.communicate(), timeout)
        except asyncio.TimeoutError:
            proc.kill()
            await proc.wait()
            raise
        return salida.decode("utf-8", "replace")

    async def leer(self):
        if self.fallos or not self.vivo:
            await self.correr_adb("connect", self.destino)
        return leer_pantalla(await self.correr_adb("-s", self.destino, "shell", CONSULTA_FIRE))

    async def correr(self):
        while True:
            paquete = None
            try:
                paquete = await self.leer()
            except asyncio.CancelledError:
                raise
            except FileNotFoundError:
                if not self.avisado:
                    log("No encuentro adb en %s (revisa «adb» en config.json)." % self.adb)
                    self.avisado = True
            except Exception:
                pass
            if paquete is None:
                self.fallos += 1
                if self.fallos == 2 and self.vivo:
                    log("Sin conexión con la tablet (%s)." % self.destino)
                    self.desconectar(self.ultimo_ok)
                elif self.fallos == 2 and not self.avisado:
                    log("Sin conexión con la tablet (%s). Conéctala con cable y corre «adb tcpip 5555»; sigo intentando." % self.destino)
                    self.avisado = True
            else:
                if not self.vivo:
                    log("Conectado a la tablet (%s)" % self.destino)
                self.avisado = False
                self.fallos = 0
                self.vivo = True
                self.ultimo_ok = time.time()
                self.poner(conectada=1, ultimo_visto=self.ultimo_ok)
                self.cambiar(paquete or None)
            await asyncio.sleep(self.intervalo)


# ---------- programa principal ----------

def main():
    cfg = leer_config()
    ap = argparse.ArgumentParser(description="Registra el tiempo por app de la TV y la tablet y lo muestra en una web local.")
    ap.add_argument("--ip", help="IP de la TV (por defecto la de config.json)")
    ap.add_argument("--puerto-web", type=int, default=int(cfg["puerto_web"]))
    ap.add_argument("--solo-local", action="store_true", help="La web solo se abre desde este computador")
    ap.add_argument("--puerto-tv", type=int, default=3001)
    ap.add_argument("--sin-ssl", action="store_true", help="Conectar a la TV con ws:// (solo para pruebas)")
    args = ap.parse_args()

    con = abrir_db()
    colectores = []
    ip_tv_cambiada = False
    for d in cfg["dispositivos"]:
        if not d.get("activo", True):
            continue
        if d["tipo"] == "lg":
            if args.ip and not ip_tv_cambiada:
                d["ip"], ip_tv_cambiada = args.ip, True
            colectores.append(ColectorLG(d, con, args.puerto_tv, not args.sin_ssl))
        elif d["tipo"] == "fire":
            colectores.append(ColectorFire(d, con))
        else:
            log("Dispositivo «%s» con tipo desconocido (%s): lo ignoro." % (d["id"], d["tipo"]))
    for c in colectores:
        recuperar(con, c.id)

    host = "127.0.0.1" if args.solo_local else "0.0.0.0"
    iniciar_web(cfg, host, args.puerto_web)
    log("Web lista: http://localhost:%s" % args.puerto_web)
    if not args.solo_local:
        log("Desde otros equipos de la casa: http://%s:%s" % (ip_local(), args.puerto_web))

    async def todo():
        await asyncio.gather(*[c.correr() for c in colectores])

    signal.signal(signal.SIGTERM, lambda *a: sys.exit(0))
    try:
        asyncio.run(todo())
    except KeyboardInterrupt:
        pass
    finally:
        for c in colectores:
            c.desconectar()
        log("Detenido.")


if __name__ == "__main__":
    main()

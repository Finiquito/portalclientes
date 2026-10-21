#!/usr/bin/env python3
"""Pruebas del cálculo por día, la recuperación tras una caída, la migración y la lectura de la tablet.
Uso: python3 tests/test_tv.py"""
import os
import sqlite3
import sys
import tempfile
from datetime import datetime
from pathlib import Path

os.environ["TIEMPO_TV_DATOS"] = tempfile.mkdtemp()
os.environ["TIEMPO_TV_LLAVE"] = os.path.join(os.environ["TIEMPO_TV_DATOS"], "llave")
os.environ["TIEMPO_TV_MIN_SEG"] = "5"
sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
import tiempo_tv as t  # noqa: E402


def ts(*f):
    return datetime(*f).timestamp()


def ok(cond, msg):
    if not cond:
        print("FALLA:", msg)
        sys.exit(1)
    print("ok  ", msg)


# ---------- reparto por día ----------
trozos = list(t.repartir(ts(2026, 10, 20, 23, 30), ts(2026, 10, 21, 0, 45)))
ok(len(trozos) == 2, "cruza medianoche: dos trozos")
ok(trozos[0][0] == "2026-10-20" and round((trozos[0][2] - trozos[0][1]) / 60) == 30, "20 de octubre: 30 min")
ok(trozos[1][0] == "2026-10-21" and round((trozos[1][2] - trozos[1][1]) / 60) == 45, "21 de octubre: 45 min")
trozos = list(t.repartir(ts(2026, 10, 19, 22, 0), ts(2026, 10, 22, 1, 0)))
ok([x[0] for x in trozos] == ["2026-10-19", "2026-10-20", "2026-10-21", "2026-10-22"], "varios días: cuatro trozos")

# ---------- sesiones, recuperación y cálculo ----------
cfg = t.normalizar_config({"titulo": "Prueba", "dispositivos": [
    {"id": "tv", "tipo": "lg", "nombre": "TV", "ip": "1.1.1.1"},
    {"id": "tablet", "tipo": "fire", "nombre": "Tablet", "ip": "1.1.1.2"}]})
con = t.abrir_db()
con.execute("INSERT INTO sesiones(dispositivo, app_id, ini, fin) VALUES('tv', 'youtube.leanback.v4', ?, ?)", (ts(2026, 10, 20, 23, 30), ts(2026, 10, 21, 0, 15)))
con.execute("INSERT INTO sesiones(dispositivo, app_id, ini, fin) VALUES('tv', 'netflix', ?, ?)", (ts(2026, 10, 21, 20, 0), ts(2026, 10, 21, 21, 0)))
con.execute("INSERT INTO sesiones(dispositivo, app_id, ini, fin) VALUES('tablet', 'com.netflix.mediaclient', ?, ?)", (ts(2026, 10, 21, 20, 0), ts(2026, 10, 21, 20, 20)))
con.commit()
api = t.datos_api(con, cfg, ahora=ts(2026, 10, 21, 22, 0))
d, tab = api["datos"]["tv"], api["datos"]["tablet"]
ok([x["id"] for x in api["dispositivos"]] == ["tv", "tablet"], "la API lista los dos dispositivos")
ok(d["dias"]["2026-10-20"]["apps"]["youtube.leanback.v4"] == 30 * 60, "TV: YouTube del 20: 30 min")
ok(d["dias"]["2026-10-21"]["apps"]["youtube.leanback.v4"] == 15 * 60, "TV: YouTube del 21: 15 min")
ok(d["dias"]["2026-10-21"]["apps"]["netflix"] == 3600, "TV: Netflix del 21: 60 min")
ok(d["primer_dia"] == "2026-10-20", "TV: primer día de registro")
seg = [s for s in d["dias"]["2026-10-21"]["segmentos"] if s[0] == "netflix"][0]
ok(seg[1] == 1200 and seg[2] == 1260, "TV: franja de Netflix 20:00 a 21:00")
ok(tab["dias"]["2026-10-21"]["apps"] == {"com.netflix.mediaclient": 1200}, "Tablet: 20 min, sin mezclarse con la TV")
ok(tab["apps"]["com.netflix.mediaclient"]["nombre"] == "Netflix", "Tablet: el paquete se muestra como «Netflix»")
ok(tab["primer_dia"] == "2026-10-21", "Tablet: su propio primer día")

# se cae todo con una sesión abierta: se cierra en el último latido, no en la mañana
con.execute("INSERT INTO sesiones(dispositivo, app_id, ini) VALUES('tv', 'disney', ?)", (ts(2026, 10, 21, 22, 0),))
con.execute("INSERT INTO sesiones(dispositivo, app_id, ini) VALUES('tablet', 'com.google.android.youtube', ?)", (ts(2026, 10, 21, 22, 0),))
con.commit()
t.poner(con, "tv", ultimo_visto=ts(2026, 10, 21, 22, 40), conectada=1, app_actual="disney")
t.poner(con, "tablet", ultimo_visto=ts(2026, 10, 21, 22, 10), conectada=1)
t.recuperar(con, "tv")
t.recuperar(con, "tablet")
fin_tv = con.execute("SELECT fin FROM sesiones WHERE app_id='disney'").fetchone()[0]
fin_tab = con.execute("SELECT fin FROM sesiones WHERE app_id='com.google.android.youtube'").fetchone()[0]
ok(fin_tv == ts(2026, 10, 21, 22, 40), "tras la caída, la TV cierra a las 22:40")
ok(fin_tab == ts(2026, 10, 21, 22, 10), "tras la caída, la tablet cierra a las 22:10 (cada una con su hora)")
ok(t.leer_estado(con, "tv")["conectada"] == "0", "tras recuperar, la TV queda como no conectada")

# parpadeos de menos de 5 s se descartan, y solo del dispositivo indicado
con.execute("INSERT INTO sesiones(dispositivo, app_id, ini) VALUES('tv', 'hdmi2', 1000.0)")
con.execute("INSERT INTO sesiones(dispositivo, app_id, ini) VALUES('tablet', 'x.y', 1000.0)")
con.commit()
t.cerrar_abiertas(con, "tv", 1002.0)
ok(con.execute("SELECT COUNT(*) FROM sesiones WHERE app_id='hdmi2'").fetchone()[0] == 0, "parpadeo de 2 s descartado")
ok(con.execute("SELECT fin FROM sesiones WHERE app_id='x.y'").fetchone()[0] is None, "cerrar la TV no toca la sesión de la tablet")

# sesión abierta cuenta hasta "ahora"
con.execute("INSERT INTO sesiones(dispositivo, app_id, ini) VALUES('tablet', 'com.amazon.kindle', ?)", (ts(2026, 10, 22, 9, 0),))
con.commit()
api = t.datos_api(con, cfg, ahora=ts(2026, 10, 22, 9, 30))
ok(api["datos"]["tablet"]["dias"]["2026-10-22"]["apps"]["com.amazon.kindle"] == 1800, "sesión abierta suma hasta ahora (30 min)")

# ---------- migración desde la primera versión (solo TV) ----------
viejo = tempfile.mkdtemp()
c = sqlite3.connect(os.path.join(viejo, "tv.sqlite"))
c.executescript("""
CREATE TABLE sesiones(id INTEGER PRIMARY KEY, app_id TEXT NOT NULL, ini REAL NOT NULL, fin REAL);
CREATE TABLE apps(id TEXT PRIMARY KEY, nombre TEXT, icono TEXT);
CREATE TABLE estado(clave TEXT PRIMARY KEY, valor TEXT);
INSERT INTO sesiones(app_id, ini, fin) VALUES('netflix', %s, %s);
INSERT INTO apps VALUES('netflix', 'Netflix', 'netflix.png');
INSERT INTO estado VALUES('conectada', '1'), ('ultimo_visto', '123.0');
""" % (ts(2026, 10, 1, 20, 0), ts(2026, 10, 1, 21, 30)))
c.commit()
c.close()
t.DATOS = Path(viejo)
con2 = t.abrir_db()
ok(con2.execute("SELECT dispositivo, app_id FROM sesiones").fetchall() == [("tv", "netflix")], "migración: las sesiones viejas pasan a la TV")
ok(con2.execute("SELECT dispositivo, id, nombre, icono FROM apps").fetchall() == [("tv", "netflix", "Netflix", "netflix.png")], "migración: las apps viejas pasan a la TV")
ok(t.leer_estado(con2, "tv") == {"conectada": "1", "ultimo_visto": "123.0"}, "migración: el estado pasa a la TV")
con2.close()
con3 = t.abrir_db()   # abrir otra vez no debe duplicar ni romper nada
ok(con3.execute("SELECT COUNT(*) FROM sesiones").fetchone()[0] == 1, "migración: abrir de nuevo no duplica")
api = t.datos_api(con3, t.normalizar_config({}), ahora=ts(2026, 10, 2, 0, 0))
ok(api["datos"]["tv"]["dias"]["2026-10-01"]["apps"]["netflix"] == 90 * 60, "migración: los minutos de la TV se conservan")

# ---------- lectura de la tablet ----------
pantalla = "mWakefulness=Awake\n  mCurrentFocus=Window{9f1c2d3 u0 com.netflix.mediaclient/com.netflix.mediaclient.ui.launch.UIWebViewActivity}\n"
ok(t.leer_pantalla(pantalla) == "com.netflix.mediaclient", "tablet: lee el paquete abierto")
ok(t.leer_pantalla("mWakefulness=Asleep\n  mCurrentFocus=Window{1 u0 com.netflix.mediaclient/.A}") == "", "tablet: pantalla apagada no cuenta")
ok(t.leer_pantalla("mWakefulness=Dozing\n") == "", "tablet: en reposo no cuenta")
ok(t.leer_pantalla("mWakefulness=Awake\n  mCurrentFocus=Window{1 u0 StatusBar}") == "", "tablet: ventana del sistema sin app no cuenta")
ok(t.leer_pantalla("mWakefulness=Awake\n  mCurrentFocus=null\n") == "", "tablet: sin foco no cuenta")
ok(t.leer_pantalla("error: device '192.168.1.14:5555' not found") is None, "tablet: error de adb = no se pudo leer")
ok(t.nombre_bonito("com.empresa.mi_juego") == "Mi juego", "tablet: nombre legible para un paquete desconocido")

print("\nTodo bien.")

#!/usr/bin/env python3
"""Pruebas del cálculo por día y de la recuperación tras una caída. Uso: python3 tests/test_tv.py"""
import os
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


# un intervalo que cruza la medianoche se reparte en dos días
trozos = list(t.repartir(ts(2026, 10, 20, 23, 30), ts(2026, 10, 21, 0, 45)))
ok(len(trozos) == 2, "cruza medianoche: dos trozos")
ok(trozos[0][0] == "2026-10-20" and round((trozos[0][2] - trozos[0][1]) / 60) == 30, "20 de octubre: 30 min")
ok(trozos[1][0] == "2026-10-21" and round((trozos[1][2] - trozos[1][1]) / 60) == 45, "21 de octubre: 45 min")

# un intervalo de varios días
trozos = list(t.repartir(ts(2026, 10, 19, 22, 0), ts(2026, 10, 22, 1, 0)))
ok([x[0] for x in trozos] == ["2026-10-19", "2026-10-20", "2026-10-21", "2026-10-22"], "varios días: cuatro trozos")

# sesiones normales, descarte de parpadeos y recuperación tras caída
con = t.abrir_db()
cfg = {"titulo": "Prueba"}
con.execute("INSERT INTO sesiones(app_id, ini, fin) VALUES('youtube.leanback.v4', ?, ?)", (ts(2026, 10, 20, 23, 30), ts(2026, 10, 21, 0, 15)))
con.execute("INSERT INTO sesiones(app_id, ini, fin) VALUES('netflix', ?, ?)", (ts(2026, 10, 21, 20, 0), ts(2026, 10, 21, 21, 0)))
con.commit()
d = t.datos_api(con, cfg, ahora=ts(2026, 10, 21, 22, 0))
ok(d["dias"]["2026-10-20"]["apps"]["youtube.leanback.v4"] == 30 * 60, "YouTube del 20: 30 min")
ok(d["dias"]["2026-10-21"]["apps"]["youtube.leanback.v4"] == 15 * 60, "YouTube del 21: 15 min")
ok(d["dias"]["2026-10-21"]["apps"]["netflix"] == 3600, "Netflix del 21: 60 min")
ok(d["primer_dia"] == "2026-10-20", "primer día de registro")
seg = [s for s in d["dias"]["2026-10-21"]["segmentos"] if s[0] == "netflix"][0]
ok(seg[1] == 1200 and seg[2] == 1260, "franja de Netflix: 20:00 a 21:00")

# se cae todo con una sesión abierta: se cierra en el último latido, no en la mañana
con.execute("INSERT INTO sesiones(app_id, ini) VALUES('disney', ?)", (ts(2026, 10, 21, 22, 0),))
con.commit()
t.poner(con, ultimo_visto=ts(2026, 10, 21, 22, 40), conectada=1, app_actual="disney")
t.recuperar(con)
fin = con.execute("SELECT fin FROM sesiones WHERE app_id='disney'").fetchone()[0]
ok(fin == ts(2026, 10, 21, 22, 40), "tras la caída, Disney+ termina a las 22:40")
est = t.leer_estado(con)
ok(est["conectada"] == "0", "tras recuperar, la TV queda como no conectada")

# una sesión de un parpadeo (menos de 5 s) se descarta
con.execute("INSERT INTO sesiones(app_id, ini) VALUES('hdmi2', ?)", (1000.0,))
con.commit()
t.cerrar_abiertas(con, 1002.0)
ok(con.execute("SELECT COUNT(*) FROM sesiones WHERE app_id='hdmi2'").fetchone()[0] == 0, "parpadeo de 2 s descartado")

# sesión abierta cuenta hasta "ahora"
con.execute("INSERT INTO sesiones(app_id, ini) VALUES('amazon', ?)", (ts(2026, 10, 22, 9, 0),))
con.commit()
d = t.datos_api(con, cfg, ahora=ts(2026, 10, 22, 9, 30))
ok(d["dias"]["2026-10-22"]["apps"]["amazon"] == 1800, "sesión abierta suma hasta ahora (30 min)")

print("\nTodo bien.")

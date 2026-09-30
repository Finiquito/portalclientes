#!/usr/bin/env python3
"""
Genera las ilustraciones isométricas del landing (el prisma «desarmado» en piezas).

    python3 theme/prisma/tools/despiece.py

Escribe templates/partials/despiece-hero.latte y despiece-diagrama.latte: SVG en línea
(así cada capa se puede animar con CSS). Cada pieza es un <g class="capa"> con su
desplazamiento de «despiece» en --dy; el CSS decide cuándo se separan.

Proyección isométrica: X = (x - y)·cos30, Y = (x + y)·sin30 - z. Se dibujan sólo las
caras que miran al observador (normal · (1,1,1) > 0), de atrás hacia adelante.
"""
from __future__ import annotations

import math
import os

C30, S30 = math.cos(math.radians(30)), math.sin(math.radians(30))

# Colores de las piezas: tomados de la ilustración de referencia.
# (cara superior, cara +y [izquierda], cara +x [derecha])
PALETA = {
    "rosado":   ("#ffc3d8", "#f7a3c2", "#ec86ae"),
    "cobalto":  ("#3f6fd8", "#2448b0", "#17338a"),
    "amarillo": ("#ffd166", "#ffb42e", "#f08d0f"),
    "rojo":     ("#ff6b5b", "#ff3b24", "#d8240f"),
    "violeta":  ("#8a78c9", "#5f4aa3", "#46337f"),
    "marino":   ("#2b3a78", "#18214d", "#10173a"),
}


def proy(p):
    x, y, z = p
    return ((x - y) * C30, (x + y) * S30 - z)


def fmt(v):
    return f"{v:.1f}".rstrip("0").rstrip(".")


def poligono(puntos, relleno, extra=""):
    pts = " ".join(f"{fmt(x)},{fmt(y)}" for x, y in (proy(p) for p in puntos))
    # trazo del mismo color con unión redonda: suaviza aristas como en la referencia
    return f'<polygon points="{pts}" fill="{relleno}" stroke="{relleno}" stroke-width="1.2" stroke-linejoin="round"{extra}/>'


def caja(x, y, z, w, d, h, color):
    t, iz, de = PALETA[color]
    x1, y1, z1 = x + w, y + d, z + h
    caras = [
        # +y (frente izquierdo)
        [(x, y1, z), (x1, y1, z), (x1, y1, z1), (x, y1, z1)],
        # +x (frente derecho)
        [(x1, y, z), (x1, y1, z), (x1, y1, z1), (x1, y, z1)],
        # arriba
        [(x, y, z1), (x1, y, z1), (x1, y1, z1), (x, y1, z1)],
    ]
    rel = [f"url(#g-{color}-iz)", f"url(#g-{color}-de)", f"url(#g-{color}-t)"]
    return "".join(poligono(c, r) for c, r in zip(caras, rel))


def cilindro(cx, cy, z, r, h, color):
    t, iz, de = PALETA[color]
    ox, oy = proy((cx, cy, z))
    _, oyt = proy((cx, cy, z + h))
    rx, ry = r * math.sqrt(2) * C30, r * math.sqrt(2) * S30
    lado = (f'<path d="M{fmt(ox - rx)},{fmt(oyt)} L{fmt(ox - rx)},{fmt(oy)} '
            f'A{fmt(rx)},{fmt(ry)} 0 0 0 {fmt(ox + rx)},{fmt(oy)} L{fmt(ox + rx)},{fmt(oyt)} Z" '
            f'fill="url(#g-{color}-cil)"/>')
    tapa = f'<ellipse cx="{fmt(ox)}" cy="{fmt(oyt)}" rx="{fmt(rx)}" ry="{fmt(ry)}" fill="url(#g-{color}-t)"/>'
    return lado + tapa


def prisma(x, y, z, w, d, h, color):
    """Prisma triangular: triángulo en el plano xz, extruido en y (la marca del logo)."""
    x1, y1 = x + w, y + d
    ax = x + w * 0.62  # vértice corrido, como el triángulo del logo
    frente = [(x, y1, z), (x1, y1, z), (ax, y1, z + h)]
    derecha = [(x1, y, z), (x1, y1, z), (ax, y1, z + h), (ax, y, z + h)]
    izquierda = [(x, y, z), (x, y1, z), (ax, y1, z + h), (ax, y, z + h)]
    out = []
    # la cara izquierda sólo se ve si su normal mira al observador
    nx, nz = -h, (ax - x)
    if nx + nz > 0:
        out.append(poligono(izquierda, f"url(#g-{color}-t)"))
    out.append(poligono(derecha, f"url(#g-{color}-de)"))
    out.append(poligono(frente, f"url(#g-{color}-iz)"))
    return "".join(out)


def defs(id_grano):
    g = []
    for nombre, (t, iz, de) in PALETA.items():
        g.append(f'<linearGradient id="g-{nombre}-t" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="{t}"/><stop offset="1" stop-color="{iz}"/></linearGradient>')
        g.append(f'<linearGradient id="g-{nombre}-iz" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="{iz}"/><stop offset="1" stop-color="{de}"/></linearGradient>')
        g.append(f'<linearGradient id="g-{nombre}-de" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="{de}"/><stop offset="1" stop-color="{iz}"/></linearGradient>')
        g.append(f'<linearGradient id="g-{nombre}-cil" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="{iz}"/><stop offset=".55" stop-color="{t}"/><stop offset="1" stop-color="{de}"/></linearGradient>')
    # Grano: ruido fractal mezclado en «overlay» sobre las piezas (claros y oscuros, como serigrafía).
    g.append(
        f'<filter id="{id_grano}" x="-5%" y="-5%" width="110%" height="110%" color-interpolation-filters="sRGB">'
        '<feTurbulence type="fractalNoise" baseFrequency=".9" numOctaves="2" stitchTiles="stitch" result="ruido"/>'
        '<feColorMatrix in="ruido" type="saturate" values="0" result="gris"/>'
        '<feComponentTransfer in="gris" result="gris2"><feFuncA type="table" tableValues=".55 .55"/></feComponentTransfer>'
        '<feComposite in="gris2" in2="SourceGraphic" operator="in" result="grano"/>'
        '<feBlend in="grano" in2="SourceGraphic" mode="overlay"/>'
        "</filter>"
    )
    return "<defs>" + "".join(g) + "</defs>"


# Piezas, de abajo hacia arriba. (clave, forma, args, desplazamiento del despiece, texto del diagrama)
PIEZAS = [
    ("portal",    "caja",     (-130, -100, 0, 260, 200, 26), "rosado",   0,
     "El portal de cada cliente", "Con su logo y su color. Entra con su correo, sin contraseña."),
    ("tareas",    "caja",     (-118, -84, 26, 168, 128, 38), "cobalto",  -64,
     "Tareas con turno", "Qué te toca a ti y qué le toca al cliente, cada cosa con su fecha."),
    ("reuniones", "cilindro", (34, 18, 64, 60, 30), "amarillo", -206,
     "De la reunión a la lista", "Pegas las notas de la reunión; la IA propone el resumen y las tareas. Tú decides cuáles van."),
    ("revisiones", "caja",    (-66, -70, 94, 118, 80, 30), "rojo",     -268,
     "Revisiones de contenido", "Posts, reels, logos o PDFs: el cliente aprueba o pide cambios sobre la pieza misma."),
    ("archivos",  "caja",     (4, -16, 124, 58, 46, 22), "violeta",  -324,
     "Archivos en su lugar", "Lo que el cliente sube queda en su tarea, no perdido en un chat."),
    ("prisma",    "prisma",   (-52, -30, 146, 76, 36, 70), "marino",   -398,
     "Prisma", ""),
]


def svg(variante):
    id_grano = f"grano-{variante}"
    capas = []
    for i, (clave, forma, args, color, dy, titulo, _txt) in enumerate(PIEZAS):
        fn = {"caja": caja, "cilindro": cilindro, "prisma": prisma}[forma]
        cuerpo = fn(*args, color)
        marca = ""
        if variante == "diagrama" and clave != "prisma":
            # marcador numerado a la derecha de la pieza + línea guía
            x, y, z = args[0], args[1], args[2]
            if forma == "cilindro":
                ax, ay = proy((args[0] + args[3], args[1], args[2] + args[4] / 2))
            else:
                ax, ay = proy((x + args[3], y + args[4] / 2, z + args[5] / 2))
            mx = 190
            marca = (f'<line x1="{fmt(ax + 6)}" y1="{fmt(ay)}" x2="{mx - 14}" y2="{fmt(ay)}" class="guia"/>'
                     f'<g class="marca" transform="translate({mx},{fmt(ay)})"><rect x="-12" y="-12" width="24" height="24"/>'
                     f'<text y="5" text-anchor="middle">{i + 1}</text></g>')
        capas.append(
            f'<g class="capa capa-{clave}" style="--dy:{dy}px;--i:{i}">'
            f'<g filter="url(#{id_grano})">{cuerpo}</g>{marca}</g>'
        )
    vb = "-240 -690 470 870" if variante == "hero" else "-240 -690 470 870"
    titulo = "El prisma de Prisma, desarmado en sus piezas: portal, tareas, reuniones, revisiones y archivos"
    return (
        f'<svg class="despiece despiece-{variante}" viewBox="{vb}" role="img" aria-label="{titulo}" '
        f'xmlns="http://www.w3.org/2000/svg">{defs(id_grano)}'
        f'<g class="sombra"><ellipse cx="0" cy="120" rx="190" ry="48"/></g>'
        + "".join(capas) + "</svg>"
    )


def main():
    raiz = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    dest = os.path.join(raiz, "partials")
    os.makedirs(dest, exist_ok=True)
    cab = "{* Generado por tools/despiece.py: no editar a mano. *}\n"
    for v in ("hero", "diagrama"):
        with open(os.path.join(dest, f"despiece-{v}.latte"), "w", encoding="utf-8") as f:
            f.write(cab + "{syntax off}" + svg(v) + "{/syntax}\n")
    # Leyenda del diagrama (HTML), para que se lea bien en móvil.
    with open(os.path.join(dest, "despiece-leyenda.latte"), "w", encoding="utf-8") as f:
        f.write(cab + '<ol class="leyenda">\n')
        for i, (clave, *_r, titulo, txt) in enumerate(PIEZAS):
            if clave == "prisma":
                continue
            f.write(f'    <li data-pieza="{clave}"><span class="leyenda-n">{i + 1}</span><strong>{titulo}</strong><span>{txt}</span></li>\n')
        f.write("</ol>\n")
    print("ok:", dest)


if __name__ == "__main__":
    main()

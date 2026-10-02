#!/usr/bin/env python3
"""
Genera la ilustración del hero del landing: cubos vistos desde abajo que llenan la lámina.

    python3 theme/prisma/tools/bloques.py

Escribe partials/hero-bloques.latte: SVG en línea con su propio CSS y JS (ver CSS y JS abajo). La animación: los cubos suben en tonos claros,
de adelante hacia atrás; nacen las ventanas y el sol sube trayendo todas las sombras.

Proyección en pantalla (no en 3D): cada cubo se define por su arista frontal
(cx, cy = pie de la arista; h = alto) y el ancho de cada cara. Las aristas
horizontales bajan desde la arista frontal con pendiente 1:2 (vista desde abajo).
"""
from __future__ import annotations

import os

P = 0.5  # pendiente de las aristas horizontales

PALETAS = {
    # (cara al sol, cara a la sombra) por tipo de cubo; ventana = interior oscuro
    "azul": {
        "cielo": ("#5fb0e4", "#a9d6f2"),
        "sol": "#ec5a2c",
        "ventana": "#14295e",
        "tipos": {
            "a": ("#f6f0e4", "#2f6bd1"),
            "b": ("#9ccbf1", "#2f6bd1"),
            "c": ("#f6f0e4", "#1f4fa8"),
            "d": ("#f47a42", "#cf4a20"),
            "e": ("#e7b48a", "#bb7a4f"),
            "f": ("#6fa8e8", "#1f4fa8"),
        },
    },
}

# (cx, cy, ancho izq, ancho der, alto, tipo, ventanas)
# ventana: (cara 'i'|'d', desde la arista [0..1], desde el pie [0..1], ancho [0..1], alto [0..1])
CUBOS = [
    # atrás (lo más alto)
    (150, 330, 72, 64, 250, "a", [("i", .30, .28, .30, .48)]),
    (300, 300, 66, 80, 200, "b", [("i", .32, .18, .32, .50), ("d", .50, .22, .24, .42)]),
    (430, 300, 60, 60, 150, "c", [("i", .30, .08, .34, .50)]),
    (-10, 360, 60, 70, 180, "f", [("d", .40, .20, .28, .45)]),
    # segunda fila
    (70, 440, 84, 66, 215, "d", [("i", .26, .26, .30, .50)]),
    (230, 450, 92, 84, 210, "a", [("i", .34, .22, .30, .52), ("d", .30, .30, .26, .40)]),
    (390, 440, 64, 70, 170, "e", [("i", .38, .22, .32, .50)]),
    # tercera fila
    (140, 560, 92, 76, 190, "c", [("i", .30, .22, .30, .50)]),
    (330, 570, 92, 92, 200, "b", [("i", .34, .26, .28, .50), ("d", .45, .22, .24, .52)]),
    (-20, 560, 60, 80, 160, "a", [("d", .34, .26, .30, .48)]),
    # adelante (se corta abajo)
    (40, 690, 70, 92, 190, "e", [("i", .30, .28, .32, .48)]),
    (250, 700, 96, 84, 240, "a", [("i", .32, .30, .32, .45), ("d", .40, .36, .26, .38)]),
    (430, 690, 70, 70, 160, "d", [("i", .34, .28, .34, .48)]),
]

W, H = 400, 560
SOBRA = 320  # cada cubo sigue hacia abajo, escondido tras los de adelante: nunca flota


def mezcla(hexa, blanco):
    r, g, b = (int(hexa[k:k + 2], 16) for k in (1, 3, 5))
    m = lambda v: round(v + (255 - v) * blanco)
    return f"#{m(r):02x}{m(g):02x}{m(b):02x}"


def f(v):
    return f"{v:.1f}".rstrip("0").rstrip(".")


def pp(pts):
    return " ".join(f"{f(x)},{f(y)}" for x, y in pts)


def punto(c, cara, a, z):
    cx, cy = c[0], c[1] + SOBRA
    z = z + SOBRA
    s = -1 if cara == "i" else 1
    return (cx + s * a, cy + a * P - z)


def cara(c, lado):
    cx, cy, wl, wr, h = c[:5]
    w = wl if lado == "i" else wr
    return [punto(c, lado, 0, -SOBRA), punto(c, lado, 0, h), punto(c, lado, w, h), punto(c, lado, w, -SOBRA)]


def ventana(c, v, pal, tipo):
    lado, u, t, aw, ah = v
    cx, cy, wl, wr, h = c[:5]
    w = wl if lado == "i" else wr
    a0, a1 = u * w, (u + aw) * w
    z0, z1 = t * h, (t + ah) * h
    hueco = [punto(c, lado, a0, z0), punto(c, lado, a1, z0), punto(c, lado, a1, z1), punto(c, lado, a0, z1)]
    # derrame (grosor del muro): franja del lado de la arista y dintel
    j = (a1 - a0) * 0.28
    sombra = [punto(c, lado, a0 + j, z0), punto(c, lado, a1, z0), punto(c, lado, a1, z1), punto(c, lado, a0 + j, z1 - j * 0.9)]
    sol_l, som = pal["tipos"][tipo]
    derrame = som if lado == "i" else sol_l
    return (f'<g class="ventana">'
            f'<polygon class="derrame" points="{pp(hueco)}" fill="{derrame}"/>'
            f'<polygon class="hondo" points="{pp(sombra)}" fill="{pal["ventana"]}"/></g>')


def svg(nombre):
    pal = PALETAS[nombre]
    # pintor: de atrás (más arriba en pantalla) hacia adelante
    orden = sorted(range(len(CUBOS)), key=lambda i: CUBOS[i][1])
    # construcción: adelante primero, luego hacia atrás
    llegada = {i: k for k, i in enumerate(sorted(range(len(CUBOS)), key=lambda i: -CUBOS[i][1]))}
    capas = []
    for i in orden:
        c = CUBOS[i]
        lit, som = pal["tipos"][c[5]]
        ci, cd = cara(c, "i"), cara(c, "d")
        vs = "".join(ventana(c, v, pal, c[5]) for v in c[6])
        capas.append(
            f'<g class="cubo" style="--i:{llegada[i]};--h:{c[4]}px"><g class="sube">'
            f'<polygon class="cara-i" points="{pp(ci)}" fill="{mezcla(som, .62)}"/>'
            f'<polygon class="cara-i-sol" points="{pp(ci)}" fill="{lit}"/>'
            f'<polygon class="cara-d" points="{pp(cd)}" fill="{mezcla(som, .48)}"/>'
            f'<polygon class="cara-d-sombra" points="{pp(cd)}" fill="{som}"/>'
            f'<g class="ventanas">{vs}</g></g></g>'
        )
    c1, c2 = pal["cielo"]
    grano = ('<filter id="grano-bloques" x="0" y="0" width="100%" height="100%" color-interpolation-filters="sRGB">'
             '<feTurbulence type="fractalNoise" baseFrequency=".85" numOctaves="2" stitchTiles="stitch" result="r"/>'
             '<feColorMatrix in="r" type="saturate" values="0" result="g"/>'
             '<feComponentTransfer in="g" result="g2"><feFuncA type="table" tableValues=".45 .45"/></feComponentTransfer>'
             '<feComposite in="g2" in2="SourceGraphic" operator="in" result="gg"/>'
             '<feBlend in="gg" in2="SourceGraphic" mode="overlay"/></filter>')
    return (f'<svg class="bloques" viewBox="0 0 {W} {H}" preserveAspectRatio="xMidYMin slice" role="img" '
            f'aria-label="Cubos de colores que se construyen de abajo hacia arriba; sale el sol y aparecen las ventanas" '
            f'xmlns="http://www.w3.org/2000/svg"><defs>{grano}'
            f'<linearGradient id="cielo-bloques" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="{c1}"/><stop offset="1" stop-color="{c2}"/></linearGradient></defs>'
            f'<g filter="url(#grano-bloques)"><rect class="fondo" width="{W}" height="{H}" fill="url(#cielo-bloques)"/>'
            f'<circle class="sol" cx="78" cy="92" r="36" fill="{pal["sol"]}"/>'
            + "".join(capas) + "</g></svg>")


# El CSS y el JS van dentro del partial: la ilustración no depende de que el
# hosting haya publicado (o refrescado) assets/css y assets/js del tema.
# Sin JS o con «reducir movimiento» se ve la imagen final.
CSS = '.lamina-bloques{padding: 0;} .lamina-bloques .bloques{position: absolute; inset: 0; z-index: 2; display: block; width: 100%; height: 100%;} @media (prefers-reduced-motion: no-preference){.js .bloques{--espera: 1200ms;} .js .bloques .sube, .js .bloques .ventanas, .js .bloques .cara-i-sol, .js .bloques .cara-d-sombra{opacity: 0;} .js .bloques .sol{opacity: 0; transform: translateY(60px);} .bloques .ventana .derrame, .bloques .ventana .hondo{transform-box: fill-box; transform-origin: 50% 0;} .js .bloques.armar .sube{animation: bloque-sube 750ms cubic-bezier(.2, .8, .3, 1) both; animation-delay: calc(var(--espera) + var(--i) * 140ms);} .js .bloques.armar .ventanas{animation: bloque-aparece 10ms linear both; animation-delay: calc(var(--espera) + 1900ms);} .js .bloques.armar .ventana .derrame{animation: bloque-aparece 400ms ease both; animation-delay: calc(var(--espera) + 1900ms + (12 - var(--i)) * 45ms);} .js .bloques.armar .ventana .hondo{animation: bloque-hondo 600ms cubic-bezier(.3, .7, .3, 1) both; animation-delay: calc(var(--espera) + 2000ms + (12 - var(--i)) * 45ms);} .js .bloques.armar .sol{animation: bloque-sol 1500ms cubic-bezier(.33, 1, .5, 1) both; animation-delay: calc(var(--espera) + 2300ms);} .js .bloques.armar .cara-i-sol, .js .bloques.armar .cara-d-sombra{animation: bloque-aparece 1500ms cubic-bezier(.33, 1, .5, 1) both; animation-delay: calc(var(--espera) + 2300ms);}}@keyframes bloque-sube{0%{opacity: 0; transform: translateY(calc(var(--h) * .9));} 20%{opacity: 1;} 100%{opacity: 1; transform: none;}}@keyframes bloque-aparece{from{opacity: 0;} to{opacity: 1;}}@keyframes bloque-hondo{from{transform: scaleY(0);} to{transform: none;}}@keyframes bloque-sol{to{opacity: 1; transform: none;}}@media (max-width: 60rem){.lamina-bloques{min-height: 0; aspect-ratio: 4 / 5;} }'

JS = (
    '(function(){var s=document.currentScript,b=s&&s.previousElementSibling;'
    'if(!b||!b.classList.contains("bloques"))return;'
    'if(window.matchMedia&&matchMedia("(prefers-reduced-motion: reduce)").matches)return;'
    'if(!("IntersectionObserver" in window)){b.classList.add("armar");return;}'
    'var io=new IntersectionObserver(function(e){if(e[0].isIntersecting){b.classList.add("armar");io.disconnect();}},{threshold:.2});'
    'io.observe(b);})();'
)


def main():
    raiz = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    dest = os.path.join(raiz, "partials", "hero-bloques.latte")
    with open(dest, "w", encoding="utf-8") as fh:
        fh.write("{* Generado por tools/bloques.py: no editar a mano. *}\n")
        fh.write("{syntax off}<style>" + CSS + "</style>" + svg("azul") + "<script>" + JS + "</script>{/syntax}\n")
    print("ok:", dest)


if __name__ == "__main__":
    main()

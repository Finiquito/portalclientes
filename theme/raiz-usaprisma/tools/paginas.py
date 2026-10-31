#!/usr/bin/env python3
"""Arma las páginas públicas (condiciones y privacidad) en los cuatro idiomas desde fuentes/.

    python3 theme/raiz-usaprisma/tools/paginas.py

Lee fuentes/plantilla.html y fuentes/<pagina>.<idioma>.html (solo el contenido de <main>) y escribe
una carpeta por idioma y página. Los textos traducidos se editan en fuentes/; no se tocan los index.html.
Dirección de cada página (las mismas que usa el plugin en OnboardingService y Compartido):

  condiciones: /condiciones/   /en/terms/      /fr/conditions/       /it/condizioni/
  privacidad:  /privacidad/    /en/privacy/    /fr/confidentialite/  /it/privacy/
"""
import os, sys

RAIZ = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
IDIOMAS = [('es', 'Español'), ('en', 'English'), ('fr', 'Français'), ('it', 'Italiano')]
RUTAS = {
    'condiciones': {'es': 'condiciones', 'en': 'en/terms', 'fr': 'fr/conditions', 'it': 'it/condizioni'},
    'privacidad':  {'es': 'privacidad',  'en': 'en/privacy', 'fr': 'fr/confidentialite', 'it': 'it/privacy'},
}
TITULOS = {
    'condiciones': {'es': 'Condiciones de uso · Prisma', 'en': 'Terms of use · Prisma', 'fr': 'Conditions d’utilisation · Prisma', 'it': 'Condizioni d’uso · Prisma'},
    'privacidad':  {'es': 'Privacidad del portal · Prisma', 'en': 'Portal privacy · Prisma', 'fr': 'Confidentialité du portail · Prisma', 'it': 'Privacy del portale · Prisma'},
}
PIES = {
    'condiciones': {'es': 'Prisma · Condiciones de uso v1.0 · 1 de noviembre de 2026', 'en': 'Prisma · Terms of use v1.0 · November 1, 2026',
                    'fr': 'Prisma · Conditions d’utilisation v1.0 · 1er novembre 2026', 'it': 'Prisma · Condizioni d’uso v1.0 · 1 novembre 2026'},
    'privacidad':  {'es': 'Prisma · Privacidad del portal v1.0 · 1 de noviembre de 2026', 'en': 'Prisma · Portal privacy v1.0 · November 1, 2026',
                    'fr': 'Prisma · Confidentialité du portail v1.0 · 1er novembre 2026', 'it': 'Prisma · Privacy del portale v1.0 · 1 novembre 2026'},
}
COMENTARIO = '<!-- v1.0 — pendiente revisión de abogado. Los textos están en theme/raiz-usaprisma/fuentes/ y esta página se genera con tools/paginas.py: no editar a mano. Si cambia el texto, sube la versión (y OnboardingService::CONDICIONES_VERSION). -->'
BASE = 'https://usaprisma.com'


def url(pagina, idioma):
    return '/' + RUTAS[pagina][idioma] + '/'


def main():
    plantilla = open(os.path.join(RAIZ, 'fuentes', 'plantilla.html'), encoding='utf-8').read()
    n = 0
    for pagina in RUTAS:
        for idioma, _ in IDIOMAS:
            f = os.path.join(RAIZ, 'fuentes', f'{pagina}.{idioma}.html')
            if not os.path.isfile(f):
                print(f'  falta {os.path.relpath(f, RAIZ)}', file=sys.stderr)
                continue
            cuerpo = open(f, encoding='utf-8').read()
            cuerpo = cuerpo.replace('{{PRIVACIDAD}}', url('privacidad', idioma)).replace('{{CONDICIONES}}', url('condiciones', idioma))
            selector = ''.join(
                f'<a href="{url(pagina, c)}" lang="{c}" hreflang="{c}"' + (' aria-current="true"' if c == idioma else '') + f'>{nombre}</a>'
                for c, nombre in IDIOMAS)
            hreflang = '\n'.join(f'<link rel="alternate" hreflang="{c}" href="{BASE}{url(pagina, c)}">' for c, _ in IDIOMAS)
            html = (plantilla.replace('{{LANG}}', idioma).replace('{{TITULO}}', TITULOS[pagina][idioma]).replace('{{COMENTARIO}}', COMENTARIO)
                    .replace('{{HREFLANG}}', hreflang).replace('{{SELECTOR}}', selector).replace('{{CUERPO}}', cuerpo).replace('{{PIE}}', PIES[pagina][idioma]))
            dest = os.path.join(RAIZ, *RUTAS[pagina][idioma].split('/'))
            os.makedirs(dest, exist_ok=True)
            open(os.path.join(dest, 'index.html'), 'w', encoding='utf-8').write(html)
            n += 1
    print(f'{n} páginas')


if __name__ == '__main__':
    main()

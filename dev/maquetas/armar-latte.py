"""Arma portal/templates/equipo/bienvenida.latte desde la maqueta aprobada (CSS y marcado) y el JS real."""
import re, os, json
d=os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))+'/'
src=open(d+'dev/maquetas/onboarding-agencia.src.html',encoding='utf-8').read()
css=re.search(r'<style>(.*?)</style>',src,re.S).group(1).replace('{{FONT}}','X')
css=re.sub(r'@font-face \{[^}]*\}','@font-face { font-family: "Schibsted Grotesk"; font-style: normal; font-weight: 400 900; font-display: swap;\n  src: url("/plugins/portal/assets/fonts/schibsted-grotesk.woff2") format("woff2"); }',css,count=1,flags=re.S)
body=re.search(r'<body>(.*?)<script>',src,re.S).group(1)
logo=open(d+'theme/prisma/partials/logo.latte',encoding='utf-8').read()
svg=re.sub(r'width="108" height="28" style="[^"]*"','',re.search(r'<svg.*?</svg>',logo,re.S).group(0),count=1)
body=body.replace('{{LOGO}}',svg)
body=re.sub(r'<div class="maqueta">.*?</div>\n?','',body,flags=re.S)
body=body.replace('<a class="logo" href="#" aria-label="Prisma" onclick="return false">','<span class="logo" role="img" aria-label="Prisma">').replace('</svg></a>\n    <ol class="pasos"','</svg></span>\n    <ol class="pasos"')
# copy de las pantallas
body=body.replace('<p class="kicker">Paso 4 de 5 · opcional</p>\n      <h1 id="h4">¿Con quién trabajas?</h1>\n      <p class="sub">Invita a tu equipo para que vea sus proyectos. Puedes hacerlo cuando quieras.</p>',
 '<p class="kicker">Paso 4 de 5 · opcional</p>\n      <h1 id="h4">¿Quieres agregar a tu equipo?</h1>\n      <p class="sub">Puedes sumar más personas para que te ayuden. Cada una recibe un correo para entrar y ve los proyectos que le asignes.</p>')
body=body.replace('<span>PNG o SVG, fondo claro</span>','<span>PNG, JPG o WEBP · hasta 2 MB</span>')
body=body.replace('<div class="interruptor"><div><b id="invTit">Invitar a Josefa al portal ahora</b><small>Le llega un correo con su acceso. Si no, puedes hacerlo después desde su ficha.</small></div>',
 '<div class="interruptor"><div><b id="invTit">Invitar al contacto al portal</b><small id="invTxt">Le enviamos la invitación en <span id="horas">6</span> horas, para que alcances a cargar tareas y revisar el proyecto. Puedes enviarla antes, o cancelarla, desde la ficha del cliente.</small><small id="invNo" hidden>No le enviamos nada. Puedes invitarlo cuando quieras desde la ficha del cliente.</small></div>')
body=body.replace('<div class="etiquetas"><span class="etiqueta">Correo de Prisma activo</span><span class="etiqueta" id="etIA">IA de prueba por 30 días</span></div>',
 '<div class="etiquetas" id="etiquetas"><span class="etiqueta" id="etCorreo">Correo de Prisma activo</span><span class="etiqueta" id="etIA">IA de prueba incluida · 30 días desde el primer uso</span></div>')
body=body.replace('<p class="kicker">Bienvenida a Prisma</p>','<p class="kicker">Bienvenida a Prisma</p>')
assert '{' not in body.replace('{{','') , 'el marcado no debe llevar llaves (Latte)'
js=open(d+'dev/maquetas/js-onboarding.js',encoding='utf-8').read()
# Los textos del JS van con __t('…'): el servidor los traduce en un diccionario (el script lleva n:syntax="off", Latte no lo procesa).
claves=[]
for k in re.findall(r"__t\('((?:[^'\\]|\\.)*)'\)",js):
    if k not in claves: claves.append(k)
dic=',\n'.join('  '+json.dumps(k.replace("\\'","'"),ensure_ascii=False)+": {t('"+k+"')}" for k in claves)
dic=f'''<script>
window.PA_I18N = Object.assign(window.PA_I18N || {{}}, {{
{dic}
}});
window.__t = window.__t || function (s) {{ var d = window.PA_I18N || {{}}; return d[s] !== undefined ? d[s] : s; }};
</script>'''
out=f'''{{* Asistente de arranque de la agencia (beta). Generado por dev/maquetas/armar-latte.py desde la maqueta aprobada: no editar a mano. *}}
<!DOCTYPE html>
<html lang="{{\\TypeDock\\Plugin\\Portal\\Idioma::actual()}}" data-tema="auto">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf" content="{{$csrf}}">
<title>{{$titulo}}</title>
<style n:syntax="off">{css}</style>
</head>
<body>{body}<script type="application/json" id="onb-datos">{{$datosJson|noescape}}</script>
{dic}
<script n:syntax="off">
{js}</script>
</body>
</html>
'''
open(d+'portal/templates/equipo/bienvenida.latte','w',encoding='utf-8').write(out)
print('ok',len(out))

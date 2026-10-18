// Arma las láminas del storyboard desde los cuadros de la composición real (out/sb, ver cuadros.js).
//   node storyboard.js <carpeta-de-salida>
const {chromium} = require('playwright');
const fs = require('fs'), path = require('path');
const salida = path.resolve(process.argv[2] || path.join(__dirname, 'out/storyboard'));
fs.mkdirSync(salida, {recursive: true});
const raiz = path.resolve(__dirname, '../../..');
const fuente = 'file://' + raiz + '/theme/prisma/assets/fonts/schibsted-grotesk-latin-wght-normal.woff2';
const img = t => 'file://' + path.join(__dirname, 'out/sb', `cuadro-${Number.isInteger(t) ? t.toFixed(1) : t}.png`);
const mmss = t => { const m = Math.floor(t / 60), s = (t - m * 60).toFixed(1).replace('.', ','); return `${m}:${s.padStart(4, '0')}`; };

const CSS = `
@font-face{font-family:S;src:url(${fuente}) format("woff2");font-weight:400 900}
*{box-sizing:border-box}body{margin:0;background:#f9fafd;color:#141c40;font-family:S,system-ui,sans-serif;padding:56px 60px 60px}
h1{font-size:44px;line-height:1.05;margin:0;font-weight:800;letter-spacing:-.025em}h1 em{font-style:normal;color:#e8432f}
.sub{font-size:21px;color:#3b4366;margin:12px 0 4px;max-width:1500px;line-height:1.4}.aviso{font-size:17px;color:#5a6286;margin:0 0 34px}
.grid{display:grid;gap:34px 26px}
.k{display:flex;flex-direction:column;gap:10px}.k img{width:100%;border-radius:12px;box-shadow:0 14px 34px rgba(20,28,64,.22);display:block}
.et{display:flex;align-items:center;gap:10px}.et b{background:#141c40;color:#fff;font-size:16px;padding:4px 10px;border-radius:7px;letter-spacing:.02em}.et span{font-size:16px;color:#5a6286;font-variant-numeric:tabular-nums}
.t{font-size:20px;font-weight:700;line-height:1.2}.n{font-size:16px;color:#5a6286;line-height:1.35}.n i{color:#3b4366}
.leyenda{background:#141c40;color:#fff;border-radius:14px;padding:22px 24px;font-size:17px;line-height:1.5}.leyenda h3{margin:0 0 8px;font-size:20px}.leyenda li{margin:3px 0}.leyenda ul{margin:0;padding-left:20px}
.fila{display:grid;grid-template-columns:230px 1fr;gap:26px;align-items:start;padding:26px 0;border-top:2px solid rgba(20,28,64,.14)}.fila h2{font-size:26px;margin:0 0 6px;letter-spacing:-.01em}.fila p{margin:0;font-size:17px;color:#3b4366;line-height:1.4}
.sec{display:grid;gap:16px}.sec .k img{box-shadow:0 8px 20px rgba(20,28,64,.2);border-radius:9px}.sec .et span{font-size:14px}
`;
const pagina = (titulo, sub, aviso, cuerpo) => `<!doctype html><html><head><meta charset="utf-8"><style>${CSS}</style></head><body><h1>${titulo}</h1><p class="sub">${sub}</p><p class="aviso">${aviso}</p>${cuerpo}</body></html>`;
const K = (id, t, narr, titulo, nota, w = '') => `<div class="k"><div class="et"><b>${id}</b><span>${mmss(t)} · ${narr}</span></div><img src="${img(t)}"><div class="t">${titulo}</div><div class="n">${nota}</div></div>`;
const AV = 'Tiempos provisorios: se ajustan a la duración real de cada MP3. Los cuadros salen de la composición real (no son maquetas).';

const A1 = [
  K('K01', 2.2, 'Narración 01', 'La portada, limpia', 'La gráfica original de portada se queda quieta ~2,5 s antes de que llegue el primer post-it. <i>«Hace dos años que trabajo como freelancer…»</i>'),
  K('K02', 4.0, 'Narración 02', 'Llegan los primeros post-its', 'Se pegan encima de la portada. Detrás de ellos asoman las primeras notificaciones de chat y correo (genéricas, sin marcas).'),
  K('K03', 6.4, 'Narración 02', 'Va cubriéndose', 'Aparece el post-it «Tareas de Google: me quedó chico» justo cuando lo dices. Los post-its de papel rayado y con cinta rompen la repetición.'),
  K('K04', 8.9, 'Narración 02', 'Tapa ~70 % de la pantalla', '20 post-its en total. Ya salió «Jira: más tiempo manteniéndolo…». Bajo todo esto, la portada se cambió por la pantalla de Josefa.'),
  K('K05', 9.45, 'Narración 02', 'Todo cae', 'Como si hubieran sacudido la pantalla (la sacudida no se ve): gravedad rápida, con un poco de giro.'),
  K('K06', 9.8, 'Narración 02', 'Chocan al caer', 'Algunas piezas se topan entre sí y se desvían. Abajo ya aparece «Buenas noches, Josefa».'),
  K('K07', 10.4, 'fin de 02', 'Queda despejado', 'La pantalla de Josefa completa, a pantalla completa y sin marco.'),
  K('K08', 12.0, 'Narración 03', 'Primer texto que se descubre', '<i>«Así que usé mi tiempo extra en armar algo simple…»</i> La caja azul se abre y descubre el texto palabra por palabra.'),
];
const A2 = [
  K('K09', 17.53, 'Narración 03', 'Josefa → grilla · zoom out / zoom in', 'La pantalla se achica dentro de la ventana, adentro cambia por la grilla y el marco crece hasta el 100 %. <i>«Mira: mi clienta recibe la grilla…»</i>'),
  K('K10', 20.96, 'Narración 04', 'Grilla → lámina · se sobrepone', 'La pantalla nueva entra encima, sin sacar a la vieja. <i>«Pasa las láminas como en el celular…»</i>'),
  K('K11', 23.77, 'Narración 04', 'Zoom dentro de la ventana', 'La pantalla se mete en el marco y se acerca 10 % a la flecha «siguiente». El marco se ve solo en este momento.'),
  K('K12', 33.28, 'Narración 06', 'Aprobado → siguiente · empuje', 'Una ventana empuja a la otra: la vieja sale a la izquierda y la nueva entra, y después el marco crece. <i>«Antes me mandaba un audio de tres minutos…»</i>'),
  K('K13', 38.9, 'Narración 06', 'Zoom dentro de la ventana', 'Se acerca 12 % al punto marcado en la imagen. <i>«…marca el punto exacto en la imagen.»</i>'),
  K('K14', 50.6, 'Narración 08', 'La story · corte sin movimiento', 'Un cambio directo (fundido de 8 cuadros). Sirve para dar descanso entre transiciones con movimiento. <i>«La story, aprobada.»</i>'),
  K('K15', 55.2, 'Narración 09', 'Resumen · «todo junto»', 'Entró encima de la story (se sobrepone). <i>«Y a mí me llega todo junto, en un solo aviso: qué aprobó, qué cambia y dónde.»</i>'),
  `<div class="leyenda"><h3>Qué transición va dónde</h3><ul><li><b>Zoom out / in:</b> Josefa → grilla</li><li><b>Se sobrepone:</b> grilla → lámina, y → resumen</li><li><b>Empuje:</b> aprobado → siguiente pieza</li><li><b>Corte:</b> la story</li><li><b>Zoom en ventana:</b> flecha «siguiente» y punto marcado</li></ul><p style="margin:10px 0 0;color:#c7cdf0">El resto va a pantalla completa, sin marco. Cámbialo todo lo que quieras.</p></div>`,
];
const A3 = [
  K('K16', 56.67, 'Narración 09', 'La pantalla se mete en la ventana', 'El fondo ya es la foto de la persona mientras la ventana se achica. <i>«…qué aprobó, qué cambia y dónde.»</i>'),
  K('K17', 57.33, 'Narración 09', 'De ventana a tarjeta', 'El contenido se desvanece y la ventana se achica hasta la tarjeta alargada y centrada de tu referencia.'),
  K('K18', 58.93, 'Narración 09', 'El texto sube dentro de la tarjeta', '<i>«Simple, para no gastar mi tiempo en ordenar.»</i> Se descubre dentro de su mismo espacio, palabra por palabra.'),
  K('K19', 60.4, 'fin de 09', 'Zoom leve y fade rápido', 'Todo hace un pequeño zoom y se va en un fade corto.'),
  K('K20', 62.5, 'Narración 10', 'La lámina final', 'La misma lámina de cierre actual, con el botón «Prueba la beta gratis → usaprisma.com». <i>«Puedes inscribirte para la beta gratis…»</i>'),
  `<div class="leyenda"><h3>Para confirmar</h3><ul><li>La foto se ve de fondo completo y la tarjeta queda tapando la parte baja del rostro, como en la referencia.</li><li>¿Es tuya o tiene licencia para publicidad?</li></ul></div>`,
];
const fila = (titulo, desc, tiempos, etiquetas = []) => `<div class="fila"><div><h2>${titulo}</h2><p>${desc}</p></div><div class="sec" style="grid-template-columns:repeat(${tiempos.length},1fr)">${tiempos.map((t, i) => `<div class="k"><div class="et"><span>${etiquetas[i] || mmss(t)}</span></div><img src="${img(t)}"></div>`).join('')}</div></div>`;
const TR = fila('Zoom out / zoom in', 'La pantalla se achica en la ventana, adentro aparece la otra y el marco crece hasta el 100 %. ~1,5 s. Usada: Josefa → grilla.', [17.02, 17.31, 17.53, 17.9, 18.19], ['1 · inicia', '2 · se achica', '3 · cambia adentro', '4 · crece', '5 · 100 %'])
  + fila('Se sobrepone', 'La nueva entra encima, con un leve deslizamiento y sin mover a la vieja. ~0,8 s. Usada: grilla → lámina y → resumen.', [20.52, 20.72, 20.96, 21.16], ['1', '2', '3', '4'])
  + fila('Empuje', 'La pantalla se achica, una ventana empuja a la otra hacia el lado y la nueva crece hasta llenar. ~1,5 s. Usada: aprobado → siguiente.', [32.62, 32.99, 33.28, 33.57, 33.79], ['1 · se achica', '2 · empuja', '3 · entra', '4 · asienta', '5 · 100 %'])
  + fila('Zoom dentro de la ventana', 'Se achica en el marco, se acerca 10–12 % y vuelve. Nada se sale del marco. Usada: flecha «siguiente» y punto marcado.', [22.04, 22.57, 23.77, 38.9, 39.3], ['1 · achica', '2 · acerca', '3 · sostiene', 'punto · acerca', 'punto · sostiene'])
  + fila('Corte', 'Sin movimiento: fundido de 8 cuadros. Usada: la story.', [50.3, 50.6, 50.75], ['antes', 'durante', 'después']);

(async () => {
  const hojas = [
    ['1-acto-1-el-caos', pagina('Storyboard · Video 1 <em>«Lo que ve mi cliente»</em>', '<b>Acto 1 · El caos.</b> Portada limpia → post-its y notificaciones la tapan → todo cae por gravedad → aparece «Buenas noches, Josefa».', AV, `<div class="grid" style="grid-template-columns:repeat(4,1fr)">${A1.join('')}</div>`), 1760],
    ['2-acto-2-el-recorrido', pagina('Storyboard · Video 1 <em>«Lo que ve mi cliente»</em>', '<b>Acto 2 · El recorrido.</b> Pantalla completa; el marco aparece solo para los zooms y las transiciones.', AV, `<div class="grid" style="grid-template-columns:repeat(4,1fr)">${A2.join('')}</div>`), 1760],
    ['3-acto-3-el-cierre', pagina('Storyboard · Video 1 <em>«Lo que ve mi cliente»</em>', '<b>Acto 3 · El cierre.</b> La ventana se achica sobre la foto, se vuelve tarjeta, el texto sube y todo se va en fade a la lámina final.', AV, `<div class="grid" style="grid-template-columns:repeat(3,1fr)">${A3.join('')}</div>`), 1400],
    ['4-transiciones', pagina('Transiciones <em>de un vistazo</em>', 'Cinco recursos distintos; no todas las pantallas se mueven. Aquí se ve cada uno en secuencia.', AV, TR), 1500],
  ];
  const b = await chromium.launch();
  for (const [nombre, html, ancho] of hojas) {
    const f = path.join(__dirname, 'out', `_${nombre}.html`); fs.writeFileSync(f, html);
    const p = await b.newPage({viewport: {width: ancho, height: 900}});
    await p.goto('file://' + f); await p.waitForTimeout(500);
    const rotas = await p.evaluate(() => [...document.images].filter(i => !i.naturalWidth).length);
    if (rotas) { console.error('Imágenes rotas en', nombre, rotas); process.exit(1); }
    await p.screenshot({path: path.join(salida, `storyboard-${nombre}.png`), fullPage: true}); await p.close();
    console.log(nombre);
  }
  await b.close();
})();

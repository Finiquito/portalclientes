// Vista previa de portadas y cierre (las mismas tarjetas que usan los videos), sin grabar nada.
const {chromium} = require('playwright');
const fs = require('fs'), path = require('path');
const {portadaHtml, cierreHtml, CIERRE, FONT, OVERLAY} = require('./lib');
const salida = process.argv[2] || path.join(__dirname, 'laminas');
fs.mkdirSync(salida, {recursive: true});
const guiones = fs.readdirSync(__dirname).filter(f => /^v\d-.*\.js$/.test(f)).sort();
(async () => {
  const b = await chromium.launch();
  const p = await b.newPage({viewport: {width: 864, height: 1080}, deviceScaleFactor: 1.25});
  // Un origen real: la capa usa sessionStorage, que about:blank no permite.
  await p.route('http://lamina.local/', r => r.fulfill({contentType: 'text/html', body: '<!doctype html><html><head><meta charset="utf-8"></head><body style="margin:0"></body></html>'}));
  await p.goto('http://lamina.local/');
  await p.addScriptTag({content: `window.__DM_FONT=${JSON.stringify(FONT)};\n` + OVERLAY});
  const foto = async (html, largo, clase, nom) => {
    await p.evaluate(() => { document.querySelectorAll('.dm-tarjeta').forEach(t => t.remove()); window.__dm.cursor(false); });
    await p.evaluate(([h, l, c]) => window.__dm.tarjeta(h, false, l, c), [html, largo, clase]);
    await p.waitForTimeout(900); await p.screenshot({path: path.join(salida, nom)});
  };
  for (const g of guiones) {
    const src = fs.readFileSync(path.join(__dirname, g), 'utf8');
    const portada = eval('(' + src.match(/portada: (\{.*?\}),\n/s)[1] + ')');
    await foto(portadaHtml(portada), portada.titulo.replace(/<[^>]+>/g, '').length > 62, '', g.replace('.js', '.png'));
  }
  await foto(cierreHtml(CIERRE), false, 'cierre', 'v9-cierre.png');
  await b.close(); console.log(salida);
})();

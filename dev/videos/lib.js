// Motor de los videos de recorrido: portada con gancho, guion con cursor y globos, cierre y MP4 1080×1350.
const {chromium} = require('playwright');
const fs = require('fs'), path = require('path'), {execFileSync} = require('child_process');
const RAIZ = path.resolve(__dirname, '../..'), DEV = path.resolve(__dirname, '..');
const B = process.env.VIDEO_URL || 'http://127.0.0.1:8082';
const W = 864, H = 1080, ESC = 1.25;
const SALIDA = process.env.VIDEO_SALIDA || path.join(RAIZ, 'entregas/videos');
const FONT = 'data:font/woff2;base64,' + fs.readFileSync(RAIZ + '/theme/prisma/assets/fonts/schibsted-grotesk-latin-wght-normal.woff2').toString('base64');
const LOGO = fs.readFileSync(__dirname + '/logo.svg.txt', 'utf8');
const PIEZAS = fs.readFileSync(__dirname + '/piezas.svg.txt', 'utf8');
const OVERLAY = fs.readFileSync(__dirname + '/overlay.js', 'utf8');
// Modo crudo (VIDEO_CRUDO=<carpeta>): graba la pantalla limpia, sin tarjetas ni globos, y deja un
// eventos-<n>.json con los tiempos (textos, cursor, clics, cambios de página) para editarla en dev/videos/remotion.
const CRUDO = process.env.VIDEO_CRUDO || '';
const esperar = ms => new Promise(r => setTimeout(r, ms));
// Tiempo de lectura: ~210 palabras por minuto más un respiro.
const lectura = html => { const n = html.replace(/<[^>]+>/g, ' ').trim().split(/\s+/).length; return Math.max(1700, 800 + n * 250); };

function ffmpeg() { return process.env.FFMPEG || execFileSync('python3', ['-c', 'import imageio_ffmpeg;print(imageio_ffmpeg.get_ffmpeg_exe())']).toString().trim(); }
function restaurar() {
  fs.copyFileSync(DEV + '/storage/video-base.sqlite', DEV + '/storage/video.sqlite');
  fs.rmSync(DEV + '/storage/video_uploads', {recursive: true, force: true});
  fs.cpSync(DEV + '/storage/video_uploads-base', DEV + '/storage/video_uploads', {recursive: true});
}
const portadaHtml = ({kick, titulo, bajada}) => `<div class="logo">${LOGO}</div><div class="kick">${kick}</div><h1>${titulo}</h1>${bajada ? `<p class="bajada">${bajada}</p>` : ''}<div class="pz">${PIEZAS}</div>`;
const cierreHtml = ({titulo}) => `<div class="logo">${LOGO}</div><div class="pz">${PIEZAS}</div><h1>${titulo}</h1><div class="pie"><div class="cta">Prueba la beta gratis <span>→ usaprisma.com</span></div><p class="nota">Cupos limitados, mientras lo afinamos.</p></div>`;
const CIERRES = [
  {titulo: 'Hizo más rápido mi trabajo diario.<br><em>Pruébalo y me cuentas cómo te va.</em>'},
  {titulo: 'Es simple y fácil de implementar.<br><em>Si me funciona a mí, te puede funcionar a ti.</em>'},
];
const CIERRE = CIERRES[0];
// Los dos cierres se alternan: impares (1, 3, 5, 7) el primero y pares (2, 4, 6) el segundo.
const cierreDe = nombre => CIERRES[(parseInt(nombre, 10) + 1) % 2];

async function video({nombre, quien = 'equipo', email = 'camila@estudiopampa.cl', url, antes, portada, cierre, guion}) {
  cierre = cierre || cierreDe(nombre);
  restaurar();
  const b = await chromium.launch();
  const pre = await b.newContext({viewport: {width: W, height: H}});
  const pp = await pre.newPage();
  await pp.goto(B + (quien === 'cliente' ? '/dev/cliente?email=' : '/dev/equipo?email=') + encodeURIComponent(email));
  let inicio = url;
  if (antes) { inicio = (await antes(pp, B)) || url; }
  const estado = await pre.storageState(); await pre.close();

  const ctx = await b.newContext({viewport: {width: W, height: H}, deviceScaleFactor: ESC, storageState: estado});
  await ctx.addInitScript({content: `window.__DM_FONT=${JSON.stringify(FONT)};\n` + OVERLAY});
  const p = await ctx.newPage();
  p.on('pageerror', e => console.log('ERR', e.message));
  const ev = [];
  const marca = o => { if (CRUDO) ev.push({t: Date.now() / 1000, ...o}); };
  const dm = (fn, ...a) => {
    if (CRUDO) {
      if (fn === 'globo') { marca({tipo: 'globo', html: a[0], lado: a[1] || ''}); return Promise.resolve(); }
      if (fn === 'sinGlobo') { marca({tipo: 'globoFin'}); return Promise.resolve(); }
      if (fn === 'mover') marca({tipo: 'ir', x: a[0], y: a[1], ms: a[2]});
      if (fn === 'clic') marca({tipo: 'clic'});
    }
    return p.evaluate(([fn, a]) => window.__dm[fn](...a), [fn, a]);
  };
  p.on('framenavigated', f => { if (f === p.mainFrame()) marca({tipo: 'nav', url: new URL(f.url()).pathname}); });
  const dir = path.join(__dirname, 'cuadros'); fs.rmSync(dir, {recursive: true, force: true}); fs.mkdirSync(dir);
  const cdp = await ctx.newCDPSession(p); const cuadros = []; let n = 0;
  cdp.on('Page.screencastFrame', async f => { const nom = 'c' + String(n++).padStart(5, '0') + '.jpg'; fs.writeFileSync(path.join(dir, nom), Buffer.from(f.data, 'base64')); cuadros.push([nom, f.metadata.timestamp]); try { await cdp.send('Page.screencastFrameAck', {sessionId: f.sessionId}); } catch (e) {} });

  const h = {
    p, B, dm, esperar, lectura,
    async ir(x, y, ms = 720) { await dm('mover', x, y, ms); await esperar(ms + 60); },
    async a(loc, dx = 0.5, dy = 0.5, ms = 720) { const r = await loc.boundingBox(); if (!r) { console.log('sin caja', loc); return; } await h.ir(r.x + r.width * dx, r.y + r.height * dy, ms); },
    async globo(titulo, sub = '', lado = '', extra = 0) { const t = titulo + (sub ? '<small>' + sub + '</small>' : ''); await dm('globo', t, lado); await esperar(lectura(t) + extra); await dm('sinGlobo'); await esperar(260); },
    async globoSinEsperar(titulo, sub = '', lado = '') { await dm('globo', titulo + (sub ? '<small>' + sub + '</small>' : ''), lado); },
    async bajar(loc, top = 160) { const r = await loc.boundingBox(); await p.evaluate(y => window.scrollBy({top: y, behavior: 'smooth'}), r.y - top); await esperar(720); },
    async arriba() { await p.evaluate(() => window.scrollTo({top: 0, behavior: 'smooth'})); await esperar(700); },
    async clic(loc, nav = false) { await dm('clic'); await esperar(180); if (nav) { await Promise.all([p.waitForNavigation(), loc.click()]); await p.waitForLoadState('networkidle'); } else { await loc.click(); } },
    async escribir(loc, txt, ms = 55) { await loc.click(); for (const ch of txt) { await p.keyboard.type(ch); await esperar(ms); } },
    async quitarAvisosFlotantes() { await esperar(250); await p.waitForLoadState('networkidle').catch(() => {}); await p.evaluate(() => document.querySelectorAll('body *').forEach(e => { if (getComputedStyle(e).position === 'fixed' && !e.id.startsWith('dm-') && !e.classList.contains('dm-tarjeta') && /creados|creadas|propuesta|guardad|enviad|subid|listo|anotado|aprobado|archivo|tarea|publicad|comentario|respuesta/i.test(e.textContent) && e.textContent.length < 300) e.remove(); })).catch(() => {}); },
  };

  await p.goto(B + inicio); await p.waitForLoadState('networkidle');
  if (CRUDO) {
    await cdp.send('Page.startScreencast', {format: 'jpeg', quality: 92, maxWidth: 1080, maxHeight: 1350, everyNthFrame: 1});
    await dm('cursor', true); await esperar(500);
  } else {
    await dm('cursor', false);
    await dm('tarjeta', portadaHtml(portada), false, portada.titulo.replace(/<[^>]+>/g, '').length > 62); await esperar(650);
    await cdp.send('Page.startScreencast', {format: 'jpeg', quality: 92, maxWidth: 1080, maxHeight: 1350, everyNthFrame: 1});
    await esperar(Math.max(3000, 700 + (portada.titulo + ' ' + (portada.bajada || '')).replace(/<[^>]+>/g, ' ').trim().split(/\s+/).length * 210));
    await dm('quitarTarjetas'); await esperar(500); await dm('cursor', true); await esperar(250);
  }
  await guion(h);
  await dm('sinGlobo'); await dm('cursor', false);
  if (CRUDO) { await esperar(700); } else { await dm('tarjeta', cierreHtml(cierre), false, false, 'cierre'); await esperar(4600); }
  const tFin = Date.now() / 1000; await cdp.send('Page.stopScreencast'); await esperar(300);
  await ctx.close(); await b.close();

  const fin = Math.max(cuadros[cuadros.length - 1][1] + 0.4, tFin);
  let txt = ''; cuadros.forEach(([nom, t], i) => { const sig = i + 1 < cuadros.length ? cuadros[i + 1][1] : fin; txt += `file '${dir}/${nom}'\nduration ${Math.max(0.001, sig - t).toFixed(4)}\n`; });
  txt += `file '${dir}/${cuadros[cuadros.length - 1][0]}'\n`;
  const lista = path.join(__dirname, 'lista.txt'); fs.writeFileSync(lista, txt);
  fs.mkdirSync(SALIDA, {recursive: true});
  const out = CRUDO ? path.join(CRUDO, `crudo-${nombre.split('-')[0]}.mp4`) : path.join(SALIDA, `prisma-${nombre}-1080x1350.mp4`);
  if (CRUDO) {
    fs.mkdirSync(CRUDO, {recursive: true});
    const t0 = cuadros[0][1];
    fs.writeFileSync(path.join(CRUDO, `eventos-${nombre.split('-')[0]}.json`), JSON.stringify({duracion: +(fin - t0).toFixed(3), ancho: W, alto: H, eventos: ev.map(e => ({...e, t: +(e.t - t0).toFixed(3)}))}, null, 1));
  }
  execFileSync(ffmpeg(), ['-y', '-loglevel', 'error', '-f', 'concat', '-safe', '0', '-i', lista, '-vf', 'scale=1080:1350:flags=lanczos,fps=30', '-c:v', 'libx264', '-preset', 'slow', '-crf', '18', '-pix_fmt', 'yuv420p', '-movflags', '+faststart', out]);
  const hoja = process.env.VIDEO_HOJAS ? path.join(process.env.VIDEO_HOJAS, `hoja-${nombre}.png`) : null;
  if (hoja) { execFileSync(ffmpeg(), ['-y', '-loglevel', 'error', '-i', out, '-vf', 'fps=1/2.5,scale=270:-1,tile=6x4', '-frames:v', '1', hoja]); }
  console.log(nombre, (fin - cuadros[0][1]).toFixed(1) + ' s', out);
}
module.exports = {video, esperar, lectura, portadaHtml, cierreHtml, CIERRE, CIERRES, FONT, OVERLAY};

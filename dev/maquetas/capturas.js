// Capturas de la maqueta del onboarding: node dev/maquetas/capturas.js <carpeta>
const {chromium} = require('playwright');
const path = require('path'), fs = require('fs');
const salida = process.argv[2] || path.join(__dirname, 'capturas');
fs.mkdirSync(salida, {recursive: true});
const url = 'file://' + path.join(__dirname, 'onboarding-agencia.html');
(async () => {
  const b = await chromium.launch();
  const ctx = await b.newContext({viewport: {width: 1440, height: 900}, deviceScaleFactor: 1.5});
  const p = await ctx.newPage();
  const errores = []; p.on('pageerror', e => errores.push(e.message)); p.on('console', m => m.type() === 'error' && errores.push(m.text()));
  // 1) Recorrido real: vacío → error de validación → llenar → avanzar
  await p.goto(url + '?limpio=1&limpio-estado=1'); await p.evaluate(() => localStorage.clear()); await p.reload();
  await p.waitForTimeout(700);
  await p.screenshot({path: path.join(salida, '1-agencia-vacio.png')});
  await p.click('#sig'); await p.waitForTimeout(400);
  await p.screenshot({path: path.join(salida, '1b-validacion.png')});
  await p.fill('#tuNombre', 'Camila Soto'); await p.fill('#agencia', 'Estudio Pampa');
  await p.click('label[for=c1]'); await p.waitForTimeout(800);
  await p.screenshot({path: path.join(salida, '1c-agencia-llena.png')});
  await p.click('#sig'); await p.waitForTimeout(700);
  // 2..5 con datos de ejemplo
  const dem = async (paso, nombre, extra) => {
    await p.goto(url + `?limpio=1&demo=1&paso=${paso}`); await p.waitForTimeout(1500);
    if (extra) await extra(); await p.screenshot({path: path.join(salida, nombre)});
  };
  await dem(2, '2-cliente.png');
  await dem(3, '3-proyecto.png');
  await dem(4, '4-equipo.png');
  await dem(5, '5-listo.png');
  // móvil
  const m = await b.newContext({viewport: {width: 390, height: 844}, deviceScaleFactor: 2, isMobile: true});
  const pm = await m.newPage(); pm.on('pageerror', e => errores.push('movil: ' + e.message));
  for (const [n, nom] of [[1, 'm1-agencia.png'], [3, 'm3-proyecto.png'], [5, 'm5-listo.png']]) {
    await pm.goto(url + `?limpio=1&demo=1&paso=${n}`); await pm.waitForTimeout(1500); await pm.screenshot({path: path.join(salida, nom)});
  }
  // oscuro
  const d = await b.newContext({viewport: {width: 1440, height: 900}, deviceScaleFactor: 1.5, colorScheme: 'dark'});
  const pd = await d.newPage();
  for (const [n, nom] of [[1, 'd1-agencia.png'], [5, 'd5-listo.png']]) {
    await pd.goto(url + `?limpio=1&demo=1&paso=${n}`); await pd.waitForTimeout(1500); await pd.screenshot({path: path.join(salida, nom)});
  }
  await b.close();
  console.log(errores.length ? 'ERRORES: ' + errores.join(' | ') : 'sin errores de consola', '→', salida);
})();

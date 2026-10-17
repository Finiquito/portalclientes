// Recorre el asistente REAL (servidor de desarrollo con PORTAL_ONBOARDING=1) y deja capturas.
// PORTAL_ONBOARDING=1 PORTAL_PRIMER_COORDINADOR=camila.soto@estudiopampa.cl PORTAL_AGENCIA_NOMBRE="Estudio Pampa" PORTAL_DB_DSN=sqlite:/tmp/onb.sqlite php -S 127.0.0.1:8083 -t dev/public dev/public/index.php
const {chromium} = require('playwright');
const path = require('path'), fs = require('fs');
const base = 'http://127.0.0.1:8083', salida = process.argv[2] || '/tmp/onb-e2e';
fs.mkdirSync(salida, {recursive: true});
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAGQAAAAyCAYAAACqNX6+AAAAXklEQVR42u3QQQ0AAAgEoNu/9K3hHBQgM3dICAkJCQkJCQkJCQkJCQkJCQkJCQkJCQkJCQkJCQkJCQkJCQkJCQkJCQkJCQkJCQkJCQkJCQkJCQkJCQkJCQkJCQkJCQkJCQnZ2gF8NgJ5kYy5PAAAAABJRU5ErkJggg==', 'base64');
(async () => {
  const b = await chromium.launch();
  const ctx = await b.newContext({viewport: {width: 1440, height: 900}, deviceScaleFactor: 1.5});
  const p = await ctx.newPage();
  const log = []; p.on('pageerror', e => log.push('JS: ' + e.message));
  p.on('response', r => { if (r.url().includes('/equipo/bienvenida/') && r.request().method() === 'POST') log.push(r.request().method() + ' ' + r.url().replace(base, '') + ' → ' + r.status()); });
  const foto = async n => { await p.waitForTimeout(700); await p.screenshot({path: path.join(salida, n)}); };
  await p.goto(base + '/dev/equipo?email=camila.soto@estudiopampa.cl');
  await p.goto(base + '/equipo');
  log.push('al entrar → ' + p.url().replace(base, ''));
  await foto('r1-agencia.png');
  await p.fill('#tuNombre', 'Camila Soto'); await p.fill('#agencia', 'Estudio Pampa');
  await p.click('label[for=c7]');                                   // azul claro (texto oscuro)
  await p.setInputFiles('#archivoLogo', {name: 'logo.png', mimeType: 'image/png', buffer: PNG});
  await p.waitForTimeout(1200); await foto('r1b-agencia-llena.png');
  await p.click('#sig'); await p.waitForTimeout(500);
  await p.fill('#cliente', 'Panadería Ruiz'); await p.fill('#contacto', 'Josefa Ruiz'); await p.fill('#correo', 'josefa@panaderiaruiz.cl');
  await foto('r2-cliente.png'); await p.click('#sig'); await p.waitForTimeout(500);
  await p.fill('#proyecto', 'Rebranding 2026'); await p.click('label[for^="p-"] >> nth=1');
  await foto('r3-proyecto.png'); await p.click('#sig'); await p.waitForTimeout(500);
  await p.fill('#miembro', 'andres@estudiopampa.cl'); await p.press('#miembro', 'Enter');
  await foto('r4-equipo.png'); await p.click('#sig'); await p.waitForTimeout(600);
  await foto('r5-listo.png');
  // recargar a mitad de camino conserva todo
  await p.reload(); await p.waitForTimeout(600);
  log.push('tras recargar: paso activo = ' + await p.locator('.paso.activo').getAttribute('data-paso') + ', agencia = ' + await p.inputValue('#agencia').catch(() => '(otro paso)'));
  await p.click('#sig'); await p.waitForURL(/\/equipo\/clientes\//, {timeout: 20000});
  log.push('al terminar → ' + p.url().replace(base, ''));
  await p.waitForTimeout(800); await foto('r6-ficha-cliente.png');
  await p.goto(base + '/equipo'); await foto('r7-panel.png');
  log.push('/equipo bienvenida otra vez → ' + (await p.goto(base + '/equipo/bienvenida')).url().replace(base, ''));
  await b.close(); console.log(log.join('\n'));
})().catch(e => { console.error('FALLÓ', e.message); process.exit(1); });

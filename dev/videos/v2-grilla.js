// Video 2 · De la grilla en Word a las piezas en la entrega, con IA.
const {video} = require('./lib');
const fs = require('fs');
const DOCX = {name: 'Grilla noviembre - Panadería Ruiz.docx', mimeType: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', buffer: fs.readFileSync(__dirname + '/grilla-noviembre.docx')};
video({
  nombre: '2-de-la-grilla-al-portal',
  portada: {kick: 'Gestionar la grilla', titulo: 'Subo la grilla y queda ordenada, pieza por pieza, <em>en pocos minutos.</em>', bajada: 'Lista para revisar, ajustar y gestionar sus cambios.'},
  async antes(pp, B) {
    await pp.goto(B + '/equipo/entregas/nuevo');
    const op = await pp.$$eval('select[name=proyecto_id] option', os => os.find(o => o.textContent.includes('Redes octubre')).value);
    await pp.selectOption('select[name=proyecto_id]', op); await pp.fill('input[name=titulo]', 'Grilla noviembre · Instagram');
    await Promise.all([pp.waitForNavigation(), pp.click('button:has-text("Crear")')]);
    return new URL(pp.url()).pathname;
  },
  async guion(h) {
    const {p} = h;
    const dz = p.locator('[data-dz]');
    await h.bajar(dz, 300);
    await h.a(dz, 0.5, 0.25, 800);
    await h.globo('Me llegó la grilla de noviembre en Word.', 'La suelto tal cual.');
    const r = await dz.boundingBox();
    await h.dm('archivos', [{nombre: DOCX.name, tipo: 'docx'}], 900, r.y + r.height * 0.3, r.x + r.width * 0.22, r.y + r.height * 0.38);
    await h.ir(r.x + r.width * 0.22 + 40, r.y + r.height * 0.38 + 30, 1100); await h.dm('clic');
    await Promise.all([p.waitForURL(/grilla/), p.setInputFiles('[data-dz-input]', DOCX)]);
    await h.esperar(300);
    const lee = p.locator('text=Leyendo la grilla con IA');
    if (await lee.count()) { await h.a(lee, 0.9, 1.6, 600); await h.globoSinEsperar('La IA separa cada pieza: tipo, láminas, copy y notas.', 'Sin reescribir el copy.'); }
    await p.locator('button:has-text("Crear contenidos")').waitFor({timeout: 30000}); await h.esperar(400); await h.dm('sinGlobo');
    const cuenta = p.locator('input[placeholder="@tumarca"]');
    await h.a(cuenta, 0.3, 0.5, 700);
    await h.globo('4 piezas listas para revisar.', 'Nada se crea hasta que yo diga.');
    await h.dm('clic'); await h.escribir(cuenta, '@panaderiaruiz', 45);
    const lam = p.locator('label:has-text("Láminas")'), notas = p.locator('label:has-text("Notas del equipo")');
    await h.bajar(lam.nth(0), 420); await h.a(lam.nth(0), 0.4, 3.2, 700);
    await h.globo('Carrusel de 3 láminas:', 'qué se ve y qué texto lleva cada una.');
    await h.bajar(notas.nth(1), 520); await h.a(lam.nth(1), 0.4, 3, 700);
    await h.globo('El reel, con su guion por escena.');
    await h.a(notas.nth(1), 0.3, 2.4, 600);
    await h.globo('El «Audio:» va a notas.', 'No se mezcla con el copy.');
    await h.bajar(notas.nth(2), 520); await h.a(notas.nth(2), 0.3, 2.4, 700);
    await h.globo('«Validar el precio» queda como nota para el cliente.');
    const crear = p.locator('button:has-text("Crear contenidos")');
    await h.bajar(crear, 700); await h.a(crear, 0.5, 0.5, 800);
    await h.globo('Reviso, ajusto si quiero… y creo todo de una vez.', '', 'arriba');
    await h.clic(crear, true); await h.quitarAvisosFlotantes();
    await h.bajar(p.locator('h2:has-text("Contenidos")').first(), 90);
    await h.a(p.locator('text=Post 1 · Masa madre').first(), 0.5, 0.5, 800);
    await h.globo('4 piezas en la entrega, con lo que le falta a cada una.', 'Ahora suelto las imágenes y van solas a su lámina.', 'abajo', 300);
  },
});

// Video 3 · Suelto todas las imágenes juntas y cada una cae en su lámina.
const {video} = require('./lib');
const fs = require('fs');
const A = __dirname + '/arte/';
const ARCH = [['1-1.jpg', '1-1.jpg'], ['1-2.jpg', '1-2.jpg'], ['1-3.jpg', '1-3.jpg'], ['2-portada.jpg', 'reel.jpg'], ['3.jpg', 'story.jpg']]
  .map(([name, f]) => ({name, mimeType: 'image/jpeg', buffer: fs.readFileSync(A + f)}));
const MINI = ARCH.map(a => ({nombre: a.name, tipo: 'img', src: 'data:image/jpeg;base64,' + a.buffer.toString('base64')}));
video({
  nombre: '3-soltar-imagenes',
  portada: {kick: 'Las imágenes', titulo: 'Suelto todas las imágenes juntas y <em>cada una cae en su lámina.</em>'},
  async antes(pp, B) {
    await pp.goto(B + '/equipo/entregas');
    return await pp.$$eval('a', as => as.filter(a => a.textContent.includes('Grilla de diciembre')).map(a => a.getAttribute('href'))[0]);
  },
  async guion(h) {
    const {p} = h;
    const lista = p.locator('h2:has-text("Contenidos")').first();
    await h.bajar(lista, 70);
    await h.a(p.locator('text=Sin imágenes').first(), 0.5, 0.5, 800);
    await h.globo('Las piezas ya están. Les faltan las imágenes.', 'El diseñador me mandó un zip con 5.');
    const dz = p.locator('[data-dz]'); const r = await dz.boundingBox();
    await h.dm('archivos', MINI, 900, r.y - 60, r.x + r.width * 0.3, r.y + 10);
    await h.ir(r.x + r.width * 0.3 + 60, r.y + 60, 1100); await h.esperar(250); await h.dm('clic'); await h.dm('soltarArchivos');
    await p.setInputFiles('[data-dz-input]', ARCH); await h.esperar(700);
    const panel = p.locator('text=Revisa antes de crear').first();
    await h.bajar(panel, 120);
    const chip = p.locator('[data-revisar] .pa-chip').nth(1);
    await h.a(chip, 0.6, 0.5, 800);
    await h.globo('Lee el número del nombre:', '«1-2.jpg» es la lámina 2 del post 1.');
    await h.a(p.locator('[data-revisar] select').nth(3), 0.5, 0.5, 700);
    await h.globo('Si algo no calza, lo cambio aquí antes de subir.', '', 'izq');
    const ok = p.locator('[data-revisar] button:has-text("Agregar")');
    await h.bajar(ok, 640); await h.a(ok, 0.5, 0.5, 800); await h.clic(ok, true); await h.quitarAvisosFlotantes();
    await h.bajar(p.locator('h2:has-text("Contenidos")').first(), 70);
    await h.a(p.locator('text=Post 1 · Masa madre').first(), 0.6, 1.8, 900);
    await h.globo('Cada una en su lugar.', 'Sin arrastrarlas una por una.', '', 200);
    const abrir = p.locator('a:has-text("Abrir")').first();
    await h.a(abrir, 0.5, 0.5, 700); await h.clic(abrir, true); await h.quitarAvisosFlotantes();
    await h.bajar(p.locator('h2:has-text("Versiones")').first(), 110);
    await h.a(p.locator('text=1-2.jpg').first(), 0.5, 0.5, 800);
    await h.globo('El carrusel quedó armado, en orden.', 'Listo para que el cliente lo revise.', '', 300);
  },
});

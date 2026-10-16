// Video 4 · La reunión que se resume sola: notas → resumen, acuerdos y tareas → el cliente lo ve.
const {video} = require('./lib');
const NOTAS = `Reunión con Josefa (Panadería Ruiz) · grilla de noviembre
- El carrusel de masa madre le encantó, va tal cual.
- Pide sumar el horario nuevo de los domingos (ella me lo manda).
- La promo café + berlín mejor en la segunda semana, no chocar con el Día del Pan.
- Reel del madrugón: grabamos el jueves a las 5:00 en el local.
- Le preocupan las ventas de la tarde.
- Próxima reunión en una semana para ver diciembre.`;
let R = '';
video({
  nombre: '4-reunion-resumida',
  portada: {kick: 'Las reuniones', titulo: 'Con la transcripción de la reunión, <em>los acuerdos y las tareas se arman solos.</em>', bajada: 'Con responsables, fechas y la próxima reunión.'},
  async antes(pp, B) {
    await pp.goto(B + '/equipo/reuniones?t=pasadas');
    R = await pp.evaluate(() => [...document.querySelectorAll('a')].filter(a => a.textContent.includes('Revisión grilla de noviembre')).map(a => a.getAttribute('href'))[0] || '');
    if (!R) { R = '/equipo/reuniones/' + require('fs').readFileSync('/tmp/reu.txt', 'utf8').trim(); }
    return R;
  },
  async guion(h) {
    const {p, B} = h;
    const tr = p.locator('textarea[name=transcripcion]');
    await h.bajar(tr, 200); await h.a(tr, 0.3, 0.3, 800);
    await h.globo('Terminé la reunión con Josefa.', 'Pego mis notas tal cual (o la transcripción de Meet).');
    await h.dm('clic'); await tr.fill(NOTAS); await h.esperar(900);
    const an = p.locator('button[value=analizar]');
    await h.a(an, 0.5, 0.5, 800); await h.dm('clic');
    await h.globoSinEsperar('La IA arma una propuesta…', 'Nada se publica sin que yo lo apruebe.', 'arriba');
    await Promise.all([p.waitForNavigation({timeout: 30000}), an.click()]); await p.waitForLoadState('networkidle'); await h.quitarAvisosFlotantes();
    const res = p.locator('textarea[name=resumen]');
    await h.bajar(res, 220); await h.a(res, 0.6, 0.5, 800);
    await h.globo('El resumen que verá el cliente,', 'en limpio y en buen español.');
    await h.a(p.locator('textarea[name=acuerdos]'), 0.6, 0.6, 700);
    await h.globo('Los acuerdos, uno por línea.');
    await h.a(p.locator('textarea[name=analisis]'), 0.6, 0.5, 700);
    await h.globo('Y una nota interna que el cliente nunca ve.');
    const pub = p.locator('input[name=resumen_publicado]');
    await h.a(pub, 0.5, 0.5, 700); await h.clic(pub);
    const tit = p.locator('h2:has-text("Tareas propuestas")').first();
    await h.bajar(tit, 120);
    const marcas = p.locator('input[name="aprobar[]"]');
    const n = Math.min(3, await marcas.count());
    for (let i = 0; i < n; i++) { await h.a(marcas.nth(i), 0.5, 0.5, 500); await h.clic(marcas.nth(i)); await h.esperar(150); }
    await h.a(p.locator('table select').first(), 0.5, 0.5, 600);
    await h.globo('Tres tareas, con responsable y fecha.', 'Una le toca a Josefa: le llega sola.');
    const ap = p.locator('button[value=aplicar]');
    await h.bajar(ap, 700); await h.a(ap, 0.5, 0.5, 800);
    await h.clic(ap, true); await h.quitarAvisosFlotantes();
    // Así lo ve Josefa
    const id = R.split('/').pop();
    await p.goto(B + '/dev/cliente?email=josefa@panaderiaruiz.cl'); await p.goto(B + '/portal/reuniones/' + id); await p.waitForLoadState('networkidle');
    await h.a(p.locator('h1').first(), 0.3, 1.5, 800);
    await h.globo('Y así lo ve Josefa:', 'el resumen, los acuerdos y lo que le toca.');
    const acu = p.locator('text=Acuerdos').first();
    if (await acu.count()) { await h.bajar(acu, 260); await h.a(acu, 0.5, 2.5, 800); }
    await h.globo('Nadie tiene que acordarse de nada.', '', '', 300);
  },
});

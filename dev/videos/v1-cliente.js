// Video 1 · Lo que ve mi cliente: revisa la grilla, aprueba, marca un punto y pide un cambio.
const {video} = require('./lib');
video({
  nombre: '1-lo-que-ve-tu-cliente', quien: 'cliente', email: 'josefa@panaderiaruiz.cl', url: '/portal',
  portada: {kick: 'Lo que ve mi cliente', titulo: 'Mis clientes ya no me preguntan <em>«¿cómo va lo mío?»</em>', bajada: 'Lo ven solos. Te muestro cómo.'},
  async guion(h) {
    const {p} = h;
    await h.a(p.locator('h1').first(), 0.35, 0.6, 900);
    await h.globo('Josefa entra con su correo y un código.', 'Sin contraseñas que olvidar.');
    const rev = p.locator('nav a[href="/portal/entregas"]').last();
    await h.a(rev, 0.5, 0.4, 900); await h.clic(rev, true);
    await h.clic(p.locator('a[href*="/portal/entregas/0"]').first(), true);
    await h.quitarAvisosFlotantes();
    await h.a(p.locator('text=Post 1 · Masa madre').first(), 0.5, -3, 900);
    await h.globo('La grilla le llega tal como se verá en Instagram.');
    await h.a(p.locator('text=Post 1 · Masa madre').first(), 0.5, 0.5, 600);
    await h.clic(p.locator('text=Post 1 · Masa madre').first(), true);
    const sig = p.locator('[data-next]').first();
    await h.a(sig, 0.5, 0.5, 900); await h.clic(sig); await h.esperar(900);
    await h.globoSinEsperar('Pasa las láminas como en el celular.'); await h.esperar(500); await h.clic(sig); await h.esperar(1500); await h.dm('sinGlobo');
    const encanta = p.locator('button[value=me_encanta]');
    await h.bajar(encanta, 420); await h.a(encanta, 0.5, 0.5, 900);
    await h.clic(encanta, true); await h.quitarAvisosFlotantes(); await h.esperar(300);
    const aprobar = p.locator('button[value=aprobar]');
    await h.a(aprobar, 0.5, 0.5, 800);
    await h.globo('Le encanta. Lo aprueba con un toque.', '', 'arriba');
    await h.clic(aprobar, true); await h.quitarAvisosFlotantes();
    // Reel: marcar un punto y pedir un cambio
    const pin = p.locator('[data-pin-modo]').first();
    await h.a(pin, 0.5, 0.5, 900);
    await h.globo('¿Algo no la convence?', 'Marca el punto exacto en la imagen.');
    await h.clic(pin); await h.esperar(300);
    const img = p.locator('[data-pinable]').first(); const r = await img.boundingBox();
    await h.ir(r.x + r.width * 0.5, r.y + r.height * 0.7, 900); await h.dm('clic');
    await p.mouse.click(r.x + r.width * 0.5, r.y + r.height * 0.7); await h.esperar(500);
    await h.bajar(img, 150); const r2 = await img.boundingBox(); await h.ir(r2.x + r2.width * 0.5 + 30, r2.y + r2.height * 0.7 + 30, 300);
    await h.globo('Punto marcado.', '', '', -400);
    const pedir = p.locator('summary:has-text("Pedir cambios")');
    await h.a(pedir, 0.5, 0.5, 800); await h.clic(pedir); await h.esperar(400);
    const caja = p.locator('#rev-cuerpo');
    await h.a(caja, 0.2, 0.5, 600);
    await h.escribir(caja, '¿Y si el pan sale más grande? Es el protagonista 🥖', 42);
    const enviar = p.locator('button[value=cambios]');
    await h.a(enviar, 0.5, 0.5, 700);
    await h.globo('Y pide el cambio ahí mismo, con el punto marcado.', '', 'arriba');
    await h.clic(enviar, true); await h.quitarAvisosFlotantes();
    // Story: aprobada
    const ap2 = p.locator('button[value=aprobar]');
    await h.bajar(ap2, 640); await h.a(ap2, 0.5, 0.5, 900); await h.clic(ap2, true); await h.quitarAvisosFlotantes();
    await h.a(p.locator('text=Reel 2 · Un día en el horno').first(), 0.5, -4, 1000);
    await h.globo('Y a mí me llega todo junto, en un solo aviso:', 'qué aprobó, qué cambia y dónde.', '', 400);
  },
});

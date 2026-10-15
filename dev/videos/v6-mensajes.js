// Video 6 · Una conversación por cada cosa: bandeja de mensajes, respuesta y lo que ve el cliente.
const {video} = require('./lib');
video({
  nombre: '6-mensajes', url: '/equipo/mensajes',
  portada: {kick: 'Mensajes', titulo: 'Los comentarios de mis clientes vivían en <em>cinco apps distintas.</em>', bajada: 'Ahora cada cosa tiene su conversación.'},
  async guion(h) {
    const {p, B} = h;
    const items = p.locator('section[aria-label=Conversaciones] li a');
    await h.a(items.nth(0), 0.35, 0.3, 800);
    await h.globo('Una conversación por cada cosa:', 'el post, la tarea de las fotos, la etiqueta.');
    await h.a(items.nth(2), 0.5, 0.75, 700);
    await h.globo('Con el cliente y el estado a la vista.', 'Lo que no he leído, marcado.');
    await h.a(items.nth(0), 0.3, 0.3, 700); await h.clic(items.nth(0), true);
    const caja = p.locator('textarea[name=cuerpo]');
    await h.a(p.locator('[data-hilo] li').first(), 0.5, 0.5, 700);
    await h.globo('Josefa pide otra foto para la lámina 2.');
    await h.a(caja, 0.2, 0.5, 700); await h.escribir(caja, '¡Claro! La cambio hoy y te aviso por aquí 🙌', 40);
    const resp = p.locator('button:has-text("Responder")');
    await h.a(resp, 0.5, 0.5, 700);
    await h.globo('Respondo sin salir de la bandeja.', 'A ella le llega el aviso por correo.', 'arriba');
    await h.clic(resp, true); await h.quitarAvisosFlotantes();
    await h.esperar(500);
    // Lo que ve Josefa
    await p.goto(B + '/dev/cliente?email=josefa@panaderiaruiz.cl'); await p.goto(B + '/portal/mensajes'); await p.waitForLoadState('networkidle');
    const it = p.locator('section[aria-label=Conversaciones] li a').first();
    await h.a(it, 0.35, 0.4, 800); await h.clic(it, true);
    await h.a(p.locator('[data-hilo] li').last(), 0.4, 0.5, 800);
    await h.globo('Y Josefa lo ve en su portal, junto al post.', 'Nada se pierde en un chat.');
    const abrir = p.locator('a:has-text("Abrir")').first();
    if (await abrir.count()) { await h.a(abrir, 0.5, 0.5, 700); await h.globo('Un toque y está en la pieza.', 'Cuando la aprueba, la conversación se archiva sola.', 'izq', 300); }
  },
});

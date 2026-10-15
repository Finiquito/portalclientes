// Video 7 · Mi día empieza en una pantalla: inicio del equipo.
const {video} = require('./lib');
video({
  nombre: '7-mi-dia', url: '/equipo',
  portada: {kick: 'Mi mañana', titulo: 'Partía el día revisando WhatsApp, correo y Drive. <em>Ahora, una pantalla.</em>'},
  async guion(h) {
    const {p} = h;
    await h.a(p.locator('h1').first(), 0.6, 0.6, 800);
    await h.globo('Abro Prisma y sé qué pasó desde ayer.');
    const cards = p.locator('text=Respuestas del cliente').first();
    await h.a(cards, 0.5, -1.2, 700);
    await h.globo('Lo que respondieron mis clientes,', 'lo que me toca y lo vencido.');
    const urg = p.locator('text=Revisar prueba de color de la etiqueta').first();
    await h.bajar(p.locator('h2:has-text("Respuestas del cliente")').first(), 110);
    await h.a(urg, 0.4, 0.5, 700);
    await h.globo('Tomás pidió cambios y vence hoy.', 'Lo urgente, primero.');
    const reu = p.locator('h2:has-text("Próximas reuniones")').first();
    await h.bajar(reu, 160); await h.a(reu, 0.3, 2.4, 700);
    await h.globo('Las reuniones de la semana, con su Meet.');
    const esp = p.locator('h2:has-text("Esperando al cliente")').first();
    await h.bajar(esp, 300); await h.a(esp, 0.4, 2.2, 700);
    await h.globo('Y lo que está en manos del cliente:', 'la grilla, todavía sin revisar.');
    await h.bajar(p.locator('h2:has-text("Respuestas del cliente")').first(), 110);
    await h.a(urg, 0.4, 0.5, 700); await h.clic(urg, true); await h.quitarAvisosFlotantes();
    const com = p.locator('text=El burdeo se ve muy café').first();
    if (await com.count()) { await h.bajar(com, 360); await h.a(com, 0.4, 0.5, 800); }
    await h.globo('Un clic y estoy en el detalle,', 'con el comentario del cliente a la vista.', '', 300);
  },
});

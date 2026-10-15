// Video 5 · Pedirle el logo al cliente una sola vez: tarea «Pedir archivos» → el cliente sube → me llega.
const {video} = require('./lib');
const d = new Date(Date.now() + 2 * 864e5); const FECHA = d.toISOString().slice(0, 10);
video({
  nombre: '5-pedir-archivos', url: '/equipo/tareas/nuevo',
  portada: {kick: 'Pedir archivos', titulo: 'Pedí el logo en alta <em>tres veces</em> por WhatsApp.', bajada: 'Ahora se lo pido una vez.'},
  async guion(h) {
    const {p, B} = h;
    const pr = p.locator('select[name=proyecto_id]');
    await h.a(pr, 0.4, 0.5, 800); await h.dm('clic');
    await pr.selectOption(await pr.evaluate(s => [...s.options].find(o => o.text.includes('Redes octubre')).value)); await h.esperar(300);
    const tit = p.locator('input[name=titulo]');
    await h.a(tit, 0.15, 0.5, 800); await h.escribir(tit, 'Logo en alta resolución', 50);
    const desc = p.locator('textarea[name=descripcion]');
    await h.a(desc, 0.2, 0.3, 600); await h.escribir(desc, 'En PNG o vector (AI, SVG o PDF). Si tienes el manual de marca, súbelo también 🙏', 22);
    const tipo = p.locator('select[name=tipo]');
    await h.a(tipo, 0.4, 0.5, 700); await h.dm('clic'); await tipo.selectOption('archivo'); await h.esperar(300);
    await h.globo('Tipo «Pedir archivos»:', 'Josefa los sube directo a la tarea.');
    const cli = p.locator('input[name=asignado][value=cliente]');
    await h.a(cli, 0.5, 0.5, 700); await h.clic(cli); await h.esperar(250);
    const resp = p.locator('select[name=responsable_contacto_id]');
    if (await resp.isVisible()) { await h.a(resp, 0.4, 0.5, 600); await h.dm('clic'); const v = await resp.evaluate(s => ([...s.options].find(o => o.text.includes('Josefa') && !o.disabled) || {}).value); if (v) await resp.selectOption(v); }
    const vence = p.locator('input[name=fecha_vencimiento]');
    await h.bajar(vence, 520); await h.a(vence, 0.3, 0.5, 700); await h.dm('clic'); await vence.fill(FECHA);
    const av = p.locator('input[name=avisar]');
    await h.a(av, 0.5, 0.5, 600); await h.clic(av);
    await h.globo('Le toca a ella, con fecha.', 'Le llega un correo con el link directo.');
    const g = p.locator('button[type=submit]:has-text("Guardar")');
    await h.a(g, 0.5, 0.5, 600); await h.clic(g, true); await h.quitarAvisosFlotantes();
    // Josefa
    await p.goto(B + '/dev/cliente?email=josefa@panaderiaruiz.cl'); await p.waitForLoadState('networkidle');
    const t = p.locator('a:has-text("Logo en alta resolución")').first();
    await h.bajar(t, 420); await h.a(t, 0.3, 0.5, 800);
    await h.globo('Josefa lo ve en su portal:', 'qué le pido y para cuándo.');
    await h.clic(t, true);
    const zona = p.locator('input[name="archivos[]"]').locator('xpath=..');
    await h.bajar(p.locator('h2:has-text("Archivos")').first(), 160);
    const caja = p.locator('text=Toca para elegir archivos').first();
    await h.a(caja, 0.5, 0.5, 800); await h.dm('clic');
    await p.setInputFiles('input[name="archivos[]"]', __dirname + '/arte/logo-panaderia-ruiz.png'); await h.esperar(600);
    const sub = p.locator('button:has-text("Subir archivos")');
    await h.a(sub, 0.5, 0.5, 600); await h.clic(sub, true); await h.quitarAvisosFlotantes(); await h.esperar(400);
    const ent = p.locator('button:has-text("Entregar al equipo")');
    await h.bajar(ent, 520); await h.a(ent, 0.5, 0.5, 800);
    await h.globo('Lo sube y lo entrega.', 'Sin buscar en qué chat se lo pedí.', 'arriba');
    await h.clic(ent, true); await h.quitarAvisosFlotantes();
    // Yo
    await p.goto(B + '/dev/equipo?email=camila@estudiopampa.cl'); await p.waitForLoadState('networkidle');
    const nov = p.locator('text=Logo en alta resolución').first();
    if (await nov.count()) { await h.bajar(nov, 380); await h.a(nov, 0.3, 0.5, 800); await h.globo('Y a mí me avisa que llegó.'); await h.clic(nov, true); }
    const arch = p.locator('text=logo-panaderia-ruiz.png').first();
    if (await arch.count()) { await h.bajar(arch, 380); await h.a(arch, 0.5, 0.5, 800); }
    await h.globo('El logo queda guardado en la tarea.', 'La próxima vez, sé dónde buscarlo.', '', 300);
  },
});

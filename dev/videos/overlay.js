// Capa de demostración: cursor, clic, globo, archivo arrastrado y tarjetas a pantalla completa.
(() => {
  const FONT = window.__DM_FONT || '';
  const css = `
  @font-face{font-family:DM;src:url(${FONT}) format("woff2");font-weight:400 900}
  #dm-cur{position:fixed;left:0;top:0;width:28px;height:28px;z-index:2147483647;pointer-events:none;transition:transform var(--t,900ms) cubic-bezier(.45,.05,.25,1);filter:drop-shadow(0 2px 3px rgba(0,0,0,.35))}
  #dm-cur.oculto{opacity:0}
  #dm-cur.clic::after{content:"";position:absolute;left:-13px;top:-13px;width:28px;height:28px;border-radius:50%;border:3px solid #ff4b2b;animation:dmr .5s ease-out forwards}
  @keyframes dmr{from{transform:scale(.3);opacity:1}to{transform:scale(1.7);opacity:0}}
  #dm-globo{position:fixed;z-index:2147483646;pointer-events:none;max-width:400px;background:#141c40;color:#fff;font:700 23px/1.3 DM,system-ui,sans-serif;letter-spacing:-.005em;padding:18px 22px;border-radius:16px;box-shadow:0 18px 44px rgba(20,28,64,.38);opacity:0;transform:translateY(10px) scale(.96);transition:opacity .28s,transform .35s cubic-bezier(.2,1.3,.4,1)}
  #dm-globo.si{opacity:1;transform:none}
  #dm-globo small{display:block;font-weight:500;font-size:18px;line-height:1.35;color:#c7cdf0;margin-top:6px}
  #dm-globo em{font-style:normal;color:#ff8a6b}
  #dm-arch{position:fixed;left:0;top:0;z-index:2147483645;pointer-events:none;display:flex;gap:12px;align-items:center;background:#fff;border:2px solid #141c40;border-radius:12px;padding:10px 14px;font:700 18px DM,system-ui,sans-serif;color:#141c40;box-shadow:0 16px 34px rgba(0,0,0,.22);transition:transform 1100ms cubic-bezier(.45,.05,.25,1),opacity .3s}
  #dm-arch b{display:inline-grid;place-items:center;width:34px;height:42px;border-radius:5px;color:#fff;font-size:15px}
  #dm-arch .mini{width:44px;height:55px;border-radius:5px;background-size:cover;background-position:center}
  .dm-tarjeta{position:fixed;inset:0;z-index:2147483640;opacity:0;transition:opacity .5s;font-family:DM,system-ui,sans-serif;color:#141c40;
    background-color:#eceef4;background-image:linear-gradient(rgba(20,28,64,.05) 1px,transparent 1px),linear-gradient(90deg,rgba(20,28,64,.05) 1px,transparent 1px);background-size:22px 22px;border-bottom:10px solid #e8432f}
  .dm-tarjeta.si{opacity:1}
  .dm-tarjeta .logo{position:absolute;left:64px;top:64px;width:150px;color:#141c40}
  .dm-tarjeta .logo svg{width:100%;height:auto}
  .dm-tarjeta .pz{position:absolute;right:34px;bottom:44px;width:215px;opacity:.95}
  .dm-tarjeta.largo h1{font-size:58px;top:360px}
  .dm-tarjeta.largo .kick{top:315px}
  .dm-tarjeta .pz svg{width:100%;height:auto}
  .dm-tarjeta .diagrama-piezas .sombra ellipse{fill:rgba(40,50,100,.14)}
  .dm-tarjeta .diagrama-piezas .capa{transform:translateY(var(--dy))}
  .dm-tarjeta .kick{position:absolute;left:64px;top:330px;font-weight:700;font-size:20px;letter-spacing:.12em;text-transform:uppercase;color:#e8432f}
  .dm-tarjeta h1{position:absolute;left:64px;right:64px;top:375px;margin:0;font-size:72px;line-height:1.02;font-weight:800;letter-spacing:-.025em}
  .dm-tarjeta h1 em{font-style:normal;color:#e8432f}
  .dm-tarjeta .bajada{position:absolute;left:64px;right:330px;bottom:96px;margin:0;font-size:26px;line-height:1.35;color:#3b4366}
  .dm-tarjeta .chip{display:inline-block;background:#e8432f;color:#fff;font-weight:800;font-size:24px;padding:12px 20px;border-radius:8px}
  .dm-tarjeta .url{display:block;margin-top:18px;font-weight:800;font-size:40px;letter-spacing:-.01em}`;
  const arrow = '<svg viewBox="0 0 24 24" width="28" height="28"><path d="M4 2l15 11.5-6.8.9 3.9 7.4-3 1.5-3.8-7.6L4 20z" fill="#fff" stroke="#111" stroke-width="1.4" stroke-linejoin="round"/></svg>';
  const listo = () => {
    const st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);
    const c = document.createElement('div'); c.id = 'dm-cur'; c.innerHTML = arrow; document.body.appendChild(c);
    const g = document.createElement('div'); g.id = 'dm-globo'; document.body.appendChild(g);
    let pos = {x: 640, y: 760};
    try { pos = JSON.parse(sessionStorage.getItem('dm-pos')) || pos; } catch (e) {}
    c.style.setProperty('--t', '0ms'); c.style.transform = `translate(${pos.x}px,${pos.y}px)`;
    const tapa = sessionStorage.getItem('dm-tapa');
    if (tapa) { const t = document.createElement('div'); t.className = 'dm-tarjeta si'; t.id = 'dm-tapa'; t.innerHTML = tapa; document.body.appendChild(t); }
    window.__dm = {
      mover(x, y, ms) { c.style.setProperty('--t', ms + 'ms'); c.style.transform = `translate(${x}px,${y}px)`; pos = {x, y}; sessionStorage.setItem('dm-pos', JSON.stringify(pos)); },
      cursor(si) { c.classList.toggle('oculto', !si); },
      clic() { c.classList.remove('clic'); void c.offsetWidth; c.classList.add('clic'); },
      globo(html, lado) {
        g.innerHTML = html; g.classList.remove('si'); g.style.left = '-2000px'; const r = g.getBoundingClientRect();
        let x = pos.x + 26, y = pos.y + 30;
        if (lado === 'arriba') { y = pos.y - r.height - 22; }
        if (lado === 'izq') { x = pos.x - r.width - 18; }
        x = Math.max(18, Math.min(innerWidth - r.width - 18, x)); y = Math.max(18, Math.min(innerHeight - r.height - 18, y));
        g.style.left = x + 'px'; g.style.top = y + 'px'; requestAnimationFrame(() => g.classList.add('si'));
      },
      sinGlobo() { g.classList.remove('si'); },
      archivos(lista, x0, y0, x1, y1) {
        // lista: [{nombre, tipo:'docx'|'img', src}]
        lista.forEach((f, i) => {
          const a = document.createElement('div'); a.id = 'dm-arch'; a.className = 'dm-arch';
          a.innerHTML = (f.tipo === 'img' ? `<span class="mini" style="background-image:url(${f.src})"></span>` : `<b style="background:#2b5797">W</b>`) + f.nombre;
          a.style.transform = `translate(${x0 + i * 14}px,${y0 + i * 62}px)`; document.body.appendChild(a);
          setTimeout(() => { a.style.transform = `translate(${x1 + i * 10}px,${y1 + i * 58}px)`; }, 40 + i * 90);
        });
      },
      soltarArchivos() { document.querySelectorAll('.dm-arch').forEach(a => { a.style.opacity = '0'; }); },
      tarjeta(html, guardar, largo) {
        const t = document.createElement('div'); t.className = 'dm-tarjeta' + (largo ? ' largo' : ''); t.innerHTML = html; document.body.appendChild(t);
        if (guardar) { sessionStorage.setItem('dm-tapa', html); }
        requestAnimationFrame(() => requestAnimationFrame(() => t.classList.add('si')));
      },
      quitarTarjetas() { sessionStorage.removeItem('dm-tapa'); document.querySelectorAll('.dm-tarjeta').forEach(t => { t.classList.remove('si'); setTimeout(() => t.remove(), 600); }); },
    };
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', listo); else listo();
})();

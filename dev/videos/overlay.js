// Capa de demostración: cursor, clic y globo explicativo. Se inyecta en cada página.
(() => {
  const css = `
  #dm-cur{position:fixed;left:0;top:0;width:30px;height:30px;z-index:2147483647;pointer-events:none;transition:transform var(--t,900ms) cubic-bezier(.45,.05,.25,1);filter:drop-shadow(0 2px 3px rgba(0,0,0,.35))}
  #dm-cur.clic::after{content:"";position:absolute;left:-14px;top:-14px;width:30px;height:30px;border-radius:50%;border:3px solid #ff4b2b;animation:dmr .5s ease-out forwards}
  @keyframes dmr{from{transform:scale(.3);opacity:1}to{transform:scale(1.6);opacity:0}}
  #dm-globo{position:fixed;z-index:2147483646;pointer-events:none;max-width:380px;background:#141c40;color:#fff;font:600 22px/1.35 "Schibsted Grotesk",system-ui,sans-serif;padding:18px 24px;border-radius:18px;box-shadow:0 14px 40px rgba(20,28,64,.35);opacity:0;transform:translateY(8px) scale(.97);transition:opacity .3s,transform .3s}
  #dm-globo.si{opacity:1;transform:none}
  #dm-globo small{display:block;font-weight:500;font-size:17px;color:#c7cdf0;margin-top:6px}
  #dm-arch{position:fixed;z-index:2147483645;pointer-events:none;display:flex;gap:12px;align-items:center;background:#fff;border:2px solid #2b5797;border-radius:12px;padding:12px 16px;font:600 19px system-ui,sans-serif;color:#1a2140;box-shadow:0 14px 30px rgba(0,0,0,.2);transition:transform 1100ms cubic-bezier(.45,.05,.25,1)}
  #dm-arch b{display:inline-grid;place-items:center;width:38px;height:46px;background:#2b5797;color:#fff;border-radius:5px;font-size:20px}
  #dm-fin{position:fixed;inset:0;z-index:2147483647;background:#eceef4;display:grid;place-items:center;opacity:0;transition:opacity .6s}
  #dm-fin.si{opacity:1}`;
  const arrow = '<svg viewBox="0 0 24 24" width="30" height="30"><path d="M4 2l15 11.5-6.8.9 3.9 7.4-3 1.5-3.8-7.6L4 20z" fill="#fff" stroke="#111" stroke-width="1.4" stroke-linejoin="round"/></svg>';
  const listo = () => {
    const st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);
    const c = document.createElement('div'); c.id = 'dm-cur'; c.innerHTML = arrow; document.body.appendChild(c);
    const g = document.createElement('div'); g.id = 'dm-globo'; document.body.appendChild(g);
    let pos = {x: 540, y: 700};
    try { pos = JSON.parse(sessionStorage.getItem('dm-pos')) || pos; } catch (e) {}
    c.style.setProperty('--t', '0ms'); c.style.transform = `translate(${pos.x}px,${pos.y}px)`;
    window.__dm = {
      mover(x, y, ms) { c.style.setProperty('--t', ms + 'ms'); c.style.transform = `translate(${x}px,${y}px)`; pos = {x, y}; sessionStorage.setItem('dm-pos', JSON.stringify(pos)); },
      clic() { c.classList.remove('clic'); void c.offsetWidth; c.classList.add('clic'); },
      globo(html, lado) {
        g.innerHTML = html; g.classList.remove('si'); const r = g.getBoundingClientRect();
        let x = pos.x + 28, y = pos.y + 30;
        if (lado === 'arriba') { y = pos.y - r.height - 24; }
        if (lado === 'izq') { x = pos.x - r.width - 20; }
        x = Math.max(20, Math.min(innerWidth - r.width - 20, x)); y = Math.max(20, Math.min(innerHeight - r.height - 20, y));
        g.style.left = x + 'px'; g.style.top = y + 'px'; requestAnimationFrame(() => g.classList.add('si'));
      },
      sinGlobo() { g.classList.remove('si'); },
      archivo(nombre, x0, y0, x1, y1) {
        const a = document.createElement('div'); a.id = 'dm-arch'; a.innerHTML = '<b>W</b>' + nombre; a.style.left = '0'; a.style.top = '0';
        a.style.transform = `translate(${x0}px,${y0}px)`; document.body.appendChild(a);
        requestAnimationFrame(() => requestAnimationFrame(() => { a.style.transform = `translate(${x1}px,${y1}px)`; }));
      },
      fin(html) { const f = document.createElement('div'); f.id = 'dm-fin'; f.innerHTML = html; document.body.appendChild(f); requestAnimationFrame(() => f.classList.add('si')); },
    };
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', listo); else listo();
})();

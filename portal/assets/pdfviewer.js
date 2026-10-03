/* Portal de clientes — visor de PDF por páginas (PDF.js incluido en ./pdf/).
   Sirve para brandbooks, presentaciones exportadas a PDF y cualquier documento.
   Si el navegador no puede (muy antiguo) o el PDF falla, deja el enlace de descarga. */
let pdfjs = null;
try {
  pdfjs = await import('./pdf/pdf.min.js');
  pdfjs.GlobalWorkerOptions.workerSrc = new URL('./pdf/pdf.worker.min.js', import.meta.url).href;
} catch (e) { pdfjs = null; }

const $ = (s, c) => (c || document).querySelector(s);
const $$ = (s, c) => Array.from((c || document).querySelectorAll(s));

function esc(t) { return String(t).replace(/[&<>"]/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m])); }

function sinVisor(root, motivo) {
  const m = $('[data-pdf-mount]', root);
  m.innerHTML = '<p class="border-t border-line p-4 text-sm text-muted">' + esc(motivo) + ' Puedes abrir el archivo con el botón de arriba.</p>';
}

async function iniciar(root) {
  const mount = $('[data-pdf-mount]', root);
  if (!pdfjs) { sinVisor(root, 'Tu navegador no puede mostrar la vista por páginas.'); return; }

  let doc;
  try {
    doc = await pdfjs.getDocument({ url: root.dataset.src, withCredentials: true }).promise;
  } catch (e) { sinVisor(root, 'No se pudo abrir el PDF.'); return; }

  const total = doc.numPages;
  const conNotas = root.hasAttribute('data-comments');
  let cur = 1, zoom = 1, tarea = null, ticket = 0;

  mount.innerHTML =
    '<div class="relative border-t border-line bg-surface2">' +
      '<div data-stage class="flex h-[58vh] overflow-auto sm:h-[68vh]" style="scrollbar-width:thin"><div data-lienzo class="relative m-auto shrink-0"><canvas data-canvas class="block cursor-zoom-in bg-white shadow-soft" aria-label="Página del documento"></canvas></div></div>' +
      '<button type="button" data-prev class="absolute left-2 top-1/2 flex size-10 -translate-y-1/2 items-center justify-center rounded-full bg-black/60 text-white transition hover:bg-black/80 disabled:opacity-30" aria-label="Página anterior"><svg class="size-5"><use href="#i-back"/></svg></button>' +
      '<button type="button" data-next class="absolute right-2 top-1/2 flex size-10 -translate-y-1/2 items-center justify-center rounded-full bg-black/60 text-white transition hover:bg-black/80 disabled:opacity-30" aria-label="Página siguiente"><svg class="size-5"><use href="#i-chevron"/></svg></button>' +
    '</div>' +
    '<div class="flex flex-wrap items-center justify-between gap-2 border-t border-line px-3 py-2">' +
      '<p class="text-sm font-bold" aria-live="polite"><span data-count></span><span data-notas class="ml-2 text-xs font-semibold text-muted"></span></p>' +
      '<span class="flex items-center gap-1.5">' +
        (conNotas ? '<button type="button" data-marcar class="btn btn-soft btn-sm" aria-pressed="false"><svg class="size-4"><use href="#i-pin"/></svg> <span data-marcar-txt>Marcar un punto</span></button><button type="button" data-comentar class="btn btn-ghost btn-sm">Comentar esta página</button>' : '') +
        '<button type="button" data-full class="btn btn-ghost btn-sm w-9 px-0!" aria-label="Pantalla completa"><svg class="size-4"><use href="#i-zoom"/></svg></button>' +
      '</span>' +
    '</div>' +
    (total > 1 ? '<div data-thumbs class="flex gap-2 overflow-x-auto border-t border-line bg-surface p-2.5" role="tablist" aria-label="Páginas"></div>' : '');

  const stage = $('[data-stage]', root), canvas = $('[data-canvas]', root), lienzo = $('[data-lienzo]', root);
  let pines = [];
  try { pines = JSON.parse(root.dataset.pines || '[]'); } catch (e) { pines = []; }
  let marcando = false, resaltar = null, nuevo = null;

  // Pines de la página actual: % del ancho y alto de la página (el lienzo mide lo mismo que el canvas).
  function dibujarPines() {
    $$('.pin', lienzo).forEach((p) => p.remove());
    const lista = pines.filter((p) => +p.p === cur).map((p) => ({ ...p, cls: p.id === resaltar ? 'activo' : '' }));
    if (nuevo && nuevo.p === cur) lista.push({ ...nuevo, n: '+', cls: 'pin-nuevo' });
    lista.forEach((p) => {
      const el = document.createElement('span');
      el.className = 'pin ubicado ' + p.cls;
      if (p.id) el.dataset.pin = p.id;
      el.style.left = p.x + '%'; el.style.top = p.y + '%';
      el.textContent = p.n;
      lienzo.appendChild(el);
    });
    resaltar = null;
  }
  const ctx = canvas.getContext('2d');
  const btnPrev = $('[data-prev]', root), btnNext = $('[data-next]', root);
  const thumbs = $('[data-thumbs]', root);
  if (total < 2) { btnPrev.hidden = true; btnNext.hidden = true; }

  async function pintar() {
    const mio = ++ticket;
    const page = await doc.getPage(cur);
    if (mio !== ticket) return;
    const base = page.getViewport({ scale: 1 });
    const cs = getComputedStyle(stage);
    const w = stage.clientWidth - 24, h = stage.clientHeight - 24;
    const fit = Math.max(0.1, Math.min(w / base.width, h / base.height));
    const dpr = Math.min(window.devicePixelRatio || 1, 2);
    const vp = page.getViewport({ scale: fit * zoom * dpr });
    canvas.width = Math.floor(vp.width); canvas.height = Math.floor(vp.height);
    canvas.style.width = Math.floor(vp.width / dpr) + 'px'; canvas.style.height = Math.floor(vp.height / dpr) + 'px';
    if (tarea) { try { tarea.cancel(); } catch (e) { /* nada */ } }
    tarea = page.render({ canvasContext: ctx, viewport: vp });
    try { await tarea.promise; } catch (e) { /* cancelado por otra página */ }
    if (zoom === 1) { stage.scrollTop = 0; stage.scrollLeft = 0; }
    if (mio === ticket) dibujarPines();
  }

  function notasPorPagina() {
    const m = {};
    $$('[data-pag]').forEach((li) => { const p = +li.dataset.pag; if (p > 0) m[p] = (m[p] || 0) + 1; });
    return m;
  }

  function actualizar() {
    $('[data-count]', root).textContent = total > 1 ? 'Página ' + cur + ' de ' + total : 'Página 1';
    btnPrev.disabled = cur <= 1; btnNext.disabled = cur >= total;
    canvas.classList.toggle('cursor-zoom-in', zoom === 1); canvas.classList.toggle('cursor-zoom-out', zoom !== 1);
    const n = conNotas ? (notasPorPagina()[cur] || 0) : 0;
    $('[data-notas]', root).textContent = n ? '· ' + n + (n === 1 ? ' nota aquí' : ' notas aquí') : '';
    if (thumbs) {
      $$('[data-t]', thumbs).forEach((b) => {
        const on = +b.dataset.t === cur;
        b.setAttribute('aria-selected', on ? 'true' : 'false');
        b.classList.toggle('border-brand', on); b.classList.toggle('border-transparent', !on);
        if (on) { const x = b.offsetLeft - thumbs.clientWidth / 2 + b.clientWidth / 2; thumbs.scrollTo({ left: x, behavior: 'smooth' }); }
      });
    }
    pintar();
  }

  function ir(n) { n = Math.max(1, Math.min(total, n)); if (n === cur) return; cur = n; zoom = 1; actualizar(); }

  btnPrev.addEventListener('click', () => ir(cur - 1));
  btnNext.addEventListener('click', () => ir(cur + 1));
  canvas.addEventListener('click', (e) => {
    if (marcando) {
      const r = canvas.getBoundingClientRect();
      const x = Math.round((e.clientX - r.left) / r.width * 1000) / 10, y = Math.round((e.clientY - r.top) / r.height * 1000) / 10;
      nuevo = { p: cur, x, y };
      modoMarcar(false);
      dibujarPines();
      if (window.portalMarcar) window.portalMarcar('p' + cur + '@' + x + ',' + y, 'Punto en la página ' + cur);
      return;
    }
    zoom = zoom === 1 ? 2 : 1; actualizar();
  });
  function modoMarcar(on) {
    marcando = on;
    const b = $('[data-marcar]', root);
    if (!b) return;
    b.setAttribute('aria-pressed', on ? 'true' : 'false');
    $('[data-marcar-txt]', root).textContent = on ? 'Toca el punto · cancelar' : 'Marcar un punto';
    canvas.classList.toggle('cursor-crosshair', on);
    canvas.style.cursor = on ? 'crosshair' : '';
    lienzo.style.outline = on ? '3px dashed var(--warn)' : '';
  }
  root.setAttribute('tabindex', '0');
  root.addEventListener('keydown', (e) => {
    if (e.target.closest('textarea,input')) return;
    if (e.key === 'ArrowRight' || e.key === 'PageDown') { ir(cur + 1); e.preventDefault(); }
    else if (e.key === 'ArrowLeft' || e.key === 'PageUp') { ir(cur - 1); e.preventDefault(); }
    else if (e.key === 'Home') { ir(1); e.preventDefault(); }
    else if (e.key === 'End') { ir(total); e.preventDefault(); }
  });
  let x0 = null;
  stage.addEventListener('touchstart', (e) => { x0 = zoom === 1 && e.touches.length === 1 ? e.touches[0].clientX : null; }, { passive: true });
  stage.addEventListener('touchend', (e) => {
    if (x0 === null) return;
    const dx = e.changedTouches[0].clientX - x0; x0 = null;
    if (Math.abs(dx) > 55) ir(cur + (dx < 0 ? 1 : -1));
  });

  // Pantalla completa
  const btnFull = $('[data-full]', root);
  if (!root.requestFullscreen) btnFull.hidden = true;
  btnFull.addEventListener('click', () => { if (document.fullscreenElement) document.exitFullscreen(); else root.requestFullscreen(); });
  function ajustarAlto() {
    stage.style.height = document.fullscreenElement === root ? 'calc(100vh - ' + (thumbs ? '9.5rem' : '4rem') + ')' : '';
    zoom = 1; actualizar();
  }
  document.addEventListener('fullscreenchange', () => { if (document.fullscreenElement === root || !document.fullscreenElement) ajustarAlto(); });
  let rt = null;
  window.addEventListener('resize', () => { clearTimeout(rt); rt = setTimeout(pintar, 150); });

  // Miniaturas (se dibujan al aparecer)
  if (thumbs) {
    const io = new IntersectionObserver((es) => {
      es.forEach(async (en) => {
        if (!en.isIntersecting) return;
        io.unobserve(en.target);
        const cv = $('canvas', en.target); const p = await doc.getPage(+en.target.dataset.t);
        const v0 = p.getViewport({ scale: 1 }); const s = 96 / v0.width; const v = p.getViewport({ scale: s * 2 });
        cv.width = Math.floor(v.width); cv.height = Math.floor(v.height); cv.style.width = '96px'; cv.style.height = Math.floor(v.height / 2) + 'px';
        p.render({ canvasContext: cv.getContext('2d'), viewport: v });
      });
    }, { root: thumbs, rootMargin: '0px 200px' });
    for (let i = 1; i <= total; i++) {
      const b = document.createElement('button');
      b.type = 'button'; b.dataset.t = i; b.setAttribute('role', 'tab'); b.setAttribute('aria-label', 'Ir a la página ' + i);
      b.className = 'relative flex-none overflow-hidden rounded-lg border-2 border-transparent bg-white transition hover:border-brand/50';
      b.innerHTML = '<canvas class="block" style="width:96px;height:68px"></canvas><span class="absolute bottom-1 left-1 rounded bg-black/65 px-1.5 text-[10px] font-bold leading-4 text-white">' + i + '</span><span data-badge hidden class="absolute right-1 top-1 min-w-4 rounded-full bg-brand px-1 text-center text-[10px] font-bold leading-4 text-brand-fg"></span>';
      b.addEventListener('click', () => ir(i));
      thumbs.appendChild(b); io.observe(b);
    }
  }

  function insignias() {
    if (!thumbs) return;
    const m = notasPorPagina();
    $$('[data-t]', thumbs).forEach((b) => { const s = $('[data-badge]', b); const n = m[+b.dataset.t] || 0; s.hidden = !n; s.textContent = n; });
  }

  // Notas por página: se conecta con el formulario de comentarios
  if (conNotas) {
    $('[data-comentar]', root).addEventListener('click', () => {
      nuevo = null; dibujarPines();
      if (window.portalMarcar) window.portalMarcar('p' + cur, 'Sobre la página ' + cur);
    });
    $('[data-marcar]', root).addEventListener('click', () => { modoMarcar(!marcando); if (marcando) zoom = 1; });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && marcando) modoMarcar(false); });
    document.addEventListener('click', (e) => {
      if (e.target.closest('[data-pag-clear]')) { nuevo = null; dibujarPines(); }
      const g = e.target.closest('[data-goto-pag]'); if (!g) return;
      resaltar = g.getAttribute('data-goto-pin');
      const p = +g.dataset.gotoPag;
      if (p === cur) dibujarPines(); else ir(p);
      root.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
    insignias();
  }

  actualizar();
}

$$('[data-pdf]').forEach(iniciar);

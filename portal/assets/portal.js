/* Portal de clientes — comportamiento mínimo, sin dependencias.
   Todo funciona sin JS (formularios normales); esto sólo mejora la experiencia. */
(function () {
  'use strict';

  function $(sel, ctx) { return (ctx || document).querySelector(sel); }
  function $$(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }
  function csrf() { var m = $('meta[name="csrf"]'); return m ? m.getAttribute('content') : ''; }

  function tamano(b) {
    if (b < 1024) return b + ' B';
    if (b < 1048576) return Math.round(b / 1024) + ' KB';
    return (b / 1048576).toFixed(1).replace('.', ',') + ' MB';
  }

  /* ---- Tema claro / oscuro (se guarda en la cuenta del contacto) ---- */
  $$('[data-tema-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var h = document.documentElement;
      var nuevo = h.classList.contains('dark') ? 'claro' : 'oscuro';
      h.setAttribute('data-tema', nuevo);
      h.classList.toggle('dark', nuevo === 'oscuro');
      var fd = new FormData();
      fd.append('_csrf', csrf());
      fd.append('tema', nuevo);
      fetch('/portal/ajustes/tema', { method: 'POST', body: fd, credentials: 'same-origin' }).catch(function () {});
    });
  });

  /* ---- Textareas que crecen; Ctrl/Cmd+Enter envía ---- */
  $$('textarea[data-autogrow]').forEach(function (ta) {
    function ajustar() { ta.style.height = 'auto'; ta.style.height = Math.min(ta.scrollHeight + 2, 320) + 'px'; }
    ta.addEventListener('input', ajustar);
    ta.addEventListener('keydown', function (e) {
      if ((e.ctrlKey || e.metaKey) && e.key === 'Enter' && ta.form) {
        e.preventDefault();
        if (ta.form.requestSubmit) ta.form.requestSubmit(); else ta.form.submit();
      }
    });
    ajustar();
  });

  /* ---- Confirmaciones ---- */
  $$('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      if (!window.confirm(f.getAttribute('data-confirm'))) e.preventDefault();
    });
  });

  /* ---- Evitar doble envío ---- */
  $$('form[data-once]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      if (e.defaultPrevented) return;
      var b = e.submitter || $('button[type="submit"]', f);
      if (f.getAttribute('data-sent') === '1') { e.preventDefault(); return; }
      f.setAttribute('data-sent', '1');
      if (b) {
        // El botón con name/value debe viajar en el POST: lo copiamos a un hidden antes de deshabilitar.
        if (b.name) {
          var h = document.createElement('input');
          h.type = 'hidden'; h.name = b.name; h.value = b.value; f.appendChild(h);
        }
        var txt = b.getAttribute('data-sending');
        setTimeout(function () {
          $$('button[type="submit"]', f).forEach(function (x) { x.disabled = true; });
          if (txt) b.textContent = txt;
        }, 0);
      }
    });
  });

  /* ---- Código de acceso: sólo dígitos y envío automático al completar 6 ---- */
  $$('input[data-otp]').forEach(function (inp) {
    inp.addEventListener('input', function () {
      inp.value = inp.value.replace(/\D+/g, '').slice(0, 6);
      if (inp.value.length === 6 && inp.form) {
        if (inp.form.requestSubmit) inp.form.requestSubmit(); else inp.form.submit();
      }
    });
  });

  /* ---- "Pedir cambios" exige comentario ---- */
  $$('form[data-revision]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      var b = e.submitter;
      var ta = $('textarea[name="cuerpo"]', f);
      if (b && b.value === 'cambios' && ta && !ta.value.trim()) {
        e.preventDefault();
        f.removeAttribute('data-sent');
        ta.focus();
        ta.classList.add('ring-2');
        var aviso = $('[data-cambios-aviso]', f);
        if (aviso) aviso.hidden = false;
      }
    }, true);
  });

  /* ---- Subida de archivos: arrastrar y soltar, lista, barra de progreso ---- */
  $$('form[data-upload]').forEach(function (form) {
    var input = $('input[type="file"]', form);
    var zone = $('[data-dropzone]', form);
    var list = $('[data-filelist]', form);
    var btn = $('[data-upload-btn]', form);
    var bar = $('[data-progress]', form);
    var barFill = bar ? bar.firstElementChild : null;
    var msg = $('[data-upload-msg]', form);
    var maxArchivo = parseInt(form.getAttribute('data-max'), 10) * 1048576 || 0;
    var maxTotal = 10;
    var canDT = true;
    var seleccion = [];

    try { new DataTransfer(); } catch (e) { canDT = false; }

    function aviso(t) { if (msg) { msg.textContent = t || ''; msg.hidden = !t; } }

    function sync() {
      if (canDT) {
        var dt = new DataTransfer();
        seleccion.forEach(function (f) { dt.items.add(f); });
        input.files = dt.files;
      }
      list.innerHTML = '';
      seleccion.forEach(function (f, i) {
        var li = document.createElement('li');
        li.className = 'flex items-center gap-3 rounded-xl border border-line bg-surface px-3 py-2 text-sm';
        var n = document.createElement('span');
        n.className = 'min-w-0 flex-1 truncate font-medium';
        n.textContent = f.name;
        var s = document.createElement('span');
        s.className = 'text-xs text-muted flex-none';
        s.textContent = tamano(f.size);
        var x = document.createElement('button');
        x.type = 'button'; x.className = 'btn btn-danger-ghost btn-sm flex-none'; x.setAttribute('aria-label', 'Quitar ' + f.name);
        x.textContent = 'Quitar';
        x.addEventListener('click', function () { seleccion.splice(i, 1); sync(); });
        li.appendChild(n); li.appendChild(s); li.appendChild(x);
        list.appendChild(li);
      });
      if (btn) btn.disabled = seleccion.length === 0;
    }

    function agregar(files) {
      aviso('');
      var rechazados = [];
      Array.prototype.forEach.call(files, function (f) {
        if (maxArchivo && f.size > maxArchivo) { rechazados.push(f.name + ' (pesa ' + tamano(f.size) + ')'); return; }
        if (seleccion.length >= maxTotal) return;
        seleccion.push(f);
      });
      if (rechazados.length) aviso('No se agregaron por superar el máximo de ' + tamano(maxArchivo) + ': ' + rechazados.join(', '));
      sync();
    }

    input.addEventListener('change', function () {
      if (!canDT) { seleccion = Array.prototype.slice.call(input.files); sync(); return; }
      agregar(input.files);
    });

    if (zone) {
      ['dragenter', 'dragover'].forEach(function (ev) {
        zone.addEventListener(ev, function (e) { e.preventDefault(); zone.classList.add('is-over'); });
      });
      ['dragleave', 'drop'].forEach(function (ev) {
        zone.addEventListener(ev, function (e) { e.preventDefault(); zone.classList.remove('is-over'); });
      });
      zone.addEventListener('drop', function (e) {
        if (canDT && e.dataTransfer && e.dataTransfer.files) agregar(e.dataTransfer.files);
      });
    }

    form.addEventListener('submit', function (e) {
      if (!window.XMLHttpRequest || !window.FormData || seleccion.length === 0 && canDT) {
        if (seleccion.length === 0 && canDT) { e.preventDefault(); aviso('Elige al menos un archivo.'); }
        return;
      }
      e.preventDefault();
      aviso('');
      if (btn) { btn.disabled = true; btn.setAttribute('data-txt', btn.textContent); btn.textContent = 'Subiendo…'; }
      if (bar) bar.hidden = false;

      var xhr = new XMLHttpRequest();
      xhr.open('POST', form.getAttribute('action'));
      xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      xhr.upload.addEventListener('progress', function (ev) {
        if (ev.lengthComputable && barFill) {
          var p = Math.round(ev.loaded * 100 / ev.total);
          barFill.style.width = p + '%';
          bar.setAttribute('aria-valuenow', String(p));
        }
      });
      xhr.addEventListener('load', function () {
        var destino = null;
        try { destino = JSON.parse(xhr.responseText).redirect; } catch (err) { /* respuesta inesperada */ }
        if (destino) {
          // Mismo path con otro #hash no recarga: forzamos la recarga para ver los archivos nuevos.
          history.replaceState(null, '', destino);
          location.reload();
        } else {
          fallo('El servidor respondió algo inesperado. Recarga la página y revisa si se subió.');
        }
      });
      xhr.addEventListener('error', function () { fallo('Se cortó la conexión. Inténtalo de nuevo.'); });
      xhr.send(new FormData(form));

      function fallo(t) {
        aviso(t);
        if (bar) bar.hidden = true;
        if (btn) { btn.disabled = false; btn.textContent = btn.getAttribute('data-txt') || 'Subir'; }
      }
    });

    sync();
  });

  /* ---- Carrusel (scroll-snap): puntos, contador y flechas ---- */
  $$('[data-carousel]').forEach(function (c) {
    var track = $('[data-track]', c);
    var slides = track ? track.children : [];
    var box = c.parentNode;
    var dots = $$('[data-dot]', box);
    var counter = $('[data-counter]', c);
    var prev = $('[data-prev]', c), next = $('[data-next]', c);
    if (!track || slides.length < 2) return;
    function actual() { return Math.round(track.scrollLeft / track.clientWidth); }
    function pintar() {
      var i = Math.max(0, Math.min(slides.length - 1, actual()));
      dots.forEach(function (d, k) { if (k === i) d.setAttribute('data-on', ''); else d.removeAttribute('data-on'); });
      if (counter) counter.textContent = (i + 1) + '/' + slides.length;
    }
    function ir(i) { track.scrollTo({ left: Math.max(0, Math.min(slides.length - 1, i)) * track.clientWidth, behavior: 'smooth' }); }
    track.addEventListener('scroll', function () { window.requestAnimationFrame(pintar); }, { passive: true });
    if (prev) prev.addEventListener('click', function () { ir(actual() - 1); });
    if (next) next.addEventListener('click', function () { ir(actual() + 1); });
    pintar();
  });

  /* ---- Zoom de imágenes (lightbox): grupo con flechas, clic para acercar ---- */
  (function () {
    var lb = $('[data-lightbox]');
    if (!lb) return;
    var img = $('[data-lb-img]', lb), stage = $('[data-lb-stage]', lb);
    var titulo = $('[data-lb-title]', lb), cuenta = $('[data-lb-count]', lb);
    var bprev = $('[data-lb-prev]', lb), bnext = $('[data-lb-next]', lb);
    var lista = [], pos = 0, zoom = false, ultimo = null;

    function mostrar(i) {
      pos = (i + lista.length) % lista.length;
      var a = lista[pos];
      img.src = a.getAttribute('href');
      img.alt = a.getAttribute('data-zoom-alt') || '';
      titulo.textContent = img.alt;
      cuenta.textContent = lista.length > 1 ? (pos + 1) + ' / ' + lista.length : '';
      bprev.hidden = bnext.hidden = lista.length < 2;
      reset();
    }
    function reset() {
      zoom = false;
      img.style.maxWidth = ''; img.style.maxHeight = ''; img.style.width = '';
      img.classList.add('max-h-full', 'max-w-full'); img.style.cursor = 'zoom-in';
      stage.scrollTo(0, 0);
    }
    function abrir(a) {
      var g = a.getAttribute('data-zoom-group');
      lista = g ? $$('[data-zoom][data-zoom-group="' + g + '"]') : [a];
      ultimo = a;
      lb.hidden = false;
      document.documentElement.style.overflow = 'hidden';
      mostrar(lista.indexOf(a));
      $('[data-lb-close]', lb).focus();
    }
    function cerrar() {
      lb.hidden = true;
      document.documentElement.style.overflow = '';
      img.removeAttribute('src');
      if (ultimo) ultimo.focus();
    }
    $$('[data-zoom]').forEach(function (a) {
      a.addEventListener('click', function (e) { e.preventDefault(); abrir(a); });
    });
    img.addEventListener('click', function (e) {
      if (!zoom) {
        // Acerca al doble del tamaño natural, centrado donde se tocó.
        var r = img.getBoundingClientRect();
        var fx = (e.clientX - r.left) / r.width, fy = (e.clientY - r.top) / r.height;
        zoom = true;
        img.classList.remove('max-h-full', 'max-w-full');
        img.style.maxWidth = 'none'; img.style.maxHeight = 'none';
        img.style.width = Math.max(r.width * 2.4, stage.clientWidth * 2) + 'px';
        img.style.cursor = 'zoom-out';
        var r2 = img.getBoundingClientRect();
        stage.scrollTo(Math.max(0, fx * r2.width - stage.clientWidth / 2 + (stage.scrollLeft)), Math.max(0, fy * r2.height - stage.clientHeight / 2));
      } else {
        reset();
      }
    });
    $('[data-lb-close]', lb).addEventListener('click', cerrar);
    bprev.addEventListener('click', function () { mostrar(pos - 1); });
    bnext.addEventListener('click', function () { mostrar(pos + 1); });
    lb.addEventListener('click', function (e) { if (e.target === lb || e.target === stage) cerrar(); });
    document.addEventListener('keydown', function (e) {
      if (lb.hidden) return;
      if (e.key === 'Escape') cerrar();
      else if (e.key === 'ArrowLeft' && lista.length > 1) mostrar(pos - 1);
      else if (e.key === 'ArrowRight' && lista.length > 1) mostrar(pos + 1);
    });
  })();

  /* ---- Reproductores incrustados (Drive): se escalan para caber completos en el marco ---- */
  function ajustarMarcos() {
    $$('[data-fit-frame]').forEach(function (f) {
      var caja = f.parentElement; if (!caja) return;
      var k = caja.clientWidth / parseFloat(f.getAttribute('data-w') || '1');
      f.style.transform = 'scale(' + k + ')';
    });
  }
  ajustarMarcos();
  window.addEventListener('resize', ajustarMarcos);
})();

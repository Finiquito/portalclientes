(function () {
  var D = JSON.parse(document.getElementById('onb-datos').textContent);
  var CSRF = document.querySelector('meta[name="csrf"]').getAttribute('content');
  var NOMBRES = [__t('Tu agencia'), __t('Tu cliente'), __t('Su proyecto'), __t('Tu equipo'), __t('Listo')];
  var COLORES = D.colores;
  var PLANTILLAS = D.plantillas.concat([{ id: 'blanco', nombre: __t('Empezar en blanco'), etapas: __t('Tú defines las etapas') }]);
  var CHECK = '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M4.5 10.5l3.5 3.5 7.5-8"/></svg>';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var estado = D.estado, logo = D.logo || '', ocupado = false;

  // Texto sobre el color de marca: el de mayor contraste (blanco o casi negro), la misma regla del plugin.
  function lin(c) { c /= 255; return c <= 0.04045 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4); }
  function lum(h) { var n = parseInt(h.slice(1), 16); return 0.2126 * lin(n >> 16 & 255) + 0.7152 * lin(n >> 8 & 255) + 0.0722 * lin(n & 255); }
  function contraste(a, b) { var x = lum(a), y = lum(b); if (x < y) { var t = x; x = y; y = t; } return (x + 0.05) / (y + 0.05); }
  function textoSobre(h) { return contraste('#ffffff', h) >= contraste('#12131a', h) ? '#ffffff' : '#12131a'; }

  // --- guía de pasos, colores y plantillas
  var ol = $('#pasos');
  NOMBRES.forEach(function (n, i) {
    var li = document.createElement('li');
    li.innerHTML = '<button type="button" data-ir="' + (i + 1) + '"><span class="n">' + (i + 1) + '</span><span class="t">' + n + '</span></button>';
    ol.appendChild(li);
  });
  ol.addEventListener('click', function (e) { var b = e.target.closest('button[data-ir]'); if (b && !b.disabled) ir(+b.dataset.ir); });
  var cont = $('#colores');
  COLORES.forEach(function (c, i) {
    cont.insertAdjacentHTML('beforeend', '<span><input type="radio" name="color" id="c' + i + '" value="' + c[0] + '"><label for="c' + i + '" style="--c:' + c[0] + ';--cf:' + textoSobre(c[0]) + '" title="' + c[1] + '"><span style="position:absolute;width:1px;height:1px;overflow:hidden">' + c[1] + '</span></label></span>');
  });
  var pl = $('#plantillas');
  PLANTILLAS.forEach(function (p) {
    var d = document.createElement('div'); d.className = 'plantilla';
    d.innerHTML = '<input type="radio" name="plantilla"><label><b></b><span class="etapas"></span><span class="radio"></span></label>';
    var inp = d.querySelector('input'), lab = d.querySelector('label');
    inp.id = 'p-' + p.id; inp.value = p.id; lab.setAttribute('for', inp.id);
    lab.querySelector('b').textContent = p.nombre; lab.querySelector('.etapas').textContent = p.etapas;
    pl.appendChild(d);
  });
  if (!D.correoPrisma && !D.iaPrueba) { $('#etiquetas').hidden = true; }
  $('#etCorreo').hidden = !D.correoPrisma; $('#etIA').hidden = !D.iaPrueba;
  $('#horas').textContent = D.horas;

  // Idioma del primer cliente: por defecto el de la agencia; si elige otro, su portal y sus correos salen en ese.
  var IDIOMAS = D.idiomas || [];
  function nombreIdioma(c) { var x = IDIOMAS.filter(function (i) { return i[0] === c; })[0]; return x ? x[1] : c; }
  var selI = $('#idiomaCliente');
  if (selI) {
    selI.innerHTML = '<option value="">' + __t('Igual que mi agencia') + ' (' + nombreIdioma(D.idiomaAgencia) + ')</option>' + IDIOMAS.map(function (i) { return '<option value="' + i[0] + '">' + i[1] + '</option>'; }).join('');
  }

  // --- pintar estado
  document.querySelectorAll('[data-k]').forEach(function (el) { el.value = estado[el.dataset.k] || ''; });
  function pintarColor() {
    document.documentElement.style.setProperty('--acento', estado.color);
    document.documentElement.style.setProperty('--acento-fg', textoSobre(estado.color));
    document.querySelectorAll('#colores input').forEach(function (i) { i.checked = i.value === estado.color; });
  }
  function pintarPlantilla() { document.querySelectorAll('#plantillas input').forEach(function (i) { i.checked = i.value === estado.plantilla; }); }
  function pintarVista() {
    var n = estado.agencia.trim() || __t('Tu agencia'), ch = $('#vChapa');
    $('#vNombre').textContent = n;
    if (logo) { ch.innerHTML = '<img alt="" src="' + logo + '">'; } else { ch.textContent = (n[0] || 'E').toUpperCase(); }
    if (logo) { $('#icoLogo').innerHTML = '<img alt="" src="' + logo + '">'; $('#logoNombre').textContent = __t('Cambiar logo'); }
  }
  function pintarEquipo() {
    var c = $('#chips'); c.innerHTML = '';
    estado.equipo.forEach(function (m, i) {
      var s = document.createElement('span'); s.className = 'chip';
      s.innerHTML = '<span></span><button type="button" aria-label="' + __t('Quitar') + ' ' + m.replace(/[<>&"]/g, '') + '">×</button>';
      s.firstChild.textContent = m;
      s.lastChild.onclick = function () { estado.equipo.splice(i, 1); guardar(); pintarEquipo(); };
      c.appendChild(s);
    });
  }
  function pintarTu() { var n = estado.tuNombre.trim() || __t('Tú'); $('#avTu').textContent = (n[0] || 'T').toUpperCase(); $('#tuLinea').textContent = n; }
  function pintarInvitar() {
    var primer = (estado.contacto.trim().split(' ')[0]) || __t('el contacto');
    $('#invTit').textContent = __t('Invitar a %s al portal').replace('%s', primer);
    $('#invitar').checked = !!estado.invitar;
    $('#invTxt').hidden = !estado.invitar; $('#invNo').hidden = !!estado.invitar;
  }
  function resumen() {
    var pn = PLANTILLAS.filter(function (p) { return p.id === estado.plantilla; })[0] || PLANTILLAS[PLANTILLAS.length - 1];
    var et = pn.id === 'blanco' ? __t('sin etapas todavía') : __t('%d etapas').replace('%d', pn.etapas.split(' · ').length);
    $('#nomFin').textContent = (estado.tuNombre.trim().split(' ')[0]) || '';
    var li = function () { return '<li>' + CHECK + '<div><b></b><span></span></div></li>'; };
    var r = $('#resumen'); r.innerHTML = li() + li() + li() + li();
    var datos = [
      [estado.agencia.trim(), __t('Tú quedas como Coordinación general')],
      [estado.cliente.trim(), __t('Contacto: ') + estado.contacto.trim() + (estado.correo.trim() ? ' · ' + estado.correo.trim() : '') + (estado.idiomaCliente ? ' · ' + __t('Portal en %s').replace('%s', nombreIdioma(estado.idiomaCliente)) : '')],
      [estado.proyecto.trim(), (pn.id === 'blanco' ? __t('En blanco') : __t('Plantilla %s').replace('%s', pn.nombre)) + ' · ' + et + ' · ' + __t('a tu cargo')],
      [__t('Equipo'), estado.equipo.length ? (estado.equipo.length === 1 ? __t('1 invitado: %s') : __t('%d invitados: %s').replace('%d', estado.equipo.length)).replace('%s', estado.equipo.join(', ')) : __t('Lo invitas cuando quieras, desde Equipo')]
    ];
    r.querySelectorAll('li').forEach(function (f, i) { f.querySelector('b').textContent = datos[i][0]; f.querySelector('span').textContent = datos[i][1]; });
    pintarInvitar();
  }

  // --- guardado en el servidor (cada cambio, con un respiro de medio segundo)
  var tG, tH, tR;
  function cuerpo(extra) {
    var d = {}; ['tuNombre', 'agencia', 'color', 'cliente', 'contacto', 'correo', 'proyecto', 'plantilla', 'equipo', 'invitar', 'idiomaCliente', 'paso'].forEach(function (k) { d[k] = estado[k]; });
    var fd = new FormData(); fd.append('_csrf', CSRF); fd.append('datos', JSON.stringify(Object.assign(d, extra || {}))); return fd;
  }
  function estadoGuardado(t, ver) { var g = $('#guardado'); $('#guardadoTxt').textContent = t; g.classList.toggle('ver', ver); }
  function enviar() {
    estadoGuardado(__t('Guardando…'), true);
    return fetch('/equipo/bienvenida/guardar', { method: 'POST', body: cuerpo(), credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { return { r: r, j: j }; }); })
      .then(function (x) {
        if (x.j && x.j.ok) { estadoGuardado(__t('Guardado'), true); clearTimeout(tH); tH = setTimeout(function () { estadoGuardado(__t('Guardado'), false); }, 1800); return true; }
        estadoGuardado(__t('No se pudo guardar'), true); aviso((x.j && x.j.error) || __t('No se pudo guardar. Revisa tu conexión.')); return false;
      })
      .catch(function () { estadoGuardado(__t('Sin conexión · reintentando'), true); clearTimeout(tR); tR = setTimeout(enviar, 4000); return false; });
  }
  function guardar() { clearTimeout(tG); estadoGuardado(__t('Guardando…'), true); tG = setTimeout(enviar, 500); }
  document.querySelectorAll('[data-k]').forEach(function (el) {
    el.addEventListener('input', function () {
      estado[el.dataset.k] = el.value; el.removeAttribute('aria-invalid'); var e = $('#e-' + el.id); if (e) e.classList.remove('ver');
      if (el.id === 'agencia') pintarVista(); if (el.id === 'tuNombre') pintarTu(); if (el.id === 'contacto') pintarInvitar(); guardar();
    });
  });
  $('#colores').addEventListener('change', function (e) { estado.color = e.target.value; pintarColor(); guardar(); });
  $('#plantillas').addEventListener('change', function (e) { estado.plantilla = e.target.value; guardar(); });
  $('#invitar').addEventListener('change', function (e) { estado.invitar = e.target.checked; pintarInvitar(); guardar(); });
  $('#subir').onclick = function () { $('#archivoLogo').click(); };
  $('#archivoLogo').onchange = function (e) {
    var f = e.target.files[0]; if (!f) return;
    var fd = new FormData(); fd.append('_csrf', CSRF); fd.append('logo', f); estadoGuardado(__t('Subiendo logo…'), true);
    fetch('/equipo/bienvenida/logo', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
      if (j.ok) { logo = j.url; pintarVista(); estadoGuardado(__t('Guardado'), true); setTimeout(function () { estadoGuardado(__t('Guardado'), false); }, 1800); }
      else { estadoGuardado(__t('No se pudo subir'), true); aviso(j.error || __t('No se pudo subir el logo.')); }
    }).catch(function () { aviso(__t('No se pudo subir el logo. Revisa tu conexión.')); });
  };
  function agregar() {
    var i = $('#miembro'), v = i.value.trim().toLowerCase(), e = $('#e-miembro');
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(v)) { e.classList.add('ver'); i.setAttribute('aria-invalid', 'true'); i.focus(); return; }
    e.classList.remove('ver'); i.removeAttribute('aria-invalid');
    if (estado.equipo.indexOf(v) < 0) estado.equipo.push(v);
    i.value = ''; pintarEquipo(); guardar(); i.focus();
  }
  $('#agregar').onclick = agregar;
  $('#miembro').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); e.stopPropagation(); if (this.value.trim()) agregar(); else siguiente(); } });

  // --- navegación
  function marcar(id, ok) {
    var el = $('#' + id); if (!el) return;
    if (ok) el.removeAttribute('aria-invalid'); else el.setAttribute('aria-invalid', 'true');
    var e = $('#e-' + id); if (e) e.classList.toggle('ver', !ok);
  }
  function validar(n) {
    var reglas = { 1: ['tuNombre', 'agencia'], 2: ['cliente', 'contacto', 'correo'], 3: ['proyecto'] }[n] || [], primero = null;
    reglas.forEach(function (id) {
      var v = $('#' + id).value.trim(), ok = v.length > 0 && (id !== 'correo' || /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(v));
      marcar(id, ok); if (!ok && !primero) primero = $('#' + id);
    });
    if (primero) { primero.focus(); return false; } return true;
  }
  var primeraVez = true;
  function ir(n, atras) {
    estado.paso = n; estado.max = Math.max(estado.max, n);
    document.querySelectorAll('.paso').forEach(function (s) { s.classList.remove('activo', 'atras'); });
    var s = $('.paso[data-paso="' + n + '"]'); s.classList.add('activo'); if (atras) s.classList.add('atras');
    document.querySelectorAll('#pasos button').forEach(function (b, i) {
      var k = i + 1; b.removeAttribute('aria-current'); b.classList.toggle('hecho', k < n);
      b.disabled = k > estado.max; if (k === n) b.setAttribute('aria-current', 'step');
      b.querySelector('.n').innerHTML = (k < n) ? CHECK : String(k);
      b.setAttribute('aria-label', NOMBRES[i] + (k < n ? __t(', completado') : ''));
    });
    $('#barra').style.width = (n / 5 * 100) + '%';
    $('#atras').hidden = n === 1; $('#omitir').hidden = n !== 4;
    $('#sigTxt').textContent = n === 5 ? __t('Ir a mi panel') : __t('Continuar');
    $('#centro').textContent = n === 5 ? '' : __t('Paso %d de 5 · se guarda solo').replace('%d', n);
    if (n === 5) resumen(); if (n === 4) pintarTu();
    if (!primeraVez) { enviar(); var f = s.querySelector('input:not([type=radio]):not([type=checkbox])'); if (f && window.innerWidth > 720) setTimeout(function () { f.focus({ preventScroll: true }); }, 60); }
    primeraVez = false; window.scrollTo(0, 0);
  }
  function finalizar() {
    if (ocupado) return; ocupado = true; var b = $('#sig'); b.disabled = true; $('#sigTxt').textContent = __t('Armando tu agencia…');
    enviar().then(function () { return fetch('/equipo/bienvenida/listo', { method: 'POST', body: cuerpo(), credentials: 'same-origin' }); })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j.ok) { location.href = j.redirect; return; }
        ocupado = false; b.disabled = false; $('#sigTxt').textContent = __t('Ir a mi panel');
        if (j.paso) { ir(j.paso, true); if (j.campo) setTimeout(function () { marcar(j.campo, false); var e = $('#e-' + j.campo); if (e && j.error) e.textContent = j.error; var el = $('#' + j.campo); if (el) el.focus(); }, 80); }
        else aviso(j.error || __t('No pudimos terminar. Inténtalo de nuevo.'));
      })
      .catch(function () { ocupado = false; b.disabled = false; $('#sigTxt').textContent = __t('Ir a mi panel'); aviso(__t('No hay conexión. Inténtalo de nuevo.')); });
  }
  function siguiente() { var n = estado.paso; if (n === 5) { finalizar(); return; } if (!validar(n)) return; ir(n + 1); }
  $('#sig').onclick = siguiente;
  $('#atras').onclick = function () { if (estado.paso > 1) ir(estado.paso - 1, true); };
  $('#omitir').onclick = function () { ir(5); };
  document.addEventListener('keydown', function (e) { if (e.key === 'Enter' && e.target.tagName === 'INPUT' && e.target.type !== 'checkbox' && e.target.type !== 'radio' && e.target.id !== 'miembro') { e.preventDefault(); siguiente(); } });
  var tT; function aviso(t) { var el = $('#toast'); el.textContent = t; el.classList.add('ver'); clearTimeout(tT); tT = setTimeout(function () { el.classList.remove('ver'); }, 3600); }
  $('#salir').onclick = function () {
    enviar().then(function () { var fd = new FormData(); fd.append('_csrf', CSRF); return fetch('/equipo/bienvenida/omitir', { method: 'POST', body: fd, credentials: 'same-origin' }); })
      .then(function (r) { return r.json(); }).then(function (j) { location.href = j.redirect || '/equipo'; }).catch(function () { location.href = '/equipo'; });
  };

  pintarColor(); pintarPlantilla(); pintarVista(); pintarEquipo(); pintarTu(); pintarInvitar();
  ir(estado.paso);
})();

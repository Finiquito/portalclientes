/* Gráfico de evolución de los contactos ganados (Chart.js, en chart.umd.js).
 * Dos partes alineadas por fecha, dentro de <div data-grafico='{...}'>:
 *   - Volumen (canvas[data-g=vol]): dos líneas acumuladas, Llegaron y Contactados, con un punto por día
 *     (por semana en rangos largos). Al pasar el cursor, o tocar, se ven los valores y los hitos de ese día.
 *   - Hitos (canvas[data-g=hitos]): calificados, ganados y perdidos como marcas en carriles separados, uno por
 *     día; si hay varios ese día, la marca crece y lleva el número. No son una línea: son hechos puntuales.
 * Los colores salen de las variables del portal y del modo claro u oscuro. En pantallas angostas se simplifica:
 * menos números, sin puntos en las líneas (salvo al tocar) y los nombres de los carriles pasan a una leyenda.
 * Si Chart.js no cargó, la página sigue funcionando (sólo falta el dibujo). */
(function () {
  'use strict';
  if (typeof Chart === 'undefined') { return; }

  var MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

  function css(nombre, defecto) {
    var v = getComputedStyle(document.documentElement).getPropertyValue(nombre).trim();
    return v || defecto;
  }
  function oscuro() { return document.documentElement.classList.contains('dark'); }

  function colores() {
    var o = oscuro();
    return {
      llegaron: o ? '#a6aed0' : '#7380a6', barra: o ? 'rgba(166,174,208,.32)' : '#d6dbea', contactados: o ? '#93b4ff' : '#1f4fd8',
      calificado: o ? '#f6c06e' : '#c26a00', ganado: o ? '#5ee0ae' : '#0a8a5a', perdido: o ? '#ff8fab' : '#d1203f',
      muted: css('--muted', '#5b6586'), grid: o ? 'rgba(255,255,255,.10)' : 'rgba(20,32,77,.10)', gridX: o ? 'rgba(255,255,255,.06)' : 'rgba(20,32,77,.06)',
      tip: o ? '#e9ecf4' : '#14204d', tipTxt: o ? '#14204d' : '#ffffff', fondo: o ? '#181a28' : '#ffffff', marca: o ? '#10131f' : '#ffffff'
    };
  }

  // Marca de cada tipo de hito: rombo ámbar, círculo verde con check, círculo rojo con ×. Con varios el mismo día va sólo el círculo (el número se dibuja encima).
  function icono(tipo, r, n, c) {
    var s = r * 2 + 4, k = document.createElement('canvas');
    k.width = k.height = s;
    var x = k.getContext('2d');
    x.fillStyle = c[tipo]; x.strokeStyle = c.fondo; x.lineWidth = 2;
    x.translate(s / 2, s / 2);
    if (tipo === 'calificado') {
      x.rotate(Math.PI / 4); x.beginPath(); x.rect(-r * 0.78, -r * 0.78, r * 1.56, r * 1.56); x.fill(); x.stroke();
      return k;
    }
    x.beginPath(); x.arc(0, 0, r, 0, 7); x.fill(); x.stroke();
    if (n > 1) { return k; }
    x.strokeStyle = c.marca; x.lineWidth = 2.2; x.lineCap = 'round'; x.lineJoin = 'round'; x.beginPath();
    if (tipo === 'ganado') { x.moveTo(-r * 0.45, 0); x.lineTo(-r * 0.1, r * 0.38); x.lineTo(r * 0.5, -r * 0.35); }
    else { x.moveTo(-r * 0.38, -r * 0.38); x.lineTo(r * 0.38, r * 0.38); x.moveTo(r * 0.38, -r * 0.38); x.lineTo(-r * 0.38, r * 0.38); }
    x.stroke();
    return k;
  }

  function plural(n, uno, varios) { return n + ' ' + (n === 1 ? uno : varios); }

  function dibujar(caja) {
    var datos;
    try { datos = JSON.parse(caja.getAttribute('data-grafico') || '{}'); } catch (e) { return; }
    var n = (datos.etiquetas || []).length;
    var cv = caja.querySelector('canvas[data-g="vol"]'), ch = caja.querySelector('canvas[data-g="hitos"]');
    if (!n || !cv || !ch) { return; }
    [cv, ch].forEach(function (el) { var v = Chart.getChart(el); if (v) { v.destroy(); } });

    var vista = caja.getAttribute('data-vista') === 'dia' ? 'dia' : 'acum';
    var c = colores(), movil = caja.clientWidth < 560, fam = getComputedStyle(document.body).fontFamily;
    Chart.defaults.font.family = fam;
    var paso = Math.max(1, Math.ceil(n / (movil ? 4 : 8)));
    var ancho = movil ? 34 : 92;

    // Fechas y líneas verticales sólo en unos pocos días (el resto de los días tiene su punto, sin rótulo).
    var marcados = [];
    for (var i = 0; i < n; i += paso) { marcados.push(i); }
    if (marcados[marcados.length - 1] !== n - 1) {
      if (marcados.length > 1 && n - 1 - marcados[marcados.length - 1] < paso / 2) { marcados.pop(); }
      marcados.push(n - 1);
    }
    var eje = {
      type: 'linear', min: -0.5, max: n - 0.5,
      grid: { color: c.gridX }, border: { display: false },
      afterBuildTicks: function (s) { s.ticks = marcados.map(function (v) { return { value: v }; }); },
      ticks: { color: c.muted, font: { size: 11, weight: '600' }, maxRotation: 0, autoSkip: false, callback: function (v) { return datos.etiquetas[v] || ''; } }
    };

    // ---- Volumen: dos líneas con un punto por día ----
    var conPuntos = !movil && n <= 46, porDia = vista === 'dia';
    var series = (porDia ? datos.volumen_dia : datos.volumen).map(function (s) {
      if (porDia && s.id === 'llegaron') {
        // Barras llenas, sin borde, en gris suave: solo para las barras.
        return {
          type: 'bar', label: s.nombre, data: s.datos.map(function (y, i) { return { x: i, y: y }; }),
          backgroundColor: c.barra, borderWidth: 0, borderRadius: 3, barThickness: movil ? 6 : (n > 30 ? 6 : 10), grouped: false, order: 2
        };
      }
      var col = c[s.id];
      var g = null;
      if (s.id === 'contactados' && !porDia) {
        g = cv.getContext('2d').createLinearGradient(0, 0, 0, cv.parentNode.clientHeight || 200);
        g.addColorStop(0, col + '33'); g.addColorStop(1, col + '00');
      }
      return {
        label: s.nombre, data: s.datos.map(function (y, i) { return { x: i, y: y }; }),
        borderColor: col, borderWidth: 2.5, tension: porDia ? 0 : 0.35, fill: !!g, backgroundColor: g || 'transparent',
        pointRadius: conPuntos ? 3 : 0, pointHoverRadius: 6, pointHitRadius: 14,
        pointBackgroundColor: c.fondo, pointBorderColor: col, pointBorderWidth: 2, order: 1
      };
    });
    var max = 0;
    series.forEach(function (s) { s.data.forEach(function (p) { if (p.y > max) { max = p.y; } }); });

    new Chart(cv, {
      type: 'line', data: { datasets: series },
      options: {
        responsive: true, maintainAspectRatio: false, animation: false, parsing: false,
        layout: { padding: { top: 18, right: 14 } },
        interaction: { mode: 'index', axis: 'x', intersect: false },
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: c.tip, titleColor: c.tipTxt, bodyColor: c.tipTxt, footerColor: c.tipTxt, cornerRadius: 10, padding: 10,
            displayColors: false, titleFont: { weight: '700' }, bodyFont: { weight: '600' }, footerFont: { weight: '700' },
            callbacks: {
              title: function (it) { return datos.etiquetas[it[0].dataIndex] + (datos.paso === 'semana' ? ' (semana)' : ''); },
              label: function (it) { return it.dataset.label + (porDia ? (datos.paso === 'semana' ? ' esa semana' : ' ese día') : '') + ': ' + it.parsed.y; },
              footer: function (it) {
                var i = it[0].dataIndex, l = [];
                datos.hitos.forEach(function (h) { if (h.datos[i] > 0) { l.push(plural(h.datos[i], h.uno, h.varios) + ' ese ' + (datos.paso === 'semana' ? 'tramo' : 'día')); } });
                return l;
              }
            }
          }
        },
        scales: {
          x: Object.assign({}, eje, { ticks: Object.assign({}, eje.ticks, { display: false }) }),
          y: {
            beginAtZero: true, suggestedMax: Math.max(4, Math.ceil(max * 1.1)), afterFit: function (s) { s.width = ancho; },
            grid: { color: c.grid, borderDash: [4, 5] }, border: { display: false },
            ticks: { color: c.muted, precision: 0, maxTicksLimit: movil ? 3 : 4, font: { size: 11, weight: '600' } }
          }
        }
      },
      plugins: [{
        id: 'finales', afterDatasetsDraw: function (g) {
          var cx = g.ctx;
          if (porDia) { return; }
          g.data.datasets.forEach(function (d, i) {
            var m = g.getDatasetMeta(i).data[n - 1];
            if (!m) { return; }
            cx.save(); cx.fillStyle = d.borderColor; cx.beginPath(); cx.arc(m.x, m.y, 4.5, 0, 7); cx.fill();
            cx.fillStyle = c.tip; cx.font = '800 12px ' + fam; cx.textAlign = 'right';
            cx.fillText(String(d.data[n - 1].y), m.x - 9, m.y - 8); cx.restore();
          });
        }
      }]
    });

    // ---- Hitos: un carril por tipo, una marca por día ----
    var carril = { calificado: 2, ganado: 1, perdido: 0 };
    var r = movil ? 6 : 8;
    var sets = datos.hitos.map(function (h) {
      var pts = [];
      h.datos.forEach(function (cant, i) { if (cant > 0) { pts.push({ x: i, y: carril[h.id], n: cant }); } });
      return {
        label: h.nombre, tipo: h.id, uno: h.uno, varios: h.varios, data: pts, showLine: false,
        pointStyle: pts.map(function (p) { return icono(h.id, p.n > 1 ? r + 3 : r, p.n, c); }),
        pointRadius: pts.map(function (p) { return p.n > 1 ? r + 3 : r; }),
        pointHoverRadius: pts.map(function (p) { return (p.n > 1 ? r + 3 : r) + 3; }), pointHitRadius: 14
      };
    });
    new Chart(ch, {
      type: 'scatter', data: { datasets: sets },
      options: {
        responsive: true, maintainAspectRatio: false, animation: false,
        layout: { padding: { top: 4, right: 14 } },
        interaction: { mode: 'nearest', intersect: true },
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: c.tip, titleColor: c.tipTxt, bodyColor: c.tipTxt, cornerRadius: 10, padding: 10, displayColors: false,
            titleFont: { weight: '700' }, bodyFont: { weight: '600' },
            callbacks: {
              title: function (it) { return datos.etiquetas[it[0].raw.x] + (datos.paso === 'semana' ? ' (semana)' : ''); },
              label: function (it) { return plural(it.raw.n, it.dataset.uno, it.dataset.varios); }
            }
          }
        },
        scales: {
          x: eje,
          y: { min: -0.7, max: 2.7, afterFit: function (s) { s.width = ancho; }, grid: { color: c.grid, borderDash: [4, 5] }, border: { display: false }, ticks: { display: false, stepSize: 1 } }
        }
      },
      plugins: [{
        id: 'numeros', afterDatasetsDraw: function (g) {
          var cx = g.ctx;
          g.data.datasets.forEach(function (d, i) {
            g.getDatasetMeta(i).data.forEach(function (p, k) {
              var cant = d.data[k].n;
              if (cant > 1) {
                cx.save(); cx.fillStyle = d.tipo === 'calificado' ? c.marca : c.marca; cx.font = '800 11px ' + fam; cx.textAlign = 'center'; cx.textBaseline = 'middle';
                cx.fillText(String(cant), p.x, p.y + 1); cx.restore();
              }
            });
          });
        }
      }, {
        // Los nombres de los carriles van a la izquierda; en el celular pasan a la leyenda de arriba.
        id: 'carriles', afterDraw: function (g) {
          if (movil) { return; }
          var cx = g.ctx, a = g.chartArea;
          cx.save(); cx.font = '700 12px ' + fam; cx.textAlign = 'right'; cx.textBaseline = 'middle';
          [['Calificados', 2, c.calificado], ['Ganados', 1, c.ganado], ['Perdidos', 0, c.perdido]].forEach(function (l) {
            cx.fillStyle = l[2]; cx.fillText(l[0], a.left - 12, g.scales.y.getPixelForValue(l[1]));
          });
          cx.restore();
        }
      }]
    });
  }

  function leerVista() { try { return localStorage.getItem('portal-vista-grafico') === 'dia' ? 'dia' : 'acum'; } catch (e) { return 'acum'; } }
  function marcarVista(caja) {
    var v = caja.getAttribute('data-vista') || 'acum';
    caja.querySelectorAll('[data-vista]').forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-vista') === v ? 'true' : 'false'); });
    caja.querySelectorAll('[data-t]').forEach(function (t) { t.hidden = t.getAttribute('data-t') !== v; });
    var cv = caja.querySelector('canvas[data-g="vol"]');
    if (cv) { cv.setAttribute('aria-label', v === 'dia' ? 'Contactos que llegaron y fueron contactados en cada día' : 'Contactos que llegaron y contactos contactados, acumulados día a día'); }
  }
  function iniciar() {
    document.querySelectorAll('[data-grafico]').forEach(function (caja) {
      if (!caja.hasAttribute('data-vista')) {
        caja.setAttribute('data-vista', caja.querySelector('button[data-vista]') ? leerVista() : 'acum');
        caja.querySelectorAll('button[data-vista]').forEach(function (b) {
          b.addEventListener('click', function () {
            var v = b.getAttribute('data-vista');
            try { localStorage.setItem('portal-vista-grafico', v); } catch (e) { /* sin almacenamiento: queda solo en esta página */ }
            document.querySelectorAll('[data-grafico]').forEach(function (o) { o.setAttribute('data-vista', v); marcarVista(o); dibujar(o); });
          });
        });
      }
      marcarVista(caja);
      dibujar(caja);
    });
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', iniciar); } else { iniciar(); }

  // Al cambiar entre claro y oscuro, o el ancho de la pantalla, se dibuja de nuevo.
  new MutationObserver(iniciar).observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
  var ancho0 = window.innerWidth, t = null;
  window.addEventListener('resize', function () {
    clearTimeout(t);
    t = setTimeout(function () { if ((window.innerWidth < 600) !== (ancho0 < 600)) { ancho0 = window.innerWidth; iniciar(); } else { ancho0 = window.innerWidth; } }, 200);
  });
})();

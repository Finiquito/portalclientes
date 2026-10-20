/* Gráficos de línea del portal (Chart.js, en chart.umd.js). Sin dependencias más: lee los datos de
 *   <canvas data-grafico='{"etiquetas":[..],"series":[{"id":"contactos","nombre":"Contactos","datos":[..],"oculta":false}]}'>
 * Los colores salen de las variables del portal (--brand, --ok, --danger…), así que siguen al color de cada
 * cliente y al modo claro u oscuro. Los botones [data-serie] dentro de [data-grafico-wrap] encienden o apagan
 * una serie. Si Chart.js no cargó, la página sigue funcionando (sólo falta el dibujo). */
(function () {
  'use strict';
  if (typeof Chart === 'undefined') { return; }

  var instancias = [];

  function css(nombre, defecto) {
    var v = getComputedStyle(document.documentElement).getPropertyValue(nombre).trim();
    return v || defecto;
  }

  function colorDe(id, idx) {
    if (id === 'ganados') { return css('--ok', '#0a8a5a'); }
    if (id === 'perdidos') { return css('--danger', '#d1203f'); }
    return css('--brand', '#2454c5');
  }

  // Burbuja con el valor del último punto de la primera serie visible (como en los tableros de referencia).
  var burbuja = {
    id: 'burbuja',
    afterDatasetsDraw: function (ch) {
      var oscuro = document.documentElement.classList.contains('dark');
      for (var i = 0; i < ch.data.datasets.length; i++) {
        if (!ch.isDatasetVisible(i)) { continue; }
        var meta = ch.getDatasetMeta(i), pt = meta.data[meta.data.length - 1];
        if (!pt) { return; }
        var d = ch.data.datasets[i], n = d.data.length - 1;
        var t = ch.data.labels[n] + ' · ' + d.data[n] + ' ' + (d.data[n] === 1 ? d.etiquetaUno : d.etiquetaVarios);
        var c = ch.ctx;
        c.save();
        c.font = '700 12px ' + getComputedStyle(document.body).fontFamily;
        var w = c.measureText(t).width + 22, x = Math.min(pt.x - w + 16, ch.width - w - 2), y = pt.y - 42;
        if (y < 2) { y = pt.y + 14; }
        c.fillStyle = oscuro ? '#e9ecf4' : css('--azul', '#14204d');
        c.beginPath(); c.roundRect(x, y, w, 26, 13); c.fill();
        c.fillStyle = oscuro ? '#14204d' : '#ffffff';
        c.textAlign = 'center'; c.fillText(t, x + w / 2, y + 17);
        c.restore();
        return;
      }
    }
  };

  function dibujar(el) {
    var datos;
    try { datos = JSON.parse(el.getAttribute('data-grafico') || '{}'); } catch (e) { return; }
    if (!datos.etiquetas || !datos.series || !datos.etiquetas.length) { return; }
    var viejo = Chart.getChart(el);
    if (viejo) { viejo.destroy(); }

    var oscuro = document.documentElement.classList.contains('dark');
    var muted = css('--muted', '#5b6586');
    var ctx = el.getContext('2d');
    var alto = el.parentNode.clientHeight || 240;
    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;

    var conjuntos = datos.series.map(function (s, i) {
      var color = colorDe(s.id, i), principal = i === 0;
      var fondo = 'transparent';
      if (principal) {
        fondo = ctx.createLinearGradient(0, 0, 0, alto);
        fondo.addColorStop(0, color + '59'); fondo.addColorStop(1, color + '00');
      }
      return {
        id: s.id, label: s.nombre, data: s.datos, hidden: !!s.oculta,
        etiquetaUno: s.uno || 'contacto', etiquetaVarios: s.varios || 'contactos',
        borderColor: color, borderWidth: 2.5, tension: 0.4, fill: principal, backgroundColor: fondo,
        pointRadius: s.datos.length > 24 ? 0 : 4, pointHoverRadius: 6,
        pointBackgroundColor: oscuro ? '#181a28' : '#ffffff', pointBorderColor: color, pointBorderWidth: 2.2
      };
    });
    var max = 0;
    conjuntos.forEach(function (c) { c.data.forEach(function (v) { if (v > max) { max = v; } }); });

    var g = new Chart(el, {
      type: 'line',
      data: { labels: datos.etiquetas, datasets: conjuntos },
      options: {
        responsive: true, maintainAspectRatio: false, animation: false,
        layout: { padding: { top: 38, right: 14 } },
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: oscuro ? '#e9ecf4' : '#14204d', titleColor: oscuro ? '#14204d' : '#ffffff', bodyColor: oscuro ? '#14204d' : '#ffffff',
            cornerRadius: 10, padding: 10, displayColors: false, titleFont: { weight: '700' }, bodyFont: { weight: '600' }
          }
        },
        scales: {
          x: {
            grid: { display: false }, border: { display: false },
            ticks: { color: muted, font: { size: 11, weight: '600' }, maxRotation: 0, autoSkip: true, maxTicksLimit: 8 }
          },
          y: {
            beginAtZero: true, suggestedMax: Math.max(4, Math.ceil(max * 1.1)),
            grid: { color: oscuro ? 'rgba(255,255,255,.10)' : 'rgba(20,32,77,.10)', borderDash: [4, 5] }, border: { display: false },
            ticks: { color: muted, precision: 0, maxTicksLimit: 5, font: { size: 11, weight: '600' } }
          }
        }
      },
      plugins: [burbuja]
    });
    instancias.push(g);
    return g;
  }

  function iniciar() {
    document.querySelectorAll('canvas[data-grafico]').forEach(function (el) {
      var g = dibujar(el);
      var caja = el.closest('[data-grafico-wrap]');
      if (!g || !caja) { return; }
      caja.querySelectorAll('[data-serie]').forEach(function (b) {
        var idx = g.data.datasets.findIndex(function (d) { return d.id === b.getAttribute('data-serie'); });
        if (idx < 0) { b.hidden = true; return; }
        b.classList.toggle('on', g.isDatasetVisible(idx));
        b.setAttribute('aria-pressed', g.isDatasetVisible(idx) ? 'true' : 'false');
        b.addEventListener('click', function () {
          var ch = Chart.getChart(el);
          var v = !ch.isDatasetVisible(idx);
          ch.setDatasetVisibility(idx, v);
          ch.update();
          b.classList.toggle('on', v);
          b.setAttribute('aria-pressed', v ? 'true' : 'false');
        });
      });
    });
  }

  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', iniciar); } else { iniciar(); }

  // Al cambiar entre claro y oscuro los colores cambian: se dibuja de nuevo.
  new MutationObserver(function () {
    document.querySelectorAll('canvas[data-grafico]').forEach(function (el) {
      var ch = Chart.getChart(el), vis = ch ? ch.data.datasets.map(function (d, i) { return ch.isDatasetVisible(i); }) : null;
      var g = dibujar(el);
      if (g && vis) { vis.forEach(function (v, i) { g.setDatasetVisibility(i, v); }); g.update(); }
    });
  }).observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
})();

/* Aviso de cookies del landing. Google Analytics solo se carga si la persona lo acepta; la elección queda en su navegador (localStorage), sin cookies propias. */
(function () {
  var ID = "G-TLZCJ1N0XE", K = "prisma_cookies", cargado = false;
  window.dataLayer = window.dataLayer || [];
  window.gtag = function () { window.dataLayer.push(arguments); };

  function leer() { try { return localStorage.getItem(K); } catch (e) { return null; } }
  function guardar(v) { try { localStorage.setItem(K, v); } catch (e) {} }

  function cargar() {
    if (cargado) return; cargado = true;
    var s = document.createElement("script"); s.async = true; s.src = "https://www.googletagmanager.com/gtag/js?id=" + ID;
    document.head.appendChild(s);
    window.gtag("js", new Date()); window.gtag("config", ID);
  }

  // Al rechazar (o cambiar de idea) se borran las cookies de medición que hubiera dejado antes.
  function limpiar() {
    var host = location.hostname, partes = host.split("."), dominios = ["", host, "." + host];
    if (partes.length > 2) dominios.push("." + partes.slice(-2).join("."));
    document.cookie.split(";").forEach(function (c) {
      var n = c.split("=")[0].trim();
      if (n === "_ga" || n.indexOf("_ga_") === 0) dominios.forEach(function (d) { document.cookie = n + "=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/" + (d ? "; domain=" + d : ""); });
    });
  }

  function cerrar() { var b = document.getElementById("aviso-cookies"); if (b) b.remove(); }

  function preguntar() {
    cerrar();
    var b = document.createElement("div"); b.id = "aviso-cookies"; b.className = "aviso-cookies";
    b.setAttribute("role", "dialog"); b.setAttribute("aria-label", "Cookies");
    b.innerHTML = '<h2>Cookies de medición</h2><p>Usamos Google Analytics para saber cómo se usa esta página. Solo se activa si lo aceptas, y puedes cambiar de idea cuando quieras. <a href="/cookies/">Más información</a></p><div class="aviso-cookies-bt"><button type="button" data-v="no">Rechazar</button><button type="button" class="si" data-v="si">Aceptar</button></div>';
    b.addEventListener("click", function (e) {
      var v = e.target && e.target.getAttribute && e.target.getAttribute("data-v"); if (!v) return;
      var antes = leer(); guardar(v); cerrar();
      if (v === "si") cargar(); else { limpiar(); if (antes === "si") location.reload(); }
    });
    document.body.appendChild(b);
    var bt = b.querySelector("button.si"); if (bt && !document.activeElement.closest("form")) { /* no roba el foco del formulario */ }
  }

  window.prismaCookies = { preguntar: preguntar };
  function iniciar() { var v = leer(); if (v === "si") cargar(); else if (v !== "no") preguntar(); }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", iniciar); else iniciar();
})();

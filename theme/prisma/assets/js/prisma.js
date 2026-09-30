/* Prisma · landing. Sin dependencias; todo funciona sin JS (formularios normales). */
(function () {
  "use strict";

  var reducir = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  /* ---- Despiece: las piezas se separan al entrar en pantalla (una vez) ---- */
  var ilustraciones = Array.prototype.slice.call(document.querySelectorAll(".despiece"));
  if (reducir || !("IntersectionObserver" in window)) {
    ilustraciones.forEach(function (s) { s.classList.add("abierto"); });
  } else {
    var io = new IntersectionObserver(function (entradas) {
      entradas.forEach(function (e) {
        if (e.isIntersecting) {
          e.target.classList.add("abierto");
          io.unobserve(e.target);
        }
      });
    }, { threshold: 0.35 });
    ilustraciones.forEach(function (s) { io.observe(s); });
  }

  /* Leyenda del diagrama: al pasar por un ítem se resalta su pieza */
  document.querySelectorAll(".leyenda li").forEach(function (li) {
    var capa = document.querySelector(".despiece-diagrama .capa-" + li.getAttribute("data-pieza"));
    if (!capa) return;
    li.addEventListener("mouseenter", function () { capa.classList.add("activa"); });
    li.addEventListener("mouseleave", function () { capa.classList.remove("activa"); });
  });

  /* ---- Invitación ---- */
  var t0 = Date.now();
  document.querySelectorAll("[data-t]").forEach(function (i) { i.value = String(t0); });

  function gracias(form, nombre, correo) {
    var caja = document.createElement("div");
    caja.className = "gracias";
    caja.setAttribute("role", "status");
    caja.tabIndex = -1;
    var titulo = document.createElement("strong");
    titulo.textContent = nombre ? "Listo, " + nombre + "." : "Listo, quedaste en la lista.";
    var texto = document.createElement("p");
    texto.textContent = "Te escribimos a " + correo + " cuando tengamos cupo. Si Mailchimp te pide confirmar tu correo, revisa tu bandeja (y la de spam).";
    caja.appendChild(titulo);
    caja.appendChild(texto);
    form.replaceWith(caja);
    caja.focus({ preventScroll: true });
  }

  var CORREO = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
  var largo = document.querySelector(".form-largo");

  function aviso(form, msg, tono) {
    var e = form && form.querySelector("[data-estado]");
    if (!e) return;
    e.textContent = msg;
    e.setAttribute("data-tono", tono || "");
  }

  /* «✓ Ya lo tenemos» junto al correo que vino de la portada; se apaga si lo borran o lo cambian */
  var listo = largo && largo.querySelector("[data-listo]");
  var correoLargo = largo && largo.querySelector("input[name=email]");
  function marcarCorreo(si) {
    if (listo) listo.hidden = !si;
    if (correoLargo) correoLargo.classList.toggle("lleno", !!si);
  }
  if (correoLargo) {
    var original = null;
    correoLargo.addEventListener("focus", function () { original = correoLargo.value; });
    correoLargo.addEventListener("input", function () { if (correoLargo.value !== original) marcarCorreo(false); });
  }

  /* Pasa el correo al formulario completo, baja hasta él y deja el cursor en el nombre */
  function continuar(correo, desde) {
    if (!largo) return;
    var c = largo.querySelector("input[name=email]");
    if (c && correo) { c.value = correo; marcarCorreo(true); }
    var o = largo.querySelector("[data-origen]");
    if (o && desde) o.value = desde;
    var destino = document.getElementById("invitacion") || largo;
    destino.scrollIntoView({ behavior: reducir ? "auto" : "smooth", block: "start" });
    var n = largo.querySelector("input[name=nombre]");
    if (n) setTimeout(function () { n.focus({ preventScroll: true }); }, reducir ? 0 : 450);
    aviso(largo, "", "");
  }

  /* Formulario de la portada: sólo el correo; el envío real es el de abajo */
  document.querySelectorAll("form[data-continuar]").forEach(function (form) {
    form.addEventListener("submit", function (ev) {
      ev.preventDefault();
      var c = form.querySelector("input[name=email]");
      var valor = c ? c.value.trim() : "";
      if (valor && !CORREO.test(valor)) {
        aviso(form, "Revisa tu correo: parece que le falta algo.", "error");
        c.focus();
        return;
      }
      aviso(form, "", "");
      continuar(valor, "portada");
    });
  });

  /* Sin JS, la portada llega como ?email=…#invitacion */
  var correoUrl = new URLSearchParams(window.location.search).get("email");
  if (correoUrl && largo && CORREO.test(correoUrl)) {
    var cu = largo.querySelector("input[name=email]");
    if (cu) { cu.value = correoUrl; marcarCorreo(true); }
    var ou = largo.querySelector("[data-origen]");
    if (ou) ou.value = "portada";
  }

  document.querySelectorAll("form[data-invitacion]").forEach(function (form) {
    var estado = form.querySelector("[data-estado]");
    var boton = form.querySelector("button[type=submit]");
    var textoBoton = boton ? boton.textContent : "";

    function avisar(msg, tono) {
      if (!estado) return;
      estado.textContent = msg;
      estado.setAttribute("data-tono", tono || "");
    }

    form.addEventListener("submit", function (ev) {
      var correo = form.querySelector("input[name=email]");
      var valor = correo ? correo.value.trim() : "";
      if (!CORREO.test(valor)) {
        ev.preventDefault();
        avisar("Revisa tu correo: parece que le falta algo.", "error");
        if (correo) correo.focus();
        return;
      }
      var nombre = form.querySelector("input[name=nombre]");
      if (nombre && !nombre.value.trim()) {
        ev.preventDefault();
        avisar("Falta tu nombre.", "error");
        nombre.focus();
        return;
      }
      var tamanos = form.querySelectorAll("input[name=tamano]");
      if (tamanos.length && !form.querySelector("input[name=tamano]:checked")) {
        ev.preventDefault();
        avisar("Elige cuántos son en tu equipo.", "error");
        tamanos[0].focus();
        return;
      }
      if (!window.fetch || !window.FormData) return; // envío normal
      ev.preventDefault();

      var datos = new FormData(form);
      if (boton) { boton.disabled = true; boton.setAttribute("aria-busy", "true"); boton.textContent = boton.getAttribute("data-enviando") || "Enviando…"; }
      avisar("", "");

      fetch(form.action, { method: "POST", body: datos, headers: { "Accept": "application/json" }, credentials: "same-origin" })
        .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
        .then(function (res) {
          if (res && res.ok) {
            var nombre = (form.querySelector("input[name=nombre]") || {}).value || "";
            gracias(form, nombre.trim().split(/\s+/)[0], valor);
          } else {
            avisar((res && res.error) || "No pudimos guardar tus datos. Intenta de nuevo en un rato.", "error");
          }
        })
        .catch(function () { avisar("Parece que no hay conexión. Intenta de nuevo.", "error"); })
        .then(function () {
          if (boton && document.body.contains(boton)) { boton.disabled = false; boton.removeAttribute("aria-busy"); boton.textContent = textoBoton; }
        });
    });
  });

  /* Sin JS el formulario vuelve con ?invitacion=ok o ?invitacion=error */
  var q = new URLSearchParams(window.location.search).get("invitacion");
  if (q) {
    var f = document.querySelector(".form-largo");
    if (f && q === "ok") gracias(f, "", "tu correo");
    else if (f) { var e = f.querySelector("[data-estado]"); if (e) { e.textContent = "No pudimos guardar tus datos. Intenta de nuevo."; e.setAttribute("data-tono", "error"); } }
  }
})();

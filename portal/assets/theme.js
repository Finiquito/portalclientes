/* Se ejecuta en <head> antes de pintar para evitar el "flash" de tema claro. */
(function () {
  var h = document.documentElement;
  var t = h.getAttribute('data-tema');
  var mq = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
  function aplicar() {
    var dark = t === 'oscuro' || (t === 'auto' && mq && mq.matches);
    h.classList.toggle('dark', !!dark);
  }
  aplicar();
  if (mq && mq.addEventListener) mq.addEventListener('change', function () {
    t = h.getAttribute('data-tema');
    aplicar();
  });
})();

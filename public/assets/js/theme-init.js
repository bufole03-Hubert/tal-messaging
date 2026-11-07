/* Chargé en <head>, de façon synchrone : applique le thème (clair/sombre) et l'état de la sidebar avant le premier rendu (aucun flash). */
(function () {
  var root = document.documentElement;
  var stored = null, sidebar = null;
  try { stored = localStorage.getItem('tal-theme'); sidebar = localStorage.getItem('tal-sidebar'); } catch (e) { /* stockage indisponible */ }
  var mode = (stored === 'light' || stored === 'dark' || stored === 'system') ? stored : (root.getAttribute('data-theme-default') || 'system');
  var dark = mode === 'dark' || (mode === 'system' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
  root.classList.toggle('dark', dark);
  root.setAttribute('data-theme-mode', mode);
  if (sidebar === 'collapsed') root.setAttribute('data-sidebar', 'collapsed');
})();

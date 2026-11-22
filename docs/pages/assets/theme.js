// The head sets data-theme before paint; this wires the moon button.
document.querySelectorAll('[data-theme-toggle]').forEach(function (b) {
  var root = document.documentElement;
  var sync = function () { b.setAttribute('aria-pressed', root.dataset.theme === 'dark' ? 'true' : 'false'); };
  sync();
  b.addEventListener('click', function () {
    root.dataset.theme = root.dataset.theme === 'dark' ? 'light' : 'dark';
    try { localStorage.setItem('intakt-theme', root.dataset.theme); } catch (e) {}
    sync();
  });
});

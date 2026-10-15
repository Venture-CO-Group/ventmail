// Opens the ventCookie preferences panel from the footer link (no inline handler: CSP script-src 'self').
document.querySelectorAll('[data-vc-open-preferences]').forEach(function (el) {
  el.addEventListener('click', function (e) {
    e.preventDefault();
    if (window.ventCookie && typeof window.ventCookie.openPreferences === 'function') {
      window.ventCookie.openPreferences();
    }
  });
});

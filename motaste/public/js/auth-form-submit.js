/**
 * Disable a form's submit button once it is submitted, so a slow request cannot
 * be double-submitted. The button text is taken from its `data-loading-text`
 * attribute (falls back to "Please wait...").
 *
 * This lives in an external file — and is wired up with addEventListener rather
 * than an inline `onsubmit`/`onclick` attribute — so the auth pages can run
 * under a strict Content-Security-Policy whose script-src omits 'unsafe-inline'.
 */
(function () {
    'use strict';

    document.querySelectorAll('form').forEach(function (form) {
        form.addEventListener('submit', function () {
            var btn = form.querySelector('button[type="submit"]');
            if (!btn || btn.disabled) {
                return;
            }
            btn.disabled = true;
            btn.textContent = btn.getAttribute('data-loading-text') || 'Please wait...';
        });
    });
})();

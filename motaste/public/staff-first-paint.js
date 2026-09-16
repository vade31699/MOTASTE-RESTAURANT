/* Decide the first paint before the deferred dashboard script runs.
         *
         * The login form is the default painted state of this page and is only
         * hidden by restoreStaffSession() inside script.js. Until that ~13k-line
         * deferred file has been fetched, parsed and executed, a page refresh
         * with a live staff session therefore showed the login form for as long
         * as the script took to arrive (1-2s), then jumped to the dashboard.
         *
         * This runs synchronously in <head>, so the correct surface is chosen
         * before anything is painted. script.js clears the class as soon as it
         * has made the same decision for real.
         *
         * The storage key must match staffSessionStorageKey in script.js, and
         * only the NON-SECRET role/email hints are read (the session token
         * itself is an HttpOnly cookie this script cannot and must not see).
         */
        (function () {
            try {
                var raw = sessionStorage.getItem('motasteStaffSession')
                    || localStorage.getItem('motasteStaffSession');
                if (!raw) return;

                var session = JSON.parse(raw);
                if (!session || !session.role || !session.email) return;

                document.documentElement.classList.add('staff-auth-pending');

                // Safety net: if script.js never runs (offline, blocked CDN,
                // JS error), fall back to the login form rather than leaving a
                // spinner up forever.
                window.setTimeout(function () {
                    document.documentElement.classList.remove('staff-auth-pending');
                }, 8000);
            } catch (error) {
                // Storage unavailable (private mode, cookies disabled) or the
                // hint is corrupt: show the login form, which is the safe state.
            }
        })();
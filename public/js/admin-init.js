/* The stored theme, rail state and density, applied before anything is
   painted.

   Loaded with a plain blocking `<script src>` in `<head>`, before the
   stylesheet, on purpose: a stylesheet cannot read localStorage, and a
   script that ran after the first paint would show the light palette (or
   the full-width rail) for one frame before correcting it, on every
   navigation. `defer`/`async` would defeat that -- both let the browser
   paint before this runs.

   The same `tb-theme` key as the public site, so choosing dark out there
   means dark in here. `data-bs-theme` is set alongside `data-theme` so
   Bootstrap's own component colours never disagree with ours.

   `data-rail-collapsed` sits on `<html>` rather than `<body>`, because
   `<body>` does not exist yet while this still runs in `<head>`. */
(function () {
    try {
        var choice = window.localStorage.getItem('tb-theme');

        if (choice === 'light' || choice === 'dark') {
            document.documentElement.setAttribute('data-theme', choice);
            document.documentElement.setAttribute('data-bs-theme', choice);
        } else if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
            /* admin.css already follows prefers-color-scheme on its own tokens;
               Bootstrap's tokens only flip under data-bs-theme, so it needs setting here too. */
            document.documentElement.setAttribute('data-bs-theme', 'dark');
        }

        if (window.localStorage.getItem('tb-admin-rail-collapsed') === '1') {
            document.documentElement.setAttribute('data-rail-collapsed', '');
        }

        /* No OS-level preference to fall back to here, unlike the
           theme above -- density is compact only when asked for. */
        if (window.localStorage.getItem('tb-density') === 'compact') {
            document.documentElement.setAttribute('data-density', 'compact');
        }
    } catch (e) {}
}());

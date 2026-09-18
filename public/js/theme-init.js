/* The stored theme, applied before anything is painted.

   Loaded with a plain blocking `<script src>` in `<head>`, before the
   stylesheet, on purpose: a stylesheet cannot read localStorage, and this
   has to run and finish before the first paint or a reader who chose dark
   would see the light palette flash and then be corrected, on every
   navigation. `defer`/`async` would defeat that -- both let the browser
   paint before this runs.

   Two attributes, because there are two palettes to keep in step. main.css
   reads `data-theme`; Bootstrap reads `data-bs-theme` and covers the
   components whose variables main.css does not name.

   With nothing stored, `data-theme` is deliberately left off so the media
   query decides and a machine that dims at sunset dims this with it. But
   Bootstrap has no media query of its own, so `data-bs-theme` is resolved
   here and kept in step below.

   try/catch because localStorage throws rather than returning null in a
   browser set to block site data, and a theme preference is not worth a
   broken page. */
(function () {
    var choice = null;

    try {
        choice = window.localStorage.getItem('tb-theme');
    } catch (e) {}

    if (choice !== 'light' && choice !== 'dark') {
        choice = null;
    }

    if (choice !== null) {
        document.documentElement.setAttribute('data-theme', choice);
    }

    var dark = window.matchMedia('(prefers-color-scheme: dark)');

    document.documentElement.setAttribute(
        'data-bs-theme',
        (choice !== null ? choice === 'dark' : dark.matches) ? 'dark' : 'light'
    );

    // Only while there is no stored choice: an explicit one outranks
    // the machine, and should not be undone when the machine changes.
    dark.addEventListener('change', function (event) {
        try {
            if (window.localStorage.getItem('tb-theme') !== null) {
                return;
            }
        } catch (e) {}

        document.documentElement.setAttribute('data-bs-theme', event.matches ? 'dark' : 'light');
    });
})();

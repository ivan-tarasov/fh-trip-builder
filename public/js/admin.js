/*
|------------------------------------------------------------------------------
| The panel
|------------------------------------------------------------------------------
|
| The panel's own script, sitting beside the vendored Bootstrap bundle rather
| than replacing it (A10.1, #287). Still no jQuery, Font Awesome, sweetalert,
| a range slider or a datepicker -- none of which an operator page uses
| (A3.5, #230) -- and everything below is here because Bootstrap's own bundle
| does not do it: a markdown preview, a confirm before a destructive action,
| and the dark/light toggle.
|
| One function per piece, because the first one written was inline and bailed
| out with a `return` when its element was missing -- which on every page but
| the editor meant nothing after it ever ran.
|
*/
(function () {
    'use strict';

    /*
    | The markdown preview.
    |
    | Markdown goes to the server and HTML comes back, because the server is
    | what will render it when the page is published. A markdown library in the
    | browser would agree with `View\Markdown` right up until it did not, and a
    | preview that can disagree with the page is worse than no preview.
    |
    | Debounced, because the alternative is a request per keystroke. Half a
    | second is long enough that a sentence is one request and short enough
    | that nobody waits for it.
    */
    var markdownPreview = function () {
        var source = document.querySelector('.js-markdown-source');
        var pane = document.querySelector('.js-markdown-preview');

        if (!source || !pane) {
            return;
        }

        var timer = null;
        var inFlight = null;

        var render = function () {
            // Abort the one still running: the answer to two keystrokes ago is not
            // worth waiting for and could arrive after the newer one.
            if (inFlight) {
                inFlight.abort();
            }

            inFlight = new AbortController();

            var body = new FormData();
            body.append('body', source.value);
            body.append('_csrf', source.dataset.csrf);

            fetch(source.dataset.previewUrl, {
                method: 'POST',
                body: body,
                signal: inFlight.signal
            }).then(function (response) {
                return response.ok ? response.text() : null;
            }).then(function (html) {
                if (html !== null) {
                    pane.innerHTML = html;
                }
            }).catch(function () {
                // An aborted request is the normal case here, and a failed one
                // leaves the last good preview rather than blanking the pane.
            });
        };

        source.addEventListener('input', function () {
            window.clearTimeout(timer);
            timer = window.setTimeout(render, 500);
        });

        render();
    };

    /*
    | The one confirm.
    |
    | On the booking buttons, which are the only controls in the panel that
    | change something a traveller can see. Native `confirm()` and not a dialog
    | of our own: a stylesheet's worth of modal for one sentence is how the 230KB
    | this panel was built to shed got there in the first place.
    |
    | Delegated from the document, so a button added to a later page is covered
    | by having the attribute rather than by remembering this file exists.
    */
    var confirmFirst = function () {
        document.addEventListener('click', function (event) {
            // A click can land on a text node's parent, on the document, or
            // on an SVG -- only an element has `closest`.
            var button = event.target.closest && event.target.closest('[data-confirm]');

            if (button && !window.confirm(button.dataset.confirm)) {
                event.preventDefault();
            }
        });
    };

    /*
    | Dark or light, the same `.js-theme` pattern `global.js` already proved on
    | the public site -- `aria-pressed` is the whole of the visual state, so
    | there is one source for "is it dark" rather than a class kept in step
    | with an attribute. The layout's own inline script already set
    | `data-theme`/`data-bs-theme` from storage before the first paint; this is
    | only what runs after a click.
    |
    | No page carries more than one `.js-theme` button -- the topbar's and the
    | sign-in page's replace each other, never coexist -- so there is nothing
    | here to delegate.
    */
    var themeToggle = function () {
        var button = document.querySelector('.js-theme');

        if (!button) {
            return;
        }

        var media = window.matchMedia('(prefers-color-scheme: dark)');

        var stored = function () {
            try {
                var choice = window.localStorage.getItem('tb-theme');

                return choice === 'light' || choice === 'dark' ? choice : null;
            } catch (e) {
                return null;
            }
        };

        var effective = function () {
            return stored() || (media.matches ? 'dark' : 'light');
        };

        var show = function (mode) {
            button.setAttribute('aria-pressed', mode === 'dark' ? 'true' : 'false');
        };

        button.addEventListener('click', function () {
            var next = effective() === 'dark' ? 'light' : 'dark';
            var root = document.documentElement;

            root.setAttribute('data-theme', next);
            root.setAttribute('data-bs-theme', next);

            try {
                window.localStorage.setItem('tb-theme', next);
            } catch (e) {}

            show(next);
        });

        media.addEventListener('change', function () {
            if (stored() === null) {
                show(effective());
            }
        });

        show(effective());
    };

    markdownPreview();
    confirmFirst();
    themeToggle();
}());

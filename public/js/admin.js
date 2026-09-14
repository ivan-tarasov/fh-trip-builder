/*
|------------------------------------------------------------------------------
| The panel
|------------------------------------------------------------------------------
|
| Its own file, and the only script the panel loads. What it replaced was
| fourteen external assets inherited from the site's layout -- jQuery,
| Bootstrap, Font Awesome, sweetalert, a range slider, a datepicker -- none of
| which an operator page uses (A3.5, #230).
|
| No framework and no jQuery on purpose: what is here is one fetch, one timer
| and one confirm.
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

    markdownPreview();
    confirmFirst();
}());

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
    | The one confirm -- Bootstrap's own modal now (G3.2, #305), not the
    | browser's native `confirm()`. That was the reason given for staying on
    | native: "a stylesheet's worth of modal for one sentence is how the
    | 230KB this panel was built to shed got there in the first place." True
    | when it was written (A3.5, #230), stale since G2.1 (#287) loaded
    | Bootstrap's bundle for other things -- the modal has been sitting there
    | unused.
    |
    | `confirm()` blocks the thread and returns before the click finishes; a
    | modal answers later, on its own click. So every `[data-confirm]` click
    | is prevented unconditionally and the element remembered, and only the
    | modal's own "Confirm" button carries the action out -- a form's
    | `requestSubmit()` if the element sits in one, a navigation to its
    | `href` otherwise. Delegated from the document, so a button added to a
    | later page is covered by having the attribute rather than by
    | remembering this file exists.
    */
    var confirmFirst = function () {
        var modalEl = document.getElementById('confirmModal');

        if (!modalEl || typeof bootstrap === 'undefined') {
            return;
        }

        var modal = new bootstrap.Modal(modalEl);
        var body = modalEl.querySelector('[data-confirm-modal-body]');
        var accept = modalEl.querySelector('[data-confirm-modal-accept]');
        var pending = null;

        document.addEventListener('click', function (event) {
            // A click can land on a text node's parent, on the document, or
            // on an SVG -- only an element has `closest`.
            var button = event.target.closest && event.target.closest('[data-confirm]');

            if (!button) {
                return;
            }

            event.preventDefault();
            pending = button;
            body.textContent = button.dataset.confirm;
            modal.show();
        });

        accept.addEventListener('click', function () {
            var button = pending;
            pending = null;
            modal.hide();

            if (!button) {
                return;
            }

            // `.form`, not `.closest('form')`: a button can belong to one
            // through the `form="..."` attribute without being inside it at
            // all, the bulk-remove button's own shape (G3.7, #310), and
            // `.closest` only ever finds an ancestor.
            var form = button.form;

            if (form) {
                form.requestSubmit(button);
            } else if (button.href) {
                window.location.href = button.href;
            }
        });
    };

    /*
    | The one queued message a redirect can carry (G3.1, #304). Bootstrap's
    | own `.toast`, already loaded and otherwise unused -- it does not show
    | itself, so this is the one line that does. Shown once: `Flash::take()`
    | already cleared the session the moment the page that draws it asked,
    | so a refresh of the same page finds nothing queued and shows nothing.
    */
    var toasts = function () {
        var el = document.querySelector('[data-flash-toast]');

        if (!el || typeof bootstrap === 'undefined') {
            return;
        }

        new bootstrap.Toast(el).show();
    };

    /*
    | Copy a booking reference, a session id, an email onto the clipboard
    | (G3.3, #306) -- read aloud or pasted elsewhere constantly, and until
    | now only ever selectable by hand. The icon itself is the confirmation,
    | swapped to a checkmark for a moment: an instant client-side action has
    | nothing to survive a redirect for, which is what `toasts()` above is
    | actually built around. Guarded on `navigator.clipboard` existing, the
    | same graceful-degradation `toasts()` gives `bootstrap`: an insecure
    | context (plain HTTP, not localhost) has no Clipboard API at all.
    */
    var copyButtons = function () {
        if (!navigator.clipboard) {
            return;
        }

        document.addEventListener('click', function (event) {
            var button = event.target.closest && event.target.closest('[data-copy]');

            if (!button) {
                return;
            }

            navigator.clipboard.writeText(button.dataset.copy).then(function () {
                var icon = button.querySelector('i');
                var original = icon.className;

                button.classList.add('is-copied');
                icon.className = 'bi bi-check-lg';

                window.setTimeout(function () {
                    button.classList.remove('is-copied');
                    icon.className = original;
                }, 1500);
            }, function () {
                // Denied (an insecure context slipping past the guard above,
                // a browser permission the operator refused) is silent on
                // purpose: the icon simply does not confirm, which is the
                // honest answer, rather than a second UI for an error this
                // small.
            });
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

    /*
    | Comfortable or compact, the same `aria-pressed`-reads-the-icon shape as
    | `themeToggle()` above -- minus the `matchMedia` half, since there is no
    | OS-level "compact" preference to default to. One key, `tb-density`,
    | read by the layout's own pre-paint script the same way `tb-theme` is
    | (G3.6, #309).
    */
    var densityToggle = function () {
        var button = document.querySelector('.js-density');

        if (!button) {
            return;
        }

        var stored = function () {
            try {
                return window.localStorage.getItem('tb-density') === 'compact' ? 'compact' : 'comfortable';
            } catch (e) {
                return 'comfortable';
            }
        };

        var show = function (mode) {
            button.setAttribute('aria-pressed', mode === 'compact' ? 'true' : 'false');
        };

        button.addEventListener('click', function () {
            var next = stored() === 'compact' ? 'comfortable' : 'compact';
            var root = document.documentElement;

            if (next === 'compact') {
                root.setAttribute('data-density', 'compact');
            } else {
                root.removeAttribute('data-density');
            }

            try {
                window.localStorage.setItem('tb-density', next);
            } catch (e) {}

            show(next);
        });

        show(stored());
    };

    /*
    | The fare-alert list's contextual toolbar: nothing until a row is
    | checked, then a live count and the bulk-remove button -- `data-confirm`
    | on that button routes it through the shared modal `confirmFirst()`
    | already wires up, the same as any other destructive action.
    |
    | The toolbar toggles `d-none`/`d-flex` rather than the `hidden`
    | attribute: a `.d-flex` utility class outranks `[hidden]` regardless of
    | specificity, because author styles always beat a user-agent default,
    | so the two would otherwise fight and `.d-flex` would win -- visible
    | with nothing checked (G3.7, #310).
    */
    var bulkSelect = function () {
        var table = document.querySelector('[data-bulk-table]');

        if (!table) {
            return;
        }

        var bar = document.querySelector('[data-bulk-bar]');
        var count = bar.querySelector('[data-bulk-count]');
        var selectAll = table.querySelector('[data-bulk-select-all]');

        var boxes = function () {
            return Array.prototype.slice.call(table.querySelectorAll('[data-bulk-checkbox]'));
        };

        var refresh = function () {
            var all = boxes();
            var checked = all.filter(function (box) { return box.checked; });

            bar.classList.toggle('d-none', checked.length === 0);
            bar.classList.toggle('d-flex', checked.length > 0);
            count.textContent = checked.length + ' selected';

            if (selectAll) {
                selectAll.checked = checked.length > 0 && checked.length === all.length;
                selectAll.indeterminate = checked.length > 0 && checked.length < all.length;
            }
        };

        if (selectAll) {
            selectAll.addEventListener('change', function () {
                boxes().forEach(function (box) { box.checked = selectAll.checked; });
                refresh();
            });
        }

        table.addEventListener('change', function (event) {
            if (event.target.matches('[data-bulk-checkbox]')) {
                refresh();
            }
        });

        refresh();
    };

    /*
    | The sidebar: a mobile drawer below `lg`, a collapse-to-icons toggle at
    | `lg` and up. Orchid's own `sidebar.js` behind this, minus the half that
    | marks a clicked link "active" -- ours already renders that server-side,
    | correctly, from the route that answered the request, so there is
    | nothing for a click handler to get out of step with.
    */
    var railToggle = function () {
        var rail = document.getElementById('adminRail');
        var backdrop = document.querySelector('.rail-backdrop');

        if (!rail) {
            return;
        }

        var MOBILE_BREAKPOINT = 992;
        var COLLAPSED_KEY = 'tb-admin-rail-collapsed';

        var isMobile = function () {
            return window.innerWidth < MOBILE_BREAKPOINT;
        };

        var openMobile = function () {
            rail.classList.add('is-open');

            if (backdrop) {
                backdrop.classList.add('is-visible');
            }
        };

        var closeMobile = function () {
            rail.classList.remove('is-open');

            if (backdrop) {
                backdrop.classList.remove('is-visible');
            }
        };

        var toggleDesktopCollapse = function () {
            var root = document.documentElement;
            var collapsed = root.hasAttribute('data-rail-collapsed');

            if (collapsed) {
                root.removeAttribute('data-rail-collapsed');
            } else {
                root.setAttribute('data-rail-collapsed', '');
            }

            try {
                window.localStorage.setItem(COLLAPSED_KEY, collapsed ? '0' : '1');
            } catch (e) {}
        };

        document.querySelectorAll('[data-rail-toggle]').forEach(function (button) {
            button.addEventListener('click', function () {
                if (isMobile()) {
                    rail.classList.contains('is-open') ? closeMobile() : openMobile();
                } else {
                    toggleDesktopCollapse();
                }
            });
        });

        document.querySelectorAll('[data-rail-close]').forEach(function (el) {
            el.addEventListener('click', closeMobile);
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && rail.classList.contains('is-open')) {
                closeMobile();
            }
        });

        // A drawer left open across a resize past the breakpoint would sit
        // there translated back into a static sidebar's place.
        var resizeTimer = null;

        window.addEventListener('resize', function () {
            window.clearTimeout(resizeTimer);
            resizeTimer = window.setTimeout(function () {
                if (!isMobile()) {
                    closeMobile();
                }
            }, 120);
        });
    };

    /*
    | The dashboard's two real charts -- a sparkline on the Rates card and a
    | ranking of the five most-searched routes. Only `Chart` global exists at
    | all on the one page that loads `chart.umd.min.js`, so every other page
    | hits the guard and returns (G2.2, #288).
    |
    | Colours read from the page's own tokens rather than being written here
    | a second time, so a chart drawn in the dark palette does not need its
    | own copy kept in step with `admin.css`.
    */
    var charts = function () {
        if (typeof Chart === 'undefined') {
            return;
        }

        var canvases = document.querySelectorAll('.js-chart');

        if (canvases.length === 0) {
            return;
        }

        var style = getComputedStyle(document.documentElement);
        var signal = style.getPropertyValue('--signal').trim();
        var quiet = style.getPropertyValue('--ink-quiet').trim();
        var rule = style.getPropertyValue('--rule').trim();

        canvases.forEach(function (canvas) {
            if (canvas.dataset.chart === 'sparkline') {
                sparkline(canvas, signal);
            } else if (canvas.dataset.chart === 'searches') {
                searchesChart(canvas, signal, quiet, rule);
            }
        });
    };

    /*
    | A line with nothing else on it: no axis, no grid, no legend, no points
    | -- the shape is the whole message, the way Orchid's own
    | `.orchid-stat-card__spark` is used.
    */
    var sparkline = function (canvas, colour) {
        var points = JSON.parse(canvas.dataset.points || '{}');

        new Chart(canvas, {
            type: 'line',
            data: {
                labels: Object.keys(points),
                datasets: [{
                    data: Object.values(points),
                    borderColor: colour,
                    borderWidth: 2,
                    pointRadius: 0,
                    tension: .3,
                    fill: false
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: false,
                plugins: { legend: { display: false }, tooltip: { enabled: false } },
                scales: {
                    x: { display: false },
                    y: { display: false }
                }
            }
        });
    };

    /*
    | Five real counts, ranked -- not a trend, which `search` cannot answer
    | (see `DashboardRepository::topSearches()`). Horizontal, so a route code
    | reads left to right the way it is written.
    */
    var searchesChart = function (canvas, colour, mutedColour, gridColour) {
        var rows = JSON.parse(canvas.dataset.rows || '[]');

        new Chart(canvas, {
            type: 'bar',
            data: {
                labels: rows.map(function (row) { return row.from + ' → ' + row.to; }),
                datasets: [{
                    data: rows.map(function (row) { return row.count; }),
                    backgroundColor: colour,
                    borderRadius: 4,
                    maxBarThickness: 22
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                animation: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: { color: mutedColour, precision: 0 },
                        grid: { color: gridColour }
                    },
                    y: {
                        ticks: { color: mutedColour },
                        grid: { display: false }
                    }
                }
            }
        });
    };

    markdownPreview();
    confirmFirst();
    toasts();
    copyButtons();
    themeToggle();
    densityToggle();
    bulkSelect();
    railToggle();
    charts();
}());

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
    | A dropdown whose toggle sits in a `position: sticky` cell -- the
    | tickets table's own actions column (G8.3, #338) -- opens in the wrong
    | place with Bootstrap's default Popper strategy. `position: absolute`
    | positions the menu against the nearest positioned ancestor, which is
    | the sticky cell rather than the toggle itself, so the menu renders
    | wherever that cell's own box happens to be instead of beside the
    | button that opened it. `strategy: 'fixed'` positions against the
    | viewport instead, which nothing in between can misdirect it to.
    |
    | Not a `data-bs-strategy` attribute: Bootstrap's `Dropdown.Default` has
    | no such option, only `popperConfig`, which takes a function -- not
    | something a data attribute can carry.
    */
    var fixedStrategyDropdowns = function () {
        if (typeof bootstrap === 'undefined') {
            return;
        }

        document.querySelectorAll('[data-fixed-dropdown]').forEach(function (toggle) {
            new bootstrap.Dropdown(toggle, {
                popperConfig: function (defaultConfig) {
                    return Object.assign({}, defaultConfig, { strategy: 'fixed' });
                }
            });
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
    | Every admin timestamp is rendered UTC server-side, deliberately (G12,
    | #360's own template comment has the reasoning) -- this rewrites each
    | one's text to the browser's own local zone, the only place that is
    | known with no accounts table to store a preference in. The UTC
    | reading it replaces was already correct, just not local, so a page
    | with JS disabled or blocked still shows a real, honest time.
    |
    | `en-GB`, not the visitor's own locale: only the zone should change --
    | the panel's own day-month-year, 24-hour style should not vary by who
    | is reading it.
    */
    var LOCAL_TIME_MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    var localizeTimestampsPad = function (n) {
        return n < 10 ? '0' + n : String(n);
    };

    var localizeTimestamps = function () {
        document.querySelectorAll('time[data-local]').forEach(function (el) {
            var flavor = el.dataset.local;
            var iso = el.getAttribute('datetime');

            if (!iso) {
                return;
            }

            var instant = new Date(iso);

            if (isNaN(instant.getTime())) {
                return;
            }

            // A `Date`'s own getters already read the runtime's local
            // timezone -- no `Intl` needed, and no locale quirk (`en-GB`
            // gives September as "Sept", not the three letters every date
            // server-side already uses) to keep in step by hand.
            var day = instant.getDate();
            var month = LOCAL_TIME_MONTHS[instant.getMonth()];
            var year = instant.getFullYear();
            var time = localizeTimestampsPad(instant.getHours()) + ':' + localizeTimestampsPad(instant.getMinutes());

            if (flavor === 'date') {
                el.textContent = day + ' ' + month + ' ' + year;
            } else if (flavor === 'freshness') {
                el.textContent = day + ' ' + month + ', ' + time;
            } else if (flavor === 'datetime') {
                el.textContent = day + ' ' + month + ' ' + year + ', ' + time;
            } else if (flavor === 'datetime-seconds') {
                var seconds = localizeTimestampsPad(instant.getSeconds());
                el.textContent = day + ' ' + month + ' ' + year + ', ' + time + ':' + seconds;
            }
        });
    };

    /*
    | The same toast, built by hand for an answer `ajaxBookingForms()` gets
    | back from `fetch()` rather than a queued flash -- there is nothing in
    | the session for `flash()` to have written into the page, since the
    | page never reloaded. `layout.html.twig`'s `[data-toast-container]` is
    | always there now, flash or not, precisely so this has somewhere to put
    | one (G8.3, #338).
    |
    | `textContent`, not `innerHTML`, for the message: the server already
    | escapes what `{{ flash.message }}` prints, and a message built here
    | from JSON deserves the same rather than a second, easier-to-miss place
    | that could inject markup into the page.
    */
    var showToast = function (message, tone) {
        var container = document.querySelector('[data-toast-container]');

        if (!container || typeof bootstrap === 'undefined') {
            return;
        }

        var el = document.createElement('div');
        var isError = tone === 'danger';

        el.className = 'toast align-items-center text-bg-' + (tone || 'success') + ' border-0';
        el.setAttribute('role', isError ? 'alert' : 'status');
        el.setAttribute('aria-live', isError ? 'assertive' : 'polite');
        el.setAttribute('aria-atomic', 'true');
        el.innerHTML = '<div class="d-flex"><div class="toast-body"></div>'
            + '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div>';
        el.querySelector('.toast-body').textContent = message;
        container.appendChild(el);

        var toast = new bootstrap.Toast(el);

        // Bootstrap hides a toast on a timer or a close click but never
        // removes its element -- fine for the one the layout renders once,
        // not fine for a container this keeps appending to on every action.
        el.addEventListener('hidden.bs.toast', function () {
            toast.dispose();
            el.remove();
        });

        toast.show();
    };

    /*
    | The booking page's forms, answered by `fetch()` instead of a reload --
    | every one of them already posts to `/admin/bookings/{id}` and gets a
    | flash and the whole page back; this asks for JSON instead and drops
    | its rendering of `#booking-content`/`#booking-tools` in over what is
    | there rather than navigating, which is the entire point: a reload sent
    | whoever just edited a ticket or a remark back to the top of a long
    | page, past the block they were just looking at (G8.3, #338).
    |
    | Delegated from `document`, the same as `confirmFirst()`/`copyButtons()`
    | above -- a ticket this adds needs no re-wiring of its own new row's
    | buttons for that reason. `fixedStrategyDropdowns()` is the one
    | exception: it constructs an actual `bootstrap.Dropdown` per toggle
    | rather than relying on Bootstrap's own delegated handling, so a toggle
    | this drops in needs that call run again to get the same fix.
    |
    | A network failure falls through to the form's own plain POST -- not a
    | second error path to maintain, the one this whole page already had.
    */
    var ajaxBookingForms = function () {
        var content = document.getElementById('booking-content');
        var tools = document.getElementById('booking-tools');

        if (!content) {
            return;
        }

        var owns = function (form) {
            return content.contains(form) || (tools !== null && tools.contains(form));
        };

        document.addEventListener('submit', function (event) {
            var form = event.target;

            if (!(form instanceof HTMLFormElement) || !owns(form)) {
                return;
            }

            event.preventDefault();

            // A submit from inside `#ticketAddModal`/`#ticketNumberModal{id}`
            // is about to destroy that modal's own DOM node along with the
            // rest of `#booking-content` -- see the comment further down for
            // what that costs Bootstrap's own cleanup. It also costs focus:
            // nothing left holding it moves it anywhere, so a keyboard user
            // silently lands back on `<body>` (G5.3, #318). Recorded here,
            // before the node it would be found on is gone.
            var modalWasOpen = form.closest('.modal.show') !== null;

            // `innerHTML` does not preserve scroll on its own: the container
            // goes briefly empty as the browser parses the replacement, the
            // document is shorter for that instant, and the scroll position
            // clamps to whatever the shorter page allows -- which for a form
            // near the bottom is the top. Read before, restore after: the one
            // reason this whole feature exists is so editing a block near the
            // bottom does not throw the reader back to the top of it (G8.3,
            // #338).
            var scrollY = window.scrollY;

            // Not `form.action`: every form on this page carries a hidden
            // `<input name="action">`, and HTML's named-element access
            // shadows the form's own `action` IDL property with that input
            // once it exists -- `form.action` reads back the *element*, not
            // the URL, everywhere on this page. The attribute is the one
            // thing that cannot be shadowed this way.
            fetch(form.getAttribute('action'), {
                method: 'POST',
                headers: { 'Accept': 'application/json' },
                body: new FormData(form, event.submitter)
            }).then(function (response) {
                return response.json();
            }).then(function (json) {
                if (typeof json.content_html === 'string') {
                    content.innerHTML = json.content_html;
                }

                if (tools !== null && typeof json.tools_html === 'string') {
                    tools.innerHTML = json.tools_html;
                }

                // Neither swap runs through the page's own load-time init --
                // a timestamp just written into either one would otherwise
                // sit in UTC until the next full page load.
                localizeTimestamps();

                // A submit from inside `#ticketNumberModal{id}` or
                // `#ticketAddModal` just destroyed the open modal's own DOM
                // node along with the rest of `#booking-content` -- Bootstrap
                // never gets to run the backdrop/`modal-open` cleanup it
                // normally does on its own `hide()`, because that cleanup is
                // wired to a node that no longer exists. Nothing rendered
                // here is ever open on arrival, so if nothing carries `.show`
                // now, nothing should be holding the page open either.
                if (!document.querySelector('.modal.show')) {
                    document.querySelectorAll('.modal-backdrop').forEach(function (el) {
                        el.remove();
                    });
                    document.body.classList.remove('modal-open');
                    document.body.style.removeProperty('overflow');
                    document.body.style.removeProperty('padding-right');

                    // Focus went nowhere when the modal's own node was
                    // destroyed above -- landing it on the refreshed content
                    // itself is a real destination, the same reasoning
                    // `<main id="main" tabindex="-1">` already gives its own
                    // skip link.
                    if (modalWasOpen) {
                        content.focus();
                    }
                }

                fixedStrategyDropdowns();
                showToast(json.message, json.tone);
                window.scrollTo(0, scrollY);
            }).catch(function () {
                form.submit();
            });
        });
    };

    /*
    | The Diagnostics page's own test-send form, answered by fetch() instead
    | of a reload -- the same reasoning ajaxBookingForms() gives, simpler
    | because nothing else on this page changes when a check runs, so there
    | is no HTML to swap in.
    |
    | The button disables and grows a spinner for the run's whole length --
    | without it, a second click before the first send lands would be a
    | second real email out.
    |
    | Answered two ways at once, on purpose: the toast is the same glance-able
    | nudge every other admin form gives, and `.js-diagnostic-console` is a
    | second, visible log beside the form itself -- a toast is gone in a few
    | seconds, and this page exists precisely so an operator can read back
    | what the run before last actually said. Also logged to the real
    | console: this calls a third party over the network, and "it did
    | nothing" is otherwise a dead end to debug from the page alone.
    */
    var diagnosticsForm = function () {
        var form = document.querySelector('.js-diagnostic-form');

        if (!form) {
            return;
        }

        var submit = form.querySelector('[type="submit"]');
        var spinner = submit.querySelector('.spinner-border');
        var label = submit.querySelector('.js-diagnostic-label');
        var originalLabel = label.textContent;
        var out = form.parentElement.querySelector('.js-diagnostic-console');
        var empty = out ? out.querySelector('.js-diagnostic-console-empty') : null;

        // A line in the visible log -- timestamped, so a run can be told
        // apart from the one before it without relying on scroll order alone.
        var logLine = function (text, kind) {
            if (!out) {
                return;
            }

            if (empty) {
                empty.remove();
                empty = null;
            }

            var line = document.createElement('p');
            var stamp = new Date().toLocaleTimeString();

            line.className = 'diagnostic-console__line diagnostic-console__line--' + kind;
            line.textContent = '[' + stamp + '] ' + text;
            out.appendChild(line);
            out.scrollTop = out.scrollHeight;
        };

        form.addEventListener('submit', function (event) {
            event.preventDefault();

            var check = form.querySelector('[name="check"]').value;

            console.log('[diagnostics] running "' + check + '"…');
            logLine('Running "' + check + '"…', 'quiet');

            submit.disabled = true;
            spinner.classList.remove('d-none');
            label.textContent = 'Sending…';

            fetch(form.getAttribute('action'), {
                method: 'POST',
                headers: { 'Accept': 'application/json' },
                body: new FormData(form)
            }).then(function (response) {
                return response.json().then(function (json) {
                    return { ok: response.ok, status: response.status, json: json };
                });
            }).then(function (result) {
                console.log('[diagnostics] "' + check + '" answered ' + result.status, result.json);
                logLine(result.json.message, result.json.tone === 'success' ? 'ok' : 'error');
                showToast(result.json.message, result.json.tone);
            }).catch(function (error) {
                console.log('[diagnostics] "' + check + '" request failed', error);
                logLine('That did not work: ' + error, 'error');
                showToast('That did not work. Try again in a moment.', 'danger');
            }).finally(function () {
                submit.disabled = false;
                spinner.classList.add('d-none');
                label.textContent = originalLabel;
            });
        });
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
        var well = document.querySelector('.well');

        if (!rail) {
            return;
        }

        var MOBILE_BREAKPOINT = 992;
        var COLLAPSED_KEY = 'tb-admin-rail-collapsed';

        // Who to give focus back to on close -- whichever toggle button
        // actually opened the drawer, not always the same one on a page
        // with more than one (G5.3, #318).
        var opener = null;

        var isMobile = function () {
            return window.innerWidth < MOBILE_BREAKPOINT;
        };

        var openMobile = function (trigger) {
            rail.classList.add('is-open');

            if (backdrop) {
                backdrop.classList.add('is-visible');
            }

            // Everything the drawer now covers stops being reachable by
            // Tab too, the same way a native `<dialog>` would -- without
            // this, a keyboard user could tab straight past the open
            // drawer into content it is visually sitting on top of.
            if (well) {
                well.inert = true;
            }

            opener = trigger || null;

            var closeButton = rail.querySelector('button[data-rail-close]');

            if (closeButton) {
                closeButton.focus();
            }
        };

        var closeMobile = function () {
            rail.classList.remove('is-open');

            if (backdrop) {
                backdrop.classList.remove('is-visible');
            }

            if (well) {
                well.inert = false;
            }

            if (opener) {
                opener.focus();
                opener = null;
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
                    rail.classList.contains('is-open') ? closeMobile() : openMobile(button);
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
    | Two real charts -- the bookings hero's sparklines and the Searches
    | page's own volume trend (G16, #369). Only `Chart` global exists at all
    | on a page that loads `chart.umd.min.js`, so every other page hits the
    | guard and returns (G2.2, #288).
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
                // The bookings hero is always dark regardless of the panel's
                // own light/dark toggle (G7.1, #332), so its sparklines take
                // a fixed colour by tone rather than the ambient `--signal`/
                // `--good`/`--bad`, which are tuned for whichever palette is
                // currently showing and would go low-contrast against a hero
                // that never follows it.
                var tone = HERO_SPARKLINE_TONES[canvas.dataset.tone];
                sparkline(canvas, tone || signal);
            } else if (canvas.dataset.chart === 'volume') {
                volumeChart(canvas, signal, quiet, rule);
            }
        });
    };

    var HERO_SPARKLINE_TONES = { good: '#a3e635', bad: '#fda4af', neutral: '#7dd3fc' };

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
    | Real per-day counts (`SearchRepository::dailyCounts()`, G7.1, #332) --
    | axes, gridlines and a tooltip, unlike the sparkline above, because this
    | one is read for its actual values rather than just its shape.
    */
    var volumeChart = function (canvas, colour, mutedColour, gridColour) {
        var series = JSON.parse(canvas.dataset.series || '{}');

        new Chart(canvas, {
            type: 'line',
            data: {
                labels: Object.keys(series),
                datasets: [{
                    data: Object.values(series),
                    borderColor: colour,
                    backgroundColor: colour,
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
                plugins: { legend: { display: false } },
                scales: {
                    x: {
                        ticks: { color: mutedColour, maxRotation: 0, autoSkipPadding: 16 },
                        grid: { display: false }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { color: mutedColour, precision: 0 },
                        grid: { color: gridColour }
                    }
                }
            }
        });
    };

    /*
    | Recently-viewed bookings (G4.3, #314) -- `localStorage`-backed, since a
    | single operator's own "what did I just look at" needs no server table.
    | Stored in the exact `{type, label, meta, url}` shape the command
    | palette's own search results already use, so the palette can draw
    | either one with the same `render()`.
    */
    var RECENT_BOOKINGS_KEY = 'tb-recent-bookings';
    var RECENT_BOOKINGS_MAX = 8;

    var readRecentBookings = function () {
        try {
            var stored = JSON.parse(window.localStorage.getItem(RECENT_BOOKINGS_KEY) || '[]');

            return Array.isArray(stored) ? stored : [];
        } catch (e) {
            return [];
        }
    };

    // Read from the booking page's own `#booking-content` -- present once
    // per real navigation to the page, and untouched by `ajaxBookingForms()`
    // swapping what is inside it, so a cancel/reinstate never bumps a
    // booking's place in this list on its own.
    var recordRecentBooking = function () {
        var content = document.getElementById('booking-content');

        if (!content || !content.dataset.recentBookingUrl) {
            return;
        }

        var entry = {
            type: 'Recently viewed',
            label: content.dataset.recentBookingLabel,
            meta: content.dataset.recentBookingMeta,
            url: content.dataset.recentBookingUrl
        };

        try {
            var list = readRecentBookings().filter(function (item) {
                return item.url !== entry.url;
            });

            list.unshift(entry);

            window.localStorage.setItem(RECENT_BOOKINGS_KEY, JSON.stringify(list.slice(0, RECENT_BOOKINGS_MAX)));
        } catch (e) {}
    };

    /*
    | The command palette (G4.1, #312) -- `Ctrl`/`Cmd`+`K` from anywhere, or
    | the search icon in the topbar, opens the same thing: a booking, a
    | subscriber, a help-content article, without going to that thing's own
    | list page first. Built on a shared Bootstrap modal -- the same
    | primitive `#confirmModal` uses -- rather than a bespoke overlay, since
    | correct focus trapping and Escape handling are exactly the kind of
    | thing not worth re-implementing for one more component.
    |
    | Debounced like `markdownPreview()` above, and for the same reason: a
    | request per keystroke is a request too many. Faster than that one's
    | 500ms, because a palette that feels laggy defeats the point of it.
    */
    var commandPalette = function () {
        var modalEl = document.getElementById('commandPalette');
        var input = document.querySelector('[data-palette-input]');
        var resultsEl = document.querySelector('[data-palette-results]');

        if (!modalEl || !input || !resultsEl || typeof bootstrap === 'undefined') {
            return;
        }

        var modal = new bootstrap.Modal(modalEl);
        var items = [];
        var activeIndex = -1;
        var timer = null;
        var inFlight = null;

        var ICONS = {
            'Booking': 'ticket-perforated',
            'Subscriber': 'envelope',
            'Help content': 'life-preserver',
            'Recently viewed': 'clock-history'
        };

        var clear = function () {
            resultsEl.innerHTML = '';
            items = [];
            activeIndex = -1;
            input.removeAttribute('aria-activedescendant');
        };

        var highlight = function (index) {
            items.forEach(function (item) {
                item.classList.remove('is-active');
                item.setAttribute('aria-selected', 'false');
            });

            if (index >= 0 && index < items.length) {
                items[index].classList.add('is-active');
                items[index].setAttribute('aria-selected', 'true');
                items[index].scrollIntoView({ block: 'nearest' });
                input.setAttribute('aria-activedescendant', items[index].id);
            } else {
                input.removeAttribute('aria-activedescendant');
            }

            activeIndex = index;
        };

        var render = function (list) {
            clear();

            if (list.length === 0) {
                resultsEl.innerHTML = '<p class="palette__empty">No matches.</p>';

                return;
            }

            var lastType = null;

            list.forEach(function (result) {
                if (result.type !== lastType) {
                    var group = document.createElement('div');
                    group.className = 'palette__group';
                    group.textContent = result.type;
                    resultsEl.appendChild(group);
                    lastType = result.type;
                }

                var item = document.createElement('button');
                item.type = 'button';
                item.className = 'palette__item';
                item.id = 'palette-option-' + items.length;
                item.setAttribute('role', 'option');
                item.setAttribute('aria-selected', 'false');
                item.dataset.url = result.url;

                var icon = document.createElement('i');
                icon.className = 'bi bi-' + (ICONS[result.type] || 'search') + ' palette__item-icon';
                icon.setAttribute('aria-hidden', 'true');
                item.appendChild(icon);

                var label = document.createElement('span');
                label.className = 'palette__item-label';
                label.textContent = result.label;
                item.appendChild(label);

                if (result.meta) {
                    var meta = document.createElement('span');
                    meta.className = 'palette__item-meta';
                    meta.textContent = result.meta;
                    item.appendChild(meta);
                }

                item.addEventListener('click', function () {
                    window.location.href = result.url;
                });

                resultsEl.appendChild(item);
                items.push(item);
            });
        };

        // What the box shows before a term is typed -- G4.3 (#314), the
        // same bookings `recordRecentBooking()` below writes, so opening
        // the palette with nothing typed is a way back to what was just
        // looked at rather than a blank box.
        var showRecent = function () {
            var recent = readRecentBookings();

            if (recent.length === 0) {
                clear();
                resultsEl.innerHTML = '<p class="palette__empty">Search for a booking, a subscriber or a help article.</p>';

                return;
            }

            render(recent);
        };

        var search = function (term) {
            if (inFlight) {
                inFlight.abort();
            }

            if (term === '') {
                showRecent();

                return;
            }

            inFlight = new AbortController();

            fetch('/admin/search?q=' + encodeURIComponent(term), {
                headers: { 'Accept': 'application/json' },
                signal: inFlight.signal
            }).then(function (response) {
                return response.json();
            }).then(function (json) {
                render(json.results || []);
            }).catch(function () {
                // An aborted request is the normal case here: a faster
                // keystroke arrived before this one's answer did.
            });
        };

        input.addEventListener('input', function () {
            window.clearTimeout(timer);
            var term = input.value.trim();
            timer = window.setTimeout(function () { search(term); }, 150);
        });

        input.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                highlight(Math.min(activeIndex + 1, items.length - 1));
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                highlight(Math.max(activeIndex - 1, 0));
            } else if (event.key === 'Enter' && activeIndex >= 0) {
                event.preventDefault();
                window.location.href = items[activeIndex].dataset.url || '';
            }
        });

        modalEl.addEventListener('shown.bs.modal', function () {
            input.focus();

            if (input.value.trim() === '') {
                showRecent();
            }
        });

        modalEl.addEventListener('hidden.bs.modal', clear);

        // The one place `Ctrl`/`Cmd` combine with a bare letter on this
        // page: safe to catch from anywhere, unlike a bare key, because
        // nothing a reader types into a field ever collides with holding
        // a modifier down too.
        document.addEventListener('keydown', function (event) {
            if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                modal.show();
            }
        });
    };

    /*
    | The job editor's "Command" select carries every schedulable command's
    | own description and arguments as one JSON blob -- `data-help`, read
    | once rather than fetched per pick, since there are only a dozen of
    | them and the whole point is to answer "what does this take?" before a
    | save round-trips to find out.
    |
    | The `arguments` field itself is picked, not typed: a dropdown of
    | whatever the selected command actually takes, a value box next to it
    | when the pick needs one, and an "Add" that turns it into a removable
    | chip -- so more than one can build up (`10000` and `--level` together)
    | without hand-typing the exact flag spelling.
    |
    | Switching commands clears the chips, since another command's flags do
    | not apply -- except once, on page load, when whatever the job already
    | has (an edit) is parsed into chips instead of being thrown away. A
    | token that parse cannot place -- a flag `data-help` does not list, an
    | edit from outside this page -- is kept as `leftover` and folded back
    | into the value verbatim rather than silently dropped.
    */
    var scheduleCommandHelp = function () {
        var select = document.getElementById('command_base');
        var description = document.querySelector('.js-command-description');
        var argumentsInput = document.getElementById('arguments');
        var picker = document.querySelector('.js-argument-picker');
        var pickerSelect = document.querySelector('.js-argument-picker-select');
        var pickerValue = document.querySelector('.js-argument-picker-value');
        var pickerAdd = document.querySelector('.js-argument-picker-add');
        var pickerList = document.querySelector('.js-argument-picker-list');
        var pickerEmpty = document.querySelector('.js-argument-picker-empty');

        if (!select || !description || !argumentsInput || !picker || !pickerSelect
            || !pickerValue || !pickerAdd || !pickerList || !pickerEmpty) {
            return;
        }

        var help = JSON.parse(select.dataset.help || '{}');
        var chips = [];
        var leftover = '';
        var firstRender = true;

        // Arguments and options, as one flat list of the same shape --
        // `key` is what gets typed on the command line (`flights`, `--day`),
        // `isArgument` is what tells `serialize()` whether it needs the `--`.
        var currentItems = function () {
            var info = help[select.value];

            if (!info) {
                return [];
            }

            return info.arguments.map(function (argument) {
                return {
                    key: argument.name,
                    label: argument.name,
                    description: argument.description,
                    required: argument.required,
                    takesValue: true,
                    isArgument: true
                };
            }).concat(info.options.map(function (option) {
                return {
                    key: option.flag,
                    label: option.flag,
                    description: option.description,
                    takesValue: option.takesValue,
                    isArgument: false
                };
            }));
        };

        var chipText = function (chip) {
            if (chip.isArgument) {
                return chip.label + ' = ' + chip.value;
            }

            return chip.takesValue && chip.value !== '' ? chip.key + '=' + chip.value : chip.key;
        };

        var serialize = function () {
            var composed = chips.map(function (chip) {
                return chip.isArgument ? chip.value : (chip.takesValue && chip.value !== '' ? chip.key + '=' + chip.value : chip.key);
            }).join(' ');

            if (leftover === '') {
                return composed;
            }

            return composed === '' ? leftover : composed + ' ' + leftover;
        };

        var updateValueVisibility = function () {
            var items = currentItems();
            var picked = items.filter(function (item) { return item.key === pickerSelect.value; })[0];

            pickerValue.style.display = picked && picked.takesValue ? '' : 'none';
        };

        var renderSelect = function () {
            var items = currentItems();
            var chosen = chips.map(function (chip) { return chip.key; });

            pickerSelect.innerHTML = items.map(function (item) {
                var disabled = chosen.indexOf(item.key) !== -1;
                var required = item.isArgument && item.required ? ' (required)' : '';

                return '<option value="' + item.key + '"' + (disabled ? ' disabled' : '') + '>'
                    + item.label + required + (item.description ? ' — ' + item.description : '') + '</option>';
            }).join('');

            updateValueVisibility();
        };

        var renderChips = function () {
            argumentsInput.value = serialize();

            pickerList.innerHTML = chips.map(function (chip, index) {
                return '<li class="argument-chip">'
                    + '<code>' + chipText(chip) + '</code>'
                    + '<button type="button" class="argument-chip__remove js-argument-chip-remove" data-index="' + index + '"'
                    + ' aria-label="Remove ' + chipText(chip) + '"><i class="bi bi-x" aria-hidden="true"></i></button>'
                    + '</li>';
            }).join('');
        };

        // Splits an existing `arguments` string (an edit) back into chips
        // against the now-selected command's own list -- a bare token is the
        // argument's value, `--flag` or `--flag=value` an option's. Anything
        // that does not match either shape is kept as `leftover`.
        var parseExisting = function (raw, items) {
            var parsedChips = [];
            var unparsed = [];

            raw.split(/\s+/).filter(Boolean).forEach(function (token) {
                if (token.indexOf('--') === 0) {
                    var eq = token.indexOf('=');
                    var flag = eq === -1 ? token : token.slice(0, eq);
                    var value = eq === -1 ? '' : token.slice(eq + 1);
                    var option = items.filter(function (item) { return !item.isArgument && item.key === flag; })[0];

                    if (option) {
                        parsedChips.push({ key: option.key, label: option.label, value: value, takesValue: option.takesValue, isArgument: false });
                    } else {
                        unparsed.push(token);
                    }
                } else {
                    var argument = items.filter(function (item) { return item.isArgument; })[0];

                    if (argument && !parsedChips.some(function (chip) { return chip.isArgument; })) {
                        parsedChips.push({ key: argument.key, label: argument.label, value: token, takesValue: true, isArgument: true });
                    } else {
                        unparsed.push(token);
                    }
                }
            });

            return { chips: parsedChips, leftover: unparsed.join(' ') };
        };

        var render = function () {
            var info = help[select.value];

            description.textContent = info ? info.description : '';

            var items = currentItems();

            if (firstRender) {
                var parsed = parseExisting(argumentsInput.value, items);

                chips = parsed.chips;
                leftover = parsed.leftover;
                firstRender = false;
            } else {
                chips = [];
                leftover = '';
            }

            picker.classList.toggle('d-none', items.length === 0);
            pickerEmpty.classList.toggle('d-none', items.length !== 0);

            renderSelect();
            renderChips();
        };

        pickerSelect.addEventListener('change', updateValueVisibility);

        pickerAdd.addEventListener('click', function () {
            var items = currentItems();
            var picked = items.filter(function (item) { return item.key === pickerSelect.value; })[0];

            if (!picked) {
                return;
            }

            var value = picked.takesValue ? pickerValue.value.trim() : '';

            if (picked.takesValue && value === '') {
                return;
            }

            chips = chips.filter(function (chip) { return chip.key !== picked.key; });
            chips.push({ key: picked.key, label: picked.label, value: value, takesValue: picked.takesValue, isArgument: picked.isArgument });

            pickerValue.value = '';
            renderSelect();
            renderChips();
        });

        pickerList.addEventListener('click', function (event) {
            var button = event.target.closest('.js-argument-chip-remove');

            if (!button) {
                return;
            }

            chips.splice(Number(button.dataset.index), 1);
            renderSelect();
            renderChips();
        });

        select.addEventListener('change', render);
        render();
    };

    markdownPreview();
    confirmFirst();
    fixedStrategyDropdowns();
    toasts();
    localizeTimestamps();
    copyButtons();
    themeToggle();
    densityToggle();
    bulkSelect();
    railToggle();
    charts();
    ajaxBookingForms();
    commandPalette();
    recordRecentBooking();
    diagnosticsForm();
    scheduleCommandHelp();
}());

(function ($) {
    'use strict';

    const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

    try {
        const singleDatePickers = $('.js-single-datepicker');
        const inputDateFormat = 'YYYY-MM-DD';
        const showDateFormat  = 'MMMM D, YYYY';

        let inputStart = $("#depart_date_value").val();
        let startDate  = inputStart
            ? moment(inputStart).format(showDateFormat)
            : moment().format(showDateFormat);

        let inputEnd   = $("#return_date_value").val();
        let endDate    = inputEnd
            ? moment(inputEnd).format(showDateFormat)
            : moment(inputStart).add(1, 'day').format(showDateFormat);

        singleDatePickers.each(function () {
            const $this = $(this);
            const elementID = $this.attr('id');
            const dropId = $this.data('drop');
            const today = moment().format(showDateFormat);

            const commonConfig = {
                autoApply: true,
                showCustomRangeLabel: false,
                autoUpdateInput: true,
                startDate: startDate,
                endDate: endDate,
                minDate: today,
                opens: "center",
                drops: "auto",
                locale: {
                    format: showDateFormat,
                    separator: " – ",
                    firstDay: 1
                }
            };

            commonConfig.singleDatePicker = (elementID == 'oneway_depart_date');

            // Initialize the date range picker
            $this.daterangepicker(commonConfig);
        });

        $(singleDatePickers).on('apply.daterangepicker', function (ev, picker) {
            $("#depart_date_value").val(picker.startDate.format(inputDateFormat));

            if (ev.target.id == 'roundtrip_dates') {
                $("#return_date_value").val(picker.endDate.format(inputDateFormat));
            }
        });

        let departDate_roundtrip = $('#depart_date_value').val();
        let departDate_oneway = $('#depart_date_value').val();

        $('#tab-roundtrip').click(function () {
            $('#tab-roundtrip').prop('disabled', true);
            $('#tab-oneway').prop('disabled', false);

            departDate_oneway = $('#depart_date_value').val();
            $('#depart_date_value').val(departDate_roundtrip);

            $('#hidden_triptype').val('roundtrip');
            $('#return_date_value').prop('disabled', false);
        });
        $('#tab-oneway').click(function () {
            $('#tab-roundtrip').prop('disabled', false);
            $('#tab-oneway').prop('disabled', true);

            departDate_roundtrip = $('#depart_date_value').val();
            $('#depart_date_value').val(departDate_oneway);

            $('#hidden_triptype').val('oneway');
            $('#return_date_value').prop('disabled', true);
        });

    } catch (er) {
        console.log(er);
    }

    /*[ Checkout: fill the form with plausible test data ]
    ===========================================================*/
    try {
        const autofillBar = document.querySelector('[data-autofill]');

        if (autofillBar) {
            // Revealed from script: without JavaScript the button could not do
            // anything, and a control that does nothing is worse than no
            // control. Same reasoning that removed the placeholder links.
            autofillBar.hidden = false;

            // Pools, not fixed rows. Nothing below is a complete identity -- a
            // name is drawn independently of a country, a card of a person --
            // so the combinations multiply out well past anything anyone will
            // exhaust by clicking.
            const FIRST = [
                'Ivan', 'Chloe', 'Mateo', 'Aisha', 'Lukas', 'Priya', 'Noah', 'Marta',
                'Tomas', 'Yuki', 'Amara', 'Felix', 'Sofia', 'Omar', 'Elena', 'Hugo',
                'Nadia', 'Sean', 'Ingrid', 'Rafael',
            ];
            const LAST = [
                'Tarasov', 'Beaulieu', 'Okafor', 'Nguyen', 'Kowalski', 'Ferreira',
                'Lindqvist', 'Hassan', 'Moreau', 'Bianchi', 'Novak', 'Andersen',
                'Costa', 'Volkov', 'Muller', 'Sharma', 'Fontaine', 'Larsen',
                'Rossi', 'Devries',
            ];
            const DOMAINS = [
                'example.com', 'mail.example', 'example.net', 'inbox.example',
                'example.org', 'post.example', 'mailbox.example', 'example.co',
                'letters.example', 'example.email',
            ];

            // Country, dialling code, a number shape, and a postcode shape that
            // belongs to that country -- a Canadian card with a British
            // postcode reads as fake at a glance, which defeats the point.
            // `#` is a digit, `@` an uppercase letter.
            const PLACES = [
                { country: 'CA', phone: '+1 514 ### ####', postcode: '@#@ #@#' },
                { country: 'US', phone: '+1 212 ### ####', postcode: '#####' },
                { country: 'GB', phone: '+44 20 #### ####', postcode: '@@# #@@' },
                { country: 'FR', phone: '+33 1 ## ## ## ##', postcode: '#####' },
                { country: 'DE', phone: '+49 30 ########', postcode: '#####' },
                { country: 'NL', phone: '+31 20 ### ####', postcode: '#### @@' },
                { country: 'ES', phone: '+34 91 ### ####', postcode: '#####' },
                { country: 'IT', phone: '+39 06 #### ####', postcode: '#####' },
                { country: 'AU', phone: '+61 2 #### ####', postcode: '####' },
                { country: 'JP', phone: '+81 3 #### ####', postcode: '###-####' },
                { country: 'SE', phone: '+46 8 ### ## ##', postcode: '### ##' },
                { country: 'PT', phone: '+351 21 ### ####', postcode: '####-###' },
                { country: 'IE', phone: '+353 1 ### ####', postcode: '@## @@##' },
                { country: 'PL', phone: '+48 22 ### ## ##', postcode: '##-###' },
                { country: 'BR', phone: '+55 11 #### ####', postcode: '#####-###' },
            ];

            // Issuer prefixes and lengths. The check digit is computed, so the
            // numbers really are valid -- the form runs Luhn on them and a
            // number that failed would send us round the error path instead of
            // to the confirmation page.
            const SCHEMES = [
                { prefix: '4', length: 16, cvv: 3 },        // Visa
                { prefix: '4539', length: 16, cvv: 3 },     // Visa
                { prefix: '51', length: 16, cvv: 3 },       // Mastercard
                { prefix: '55', length: 16, cvv: 3 },       // Mastercard
                { prefix: '2221', length: 16, cvv: 3 },     // Mastercard (2-series)
                { prefix: '34', length: 15, cvv: 4 },       // Amex
                { prefix: '37', length: 15, cvv: 4 },       // Amex
                { prefix: '6011', length: 16, cvv: 3 },     // Discover
            ];

            const DECLINE = (autofillBar.querySelector('code') || {}).textContent || '';

            const pick = list => list[Math.floor(Math.random() * list.length)];
            const digits = n => Array.from({ length: n }, () => Math.floor(Math.random() * 10)).join('');

            const shape = pattern => pattern.replace(/[#@]/g, ch => ch === '#'
                ? String(Math.floor(Math.random() * 10))
                : 'ABCDEFGHJKLMNPRSTVWXYZ'[Math.floor(Math.random() * 22)]);

            // Luhn: sum with every second digit from the right doubled, then the
            // digit that takes the total to a multiple of ten.
            const luhnCheckDigit = function (partial) {
                let sum = 0;
                let double = true;

                for (let i = partial.length - 1; i >= 0; i--) {
                    let d = Number(partial[i]);
                    if (double) { d *= 2; if (d > 9) { d -= 9; } }
                    sum += d;
                    double = !double;
                }

                return (10 - (sum % 10)) % 10;
            };

            const cardNumber = function (scheme) {
                let number;

                do {
                    const body = scheme.prefix + digits(scheme.length - scheme.prefix.length - 1);
                    number = body + luhnCheckDigit(body);
                    // The one number the checkout declines on purpose. Redrawing
                    // is simpler than explaining why autofill sometimes fails.
                } while (number === DECLINE);

                return number;
            };

            // Grouped the way the card is printed, so it reads like a card.
            const groupCard = n => n.length === 15
                ? n.replace(/^(\d{4})(\d{6})(\d{5})$/, '$1 $2 $3')
                : n.replace(/(\d{4})(?=\d)/g, '$1 ');

            const pad = n => String(n).padStart(2, '0');

            const born = function () {
                // Somewhere between 18 and 75, which is who buys a ticket.
                const now = new Date();
                const year = now.getFullYear() - (18 + Math.floor(Math.random() * 58));
                const month = 1 + Math.floor(Math.random() * 12);
                // 28 keeps every month valid without caring which one it is.
                const day = 1 + Math.floor(Math.random() * 28);

                return year + '-' + pad(month) + '-' + pad(day);
            };

            const expiry = function () {
                // One to five years out, so it is always in the future.
                const now = new Date();
                const year = now.getFullYear() + 1 + Math.floor(Math.random() * 5);

                return pad(1 + Math.floor(Math.random() * 12)) + '/' + String(year).slice(-2);
            };

            // Strip the accents a name might carry before it becomes an address.
            const slug = s => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

            autofillBar.querySelector('[data-autofill-button]').addEventListener('click', function () {
                const form = autofillBar.closest('form');
                const first = pick(FIRST);
                const last = pick(LAST);
                const place = pick(PLACES);
                const scheme = pick(SCHEMES);

                const values = {
                    email: slug(first) + '.' + slug(last) + Math.floor(Math.random() * 90 + 10) + '@' + pick(DOMAINS),
                    phone: shape(place.phone),
                    first_name: first,
                    last_name: last,
                    dob: born(),
                    gender: pick(['F', 'M', 'X']),
                    card_name: first + ' ' + last,
                    card_number: groupCard(cardNumber(scheme)),
                    card_expiry: expiry(),
                    card_cvv: digits(scheme.cvv),
                    billing_postcode: shape(place.postcode),
                    billing_country: place.country,
                };

                Object.keys(values).forEach(function (name) {
                    // By name attribute, not form.elements[name]: that lookup
                    // matches on id as well, and the searchable country field
                    // puts its input's id alongside the select's name -- so it
                    // returned a collection and the country silently stopped
                    // being filled.
                    const field = form.querySelector('[name="' + name + '"]');
                    if (!field) { return; }
                    field.value = values[name];
                    // So anything listening -- validation, a mask -- sees it as
                    // typed rather than as a value that appeared.
                    field.dispatchEvent(new Event('input', { bubbles: true }));
                    field.dispatchEvent(new Event('change', { bubbles: true }));
                });

                const accept = form.querySelector('[name="accept_rules"]');
                if (accept) {
                    accept.checked = true;
                    accept.dispatchEvent(new Event('change', { bubbles: true }));
                }
            });
        }
    } catch (er) {
        console.log(er);
    }

    /*[ Searchable select (billing country) ]
    ===========================================================*/
    try {
        document.querySelectorAll('select[data-searchable]').forEach(function (select) {
            // The select stays: it is what the form submits, what autofill
            // writes to, and what the page is left with if this never runs.
            // Everything below is a layer on top of it.
            const options = [...select.options].filter(o => o.value !== '');
            const placeholder = (select.options[0] || {}).textContent || 'Search…';
            const listId = select.id + '-listbox';

            const wrap = document.createElement('div');
            wrap.className = 'combo';

            const input = document.createElement('input');
            input.type = 'text';
            input.className = select.className + ' combo__input';
            input.setAttribute('role', 'combobox');
            input.setAttribute('aria-expanded', 'false');
            input.setAttribute('aria-controls', listId);
            input.setAttribute('aria-autocomplete', 'list');
            // Off, or the browser's own suggestions cover the list below.
            input.autocomplete = 'off';
            input.placeholder = placeholder.trim();

            const list = document.createElement('ul');
            list.className = 'combo__list';
            list.id = listId;
            list.setAttribute('role', 'listbox');
            list.hidden = true;

            // The label points at the select's id, so the id moves to the thing
            // that now takes the focus and the select keeps only its name.
            input.id = select.id;
            select.id = select.id + '-native';
            select.setAttribute('tabindex', '-1');
            select.setAttribute('aria-hidden', 'true');
            select.classList.add('combo__native');

            select.parentNode.insertBefore(wrap, select);
            wrap.appendChild(input);
            wrap.appendChild(list);
            wrap.appendChild(select);

            let active = -1;
            let matches = [];

            const render = function (query) {
                const needle = query.trim().toLowerCase();
                // Rank, do not just filter. Alphabetical order alone answered
                // "ire" with Bonaire, Cote d'Ivoire and Zaire before Ireland,
                // which is every country containing the letters except the one
                // being typed. A name that starts with the query comes first,
                // then the country code, then anything containing it.
                const rank = function (option) {
                    const name = option.textContent.trim().toLowerCase();

                    if (needle === '') { return 0; }
                    if (name.startsWith(needle)) { return 0; }
                    if (option.value.toLowerCase().startsWith(needle)) { return 1; }
                    if (name.includes(needle)) { return 2; }

                    return -1;
                };

                matches = options
                    .map(o => ({ option: o, rank: rank(o) }))
                    .filter(m => m.rank >= 0)
                    // Stable within a rank, so each band stays alphabetical.
                    .sort((a, b) => a.rank - b.rank)
                    .map(m => m.option);

                list.innerHTML = '';

                if (matches.length === 0) {
                    const empty = document.createElement('li');
                    empty.className = 'combo__empty';
                    empty.textContent = 'No country matches that.';
                    list.appendChild(empty);
                    return;
                }

                matches.forEach(function (option, i) {
                    const li = document.createElement('li');
                    li.className = 'combo__option';
                    li.setAttribute('role', 'option');
                    li.setAttribute('aria-selected', option.value === select.value ? 'true' : 'false');
                    li.dataset.value = option.value;
                    li.textContent = option.textContent.trim();
                    if (i === active) { li.classList.add('is-active'); }
                    list.appendChild(li);
                });
            };

            const open = function () {
                render(input.value === select.selectedOptions[0]?.textContent.trim() ? '' : input.value);
                list.hidden = false;
                input.setAttribute('aria-expanded', 'true');
            };

            const close = function () {
                list.hidden = true;
                input.setAttribute('aria-expanded', 'false');
                active = -1;
            };

            const choose = function (option) {
                select.value = option.value;
                input.value = option.textContent.trim();
                // So validation, autofill and anything else see a real change.
                select.dispatchEvent(new Event('change', { bubbles: true }));
                close();
            };

            const moveActive = function (step) {
                if (list.hidden) { open(); }
                if (matches.length === 0) { return; }
                active = (active + step + matches.length) % matches.length;
                render(input.value);
                const el = list.children[active];
                if (el && el.scrollIntoView) { el.scrollIntoView({ block: 'nearest' }); }
            };

            input.addEventListener('focus', open);
            input.addEventListener('input', function () { active = -1; open(); });

            input.addEventListener('keydown', function (e) {
                if (e.key === 'ArrowDown') { e.preventDefault(); moveActive(1); }
                else if (e.key === 'ArrowUp') { e.preventDefault(); moveActive(-1); }
                else if (e.key === 'Enter') {
                    if (!list.hidden && matches[active]) { e.preventDefault(); choose(matches[active]); }
                } else if (e.key === 'Escape') {
                    close();
                    input.value = select.selectedOptions[0] ? select.selectedOptions[0].textContent.trim() : '';
                }
            });

            list.addEventListener('mousedown', function (e) {
                // mousedown, not click: blur would close the list first.
                const li = e.target.closest('.combo__option');
                if (!li) { return; }
                e.preventDefault();
                choose(options.find(o => o.value === li.dataset.value));
            });

            input.addEventListener('blur', function () {
                close();
                // Whatever half-typed text is left is not a country; show what
                // is actually selected rather than leaving a lie in the box.
                input.value = select.value && select.selectedOptions[0]
                    ? select.selectedOptions[0].textContent.trim()
                    : '';
            });

            // Autofill and the server-rendered value both arrive this way.
            select.addEventListener('change', function () {
                if (document.activeElement !== input) {
                    input.value = select.selectedOptions[0] && select.value
                        ? select.selectedOptions[0].textContent.trim()
                        : '';
                }
            });

            if (select.value) {
                input.value = select.selectedOptions[0].textContent.trim();
            }
        });
    } catch (er) {
        console.log(er);
    }

    /*[ Copy button on README code blocks ]
    ===========================================================*/
    try {
        // The clipboard API needs a secure context, so it is absent over plain
        // http. Adding a button that could not copy would be worse than not
        // offering one, hence the feature test rather than a fallback.
        if (navigator.clipboard && window.isSecureContext) {
            const RESET_MS = 1600;
            const LABEL = 'Copy code to clipboard';

            document.querySelectorAll('.readme pre > code').forEach(function (code) {
                const button = document.createElement('button');

                button.type = 'button';
                button.className = 'readme__copy';

                let timer = null;

                // Font Awesome's JS bundle rewrites every <i> into an <svg>, and
                // an SVG's className is a read-only SVGAnimatedString -- so the
                // icon has to be replaced rather than reclassed. Writing fresh
                // markup lets that bundle convert it again.
                const paint = function (label, icon) {
                    // The icon is decorative; the accessible name carries the
                    // meaning, and it changes with the outcome so a screen
                    // reader hears whether the copy worked.
                    button.setAttribute('aria-label', label);
                    button.innerHTML = '<i class="' + icon + '" aria-hidden="true"></i>';
                };

                const settle = function (state, label, icon) {
                    button.dataset.state = state;
                    paint(label, icon);

                    window.clearTimeout(timer);
                    timer = window.setTimeout(function () {
                        delete button.dataset.state;
                        paint(LABEL, 'fa-regular fa-copy');
                    }, RESET_MS);
                };

                paint(LABEL, 'fa-regular fa-copy');

                button.addEventListener('click', function () {
                    // textContent, not innerText: the block is preformatted and
                    // innerText would collapse the layout's own whitespace.
                    navigator.clipboard.writeText(code.textContent.replace(/\n+$/, '')).then(
                        function () {
                            settle('done', 'Copied', 'fa-solid fa-check');
                        },
                        function () {
                            // Refused, usually because the document lost focus.
                            // Say so rather than looking like nothing happened.
                            settle('failed', 'Could not copy — select the text instead', 'fa-solid fa-xmark');
                        }
                    );
                });

                code.parentElement.appendChild(button);
            });
        }
    } catch (er) {
        console.log(er);
    }

    /*[ Booking actions + sweetalert2 ]
    ===========================================================*/
    // Delegated, like saving and sharing: this drops the per-button DOM id the
    // old handler needed and keeps working for any card rendered later.
    document.addEventListener('click', function (event) {
        const button = event.target.closest('.js-booking-cancel');

        if (!button) {
            return;
        }

        const card = button.closest('[data-booking-card]');
        const reference = button.dataset.bookingReference || '';
        const named = reference ? 'Booking ' + reference : 'This booking';

        Swal.fire({
            title: 'Cancel this booking?',
            // text, not html: a reference is data and Swal escapes this one.
            text: named + ' will be marked cancelled. It stays in your list.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Cancel booking',
            cancelButtonText: 'Keep it',
            focusCancel: true,
            buttonsStyling: false,
            customClass: {
                confirmButton: 'btn btn-danger me-2',
                cancelButton: 'btn btn-outline-secondary'
            },
            showLoaderOnConfirm: true,
            preConfirm: function () {
                return fetch('/ajax/cancel-booking', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-CSRF-Token': csrfToken()
                    },
                    body: new URLSearchParams({booking_id: button.dataset.bookingId || ''})
                }).then(function (response) {
                    if (!response.ok) {
                        throw new Error(response.statusText);
                    }
                    return response.json();
                }).catch(function (error) {
                    Swal.showValidationMessage('Request failed: ' + error);
                });
            },
            allowOutsideClick: function () {
                return !Swal.isLoading();
            }
        }).then(function (result) {
            if (!result.isConfirmed) {
                return;
            }

            if (result.value.status !== 'success') {
                Swal.fire({title: result.value.message, icon: 'error'});
                return;
            }

            markCancelled(card, button, reference);
        });
    });

    // The row survives a cancel, so the card is restyled where it stands
    // rather than removed and the page reloaded. No success dialog: the card
    // changing in front of you is the confirmation, and the live region
    // carries it for anyone who cannot see that.
    function markCancelled(card, button, reference) {
        // The tooltip outlives its trigger otherwise, and hangs over the card.
        const tip = bootstrap.Tooltip.getInstance(button);

        if (tip) {
            tip.dispose();
        }

        button.remove();

        if (!card) {
            return;
        }

        card.classList.add('booking-card--cancelled');
        card.classList.remove('shadow-sm');

        const status = card.querySelector('.js-booking-status');

        if (status) {
            status.className = 'booking-status js-booking-status booking-status--cancelled';
            status.textContent = 'Cancelled';
        }

        // How near the departure is stops being the point once it is cancelled.
        const when = card.querySelector('.booking-when');

        if (when) {
            when.remove();
        }

        const live = document.querySelector('.js-bookings-live');

        if (live) {
            live.textContent = (reference ? 'Booking ' + reference : 'Booking') + ' cancelled.';
        }
    }

    /*[ Copy to clipboard ]
    ===========================================================*/
    // Generic on purpose: the next thing worth copying needs markup, not a
    // second handler.
    document.addEventListener('click', function (event) {
        const button = event.target.closest('.js-copy');

        if (!button) {
            return;
        }

        event.preventDefault();

        const label = button.getAttribute('aria-label');

        copyToClipboard(button.dataset.copyText).then(function () {
            button.classList.add('is-copied');
            button.setAttribute('aria-label', 'Copied');

            setTimeout(function () {
                button.classList.remove('is-copied');
                button.setAttribute('aria-label', label);
            }, 1600);
        }, function () {
            window.prompt('Copy this reference', button.dataset.copyText);
        });
    });

    /*[ Fly this route again ]
    ===========================================================*/
    // A picker per booking card. The search form's own init above cannot be
    // reused here: it is wired to four page-specific element ids and writes the
    // chosen date into those globals, so a second copy on the page would fight
    // it. This one only ever touches its own form.
    try {
        $('.js-rebook-date').each(function () {
            const $input = $(this);
            const $form = $input.closest('.js-rebook');
            const roundtrip = $input.data('triptype') === 'roundtrip';
            const display = 'MMMM D, YYYY';

            $input.daterangepicker({
                autoApply: true,
                showCustomRangeLabel: false,
                autoUpdateInput: false,
                singleDatePicker: !roundtrip,
                minDate: moment().format(display),
                opens: 'center',
                drops: 'auto',
                locale: {format: display, separator: ' – ', firstDay: 1}
            });

            $input.on('apply.daterangepicker', function (ev, picker) {
                $form.find('.js-rebook-depart').val(picker.startDate.format('YYYY-MM-DD'));

                if (roundtrip) {
                    $form.find('.js-rebook-return').val(picker.endDate.format('YYYY-MM-DD'));
                    $input.val(picker.startDate.format(display) + ' – ' + picker.endDate.format(display));
                } else {
                    $input.val(picker.startDate.format(display));
                }
            });
        });
    } catch (err) {
        console.log(err);
    }

    // Cards arrive after load too, when the list grows, so this has to be
    // callable again. getOrCreateInstance rather than new: running it twice
    // over the same element would leave two tooltips fighting over one target.
    function initTooltips(root) {
        (root || document).querySelectorAll('[data-toggle="tooltip"]').forEach(function (el) {
            bootstrap.Tooltip.getOrCreateInstance(el);
        });
    }

    $(function () {
        initTooltips(document);
    });

    /*[ Saved flights ]
    ===========================================================*/
    // Kept in a cookie rather than localStorage so the server can render
    // /my/saved without the page having to hand the list back over AJAX.
    // A saved flight is its ordered leg ids, which is all that is needed to
    // rebuild the itinerary; prices are looked up fresh, never stored.
    const SAVED_KEY = 'tb_saved_flights';
    const SAVED_MAX = 50;
    const SAVED_MAX_AGE = 60 * 60 * 24 * 365;

    function savedFlights() {
        const match = document.cookie.match(/(?:^|;\s*)tb_saved_flights=([^;]*)/);

        if (!match) {
            return [];
        }

        try {
            const list = JSON.parse(decodeURIComponent(match[1]));

            return Array.isArray(list) ? list.filter(function (key) {
                return typeof key === 'string';
            }) : [];
        } catch (e) {
            return [];
        }
    }

    function storeSavedFlights(list) {
        // This cookie is sent with every request, so cap it. Oldest saves fall
        // off the end rather than the newest silently failing to stick.
        const value = encodeURIComponent(JSON.stringify(list.slice(0, SAVED_MAX)));

        document.cookie = SAVED_KEY + '=' + value
            + ';path=/;max-age=' + SAVED_MAX_AGE + ';samesite=lax'
            + (window.location.protocol === 'https:' ? ';secure' : '');
    }

    // Anything saved before this moved to a cookie would otherwise vanish.
    (function migrateFromLocalStorage() {
        try {
            const legacy = window.localStorage.getItem(SAVED_KEY);

            if (legacy === null) {
                return;
            }

            if (savedFlights().length === 0) {
                const list = JSON.parse(legacy);

                if (Array.isArray(list) && list.length) {
                    storeSavedFlights(list);
                }
            }

            window.localStorage.removeItem(SAVED_KEY);
        } catch (e) {
            // Private browsing, or no localStorage at all: nothing to carry over.
        }
    })();

    // Saved flights live in this browser only — there is no account to sync to.
    //
    // The click is delegated because the list grows: a card appended by "show
    // more" never passes through a querySelectorAll that ran at load. Only the
    // painting of the saved state is per-element, so it is a function the
    // append can call again.
    function paintSavedFlights(root) {
        const list = savedFlights();

        (root || document).querySelectorAll('.js-like').forEach(function (button) {
            button.classList.toggle('is-active', list.indexOf(button.dataset.flightKey) !== -1);
        });
    }

    document.addEventListener('click', function (event) {
        const button = event.target.closest('.js-like');

        if (!button) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        const list = savedFlights();
        const at = list.indexOf(button.dataset.flightKey);

        if (at === -1) {
            list.unshift(button.dataset.flightKey);
        } else {
            list.splice(at, 1);
        }

        button.classList.toggle('is-active', at === -1);
        storeSavedFlights(list);
    });

    paintSavedFlights(document);

    // The saved-flights page: dropping one takes its card with it, so the list
    // does not disagree with the cookie until the next reload.
    document.querySelectorAll('.js-unsave').forEach(function (button) {
        button.addEventListener('click', function (event) {
            event.preventDefault();

            const key = button.dataset.flightKey;
            const list = savedFlights();
            const at = list.indexOf(key);

            if (at !== -1) {
                list.splice(at, 1);
                storeSavedFlights(list);
            }

            // Counted after the card has actually gone, not before.
            const settle = function () {
                const remaining = document.querySelectorAll('.saved-item').length;
                const empty = document.querySelector('.js-saved-empty');

                if (remaining === 0 && empty) {
                    empty.classList.remove('d-none');
                    const list_ = document.querySelector('.js-saved-list');

                    if (list_) {
                        list_.remove();
                    }
                }
            };

            const card = button.closest('.saved-item');

            if (!card) {
                settle();
                return;
            }

            let removed = false;

            const finish = function () {
                if (removed) {
                    return;
                }

                removed = true;
                card.remove();
                settle();
            };

            // An explicit height first, or there is nothing for the collapse
            // to run from; then the class and the target in the next frame.
            card.style.height = card.offsetHeight + 'px';

            window.requestAnimationFrame(function () {
                card.classList.add('is-leaving');
                card.style.height = '0px';
            });

            // The fade is the cue, but the removal never depends on it: a
            // stylesheet, a reduced-motion preference or a backgrounded tab
            // can all stop a transition from finishing, and a card that stays
            // behind after being dropped is worse than one that goes without
            // ceremony.
            card.addEventListener('transitionend', function (event) {
                if (event.propertyName === 'opacity') {
                    finish();
                }
            });

            window.setTimeout(finish, 400);
        });
    });

    /*[ Share link ]
    ===========================================================*/
    // The clipboard API rejects when the document is not focused, so keep a
    // selection-based fallback rather than dropping the user into a prompt.
    function copyToClipboard(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text).catch(function () {
                return selectionCopy(text) ? Promise.resolve() : Promise.reject();
            });
        }

        return selectionCopy(text) ? Promise.resolve() : Promise.reject();
    }

    function selectionCopy(text) {
        const area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.top = '-1000px';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();

        let copied = false;

        try {
            copied = document.execCommand('copy');
        } catch (e) {
            copied = false;
        }

        document.body.removeChild(area);

        return copied;
    }

    // Delegated for the same reason as saving: appended cards must work too.
    document.addEventListener('click', function (event) {
        const button = event.target.closest('.js-share');

        if (!button) {
            return;
        }

        // Keep the click off the card, which would navigate away.
        event.preventDefault();
        event.stopPropagation();

        // The share URL is a path, so resolve it against this origin.
        const url = new URL(button.dataset.shareUrl, window.location.origin).href;

        copyToClipboard(url).then(function () {
            button.classList.add('is-copied');

            setTimeout(function () {
                button.classList.remove('is-copied');
            }, 1600);
        }, function () {
            window.prompt('Copy this link', url);
        });
    });

    /*[ Checkout card ]
    ===========================================================*/
    // The card drawing mirrors the inputs; the inputs stay the state. Nothing
    // here validates on the server's behalf — it formats, identifies the
    // scheme, and turns the card over when the security code is being typed.
    (function () {
        const card = document.querySelector('.js-pay-card');

        if (!card) {
            return;
        }

        const field = function (name) {
            return document.querySelector('[data-card="' + name + '"]');
        };

        // Prefix ranges are how a scheme is identified; the grouping is how it
        // is printed. Amex is 4-6-5, everyone else 4-4-4-4.
        const SCHEMES = [
            { name: 'Visa', test: /^4/, groups: [4, 4, 4, 4], cvv: 3 },
            { name: 'Mastercard', test: /^(5[1-5]|2[2-7])/, groups: [4, 4, 4, 4], cvv: 3 },
            { name: 'Amex', test: /^3[47]/, groups: [4, 6, 5], cvv: 4 },
            { name: 'Discover', test: /^6(011|5|4[4-9])/, groups: [4, 4, 4, 4], cvv: 3 },
        ];

        const schemeOf = function (digits) {
            for (const scheme of SCHEMES) {
                if (scheme.test.test(digits)) {
                    return scheme;
                }
            }

            return { name: 'Card', groups: [4, 4, 4, 4], cvv: 3 };
        };

        const group = function (digits, groups) {
            const parts = [];
            let at = 0;

            for (const size of groups) {
                if (at >= digits.length) {
                    break;
                }

                parts.push(digits.slice(at, at + size));
                at += size;
            }

            return parts.join(' ');
        };

        const paintNumber = function () {
            const input = field('number');
            const digits = input.value.replace(/\D+/g, '').slice(0, 19);
            const scheme = schemeOf(digits);

            // Reformat in place. The caret is left at the end, which is where it
            // is during typing; a mid-string edit is rare enough not to justify
            // the arithmetic.
            input.value = group(digits, scheme.groups);

            card.dataset.scheme = scheme.name;
            document.querySelector('.js-card-brand').textContent = scheme.name;

            const shown = group(digits, scheme.groups);
            const placeholder = group('••••••••••••••••'.slice(0, scheme.groups.reduce(function (a, b) {
                return a + b;
            }, 0)), scheme.groups);

            document.querySelector('.js-card-number').textContent =
                shown === '' ? placeholder : shown + placeholder.slice(shown.length);

            field('cvv').setAttribute('maxlength', String(scheme.cvv));
        };

        const paintName = function () {
            const value = field('name').value.trim();

            document.querySelector('.js-card-name').textContent =
                value === '' ? 'YOUR NAME' : value.toUpperCase();
        };

        const paintExpiry = function () {
            const input = field('expiry');
            const digits = input.value.replace(/\D+/g, '').slice(0, 4);

            // Slash inserted as soon as a month is complete, so the format is
            // shown rather than demanded.
            input.value = digits.length > 2 ? digits.slice(0, 2) + ' / ' + digits.slice(2) : digits;

            document.querySelector('.js-card-expiry').textContent =
                input.value === '' ? 'MM / YY' : input.value;
        };

        const paintCvv = function () {
            const value = field('cvv').value.replace(/\D+/g, '');

            field('cvv').value = value;

            // Shown, not masked. The whole card number is on the front; hiding
            // three digits on the back would only stop you checking them.
            document.querySelector('.js-card-cvv').textContent = value === '' ? '•••' : value;
        };

        field('number').addEventListener('input', paintNumber);
        field('name').addEventListener('input', paintName);
        field('expiry').addEventListener('input', paintExpiry);
        field('cvv').addEventListener('input', paintCvv);

        // Amex prints its code on the front, so that one does not turn over.
        field('cvv').addEventListener('focus', function () {
            card.classList.toggle('is-flipped', card.dataset.scheme !== 'Amex');
        });

        field('cvv').addEventListener('blur', function () {
            card.classList.remove('is-flipped');
        });

        paintNumber();
        paintName();
        paintExpiry();
        paintCvv();
    })();

    /*[ Back to top ]
    ===========================================================*/
    (function () {
        const button = document.querySelector('.js-to-top');

        if (!button) {
            return;
        }

        // Roughly a screen and a half: far enough that the header is well out
        // of reach, near enough that it is there when it is wanted.
        const THRESHOLD = 800;

        let ticking = false;

        // Read on every use, not once, so a preference changed mid-session is
        // honoured without a reload.
        const still = window.matchMedia('(prefers-reduced-motion: reduce)');

        const paint = function () {
            const past = window.scrollY > THRESHOLD;

            // `hidden` keeps it out of the tab order and off a screen reader
            // when there is nowhere to go back to; the class does the fading.
            if (past) {
                button.hidden = false;
                // A frame after unhiding, or the transition has nothing to
                // move from.
                window.requestAnimationFrame(function () {
                    button.classList.add('is-visible');
                });
            } else {
                button.classList.remove('is-visible');

                // Belt and braces. The stylesheet keeps the opacity fade
                // under reduced motion, so `transitionend` below does still
                // fire -- but it is the only thing that puts `hidden` back,
                // and any future rule that drops the transition would leave
                // an invisible button parked in the tab order for exactly
                // the readers who opted out. Hiding straight away costs a
                // fade nobody asked to see.
                if (still.matches) {
                    button.hidden = true;
                }
            }

            ticking = false;
        };

        // The class comes off before the element goes, so the fade can finish.
        button.addEventListener('transitionend', function (event) {
            if (event.propertyName === 'opacity' && !button.classList.contains('is-visible')) {
                button.hidden = true;
            }
        });

        window.addEventListener('scroll', function () {
            if (!ticking) {
                ticking = true;
                window.requestAnimationFrame(paint);
            }
        }, { passive: true });

        button.addEventListener('click', function () {
            window.scrollTo({ top: 0, behavior: still.matches ? 'auto' : 'smooth' });

            // Scrolling moves the page, not the keyboard. Without this a tab
            // press would carry on from the button at the bottom, which is not
            // where the reader is now looking.
            const heading = document.querySelector('h1, .header, header');

            if (heading) {
                heading.setAttribute('tabindex', '-1');
                heading.focus({ preventScroll: true });
            }
        });

        paint();
    })();

    /*[ A list that grows ]
    ===========================================================*/
    // "Show more" is a real link to a longer list, so this only upgrades it:
    // fetch the same URL as a fragment, append the cards it returns, and leave
    // the address bar describing what is on screen. Without scripting the link
    // still works — it just reloads.
    (function () {
        const results = document.querySelector('.js-results');

        if (!results || !window.fetch) {
            return;
        }

        // The first slice is always asked for: someone who lands here and reads
        // one card has not said they want a longer list, and fetching one on
        // their behalf spends their data to answer a question nobody put.
        //
        // A click says otherwise, and buys the two loads after it for free.
        // Scrolling for ever would take the footer out of reach, so each burst
        // ends back at the button rather than running on.
        const AUTO_LOADS = 2;

        // Starts spent, so nothing loads until the button is used.
        let autoLoaded = AUTO_LOADS;
        let loading = false;
        let observer = null;

        const block = function () {
            return results.querySelector('.js-more-block');
        };

        const load = function (link) {
            if (loading) {
                return;
            }

            loading = true;

            const holder = link.closest('.js-more-block');
            const label = link.querySelector('.js-more-label');
            const spinner = link.querySelector('.show-more__spinner');

            link.classList.add('is-loading');

            if (label) {
                label.textContent = 'Loading…';
            }

            if (spinner) {
                spinner.hidden = false;
            }

            // `after` is what the page already holds; the href already carries
            // the new total, along with the search, sort and filters.
            const url = link.href + '&fragment=1&after=' + encodeURIComponent(link.dataset.after);

            fetch(url, { headers: { 'X-Requested-With': 'fetch' } })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status);
                    }

                    return response.text();
                })
                .then(function (html) {
                    // Counted first, so the cards the fragment brings can be
                    // told apart from the ones already on the page.
                    const settled = results.querySelectorAll('.flight-card').length;

                    // The fragment brings its own "show more", so the old one
                    // is replaced rather than updated.
                    holder.insertAdjacentHTML('beforebegin', html);
                    holder.remove();

                    // Marked in the same task as the insert, before the
                    // browser has a chance to paint, so the new cards never
                    // show at full opacity and then blink out. The class comes
                    // off a frame later, which is what gives the transition
                    // something to run from.
                    const arrived = Array.from(
                        results.querySelectorAll('.flight-card')
                    ).slice(settled);

                    arrived.forEach(function (card) {
                        card.classList.add('is-arriving');
                    });

                    window.requestAnimationFrame(function () {
                        arrived.forEach(function (card) {
                            card.classList.remove('is-arriving');
                        });
                    });

                    // Cards that arrived have arrived. An enhancement that
                    // throws must not send us down the failure path, which
                    // would leave the list grown but the address bar and the
                    // next control still describing the old one.
                    try {
                        paintSavedFlights(results);
                        initTooltips(results);
                        announceResults(results);
                    } catch (error) {
                        // Nothing to undo: the results are on the page.
                    }

                    // The URL now describes the screen, so a refresh or a Back
                    // from a flight lands on the same list rather than the
                    // first ten.
                    window.history.replaceState(null, '', link.href);

                    loading = false;
                    watch();
                })
                .catch(function () {
                    // Put the control back so it can be pressed again. The
                    // click handler calls preventDefault unconditionally, so
                    // a retry comes back through here rather than following
                    // the href -- the no-JS path is for visitors without this
                    // script at all, not for a failed request.
                    link.classList.remove('is-loading');

                    if (label) {
                        label.textContent = 'Show more results';
                    }

                    if (spinner) {
                        spinner.hidden = true;
                    }

                    // And say what happened. Sliding back to the resting
                    // state and nothing else is indistinguishable from a
                    // press that never registered, which leaves somebody
                    // waiting for cards that are not coming. The note under
                    // the button already exists to describe what is left to
                    // load, so it is the honest place to say that nothing
                    // did. A successful retry replaces this whole block,
                    // message included.
                    const note = holder && holder.querySelector('.show-more__note');

                    if (note) {
                        note.textContent = 'Could not load more results. Check your connection and try again.';
                    }

                    // Nothing about the button changing shape reaches a
                    // screen reader, so route it through the same live
                    // region that announces arrivals.
                    const live = document.querySelector('.js-results-live');

                    if (live) {
                        live.textContent = 'Could not load more results.';
                    }

                    loading = false;
                });
        };

        // Screen readers get no scroll cue, so say what arrived.
        const announceResults = function (root) {
            const live = document.querySelector('.js-results-live');

            if (live) {
                live.textContent = root.querySelectorAll('.flight-card').length + ' results shown';
            }
        };

        const watch = function () {
            if (observer) {
                observer.disconnect();
                observer = null;
            }

            const holder = block();

            if (!holder || autoLoaded >= AUTO_LOADS || !window.IntersectionObserver) {
                return;
            }

            // A margin so the request starts before the visitor reaches the end
            // and has to wait at it.
            observer = new IntersectionObserver(function (entries) {
                if (!entries[0].isIntersecting || loading) {
                    return;
                }

                autoLoaded += 1;
                observer.disconnect();
                observer = null;
                load(holder.querySelector('.js-more'));
            }, { rootMargin: '400px' });

            observer.observe(holder);
        };

        results.addEventListener('click', function (event) {
            const link = event.target.closest('.js-more');

            if (!link) {
                return;
            }

            event.preventDefault();

            // Asking again re-arms the automatic loads behind it.
            autoLoaded = 0;
            load(link);
        });
    })();

    /*[ Search filters sidebar ]
    ===========================================================*/
    // Filters apply together on Apply, so everything here is local: nothing
    // reloads the page until the form is submitted.

    // Type to narrow a long list. Rows are hidden with a class rather than
    // removed, so the checked state of a filtered-out row survives.
    document.querySelectorAll('.js-list-search').forEach(function (input) {
        const list = document.getElementById(input.dataset.list);

        if (!list) {
            return;
        }

        input.addEventListener('input', function () {
            const term = input.value.trim().toLowerCase();

            list.classList.toggle('is-expanded', term !== '');

            list.querySelectorAll('.filter-row').forEach(function (row) {
                const hit = term === '' || row.textContent.toLowerCase().indexOf(term) !== -1;
                row.classList.toggle('is-filtered-out', !hit);
            });
        });
    });

    // "Show all (62)" — reveals the tail and takes itself away.
    document.querySelectorAll('.js-show-all').forEach(function (button) {
        button.addEventListener('click', function () {
            const list = document.getElementById(button.dataset.list);

            if (list) {
                list.classList.add('is-expanded');
            }

            button.remove();
        });
    });

    // "Only": keep this option and drop the rest of its list. The row is a
    // <label>, so the click has to be stopped from reaching the checkbox it
    // wraps — otherwise the box would toggle on top of what we just set.
    document.querySelectorAll('.js-only').forEach(function (button) {
        button.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();

            const list = document.getElementById(button.dataset.list);

            if (!list) {
                return;
            }

            list.querySelectorAll('input[type=checkbox]:not(:disabled)').forEach(function (box) {
                box.checked = box.value === button.dataset.value;
                box.dispatchEvent(new Event('change', { bubbles: true }));
            });
        });
    });

    // Select all / Clear, one per filter group, covering whatever controls that
    // group happens to hold — boxes, switches or a slider. The label follows
    // the state, so the button always says what pressing it will do.
    document.querySelectorAll('.filter-section').forEach(function (section) {
        const button = section.querySelector('.js-section-reset');

        if (!button) {
            return;
        }

        const boxes = function () {
            return Array.prototype.slice.call(
                section.querySelectorAll('input[type=checkbox]:not(:disabled)')
            );
        };

        const sliders = function () {
            return Array.prototype.slice.call(section.querySelectorAll('.js-filter-slider'));
        };

        // A slider counts as set only when it is off its maximum; parked at the
        // top it excludes nothing, which is the same as untouched.
        const untouched = function (input) {
            const min = parseInt(input.dataset.min, 10);
            const max = parseInt(input.dataset.max, 10);

            if (input.dataset.to === undefined) {
                return parseInt(input.value, 10) >= max;
            }

            const pair = String(input.value).split(/[;-]/);

            return parseInt(pair[0], 10) <= min && parseInt(pair[1], 10) >= max;
        };

        const isSet = function () {
            return boxes().some(function (box) { return box.checked; })
                || sliders().some(function (input) { return !untouched(input); });
        };

        const sync = function () {
            section.classList.toggle('has-selection', isSet());
        };

        button.addEventListener('click', function () {
            if (isSet()) {
                boxes().forEach(function (box) {
                    box.checked = false;
                    box.dispatchEvent(new Event('change', { bubbles: true }));
                });

                sliders().forEach(function (input) {
                    const slider = $(input).data('ionRangeSlider');
                    const min = parseInt(input.dataset.min, 10);
                    const max = parseInt(input.dataset.max, 10);
                    const both = input.dataset.to !== undefined;

                    if (slider) {
                        slider.update(both ? { from: min, to: max } : { from: max });
                    }

                    input.value = both ? min + '-' + max : String(max);
                });
            } else {
                // Only reachable on a group that offers "Select all".
                boxes().forEach(function (box) {
                    box.checked = true;
                    box.dispatchEvent(new Event('change', { bubbles: true }));
                });
            }

            sync();
        });

        section.addEventListener('change', sync);
        sync();
    });

    // Pills paint from a class rather than a :has() selector, so the selected
    // state has exactly one definition.
    document.querySelectorAll('.filter-pill').forEach(function (pill) {
        const input = pill.querySelector('input[type=checkbox]');

        if (!input) {
            return;
        }

        const paint = function () {
            pill.classList.toggle('is-on', input.checked);
        };

        input.addEventListener('change', paint);
        paint();
    });

    // Price and travel-time sliders. The handle carries the value the filter
    // reads, and the pill beside the label shows it in human terms.
    function formatMinutes(total) {
        const hours = Math.floor(total / 60);
        const minutes = Math.round(total % 60);

        if (hours === 0) {
            return minutes + 'm';
        }

        return minutes === 0 ? hours + 'h' : hours + 'h ' + minutes + 'm';
    }

    function sliderLabel(kind, value) {
        return kind === 'money'
            ? '$' + Math.round(value).toLocaleString('en-US')
            : formatMinutes(value);
    }

    // On two handles the end matters: a floor dragged up has to read "From" or
    // the same number would mean two opposite things. One handle sits under a
    // label that already says which end it is, so the pill carries the number
    // alone. Keep in step with Helper::sliderCaption, which paints the first
    // render.
    function sliderCaption(kind, from, to, min, max) {
        if (to === undefined) {
            return sliderLabel(kind, from);
        }

        if (from > min && to < max) {
            return 'From ' + sliderLabel(kind, from) + ' to ' + sliderLabel(kind, to);
        }

        return from > min ? 'From ' + sliderLabel(kind, from) : 'Up to ' + sliderLabel(kind, to);
    }

    $('.js-filter-slider').each(function () {
        const input = this;
        const kind = input.dataset.kind;
        const output = document.getElementById(input.dataset.output);
        const min = parseInt(input.dataset.min, 10);
        const max = parseInt(input.dataset.max, 10);
        // Two handles when the server supplied an upper one.
        const isRange = input.dataset.to !== undefined;

        const caption = output && output.querySelector('.filter-slider__caption');
        const clearButton = output && output.querySelector('.js-slider-clear');

        const show = function (from, to) {
            if (!output) {
                return;
            }

            // Parked at the ends the slider excludes nothing, so the pill stays
            // grey and keeps its clear button out of the way — there is nothing
            // to clear.
            const set = isRange ? from > min || to < max : from < max;
            const text = sliderCaption(kind, from, isRange ? to : undefined, min, max);

            if (caption) {
                caption.textContent = text;
            } else {
                output.textContent = text;
            }

            output.classList.toggle('is-on', set);

            if (clearButton) {
                clearButton.hidden = !set;
            }
        };

        // ion.rangeSlider writes the value straight onto the input without
        // firing anything, so the group's Clear button would never learn that
        // the slider had moved. Announce it ourselves.
        const announce = function () {
            input.dispatchEvent(new Event('change', { bubbles: true }));
        };

        // ion.rangeSlider writes "from;to" into a double input; the filters read
        // "from-to", so keep the value ourselves.
        const store = function (data) {
            input.value = isRange ? data.from + '-' + data.to : String(data.from);
        };

        $(input).ionRangeSlider({
            skin: 'round',
            type: isRange ? 'double' : 'single',
            min: min,
            max: max,
            from: parseInt(input.dataset.from, 10),
            to: isRange ? parseInt(input.dataset.to, 10) : undefined,
            step: parseInt(input.dataset.step, 10) || 1,
            // Stops on a handle, where the server has worked out that going
            // further can only ever return nothing. The track still spans the
            // real range, so the ends keep telling the truth about the spread.
            from_max: input.dataset.floorMax === undefined ? undefined : parseInt(input.dataset.floorMax, 10),
            to_min: input.dataset.ceilingMin === undefined ? undefined : parseInt(input.dataset.ceilingMin, 10),
            hide_min_max: true,
            hide_from_to: true,
            onStart: function (data) { show(data.from, data.to); },
            onChange: function (data) { show(data.from, data.to); store(data); },
            // Once, on release, rather than on every pixel of the drag.
            onFinish: function (data) { show(data.from, data.to); store(data); announce(); },
            // update() does not fire onChange, so the label would go stale
            // whenever a handle is moved by anything but a drag.
            onUpdate: function (data) { show(data.from, data.to); store(data); announce(); },
        });

        show(parseInt(input.dataset.from, 10), isRange ? parseInt(input.dataset.to, 10) : undefined);

        // Back to the full range. update() fires onUpdate, which repaints the
        // pill and tells the group's Clear button that this slider let go.
        if (clearButton) {
            clearButton.addEventListener('click', function (event) {
                event.preventDefault();

                const slider = $(input).data('ionRangeSlider');

                if (slider) {
                    slider.update(isRange ? { from: min, to: max } : { from: max });

                    return;
                }

                input.value = isRange ? min + '-' + max : String(max);
                show(min, max);
            });
        }
    });

    // A slider built inside a collapsed section measures zero width and stays
    // that way — the track never lays out, so the handle cannot be dragged.
    // Bootstrap tells us when a section has finished opening; that is the first
    // moment the widget can size itself correctly.
    document.querySelectorAll('.filter-section .collapse').forEach(function (panel) {
        panel.addEventListener('shown.bs.collapse', function () {
            panel.querySelectorAll('.js-filter-slider').forEach(function (input) {
                const slider = $(input).data('ionRangeSlider');

                if (slider) {
                    // Pass the current position back in: a bare update() puts
                    // the handle at the minimum, which would silently disagree
                    // with the value shown beside the label.
                    slider.update({ from: parseInt(input.value, 10) });
                }
            });
        });
    });

    // The overflow sort list navigates on choice; its options carry the URL.
    document.querySelectorAll('.js-sort-select').forEach(function (select) {
        select.addEventListener('change', function () {
            if (select.value) {
                window.location.assign(select.value);
            }
        });
    });

    // Submit by building the URL rather than letting the browser serialise the
    // form: it percent-encodes commas, so `airlines=BA,AI` would reach the
    // address bar as `airlines=BA%2CAI`. Runs last, after the handlers that
    // fold checkbox groups into comma lists and drop untouched sliders.
    function submitReadably(form) {
        const parts = [];

        new FormData(form).forEach(function (value, key) {
            if (value === '') {
                return;
            }

            parts.push(encodeURIComponent(key) + '=' + encodeURIComponent(value).replace(/%2C/g, ','));
        });

        const action = form.getAttribute('action') || window.location.pathname;

        window.location.assign(parts.length ? action + '?' + parts.join('&') : action);
    }

    // A slider parked at its maximum excludes nothing, so it must not be
    // submitted. Leaving it in would put a number in the URL that looks
    // deliberate, and — because each slider's range is measured from the
    // current results — a later change to another filter could shrink the set
    // beneath that stale ceiling and start quietly cutting flights.
    const filterForm = document.getElementById('form_filters');

    if (filterForm) {
        // Checkbox groups post one field per box (airlines[]=AF&airlines[]=AS),
        // which makes for an unreadable URL. The filters read a comma list just
        // as happily, so collect the boxes into one field per group and take
        // the boxes themselves out of the submission.
        filterForm.addEventListener('submit', function () {
            const groups = {};

            filterForm.querySelectorAll('input[type=checkbox][name$="[]"]').forEach(function (box) {
                const key = box.name.slice(0, -2);

                if (box.checked) {
                    (groups[key] = groups[key] || []).push(box.value);
                }

                box.disabled = true;
            });

            Object.keys(groups).forEach(function (key) {
                const field = document.createElement('input');
                field.type = 'hidden';
                field.name = key;
                field.value = groups[key].join(',');
                filterForm.appendChild(field);
            });
        });

        filterForm.addEventListener('submit', function () {
            filterForm.querySelectorAll('.js-filter-slider').forEach(function (input) {
                const min = parseInt(input.dataset.min, 10);
                const max = parseInt(input.dataset.max, 10);
                const slider = $(input).data('ionRangeSlider');

                if (input.dataset.to === undefined) {
                    if (parseInt(input.value, 10) >= max) {
                        input.disabled = true;
                    }

                    return;
                }

                // ion.rangeSlider writes a double as "from;to" over whatever we
                // set, and the filters read "from-to". Take the value from the
                // widget itself, which is the one thing that is never stale.
                const from = slider ? slider.result.from : min;
                const to = slider ? slider.result.to : max;

                if (from <= min && to >= max) {
                    input.disabled = true;

                    return;
                }

                // A floor resting at the bottom of the track is not a floor
                // anyone asked for, and sending it as one would rule out every
                // direct flight — they have no layover to be that long. Send
                // the ceiling alone instead.
                input.value = from <= min ? String(to) : from + '-' + to;
            });
        });
    }

    ['form_filters', 'form_sort'].forEach(function (id) {
        const form = document.getElementById(id);

        if (form) {
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                submitReadably(form);
            });
        }
    });

    $( ".auto-clear" ).on( "focus", function() {
        $(this).select();
    } );

})(jQuery);

document.addEventListener('DOMContentLoaded', () => {
    const departingAirportInput = $('#departing_airport');
    const arrivalAirportInput   = $('#arrival_airport');
    const roundtripDatesInput   = $('#roundtrip_dates');
    const onewayDatesInput      = $('#oneway_depart_date');

    // Ugly as hell
    let nextDateInput = roundtripDatesInput;
    $('#tab-oneway, #tab-roundtrip').click(function () {
        nextDateInput = $(this).attr('id') === 'tab-roundtrip' ? roundtripDatesInput : onewayDatesInput;
    });

    departingAirportInput.autocomplete({
        onPick(el, item) {
            arrivalAirportInput.focus();
        }
    });
    arrivalAirportInput.autocomplete({
        onPick(el, item) {
            nextDateInput.focus();
        }
    });

}, false);

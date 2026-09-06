(function ($) {
    'use strict';

    const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

    /*[ Search form: the two date fields ]
    ===========================================================*/
    try {
        const inputDateFormat = 'YYYY-MM-DD';
        const showDateFormat = 'MMM D';

        // Days a window may cover, itself included. SearchUrl::MAX_SPAN is the
        // definition; this is the picker's own limit and the two must agree.
        const MAX_SPAN = 3;

        const departInput = $('#depart_date');
        const returnInput = $('#return_date');
        const departValue = $('#depart_date_value');
        const returnValue = $('#return_date_value');
        const departFlex = $('#depart_flex_value');
        const returnFlex = $('#return_flex_value');
        const clearReturn = $('.js-clear-return');

        const spanOf = ($flex) => Math.max(1, parseInt($flex.val(), 10) || 1);

        // A range picker per field, where each used to pick a single day. The
        // range here is not "depart to return" -- that is what the two fields
        // are for -- but how flexible one end of the trip is.
        const pickers = [];

        // Which pickers the visitor has actually picked a day in. A picker opens
        // seeded with a date whether or not it is the one on the field, so an
        // empty return field that is opened and dismissed must close having done
        // nothing -- otherwise looking at the return dates would book a return.
        const touched = new Set();

        const pickerFor = ($input, seed, span) => {
            $input.daterangepicker({
                // Off, so the calendar stays open once a day is chosen and the
                // window can still be widened. It closes on Done or on a click
                // outside, both of which the plugin already routes through hide.
                autoApply: false,
                showCustomRangeLabel: false,
                autoUpdateInput: false,
                startDate: seed ? moment(seed) : moment(),
                endDate: seed ? moment(seed).add(span - 1, 'day') : moment(),
                minDate: moment(),
                maxSpan: {days: MAX_SPAN - 1},
                opens: 'center',
                drops: 'auto',
                locale: {
                    format: showDateFormat,
                    separator: ' – ',
                    firstDay: 1,
                    applyLabel: 'Done'
                }
            });

            const picker = $input.data('daterangepicker');

            // The rebook dialog puts a second picker on the page, and that one
            // still picks a departure and a return with two clicks. Only these
            // two can be dragged wider, so only these two say so.
            picker.container.addClass('daterangepicker--flex');

            // Reopening is a fresh decision.
            $input.on('show.daterangepicker', () => touched.delete(picker));
            pickers.push(picker);

            return picker;
        };

        const departPicker = pickerFor(departInput, departValue.val(), spanOf(departFlex));
        const returnPicker = pickerFor(returnInput, returnValue.val() || departValue.val(), spanOf(returnFlex));

        // autoUpdateInput is off so a field can stay empty until it is picked:
        // left to itself the plugin writes today's date in on load, which would
        // turn every one-way search into a round trip nobody asked for.
        const show = ($input, $hidden, $flex, start, end) => {
            const days = Math.min(MAX_SPAN, end.diff(start, 'days') + 1);

            $hidden.val(start.format(inputDateFormat));
            // Blank rather than 1: a plain search should send no flex at all.
            $flex.val(days > 1 ? String(days) : '');
            $input.val(days > 1
                ? start.format(showDateFormat) + ' – ' + end.format(showDateFormat)
                : start.format(showDateFormat));
        };

        // hide, not apply: apply fires only for the Done button, and a click
        // outside the calendar closes it just as deliberately.
        departInput.on('hide.daterangepicker', function (ev, picker) {
            if (!touched.has(picker)) {
                return;
            }

            show(departInput, departValue, departFlex, picker.startDate, picker.endDate);

            // The return can never precede the departure. Nothing enforced this
            // before -- a return a year earlier rendered a results page.
            if (returnValue.val() && moment(returnValue.val()).isBefore(picker.startDate, 'day')) {
                show(returnInput, returnValue, returnFlex, picker.startDate, picker.startDate);
            }
        });

        returnInput.on('hide.daterangepicker', function (ev, picker) {
            if (!touched.has(picker)) {
                return;
            }

            const departed = departValue.val() ? moment(departValue.val()) : null;
            const from = departed && picker.startDate.isBefore(departed, 'day') ? departed : picker.startDate;
            const to = picker.endDate.isBefore(from, 'day') ? from : picker.endDate;

            show(returnInput, returnValue, returnFlex, from, to);
            clearReturn.prop('hidden', false);
        });

        // The way back to a one-way trip, now that no tab does it.
        clearReturn.on('click', function () {
            returnValue.val('');
            returnFlex.val('');
            returnInput.val('');
            $(this).prop('hidden', true);
        });

        const redraw = ($input, $hidden, $flex) => {
            if (!$hidden.val()) { return; }

            const start = moment($hidden.val());
            show($input, $hidden, $flex, start, start.clone().add(spanOf($flex) - 1, 'day'));
        };

        redraw(departInput, departValue, departFlex);
        redraw(returnInput, returnValue, returnFlex);

        // Set from outside the picker -- a past search being put back into the
        // form. The hidden field is what carries the date, so it is what to
        // watch; the visible text and the calendar's own month both follow it.
        const follow = ($input, $hidden, $flex, picker, $clear) => {
            // Both, because the date and the width of its window arrive as two
            // separate writes and either order leaves the first redraw reading a
            // value the second is about to change. Listening to each means the
            // last write settles it whichever way round they come.
            $hidden.add($flex).on('change', function () {
                if (!$hidden.val()) {
                    $input.val('');
                    $flex.val('');
                    $clear?.prop('hidden', true);

                    return;
                }

                redraw($input, $hidden, $flex);
                $clear?.prop('hidden', false);

                const start = moment($hidden.val());

                picker.setStartDate(start);
                picker.setEndDate(start.clone().add(spanOf($flex) - 1, 'day'));
                picker.updateView();
            });
        };

        follow(departInput, departValue, departFlex, departPicker);
        follow(returnInput, returnValue, returnFlex, returnPicker, clearReturn);

        // Pick by dragging. Pressing either end of the window and pulling moves
        // that end and leaves the other where it is -- which is what the arrows
        // drawn on those two cells advertise. Pressing anywhere else starts a
        // new window, and a press with no drag picks that one day.
        //
        // The plugin's own click-to-pick is intercepted rather than extended. It
        // binds `mousedown` on `td.available`, delegated on the container, so a
        // capture-phase listener on the document sees the press first and can
        // stop it going any further. Driving the selection ourselves is what
        // makes a drag possible at all: left alone, the plugin answers the first
        // press by redrawing the calendar, detaching the very cell the release
        // would have landed on.
        let drag = null;

        const pickerAt = (node) => pickers.find((picker) => picker.container[0].contains(node)) ?? null;

        /** The day a cell stands for, read the way the plugin reads it itself. */
        const dayAt = (picker, cell) => {
            const spot = /^r(\d+)c(\d+)$/.exec(cell.dataset.title ?? '');
            const month = cell.closest('.drp-calendar').classList.contains('left')
                ? picker.leftCalendar
                : picker.rightCalendar;

            return spot ? month.calendar[Number(spot[1])][Number(spot[2])].clone() : null;
        };

        const dayUnder = (node) => {
            const cell = node instanceof Element ? node.closest('td.available') : null;
            const picker = cell ? pickerAt(cell) : null;
            const day = picker ? dayAt(picker, cell) : null;

            return day ? {picker: picker, day: day} : null;
        };

        /**
         * A day pulled back inside what the search will run. setStartDate does
         * not police maxSpan the way setEndDate does, so widening from the far
         * end has to be caught here or a window wider than MAX_SPAN gets drawn.
         */
        const reachable = (picker, fixed, day) => {
            const reach = MAX_SPAN - 1;
            let capped = day;

            if (day.diff(fixed, 'days') > reach) {
                capped = fixed.clone().add(reach, 'day');
            } else if (fixed.diff(day, 'days') > reach) {
                capped = fixed.clone().subtract(reach, 'day');
            }

            return capped.isBefore(picker.minDate, 'day') ? picker.minDate.clone() : capped;
        };

        const paint = (picker, fixed, day) => {
            const to = reachable(picker, fixed, day);

            picker.setStartDate(moment.min(fixed, to));
            picker.setEndDate(moment.max(fixed, to));
            picker.updateView();
        };

        document.addEventListener('mousedown', function (event) {
            const spot = dayUnder(event.target);

            if (spot === null) {
                return;
            }

            // Ours to handle, and not the plugin's. preventDefault also keeps
            // the drag from selecting the day numbers as text.
            event.preventDefault();
            event.stopPropagation();

            const picker = spot.picker;
            const wide = picker.endDate && !picker.startDate.isSame(picker.endDate, 'day');
            let fixed = spot.day;

            // Grabbing one end pivots on the other.
            if (wide && spot.day.isSame(picker.startDate, 'day')) {
                fixed = picker.endDate.clone();
            } else if (wide && spot.day.isSame(picker.endDate, 'day')) {
                fixed = picker.startDate.clone();
            }

            drag = {picker: picker, fixed: fixed, held: spot.day, moved: false};
        }, true);

        document.addEventListener('mousemove', function (event) {
            if (drag === null) {
                return;
            }

            // The target is hit-tested as the event is dispatched, so a repaint
            // mid-drag cannot hand back a cell that has since been replaced.
            // The point is the fallback, for a pointer over the gap between two
            // cells rather than over either of them.
            const spot = dayUnder(event.target)
                ?? dayUnder(document.elementFromPoint(event.clientX, event.clientY));

            if (spot === null || spot.picker !== drag.picker) {
                return;
            }

            if (!drag.moved && spot.day.isSame(drag.held, 'day')) {
                return;
            }

            drag.moved = true;
            paint(drag.picker, drag.fixed, spot.day);
        }, true);

        document.addEventListener('mouseup', function () {
            if (drag === null) {
                return;
            }

            if (!drag.moved) {
                paint(drag.picker, drag.held, drag.held);
            }

            touched.add(drag.picker);
            drag = null;
        }, true);
    } catch (er) {
        console.log(er);
    }

    /*[ Search form: who is flying ]
    ===========================================================*/
    try {
        // Every one on the page: the search bar has one, and a booking's rebook
        // dialog now uses the same control rather than a third copy of it.
        document.querySelectorAll('.js-party-trigger').forEach(function (trigger) {
            const panel = document.getElementById(trigger.getAttribute('aria-controls'));

            if (!panel) { return; }

            const summary = trigger.querySelector('.js-party-summary');
            const counts = [...panel.querySelectorAll('.js-party-count')];
            const cabins = [...panel.querySelectorAll('.js-party-cabin')];
            const maxSeats = parseInt(panel.dataset.maxSeats, 10) || 9;

            const at = (key) => counts.find(c => c.id.endsWith(key));
            const adults = at('adults');
            const children = at('children');
            const infants = at('infants');
            const value = (select) => parseInt(select.value, 10) || 0;

            // Party::fromCounts() in the browser, from its own number: somebody
            // is responsible for the booking, a lap needs an adult attached to
            // it, and the cabin has a limit that infants do not count against
            // because they are not in a seat. Enforced here only so the panel
            // cannot offer a search the server will refuse -- PHP still decides.
            const ceiling = (select) => {
                if (select === adults) { return maxSeats - value(children); }
                if (select === children) { return maxSeats - value(adults); }

                return value(adults);
            };

            const floor = (select) => parseInt(select.dataset.floor, 10) || 0;

            const write = (select, next) => {
                select.value = next === 0 ? (floor(select) === 0 ? '' : '0') : String(next);
                select.dispatchEvent(new Event('change', { bubbles: true }));
            };

            const paint = () => {
                counts.forEach((select) => {
                    const row = select.closest('.party__row');
                    const now = value(select);

                    row.querySelector('.party__value').textContent = String(now);
                    row.querySelector('.js-party-less').disabled = now <= floor(select);
                    row.querySelector('.js-party-more').disabled = now >= ceiling(select);
                });

                const heads = counts.reduce((sum, select) => sum + value(select), 0);
                const cabin = cabins.find(c => c.checked);

                summary.querySelector('.js-party-heads').textContent = heads + ' '
                    + (heads === 1 ? summary.dataset.one : summary.dataset.many);
                summary.querySelector('.js-party-class').textContent = cabin
                    ? cabin.closest('label').textContent.trim()
                    : '';
            };

            const open = (yes) => {
                panel.hidden = !yes;
                trigger.setAttribute('aria-expanded', yes ? 'true' : 'false');
            };

            panel.addEventListener('click', (event) => {
                const step = event.target.closest('.js-party-less, .js-party-more');

                if (!step) { return; }

                const select = step.closest('.party__row').querySelector('.js-party-count');
                const now = value(select);
                const next = step.classList.contains('js-party-more') ? now + 1 : now - 1;

                if (next < floor(select) || next > ceiling(select)) { return; }

                write(select, next);

                // Fewer adults can leave more infants than laps to hold them.
                if (select === adults && value(infants) > next) {
                    write(infants, next);
                }

                paint();
            });

            panel.addEventListener('change', paint);
            trigger.addEventListener('click', () => open(panel.hidden));

            document.addEventListener('click', (event) => {
                if (!panel.hidden && !panel.contains(event.target) && !trigger.contains(event.target)) {
                    open(false);
                }
            });

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && !panel.hidden) {
                    open(false);
                    trigger.focus();
                }
            });

            paint();
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

            // The server checks that a date of birth matches the fare type, so
            // these ranges have to agree with it: adult 12+, child 2-11, lap
            // infant under 2. A plausible-looking date that fails validation
            // would make the button that exists to save typing cost more of it.
            const AGES = { A: [18, 75], C: [2, 11], I: [0, 1] };

            const born = function (type) {
                const [low, high] = AGES[type] || AGES.A;
                const now = new Date();
                const year = now.getFullYear() - (low + Math.floor(Math.random() * (high - low + 1)));
                const month = 1 + Math.floor(Math.random() * 12);
                // 28 keeps every month valid without caring which one it is.
                const day = 1 + Math.floor(Math.random() * 28);
                const dob = new Date(year, month - 1, day);

                // An age drawn at the edge can land in the future or a month
                // ahead of today; pull it back a year rather than emit a date
                // the form will reject.
                return dob > now
                    ? (year - 1) + '-' + pad(month) + '-' + pad(day)
                    : year + '-' + pad(month) + '-' + pad(day);
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
                    card_name: first + ' ' + last,
                    card_number: groupCard(cardNumber(scheme)),
                    card_expiry: expiry(),
                    card_cvv: digits(scheme.cvv),
                    billing_postcode: shape(place.postcode),
                    billing_country: place.country,
                };

                // One traveller per block, each with their own name. querySelector
            // returns the first match, so filling by bare field name would have
            // filled passenger one and silently left the rest blank -- the same
            // failure the comment below describes for the country field.
            form.querySelectorAll('[data-passenger]').forEach(function (block) {
                const index = block.dataset.passenger;
                const type = block.dataset.passengerType;
                const at = function (field) {
                    return form.querySelector('[name="passengers[' + index + '][' + field + ']"]');
                };

                const entries = {
                    first_name: pick(FIRST),
                    last_name: last,
                    dob: born(type),
                    gender: pick(['F', 'M', 'X']),
                };

                Object.keys(entries).forEach(function (field) {
                    const input = at(field);

                    if (!input) {
                        return;
                    }

                    input.value = entries[field];
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                });
            });

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

    /*[ Searchable select: billing country, and the search form's places ]
    ===========================================================*/
    try {
        // Rows rendered per keystroke. The place list is a few hundred long and
        // laying all of it out is what made the dropdown slow to open.
        const SHOWN_AT_ONCE = 40;

        // Past searches offered before the list of places. Two, because the
        // history is there to catch the trip being repeated right now and the
        // airports underneath are what the field is actually for -- six pills
        // pushed them off the bottom of the panel. The rest are one click away.
        const RECENT_AT_ONCE = 2;

        document.querySelectorAll('select[data-searchable]').forEach(function (select) {
            // The select stays: it is what the form submits, what autofill
            // writes to, and what the page is left with if this never runs.
            // Everything below is a layer on top of it.
            const options = [...select.options].filter(o => o.value !== '');
            // Searches this browser has run, offered while nothing is typed.
            // One template serves both fields -- it is the same history either
            // way -- so it is cloned rather than moved.
            const recentTpl = select.dataset.recent
                ? document.getElementById(select.dataset.recent)
                : null;
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
            // Both kinds of row live in one list so the arrow keys walk them
            // together: a recent search the keyboard cannot reach is a row only
            // a mouse can see.
            let entries = [];

            /**
             * Point the box -- and with it the screen reader -- at one row.
             *
             * Every row is cleared first rather than just the one being left
             * behind: a listbox may hold one selected option, and render marks
             * whatever is already chosen, so without this the arrows would add
             * a second.
             */
            const mark = function (rows, index) {
                rows.forEach(function (row) {
                    row.classList.remove('is-active');
                    row.setAttribute('aria-selected', 'false');
                });

                const row = rows[index];

                if (!row) {
                    return;
                }

                row.classList.add('is-active');
                row.setAttribute('aria-selected', 'true');
                input.setAttribute('aria-activedescendant', row.id);
            };

            // Whether the whole history is showing. Per field, and reset when
            // the panel closes, so it opens short every time.
            let allRecent = false;

            /** The control that reveals the rest. Not an option: it chooses nothing. */
            const moreButton = function () {
                const button = document.createElement('button');

                button.type = 'button';
                button.className = 'combo__pill combo__pill--more js-recent-more';
                button.innerHTML = 'More <i class="fas fa-chevron-down" aria-hidden="true"></i>';

                return button;
            };

            /**
             * What to write in the box once an option has been chosen.
             *
             * Not the same string as the row in the list. The list is being read
             * to pick from, so it spells the airport out; the box is a quarter
             * as wide and is being read to confirm, where "Montreal" beside YUL
             * says more than "Pierre Elliott Trudeau Internatio...". Absent the
             * attribute -- the checkout's country field -- the row's own text is
             * already short and stands in.
             */
            const labelFor = function (option) {
                return option.dataset.short || option.textContent.trim();
            };

            const render = function (query) {
                const needle = query.trim().toLowerCase();
                // Rank, do not just filter. Alphabetical order alone answered
                // "ire" with Bonaire, Cote d'Ivoire and Zaire before Ireland,
                // which is every country containing the letters except the one
                // being typed. A name that starts with the query comes first,
                // then the country code, then anything containing it.
                const rank = function (option) {
                    const name = option.textContent.trim().toLowerCase();
                    // The second line counts too: an airport is looked up by its
                    // city at least as often as by its own name, and "Montreal"
                    // must find Trudeau.
                    const sub = (option.dataset.sub || '').toLowerCase();

                    if (needle === '') { return 0; }
                    if (name.startsWith(needle)) { return 0; }
                    if (option.value.toLowerCase().startsWith(needle)) { return 1; }
                    if (sub.startsWith(needle)) { return 2; }
                    if (name.includes(needle)) { return 3; }
                    if (sub.includes(needle)) { return 4; }

                    return -1;
                };

                const ranked = options
                    .map(o => ({ option: o, rank: rank(o) }))
                    .filter(m => m.rank >= 0)
                    // Stable within a rank, so each band stays alphabetical.
                    .sort((a, b) => a.rank - b.rank)
                    .map(m => m.option);

                // Only the first screenful. Every row costs layout, and a few
                // hundred of them held the thread long enough that the list
                // took a noticeable moment to appear -- for a list nobody reads
                // to the end, since typing one more letter is quicker than
                // scrolling. What is cut is always the worst-ranked.
                const found = ranked.slice(0, SHOWN_AT_ONCE);

                entries = found.map(option => ({ option }));

                list.innerHTML = '';

                // Only with an empty box: once someone is typing they are
                // looking for a place, not for last week.
                if (needle === '' && recentTpl) {
                    const block = recentTpl.content.cloneNode(true);
                    const pills = [...block.querySelectorAll('[data-path]')];

                    // Dropped from the DOM rather than hidden: the arrow keys
                    // walk `[role="option"]`, and a row nobody can see is still
                    // one the keyboard would stop on.
                    if (!allRecent && pills.length > RECENT_AT_ONCE) {
                        pills.slice(RECENT_AT_ONCE).forEach(pill => pill.remove());
                        block.querySelector('.combo__pills')?.appendChild(moreButton());
                    }

                    const recents = [...block.querySelectorAll('[data-path]')]
                        .map(el => ({ recent: { ...el.dataset } }));

                    list.appendChild(block);
                    entries = [...recents, ...entries];
                }

                if (found.length === 0) {
                    const empty = document.createElement('li');
                    empty.className = 'combo__empty';
                    empty.textContent = select.dataset.empty || 'Nothing matches that.';
                    list.appendChild(empty);
                    return;
                }

                found.forEach(function (option, i) {
                    const li = document.createElement('li');
                    li.className = 'combo__option';
                    li.setAttribute('role', 'option');
                    li.setAttribute('aria-selected', option.value === select.value ? 'true' : 'false');
                    li.dataset.value = option.value;

                    // One line where there is only a name, two where the option
                    // carries a place under it. The code sits at the end, which
                    // is where a traveller who knows it looks.
                    if (option.dataset.sub) {
                        // A span drawn by CSS, not a Font Awesome <i>. Its
                        // script rewrites every <i> into an <svg>, and with a
                        // few hundred rows that scan blocks the main thread for
                        // most of two seconds -- the list took seconds to
                        // appear. Same trap the breadcrumb separator documents.
                        const icon = document.createElement('span');
                        icon.className = option.hasAttribute('data-city')
                            ? 'combo__icon combo__icon--city'
                            : 'combo__icon combo__icon--airport';
                        icon.setAttribute('aria-hidden', 'true');

                        const name = document.createElement('span');
                        name.className = 'combo__name';
                        name.textContent = option.textContent.trim();

                        const sub = document.createElement('span');
                        sub.className = 'combo__sub';
                        sub.textContent = option.dataset.sub;

                        const code = document.createElement('span');
                        code.className = 'combo__code';
                        code.textContent = option.value;

                        li.classList.add('combo__option--stacked');
                        if (option.hasAttribute('data-city')) { li.classList.add('combo__option--city'); }
                        li.append(icon, name, code, sub);
                    } else {
                        li.textContent = option.textContent.trim();
                    }

                    list.appendChild(li);
                });

                if (ranked.length > found.length) {
                    const more = document.createElement('li');
                    more.className = 'combo__more';
                    more.textContent = (ranked.length - found.length) + ' more — keep typing to narrow';
                    list.appendChild(more);
                }

                // After both kinds are in the DOM, so the index lines up with
                // `entries` rather than with either half of it.
                // By role, not by class: a recent search is a chip and a place
                // is a row, and the arrow keys walk both.
                const rows = list.querySelectorAll('[role="option"]');

                // Focus stays in the text box while the arrows walk the list, so
                // the only thing telling a screen reader which row is being read
                // is aria-activedescendant. That is an id, which means every row
                // has to carry one.
                rows.forEach(function (row, i) {
                    row.id = listId + '-option-' + i;
                });

                if (active >= 0 && rows[active]) {
                    mark(rows, active);
                } else {
                    input.removeAttribute('aria-activedescendant');
                }
            };

            const open = function () {
                const chosen = select.selectedOptions[0];

                render(chosen && input.value === labelFor(chosen) ? '' : input.value);
                list.hidden = false;
                input.setAttribute('aria-expanded', 'true');
            };

            const close = function () {
                allRecent = false;
                list.hidden = true;
                input.setAttribute('aria-expanded', 'false');
                input.removeAttribute('aria-activedescendant');
                active = -1;
            };

            /**
             * Put a past search back into the form.
             *
             * Every control here is the one that submits -- the two selects, the
             * hidden dates, the party's selects and its cabin radio -- and each
             * has something already listening for its change: the combobox
             * repaints its box and its code, the date fields redraw and re-seed
             * their calendars, the party panel recounts its summary. So this
             * writes values and says so, and the form puts itself right.
             *
             * The pieces come from the server. A search path has one parser and
             * it is SearchUrl; a second one written in JavaScript would be a
             * copy of that grammar to keep in step.
             */
            const refill = function (parts) {
                const set = function (id, value) {
                    const field = document.getElementById(id);

                    if (!field) { return; }

                    field.value = value;
                    field.dispatchEvent(new Event('change', { bubbles: true }));
                };

                set('departing_airport-native', parts.from);
                set('arrival_airport-native', parts.to);

                // A span of one is no span at all, which is what an ordinary
                // search sends and what the field shows as a single day.
                set('depart_date_value', parts.depart);
                set('depart_flex_value', Number(parts.departSpan) > 1 ? parts.departSpan : '');
                set('return_date_value', parts.return);
                set('return_flex_value', Number(parts.returnSpan) > 1 ? parts.returnSpan : '');

                // "No children" is the absence of a number rather than a zero,
                // the same way the panel's own stepper writes it.
                set('passengers_adults', parts.adults);
                set('passengers_children', Number(parts.children) > 0 ? parts.children : '');
                set('passengers_infants', Number(parts.infants) > 0 ? parts.infants : '');

                const cabin = document.querySelector('.js-party-cabin[value="' + parts.cabin + '"]');

                if (cabin) {
                    cabin.checked = true;
                    cabin.dispatchEvent(new Event('change', { bubbles: true }));
                }
            };

            const choose = function (entry) {
                if (!entry) { return; }

                // A past search fills the form and stops there. It used to go
                // straight to the results, which took the decision away: the
                // whole reason to offer the trip again is usually to change one
                // thing about it.
                if (entry.recent) {
                    refill(entry.recent);

                    // This field is the one holding focus, and the change
                    // handler leaves a focused box alone so that it never
                    // overwrites what is being typed. Here the value is
                    // deliberate, so it is written directly rather than left to
                    // a blur that only fires if the window has focus at all.
                    input.value = select.value && select.selectedOptions[0]
                        ? labelFor(select.selectedOptions[0])
                        : '';
                    close();

                    return;
                }

                select.value = entry.option.value;
                input.value = labelFor(entry.option);
                // So validation, autofill and anything else see a real change.
                select.dispatchEvent(new Event('change', { bubbles: true }));
                close();
            };

            const moveActive = function (step) {
                if (list.hidden) { open(); }
                if (entries.length === 0) { return; }

                const rows = list.querySelectorAll('[role="option"]');

                active = (active + step + entries.length) % entries.length;

                // Move the marker rather than rebuild the list: re-rendering on
                // every arrow press meant holding the key down rebuilt hundreds
                // of rows per second.
                mark(rows, active);

                const el = rows[active];

                if (el && el.scrollIntoView) {
                    el.scrollIntoView({ block: 'nearest' });
                }
            };

            input.addEventListener('focus', function () {
                // Select what is there, so typing replaces the current choice
                // instead of landing inside it. The text inputs this replaced
                // carried a class for exactly this; a combobox that reopens on
                // an already-filled field needs it more, not less.
                input.select();
                open();
            });
            input.addEventListener('input', function () { active = -1; open(); });

            input.addEventListener('keydown', function (e) {
                if (e.key === 'ArrowDown') { e.preventDefault(); moveActive(1); }
                else if (e.key === 'ArrowUp') { e.preventDefault(); moveActive(-1); }
                else if (e.key === 'Enter') {
                    if (!list.hidden && entries[active]) { e.preventDefault(); choose(entries[active]); }
                } else if (e.key === 'Escape') {
                    close();
                    input.value = select.selectedOptions[0] ? labelFor(select.selectedOptions[0]) : '';
                }
            });

            list.addEventListener('mousedown', function (e) {
                // mousedown, not click: blur would close the list first.
                if (e.target.closest('.js-recent-more')) {
                    e.preventDefault();
                    allRecent = true;
                    active = -1;
                    render('');

                    return;
                }

                const li = e.target.closest('[role="option"]');
                if (!li) { return; }
                e.preventDefault();

                choose(li.dataset.path
                    ? { recent: { ...li.dataset } }
                    : { option: options.find(o => o.value === li.dataset.value) });
            });

            input.addEventListener('blur', function () {
                close();
                // Whatever half-typed text is left is not a country; show what
                // is actually selected rather than leaving a lie in the box.
                input.value = select.value && select.selectedOptions[0]
                    ? labelFor(select.selectedOptions[0])
                    : '';
            });

            // The airport code, shown to one side of the field. Driven off the
            // select rather than the text box: the box holds whatever is being
            // typed, and half a name is not a code.
            const code = input.closest('.searchbar__field')?.querySelector('.searchbar__code') ?? null;
            const showCode = function () {
                if (code) {
                    code.textContent = select.value;
                }
            };

            // Autofill and the server-rendered value both arrive this way, and
            // so does choose(), which dispatches change once it has written.
            select.addEventListener('change', function () {
                if (document.activeElement !== input) {
                    input.value = select.selectedOptions[0] && select.value
                        ? labelFor(select.selectedOptions[0])
                        : '';
                }

                showCode();
            });

            if (select.value) {
                input.value = labelFor(select.selectedOptions[0]);
            }

            showCode();
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
    // A picker per booking, inside its dialog. The search form's own init
    // cannot be reused: it is wired to four page-specific element ids and
    // writes the chosen date into those globals, so a second copy on the page
    // would fight it. This one only ever touches its own form.
    //
    // Built on first open rather than on load, because daterangepicker measures
    // the field to place its calendar and a field inside a hidden modal has no
    // position to measure. parentEl keeps the calendar inside the dialog, where
    // it stacks above the backdrop instead of behind it.
    document.addEventListener('show.bs.modal', function (event) {
        const modal = event.target;
        const input = modal.querySelector('.js-rebook-date');

        if (!input || input.dataset.pickerReady) {
            return;
        }

        input.dataset.pickerReady = '1';

        const $input = $(input);
        const $form = $input.closest('.js-rebook');
        const roundtrip = $input.data('triptype') === 'roundtrip';
        const display = 'MMMM D, YYYY';

        $input.daterangepicker({
            autoApply: true,
            showCustomRangeLabel: false,
            autoUpdateInput: false,
            singleDatePicker: !roundtrip,
            minDate: moment().format(display),
            parentEl: '#' + modal.id + ' .modal-content',
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

    // "No children" is the absence of a number, not an empty one. A disabled
    // control is not submitted, which keeps `children=&infants=` out of a
    // search URL people share.
    //
    // Both search forms need this now that their passenger selects have names;
    // it used to cover the rebook dialog alone, which was the only form in the
    // app that submitted a count.
    document.addEventListener('submit', function (event) {
        const form = event.target.closest('.js-rebook, #searchForm');

        if (!form) {
            return;
        }

        form.querySelectorAll('select').forEach(function (select) {
            select.disabled = select.value === '';
        });
    });

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
            //
            // The separator has to be worked out: the search itself lives in the
            // path now, so a link with no filters or sort carries no query
            // string at all and a bare `&` would make a URL nothing serves.
            const url = link.href
                + (link.href.includes('?') ? '&' : '?')
                + 'fragment=1&after=' + encodeURIComponent(link.dataset.after);

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

})(jQuery);


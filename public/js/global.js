(function ($) {
    'use strict';

    const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

    /*[ Search form: the two date fields ]
    ===========================================================*/
    try {
        // Days a window may cover, itself included. SearchUrl::MAX_SPAN is the
        // definition; this is the picker's own limit and the two must agree.
        const MAX_SPAN = 3;

        const Day = window.TripDatePicker.Day;
        const at = (id) => document.getElementById(id);

        const departInput = at('depart_date');
        const returnInput = at('return_date');

        if (departInput && returnInput) {
            const departValue = at('depart_date_value');
            const returnValue = at('return_date_value');
            const departFlex = at('depart_flex_value');
            const returnFlex = at('return_flex_value');
            const clearReturn = document.querySelector('.js-clear-return');

            const spanOf = (field) => Math.max(1, parseInt(field.value, 10) || 1);

            // The picker never writes the field itself: a field has to be able
            // to stay empty until it is picked, and one that filled itself on
            // load would turn every one-way search into a round trip nobody
            // asked for.
            const show = (input, hidden, flex, start, end) => {
                const days = Math.min(MAX_SPAN, Day.diff(end, start) + 1);

                hidden.value = Day.iso(start);
                // Blank rather than 1: a plain search should send no flex at all.
                flex.value = days > 1 ? String(days) : '';
                input.value = days > 1
                    ? Day.short(start) + ' \u2013 ' + Day.short(end)
                    : Day.short(start);
            };

            const redraw = (input, hidden, flex) => {
                const start = Day.parse(hidden.value);

                if (!start) {
                    input.value = '';

                    return;
                }

                show(input, hidden, flex, start, Day.add(start, spanOf(flex) - 1));
            };

            // What a seat costs on each day of the route, fetched once the
            // calendar is already on screen.
            //
            // Never before it: a route nobody has opened yet has to be worked
            // out, which runs to seconds on a busy one. Asking first would mean
            // a calendar that takes five seconds to appear; asking after means
            // one that appears at once and fills in.
            const asked = new Set();

            const cabinNow = () => document.querySelector('.js-party-cabin:checked')?.value ?? 'economy';

            /**
             * How many adult fares and how many adult taxes the party costs.
             *
             * The rates come from Party as data attributes rather than being
             * retyped here -- there is one definition of what a child costs and
             * it is in PHP -- and the counts come from the selects that submit.
             */
            const sharesNow = () => {
                const panel = document.querySelector('.party__panel');

                if (!panel) {
                    return {fare: 1, tax: 1};
                }

                const count = (key) => Number(document.getElementById('passengers_' + key)?.value) || 0;
                const rate = (key) => Number(panel.dataset[key]);
                const adults = count('adults');
                const children = count('children');
                const infants = count('infants');

                return {
                    fare: adults + children * rate('childFare') + infants * rate('infantFare'),
                    tax: adults + children * rate('childTax') + infants * rate('infantTax')
                };
            };

            const pricesFor = (picker, fromField, toField, legName) => {
                const from = document.getElementById(fromField)?.value;
                const to = document.getElementById(toField)?.value;

                if (!from || !to || from === to) {
                    return;
                }

                // The cabin is part of what is being asked. The cheapest
                // business day on a route is not the cheapest economy day, so
                // changing cabin asks again rather than leaving economy fares
                // under a business search.
                const cabin = cabinNow();
                const route = legName + ':' + from + '-' + to + ':' + cabin;

                // Once per route per page, and only once it has answered with
                // something. An empty answer means somebody else is working the
                // route out right now, so the next open should ask again rather
                // than leave this calendar priceless for the rest of the visit.
                if (asked.has(route)) {
                    return;
                }

                asked.add(route);

                const body = new FormData();

                body.append('from', from);
                body.append('to', to);
                body.append('class', cabin);
                // Csrf::FIELD. The header name below is mirrored from
                // Csrf::HEADER the same way -- JavaScript cannot read a PHP
                // constant, so these two strings are the contract, and the
                // constants are where it is defined.
                body.append('_csrf', csrfToken());

                fetch('/ajax/day-prices', {method: 'POST', body: body})
                    .then((response) => response.ok ? response.json() : null)
                    .then((data) => {
                        if (data && data.prices && Object.keys(data.prices).length) {
                            // The currency the response declared, not the one
                            // the page was rendered in. They are the same
                            // almost always, and when they are not it is
                            // because the cookie changed since -- in which case
                            // these figures belong to the new one.
                            picker.setPrices(data.prices, legName, data.currency);

                            return;
                        }

                        asked.delete(route);
                    })
                    // A calendar without fares still picks dates, so a route
                    // that cannot be priced is not worth an error in anyone's
                    // console -- but it is worth asking again next time.
                    .catch(() => asked.delete(route));
            };

            // The calendar hangs from whatever carries the search bar, so a bar
            // that sticks to the header takes its calendar with it. Parented to
            // the body it was positioned once, when it opened, and then sat
            // where the page had been rather than where the field now is.
            //
            // Found by asking rather than by naming the two bands that do this:
            // the homepage and the results page stick different elements, and a
            // third would have to be remembered here.
            const carrier = (node) => {
                for (let el = node.parentElement; el && el !== document.body; el = el.parentElement) {
                    if (getComputedStyle(el).position === 'sticky') {
                        return el;
                    }
                }

                return document.body;
            };

            // One calendar for both fields, the way the reference works: the
            // field being edited owns the clicks and the other leg stays on
            // screen dimmed, so the trip reads as a whole while either end of
            // it is being changed.
            const picker = new window.TripDatePicker(departInput, {
                parent: carrier(departInput),
                // The bar wraps on a narrow window, putting the party and the
                // submit on a row of their own under the dates.
                clears: departInput.closest('.searchbar'),
                legs: [
                    {name: 'out', input: departInput, start: departValue.value || null, span: spanOf(departFlex)},
                    {name: 'back', input: returnInput, start: returnValue.value || null, span: spanOf(returnFlex)}
                ],
                maxSpan: MAX_SPAN,
                // Sized for fares from the first paint, so the grid does not
                // grow a line under the pointer when they arrive.
                showPrices: true,
                shares: sharesNow(),
                // The window here is not "depart to return" -- that is what the
                // two legs are for -- but how flexible one end of the trip is,
                // so it is dragged rather than clicked out over two days.
                drag: true,
                legLabels: {one: 'Choose one way', round: 'Choose round trip'},
                // Both legs, whichever one is being edited. The button totals
                // the trip, and a total needs the fare on the way back as well
                // as the fare out -- asking for it only when the return field
                // is opened would leave the button blank until it was.
                onOpen: (self) => {
                    pricesFor(self, 'departing_airport-native', 'arrival_airport-native', 'out');
                    pricesFor(self, 'arrival_airport-native', 'departing_airport-native', 'back');
                },
                // Both legs at once. Either can have moved while the calendar
                // was open -- choosing a departure after a return drags the
                // return along with it -- so both are written back.
                onCommit: (legs) => {
                    const out = legs.find((leg) => leg.name === 'out');
                    const back = legs.find((leg) => leg.name === 'back');

                    if (out.start) {
                        show(departInput, departValue, departFlex, out.start, out.end ?? out.start);
                    }

                    if (back.start) {
                        show(returnInput, returnValue, returnFlex, back.start, back.end ?? back.start);
                        clearReturn?.removeAttribute('hidden');
                    }
                }
            });

            const legOf = (name) => picker.legs.find((leg) => leg.name === name);

            /**
             * Who the price on the button is for.
             *
             * The words come from the selects themselves, so "1 child" and
             * "2 children" are not a second list to keep in step with the panel.
             */
            const captionNow = () => {
                const parts = [...document.querySelectorAll('.js-party-count')]
                    .map((select) => {
                        const count = Number(select.value) || 0;

                        return count === 0
                            ? null
                            : count + ' ' + (count === 1 ? select.dataset.one : select.dataset.many);
                    })
                    .filter(Boolean);

                return parts.join(', ');
            };

            const repriceForParty = () => {
                picker.setShares(sharesNow());
                picker.setCaption(captionNow());
            };

            // Fares follow the cabin. The panel can be open while it changes --
            // the party dropdown sits in the same bar -- so the cells are
            // refilled rather than waiting for the calendar to be reopened.
            // A different party is the same fares multiplied differently, so
            // this is arithmetic on what is already loaded rather than another
            // request.
            document.querySelectorAll('.js-party-count').forEach((select) => {
                select.addEventListener('change', repriceForParty);
            });

            picker.setCaption(captionNow());

            document.querySelectorAll('.js-party-cabin').forEach((radio) => {
                radio.addEventListener('change', () => {
                    picker.legs.forEach((leg) => { leg.prices = null; });
                    // And forget what has been asked for. Switching back to a
                    // cabin already seen would otherwise be skipped as a repeat
                    // and leave the calendar with no fares at all.
                    asked.clear();
                    picker.paintPrices();
                    picker.paint();
                    pricesFor(picker, 'departing_airport-native', 'arrival_airport-native', 'out');
                    pricesFor(picker, 'arrival_airport-native', 'departing_airport-native', 'back');
                });
            });

            // The way back to a one-way trip, now that no tab does it.
            clearReturn?.addEventListener('click', function () {
                const back = legOf('back');

                returnValue.value = '';
                returnFlex.value = '';
                returnInput.value = '';
                back.start = null;
                back.end = null;
                picker.paint();
                this.hidden = true;
            });

            redraw(departInput, departValue, departFlex);
            redraw(returnInput, returnValue, returnFlex);

            // Set from outside the picker -- a past search being put back into
            // the form. The hidden field carries the date and the flex field its
            // width, and either can arrive first, so both are watched: whichever
            // writes last settles what the field reads.
            const follow = (input, hidden, flex, name, clear) => {
                const update = () => {
                    const leg = legOf(name);
                    const start = Day.parse(hidden.value);

                    if (!start) {
                        input.value = '';
                        flex.value = '';
                        clear?.setAttribute('hidden', '');
                        leg.start = null;
                        leg.end = null;
                        picker.paint();

                        return;
                    }

                    redraw(input, hidden, flex);
                    clear?.removeAttribute('hidden');
                    leg.start = start;
                    leg.end = Day.add(start, spanOf(flex) - 1);
                    picker.enforceOrder();
                    picker.paint();
                };

                hidden.addEventListener('change', update);
                flex.addEventListener('change', update);
            };

            follow(departInput, departValue, departFlex, 'out');
            follow(returnInput, returnValue, returnFlex, 'back', clearReturn);
        }
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
            const slug = s => s.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();

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

            /**
             * Which cities are in a set of options, so an airport in it can
             * tell whether it is being shown under its own city.
             *
             * The set and not the whole list: typing "trudeau" finds one
             * airport and no city, and indenting it under a heading that is not
             * there would be an orphan. Typing "london" finds the city and its
             * three, which are children. Keyed on the city code rather than on
             * the name it displays -- a display string is not a relationship,
             * and matching on one breaks the day two cities share a name.
             */
            const citiesIn = function (options) {
                return new Set(
                    options.filter(o => o.hasAttribute('data-city')).map(o => o.dataset.inCity),
                );
            };

            /**
             * A section heading: the nearby block's, and a city's.
             *
             * `role="presentation"` so the arrow keys walk past it. A label
             * rather than something to choose -- and for a city it has to be,
             * because the city is not selectable. pickable() draws a city row
             * only where the city sells from more than one airport, which is
             * 18 of 231 of them, and offering a second code for the rest would
             * be two ways to run one search: measured, `YMQ` and `YUL` both
             * resolve to exactly `YUL`.
             */
            const headingFor = function (text) {
                const li = document.createElement('li');
                li.className = 'combo__group';
                li.setAttribute('role', 'presentation');
                li.textContent = text;

                return li;
            };

            /**
             * One row. Shared by the list and by the nearby block above it, so
             * the city/child/second-line rules are written once.
             */
            const rowFor = function (option, citiesShown) {
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

                    const code = document.createElement('span');
                    code.className = 'combo__code';
                    code.textContent = option.value;

                    const underItsCity = !option.hasAttribute('data-city')
                        && citiesShown.has(option.dataset.inCity);

                    li.classList.add('combo__option--stacked');
                    if (option.hasAttribute('data-city')) { li.classList.add('combo__option--city'); }
                    if (underItsCity) { li.classList.add('combo__option--child'); }
                    li.append(icon, name, code);

                    // The second line is where the airport is, and under
                    // its own city that is already on screen a line above:
                    // "London, United Kingdom" three times under "London"
                    // is the same fact restated. Indented and one line, the
                    // three read as the city's airports. On its own the row
                    // keeps it, because then nothing else says where it is.
                    if (!underItsCity) {
                        const sub = document.createElement('span');
                        sub.className = 'combo__sub';
                        sub.textContent = option.dataset.sub;
                        li.append(sub);
                    }
                } else {
                    li.textContent = option.textContent.trim();
                }

                return li;
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

                // Ranked by city rather than row by row, so a city's airports
                // stay together.
                //
                // Row by row they did not. `par` put Paro International in
                // the middle of Paris: "Paris Orly Airport" matches on its
                // name and lands in band 0, Charles De Gaulle matches on its
                // city line and lands in band 2, and Paro sorts between them
                // -- so Paris was drawn as two halves around an airport in
                // Bhutan. The nesting made it visible and the grouping is what
                // fixes it.
                //
                // A group takes its best member's band, which is what makes
                // "lon" answer with London and its three before anything else
                // that merely contains those letters. Within a group the
                // original order stands: the select ships a city immediately
                // above its own airports, and `sort` is stable, so the city
                // still leads and ties between groups stay alphabetical.
                const groups = new Map();

                options.forEach(function (option) {
                    const band = rank(option);

                    if (band < 0) {
                        return;
                    }

                    // Keyed on the city, so an airport joins the group its
                    // city leads even when the two matched for different
                    // reasons -- which is the whole of the Paro problem.
                    const key = option.dataset.inCity || option.value;
                    const group = groups.get(key);

                    if (group === undefined) {
                        groups.set(key, { band: band, rows: [option] });

                        return;
                    }

                    group.band = Math.min(group.band, band);
                    group.rows.push(option);
                });

                const ranked = [...groups.values()]
                    .sort((a, b) => a.band - b.band)
                    .flatMap(group => group.rows);

                // Only the first screenful. Every row costs layout, and a few
                // hundred of them held the thread long enough that the list
                // took a noticeable moment to appear -- for a list nobody reads
                // to the end, since typing one more letter is quicker than
                // scrolling. What is cut is always the worst-ranked.
                const found = ranked.slice(0, SHOWN_AT_ONCE);

                list.innerHTML = '';

                let leading = [];

                // Where else somebody could fly from, when the field is open on
                // a place. Only with an empty box, for the same reason the
                // recent searches are: once they are typing they know what they
                // are looking for.
                //
                // Shown only while the chosen option is still the one the server
                // measured from. The list is rendered with the page, so picking
                // something else and reopening would otherwise offer the
                // neighbours of the old place -- and a block that quietly
                // describes the wrong airport is worse than no block. It
                // disappears instead.
                const anchored = select.dataset.nearbyFor;
                const chosenNow = select.selectedOptions[0];

                if (needle === '' && anchored && chosenNow && chosenNow.value === anchored) {
                    const wanted = (select.dataset.nearby || '').split(',').filter(Boolean);
                    const near = wanted
                        .map(code => options.find(o => o.value === code))
                        .filter(Boolean);

                    if (near.length > 0) {
                        list.appendChild(headingFor('Airports nearby'));

                        // The block's own cities, so an airport nests under the
                        // city beside it here rather than under one further
                        // down the full list.
                        const within = citiesIn(near);

                        near.forEach(function (option) {
                            list.appendChild(rowFor(option, within));
                        });

                        leading = near.map(option => ({ option }));
                    }
                }

                // Only with an empty box: once someone is typing they are
                // looking for a place, not for last week.
                let recents = [];

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

                    recents = [...block.querySelectorAll('[data-path]')]
                        .map(el => ({ recent: { ...el.dataset } }));

                    list.appendChild(block);
                }

                // In the order they are in the DOM, so the arrow keys and
                // aria-activedescendant agree with what is on screen.
                entries = [...leading, ...recents, ...found.map(option => ({ option }))];

                if (found.length === 0) {
                    const empty = document.createElement('li');
                    empty.className = 'combo__empty';
                    empty.textContent = select.dataset.empty || 'Nothing matches that.';
                    list.appendChild(empty);
                    return;
                }

                // A city's own airports nest under it where the city is on
                // screen as a row of its own, which is the 18 cities that sell
                // from more than one airport.
                //
                // A heading stood here for the other 213, whose city is not
                // selectable -- "Montreal, Canada" over Trudeau. It went back
                // out: for a city with one airport the row already carries the
                // city on its second line, and a heading over a single row
                // says the same thing twice and costs a line to do it.
                //
                // Measured before removing it, over every city name and every
                // 2-6 letter prefix of one: with the heading kept only for
                // cities offering two airports or more, it fired on 0 of 1,062
                // queries. There is no version of it that draws for a city
                // worth grouping, because a city with two airports has a row
                // of its own and takes the nesting path above instead.
                found.forEach(function (option) {
                    list.appendChild(rowFor(option, citiesIn(found)));
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
            text: named + ' will be cancelled. You will find it under Cancelled.',
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

            moveToCancelled(card, button, reference);
        });
    });

    // Cancelled bookings live on their own page now, so the card leaves this
    // one instead of being restyled where it stands. It is faded out rather
    // than cut: something vanishing from under the pointer with no transition
    // reads as a bug, and the half second is where the confirmation lives.
    // The live region carries it for anyone who cannot see that.
    function moveToCancelled(card, button, reference) {
        // The tooltip outlives its trigger otherwise, and hangs over the card.
        const tip = bootstrap.Tooltip.getInstance(button);

        if (tip) {
            tip.dispose();
        }

        button.remove();

        const live = document.querySelector('.js-bookings-live');

        if (live) {
            live.textContent = (reference ? 'Booking ' + reference : 'Booking')
                + ' cancelled, and moved to your cancelled bookings.';
        }

        if (!card) {
            return;
        }

        const section = card.closest('section');

        card.classList.add('booking-card--leaving');
        setTimeout(function () {
            card.remove();
            retally(section);
        }, 400);
    }

    // Every count the cancelled card was in: its own group's badge, the tab it
    // sat under, and the tab it has gone to. A group with nothing left in it
    // goes as well, heading and all, rather than standing over a gap.
    function retally(section) {
        if (section && !section.querySelector('[data-booking-card]')) {
            section.remove();
        } else if (section) {
            bump(section.querySelector('.badge'), -1);
        }

        const tabs = document.querySelectorAll('.bookings-tabs__tab');

        bump(tabs[0] && tabs[0].querySelector('.bookings-tabs__count'), -1);
        bump(tabs[1] && tabs[1].querySelector('.bookings-tabs__count'), 1);

        // Nothing left to show. The empty state is rendered by the server, so
        // the page is asked for again rather than rebuilt here.
        if (!document.querySelector('[data-booking-card]')) {
            window.location.reload();
        }
    }

    function bump(node, by) {
        if (!node) {
            return;
        }

        node.textContent = String(Math.max(0, (parseInt(node.textContent, 10) || 0) + by));
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
    // Built on first open rather than on load, because the picker measures the
    // field to place its calendar and a field inside a hidden modal has no
    // position to measure. It is rendered into the dialog, where it stacks
    // above the backdrop instead of behind it.
    const rebookPickers = new WeakMap();

    document.addEventListener('show.bs.modal', function (event) {
        const modal = event.target;
        const input = modal.querySelector('.js-rebook-date');

        if (!input || input.dataset.pickerReady) {
            return;
        }

        input.dataset.pickerReady = '1';

        const Day = window.TripDatePicker.Day;
        const form = input.closest('.js-rebook');
        const roundtrip = input.dataset.triptype === 'roundtrip';

        const picker = new window.TripDatePicker(input, {
            // A one-way is one day; a round trip is a departure and a return,
            // clicked out over two days with nothing capping how far apart they
            // are. Neither is the flexible window the search bar drags.
            single: !roundtrip,
            autoApply: true,
            // Above the dialog rather than inside it: a scrollable modal hides
            // its own overflow, and a calendar in the body of one is cut off at
            // the dialog's edge.
            //
            // Still a child of the modal, though, and not of the page. The
            // dialog holds focus inside itself, and a calendar parked in the
            // body is somewhere focus is not allowed to go -- arrowing onto a
            // day threw focus straight back to the close button. Being a child
            // of the modal also means it goes away with it.
            overlay: true,
            parent: modal,
            onApply: (start, end) => {
                form.querySelector('.js-rebook-depart').value = Day.iso(start);

                if (!roundtrip) {
                    input.value = Day.full(start);

                    return;
                }

                form.querySelector('.js-rebook-return').value = Day.iso(end);
                input.value = Day.full(start) + ' \u2013 ' + Day.full(end);
            }
        });

        rebookPickers.set(modal, picker);
    });

    // Closing the dialog puts the calendar away with it. Hiding the modal
    // already hides the panel -- it is a child of it -- but the picker would go
    // on thinking it was open, and the field would still be marked as the one
    // being filled in when the dialog came back.
    document.addEventListener('hidden.bs.modal', function (event) {
        rebookPickers.get(event.target)?.hide(false);
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

    /*[ Currency switcher ]
    ===========================================================*/
    // Open the panel, filter it, choose, reload.
    //
    // A reload rather than repainting the prices in place, and not a navigation
    // either. Every price on the page is rendered by PHP, so the server has to
    // draw them again -- and going to a URL would throw away the query string
    // the search pages carry: max_price, the party, the cabin. Reloading keeps
    // the page somebody was on, in the currency they just picked.
    //
    // The cookie's name and lifetime come off the panel's data attributes, so
    // src/Currency.php stays the only place either is written down.
    (function currencySwitcher() {
        const trigger = document.querySelector('.js-currency-trigger');
        const panel = trigger && document.getElementById(trigger.getAttribute('aria-controls'));

        if (!panel) {
            return;
        }

        const filter = panel.querySelector('.js-currency-filter');
        const options = [...panel.querySelectorAll('.js-currency-choice')];

        const open = (show) => {
            panel.hidden = !show;
            trigger.setAttribute('aria-expanded', show ? 'true' : 'false');

            if (show && filter) {
                // The list is thirty long and somebody opening it usually knows
                // which one they want.
                filter.focus();
            }
        };

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

        filter?.addEventListener('input', function () {
            const needle = this.value.trim().toLowerCase();

            options.forEach((option) => {
                // `hidden` and not a class: a filtered-out currency should leave
                // the tab order as well as the view.
                option.closest('.currency__item').hidden =
                    needle !== '' && !option.dataset.search.includes(needle);
            });
        });

        options.forEach((option) => {
            option.addEventListener('click', function () {
                document.cookie = panel.dataset.cookie + '=' + encodeURIComponent(this.dataset.code)
                    + ';path=/;max-age=' + panel.dataset.maxAge + ';samesite=lax'
                    + (window.location.protocol === 'https:' ? ';secure' : '');

                window.location.reload();
            });
        });
    }());

    /*[ Cookie notice ]
    ===========================================================*/
    // The whole of it: write the answer, take the bar away. The counters are
    // rendered by the server, so analytics begins on the next page rather than
    // this one -- a reload would start it a few seconds sooner and throw away
    // whatever the visitor had already typed into the search form.
    //
    // The cookie's name, lifetime and two values come off the element's own
    // data attributes, so src/Consent.php remains the only place they are
    // spelled. Nothing here runs when the bar is absent, which is every page
    // view after the first answer.
    (function cookieNotice() {
        const notice = document.querySelector('.js-cookie-notice');

        if (!notice) {
            return;
        }

        notice.querySelectorAll('.js-cookie-choice').forEach((button) => {
            button.addEventListener('click', function () {
                document.cookie = notice.dataset.cookie + '=' + encodeURIComponent(this.dataset.consent)
                    + ';path=/;max-age=' + notice.dataset.maxAge + ';samesite=lax'
                    + (window.location.protocol === 'https:' ? ';secure' : '');

                notice.remove();
            });
        });
    }());

    // And the way back out of that answer, on the cookies page. A reload rather
    // than a redraw: the counters are rendered by the server, so the page has
    // to be built again to stop carrying them.
    (function cookieReset() {
        const button = document.querySelector('.js-cookie-reset');

        if (!button) {
            return;
        }

        button.addEventListener('click', function () {
            document.cookie = this.dataset.cookie + '=;path=/;max-age=0';
            window.location.reload();
        });
    }());

    /*[ Saved flights ]
    ===========================================================*/
    // Kept in a cookie rather than localStorage so the server can render
    // /my/saved without the page having to hand the list back over AJAX.
    // A saved flight is its ordered leg ids, plus the cabin it was found in
    // where that was not economy -- `12-34` or `12-34:C`. That is all the
    // server needs to rebuild it, and the cabin is not a cached price: it is
    // which price to read. Prices and times are still looked up fresh, never
    // stored. The key is written by the card, so this only carries it around.
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

    /*[ How tall the header is ]
    ===========================================================*/
    // Written for the CSS, which holds the search bars against the header's
    // underside on the home and results pages. Measured rather than assumed:
    // the navbar wraps at some widths and not others. This runs on every page,
    // because every page has a header and the results page has a sticky bar
    // whether or not it has a hero.
    (function () {
        const header = document.getElementById('top');

        if (!header) {
            return;
        }

        const measure = () => document.documentElement.style
            .setProperty('--header-h', header.offsetHeight + 'px');

        measure();
        window.addEventListener('resize', measure);
    }());

    /*[ Homepage: dock the section tray into the header ]
    ===========================================================*/
    // Once the slogan and the tray have scrolled under the header, the tray
    // leaves the hero and takes its place in the middle of the header, which is
    // sticky -- so the sections stay reachable for the rest of the page.
    //
    // The search bar below it needs no script at all: it is `position: sticky`
    // and the browser holds it against the header on its own. It used to be
    // pinned from here, and could not be made not to jump -- a scroll runs on
    // the compositor and this runs on the main thread, so on a flick the page
    // had already moved by the time the class landed.
    //
    // The tray is moved rather than copied, and lands in a real slot in the
    // header row, which centres it without any arithmetic.
    (function () {
        const header = document.getElementById('top');
        const dock = document.querySelector('.js-header-dock');
        const modes = document.querySelector('.js-hero-modes');
        const slot = document.querySelector('.js-modes-slot');
        const sentinel = document.querySelector('.js-modes-sentinel');

        if (!header || !dock || !modes || !slot || !sentinel) {
            return;
        }

        // The width the docked tray needs to clear the menu beside it, and the
        // same number as the media query that hides the dock. Below it the tray
        // would be moved into something display:none and disappear on scroll
        // instead of staying in the hero, so the two have to agree.
        const DOCKS_ABOVE = 1280;

        // Nothing in the world arrives instantly. The tray appears in a place
        // it was not a frame ago, and without this it reads as a glitch rather
        // than as the same tray having moved. Short, and on the way in only:
        // going back to the hero it is landing where the visitor is already
        // looking.
        const REDUCED = window.matchMedia('(prefers-reduced-motion: reduce)');

        const fadeIn = () => {
            if (REDUCED.matches || typeof modes.animate !== 'function') {
                return;
            }

            modes.animate(
                [{opacity: 0, transform: 'translateY(-.25rem)'}, {opacity: 1, transform: 'none'}],
                {duration: 180, easing: 'cubic-bezier(0.23, 1, 0.32, 1)'}
            );
        };

        const setDocked = (docked) => {
            const wanted = docked && window.innerWidth >= DOCKS_ABOVE;

            if (wanted === modes.classList.contains('is-docked')) {
                return;
            }

            // Held open only while the tray is away, and measured at the moment
            // it leaves rather than once at load: the tray is a few pixels
            // shorter after its first trip to the header, and a height taken
            // before that left the slot standing slightly too tall for the rest
            // of the page's life.
            slot.style.minHeight = wanted ? slot.offsetHeight + 'px' : '';

            modes.classList.toggle('is-docked', wanted);
            (wanted ? dock : slot).appendChild(modes);

            if (wanted) {
                fadeIn();
            }
        };

        // An observer rather than a scroll handler: this fires a handful of
        // times per page rather than on every frame of every scroll, and a
        // callback a frame late costs nothing here -- the tray is out of sight
        // when it docks. The sentinel sits at the foot of the slot, which holds
        // its place open, so it cannot be moved by what it is watching for.
        const watch = () => {
            // The root's top edge, which the margin below has pushed down to
            // the header's underside. Compared against that rather than against
            // zero: with the margin in play a sentinel can be out of the root
            // and still have a positive top, which is a sentinel that has gone
            // under the header -- exactly the case being watched for.
            const line = header.offsetHeight;
            const observer = new IntersectionObserver(
                (entries) => setDocked(!entries[0].isIntersecting && entries[0].boundingClientRect.top < line),
                {rootMargin: '-' + line + 'px 0px 0px 0px', threshold: 0}
            );

            observer.observe(sentinel);

            return observer;
        };

        let observer = watch();

        window.addEventListener('resize', function () {
            // Put the tray back before measuring: a docked one cannot report
            // its resting height, and the observer is carrying the old header
            // height until it is rebuilt.
            setDocked(false);
            observer.disconnect();
            slot.style.minHeight = '';
            observer = watch();
        });
    }());

    /*[ Back to top ]
    ===========================================================*/
    (function () {
        const button = document.querySelector('.js-to-top');

        if (!button) {
            return;
        }

        // The theme toggle shares this corner and lifts out of the way when the
        // button above appears. Driven from here rather than from its own
        // scroll listener, so the two move on the same frame and cannot
        // disagree about whether there is anything to move for.
        const toggle = document.querySelector('.js-theme');

        // Roughly a screen and a half: far enough that the header is well out
        // of reach, near enough that it is there when it is wanted.
        const THRESHOLD = 800;

        let ticking = false;

        // Read on every use, not once, so a preference changed mid-session is
        // honoured without a reload.
        const still = window.matchMedia('(prefers-reduced-motion: reduce)');

        const paint = function () {
            const past = window.scrollY > THRESHOLD;

            if (toggle !== null) {
                toggle.classList.toggle('is-raised', past);
            }

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

    /**
     * The active currency, as the server rendered it onto <body>.
     *
     * Read on each call rather than cached, because it is three attribute
     * lookups and caching it would be one more thing to get stale.
     */
    function activeCurrency() {
        const data = document.body.dataset;

        return {
            symbol: data.currencySymbol || '$',
            before: data.currencyBefore !== '0',
            group: data.currencyGroup || ',',
            rate: Number(data.currencyRate) || 1
        };
    }

    /**
     * A price slider's caption.
     *
     * The value in is Canadian dollars and stays that way: it is what the
     * filter compares, and what a shared search link carries, so a link means
     * the same thing whoever opens it and whatever the rate did overnight. Only
     * the caption converts -- see the note on Helper::sliderCaption, which
     * paints this same pill on the first render and has to agree with it.
     *
     * The steps stay Canadian-dollar shaped, so a yen pill reads "Up to
     * ¥5,425" rather than a round number. Correct and odd beats round and off
     * by a step.
     */
    function sliderLabel(kind, value) {
        if (kind !== 'money') {
            return formatMinutes(value);
        }

        const currency = activeCurrency();
        const digits = Math.round(value * currency.rate)
            .toLocaleString('en-US')
            .replace(/,/g, currency.group);

        return currency.before ? currency.symbol + digits : digits + ' ' + currency.symbol;
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

    /*[ Footer: fare alerts ]
    ===========================================================*/
    (function () {
        const form = document.querySelector('.js-subscribe');

        if (!form) {
            return;
        }

        const field = form.querySelector('.js-subscribe-email');
        const note = form.querySelector('.js-subscribe-note');
        const submit = form.querySelector('[type="submit"]');

        // Every answer says what actually happened. The form this replaced took
        // an address and dropped it without a word, which is the one outcome
        // ruled out here -- including the failures: a request that did not
        // arrive says so rather than looking like a success.
        const say = (message, tone) => {
            note.textContent = message;
            note.dataset.tone = tone;
        };

        form.addEventListener('submit', function (event) {
            event.preventDefault();

            const email = field.value.trim();

            // Checked again on the server. This one is only here to save a
            // round trip on an obvious typo.
            if (!field.checkValidity() || email === '') {
                say('That does not look like an email address.', 'bad');
                field.focus();

                return;
            }

            submit.disabled = true;
            say('Sending…', 'quiet');

            fetch(form.action, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-Token': csrfToken(),
                    // What tells the endpoint to answer in JSON. Without it the
                    // same form posts itself and is sent back to the page, which
                    // is what happens when this script is not running.
                    'Accept': 'application/json'
                },
                body: new URLSearchParams({email: email})
            })
                .then((response) => response.json().then((data) => ({ok: response.ok, data: data})))
                .then(({ok, data}) => {
                    if (!ok) {
                        say(data.message || 'That did not work. Try again in a moment.', 'bad');

                        return;
                    }

                    // Two different things, and the difference is worth saying:
                    // somebody who cannot remember whether they subscribed is
                    // exactly who needs telling that they already have.
                    say(data.message, data.added ? 'good' : 'quiet');

                    if (data.added) {
                        form.reset();
                    }
                })
                .catch(() => say('That did not work. Try again in a moment.', 'bad'))
                .finally(() => {
                    submit.disabled = false;
                });
        });
    }());

    /*[ Article: did this help? ]
    ===========================================================*/
    (function () {
        const form = document.querySelector('.js-article-vote');

        if (!form) {
            return;
        }

        const block = form.closest('.article__verdict');
        const status = block.querySelector('.js-vote-status');
        const tally = block.querySelector('.js-vote-tally');
        const buttons = [...form.querySelectorAll('[type="submit"]')];
        const slug = form.querySelector('[name="slug"]');

        const say = (message, tone) => {
            status.textContent = message;
            status.dataset.tone = tone;
        };

        form.addEventListener('submit', function (event) {
            // Which of the two was pressed. They differ only by value, so
            // without this there is nothing to send -- and if the browser does
            // not name the submitter, the post is left to happen normally
            // rather than sent without a verdict.
            const pressed = event.submitter;

            if (!pressed || !pressed.value) {
                return;
            }

            event.preventDefault();
            buttons.forEach((button) => { button.disabled = true; });
            say('Saving…', 'quiet');

            fetch(form.action, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-Token': csrfToken(),
                    // What asks for JSON. Without it this same form posts
                    // itself and the browser is sent back to the page, which
                    // is what happens when this script is not running.
                    'Accept': 'application/json'
                },
                body: new URLSearchParams({slug: slug.value, helpful: pressed.value})
            })
                .then((response) => response.json().then((data) => ({ok: response.ok, data: data})))
                .then(({ok, data}) => {
                    if (!ok) {
                        say(data.message || 'That did not work. Try again in a moment.', 'bad');

                        return;
                    }

                    say(data.message, data.helpful ? 'good' : 'quiet');

                    // Mark the one that was pressed and clear the other, so
                    // changing your mind moves the tick rather than lighting
                    // both. The server has already replaced the row.
                    buttons.forEach((button) => {
                        button.classList.toggle('article__verdict-button--chosen', button === pressed);
                    });

                    // Whether figures may be shown is the server's answer, not
                    // a threshold repeated here.
                    if (data.shown) {
                        tally.textContent = tally.dataset.template
                            .replace('%yes%', data.yes)
                            .replace('%votes%', data.votes);
                    }

                    tally.hidden = !data.shown;
                })
                .catch(() => say('That did not work. Try again in a moment.', 'bad'))
                .finally(() => {
                    buttons.forEach((button) => { button.disabled = false; });
                });
        });
    }());

    /*[ Directory: filter and alphabet ]
    ===========================================================*/
    (function () {
        const root = document.querySelector('.directory');

        if (!root) {
            return;
        }

        const search = root.querySelector('[data-directory-search]');
        const field = root.querySelector('.js-directory-filter');
        const letters = [...root.querySelectorAll('.js-directory-letter')];
        const groups = [...root.querySelectorAll('.js-directory-group')];
        const items = [...root.querySelectorAll('.js-directory-item')];
        const empty = root.querySelector('.js-directory-empty');

        // The field is rendered hidden and unhidden here, so it never exists
        // for somebody whose browser would leave it inert.
        search.hidden = false;

        // Announces that visibility is this script's job now. Until this class
        // lands, CSS is showing the first group and letting :target swap it --
        // see the #DIRECTORY section.
        root.classList.add('is-scripted');

        // The first letter, or whichever one the address names. A shared link
        // to #letter-p should open on P, not on A.
        const named = decodeURIComponent(location.hash.replace(/^#letter-/, '')).toUpperCase();
        const has = (value) => groups.some((group) => group.dataset.letter === value);

        let letter = has(named) ? named : (groups[0]?.dataset.letter ?? null);

        const apply = () => {
            const term = field.value.trim().toLowerCase();
            let shown = 0;

            items.forEach((item) => {
                // A name or a code: people type "YMQ" as readily as "Montreal",
                // and the code is not on screen to be read.
                const hit = term === ''
                    || item.dataset.name.includes(term)
                    || item.dataset.code.includes(term);

                item.hidden = !hit;

                if (hit) {
                    shown++;
                }
            });

            groups.forEach((group) => {
                const wanted = letter === null || group.dataset.letter === letter;
                // A heading with nothing under it is worse than no heading, so a
                // group goes when its last row does.
                const has = [...group.querySelectorAll('.js-directory-item')]
                    .some((item) => !item.hidden);

                group.hidden = !wanted || !has;
            });

            letters.forEach((link) => {
                link.classList.toggle('is-current', link.dataset.letter === letter);
            });

            // Counted rather than announced as a bare "nothing found": the
            // number is what tells somebody whether to keep typing.
            empty.hidden = shown > 0;
            empty.textContent = shown > 0 ? '' : 'Nothing matches “' + field.value.trim() + '”.';
        };

        field.addEventListener('input', () => {
            // Typing is a fresh question, and it is asked of the whole list:
            // a letter chosen a moment ago would otherwise hide the very thing
            // being searched for. Emptying the field puts the alphabet back in
            // charge rather than leaving 231 rows on screen.
            letter = field.value.trim() === '' ? (groups[0]?.dataset.letter ?? null) : null;
            apply();
        });

        letters.forEach((link) => {
            link.addEventListener('click', function (event) {
                event.preventDefault();
                letter = this.dataset.letter;
                // Choosing a letter answers a different question from the one
                // in the field, so the field stops asking.
                field.value = '';
                apply();
            });
        });

        // Once at the start, or the page would sit on the whole alphabet until
        // somebody touched something. The CSS default is one letter and this is
        // the script agreeing with it rather than undoing it.
        apply();
    }());

    /* The help hub's rail: which card you are looking at.

       Progressive, and deliberately so. Without this the rail is a list of
       anchors that already works -- following one jumps to its card, because
       that is what an href does. What the script adds is the marker saying
       where you are now, which is the one part that needs the scroll position.

       A scroll listener rather than an IntersectionObserver, which is what
       this was first written as. The question here is "which card is under the
       header", and that is answered by comparing every card's top against one
       line -- so the observer's own entries were being thrown away and all of
       them measured again on every callback. An observer used as a bare
       "something moved" ping, with its data discarded, is a harder thing to
       read than the listener it was standing in for.

       The header is sticky on this page, so a card becomes current when it
       reaches the header's underside rather than the top of the viewport;
       otherwise the marker changes one card late, when the heading it names is
       already hidden behind the header. */
    (function () {
        const links = Array.from(document.querySelectorAll('.js-help-rail-link'));

        if (!links.length) {
            return;
        }

        const sections = links
            .map((link) => document.getElementById(link.getAttribute('href').slice(1)))
            .filter(Boolean);

        // Every link or none. A rail half of whose anchors are missing is a
        // template and a controller that have stopped agreeing, and marking
        // the half that resolved would hide that rather than show it.
        if (sections.length !== links.length) {
            return;
        }

        const header = document.getElementById('top');

        const mark = function (id) {
            links.forEach(function (link) {
                // Removed rather than set to "false": aria-current has no false
                // value, and a present attribute reads as current either way.
                if (link.getAttribute('href') === '#' + id) {
                    link.setAttribute('aria-current', 'true');
                } else {
                    link.removeAttribute('aria-current');
                }
            });
        };

        const current = function () {
            const line = header ? header.offsetHeight : 0;

            // The last card that has reached the line, which is the one being
            // read. Before any of them have, the first stays marked: the
            // reader is above the stack looking at the top of it.
            let found = sections[0];

            sections.forEach(function (section) {
                if (section.getBoundingClientRect().top - line <= 1) {
                    found = section;
                }
            });

            return found;
        };

        // Coalesced into a frame. Scroll fires far faster than anything can be
        // painted, and this reads layout, which is the one thing worth not
        // doing per event.
        let queued = false;

        const update = function () {
            if (queued) {
                return;
            }

            queued = true;

            window.requestAnimationFrame(function () {
                queued = false;
                mark(current().id);
            });
        };

        window.addEventListener('scroll', update, {passive: true});
        window.addEventListener('resize', update);

        // Once at the start, so the rail agrees with the page before anything
        // is scrolled.
        mark(current().id);
    }());

    /* The theme toggle.

       The head script has already applied the stored choice before anything was
       painted; this only has to make the button agree with it and write the
       next one.

       One button and two states. There is no "system" to press: with nothing
       stored the media query in main.css decides, and the button simply shows
       which way that came out. Pressing it writes the answer down, which is
       what stops the machine overriding a reader who has said what they want.

       `aria-pressed` is the whole of the visual state as well -- the stylesheet
       reads it to decide which icon is showing, so there is one source for
       "is it dark" rather than a class kept in step with an attribute. */
    (function () {
        var button = document.querySelector('.js-theme');

        if (button === null) {
            return;
        }

        var media = window.matchMedia('(prefers-color-scheme: dark)');

        var stored = function () {
            try {
                var choice = window.localStorage.getItem('tb-theme');

                return choice === 'light' || choice === 'dark' ? choice : null;
            } catch (e) {
                // Blocked site data. The toggle still works for this page, it
                // just cannot remember -- better than not drawing it.
                return null;
            }
        };

        // What the reader is actually looking at: the stored choice where there
        // is one, and the machine's answer where there is not.
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
            // Bootstrap's own palette, kept in step so the two never disagree.
            root.setAttribute('data-bs-theme', next);

            try {
                window.localStorage.setItem('tb-theme', next);
            } catch (e) {}

            show(next);
        });

        // Follow the machine while nothing has been chosen, so the button does
        // not go on offering dark after the laptop has already gone dark.
        media.addEventListener('change', function () {
            if (stored() === null) {
                show(effective());
            }
        });

        show(effective());
    }());

})(jQuery);

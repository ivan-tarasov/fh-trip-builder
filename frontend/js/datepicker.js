/*
 * A calendar, ours.
 *
 * This replaces daterangepicker and, with it, moment -- 92KB raw and 26KB
 * gzipped for a control whose behaviour we had already replaced. What was left
 * of the plugin was a month grid and a date library, and around it sat 289
 * lines working against its own interaction model: its `mousedown` intercepted
 * in the capture phase and stopped, its private `leftCalendar.calendar[r][c]`
 * read to find out what day a cell meant, `updateView()` called by hand because
 * setStartDate and setEndDate do not repaint, and a maxSpan that setEndDate
 * enforces and setStartDate ignores.
 *
 * Two things here are deliberately unlike it:
 *
 *   A day is a Date at UTC midnight, read with getUTC*. UTC has no daylight
 *   saving, so adding n * 86400000 is exactly n days on every date in the year.
 *   Local-time arithmetic is off by an hour twice a year, which is how a picker
 *   ends up a day out for the people who happen to be searching that week.
 *
 *   The grid is built once per visible month and a drag repaints classes only.
 *   The plugin rebuilt its table on every selection, which detached the very
 *   cell a drag had started on -- the bug that made drag-to-select need three
 *   attempts to land.
 */
(function (window, document) {
    'use strict';

    const DAY_MS = 86400000;

    const MONTHS = [
        'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'
    ];

    const MONTHS_SHORT = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    /** Sunday first, the way Date numbers them. The picker rotates by firstDay. */
    const WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    const WEEKDAYS_SHORT = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];

    const utc = (y, m, d) => new Date(Date.UTC(y, m, d));

    const Day = {
        today() {
            const now = new Date();

            return utc(now.getFullYear(), now.getMonth(), now.getDate());
        },

        /** A day from "YYYY-MM-DD", or null when it is not one. */
        parse(value) {
            if (value instanceof Date) {
                return utc(value.getUTCFullYear(), value.getUTCMonth(), value.getUTCDate());
            }

            const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(value ?? ''));

            if (!match) {
                return null;
            }

            const day = utc(Number(match[1]), Number(match[2]) - 1, Number(match[3]));

            // Rejects 31 February rather than rolling it into March, which is
            // the same rule SearchUrl applies on the way in.
            return day.getUTCMonth() === Number(match[2]) - 1 ? day : null;
        },

        iso: (day) => day.toISOString().slice(0, 10),
        add: (day, days) => new Date(day.getTime() + days * DAY_MS),
        diff: (a, b) => Math.round((a.getTime() - b.getTime()) / DAY_MS),
        same: (a, b) => Boolean(a) && Boolean(b) && a.getTime() === b.getTime(),
        min: (a, b) => (a.getTime() <= b.getTime() ? a : b),
        max: (a, b) => (a.getTime() >= b.getTime() ? a : b),
        startOfMonth: (day) => utc(day.getUTCFullYear(), day.getUTCMonth(), 1),

        /** Month arithmetic from a month's first day, so nothing can overflow. */
        addMonths: (day, months) => utc(day.getUTCFullYear(), day.getUTCMonth() + months, 1),

        /** "Oct 15", what the search bar shows. */
        short: (day) => MONTHS_SHORT[day.getUTCMonth()] + ' ' + day.getUTCDate(),

        /** "October 15, 2026", what the rebook dialog shows. */
        full: (day) => MONTHS[day.getUTCMonth()] + ' ' + day.getUTCDate() + ', ' + day.getUTCFullYear(),
        title: (day) => MONTHS[day.getUTCMonth()] + ' ' + day.getUTCFullYear(),
        long: (day) => WEEKDAYS[day.getUTCDay()] + ', ' + day.getUTCDate() + ' '
            + MONTHS[day.getUTCMonth()] + ' ' + day.getUTCFullYear()
    };

    let sequence = 0;

    /**
     * @param {HTMLInputElement} input the field the calendar belongs to
     * @param {Object} options
     */
    function DatePicker(input, options) {
        const settings = Object.assign({
            months: 2,
            firstDay: 1,
            // Days a window may cover, itself included. Infinity is "as far as
            // you like", which is what a departure-to-return range is.
            maxSpan: Infinity,
            // One day rather than a window.
            single: false,
            // Close and commit as soon as the selection is complete. Off means
            // the calendar stays open and Done commits it.
            autoApply: false,
            // Drag across the days to pick a window, and drag either end to
            // move it. Off leaves the two-click range the reference uses for
            // departure-to-return.
            drag: false,
            applyLabel: 'Done',
            parent: document.body,
            min: Day.today(),
            start: null,
            span: 1,
            // Whether this calendar will carry fares. Declared up front rather
            // than inferred when they arrive, so the cells are the right size
            // from the first paint: a grid that grows a line when the prices
            // land moves every day under the pointer.
            showPrices: false,
            // What a seat costs on each day, as {"YYYY-MM-DD": number}. Arrives
            // after the calendar is open -- see setPrices.
            prices: null,
            currency: '$',
            // How many adult fares and how many adult taxes this party costs.
            // Two numbers, not one: a child pays three quarters of the fare but
            // a whole adult's tax, so the halves of a price scale apart and are
            // added after -- which is exactly what Party::apply() does in PHP.
            shares: {fare: 1, tax: 1},
            onApply: null,
            onOpen: null
        }, options || {});

        this.settings = settings;
        this.id = 'datepicker-' + (++sequence);
        this.floor = Day.parse(settings.min) || Day.today();
        this.isOpen = false;
        this.touched = false;
        this.drag = null;
        this.prices = settings.prices;
        this.shares = settings.shares;

        // One calendar, one leg per date field. The reference works this way:
        // whichever field is focused owns the clicks, and the other leg stays on
        // screen dimmed so the trip reads as a whole while either end of it is
        // being changed. A single-leg picker -- the rebook dialog -- is just the
        // list with one entry in it.
        const legs = settings.legs ?? [{name: 'main', input: input, start: settings.start, span: settings.span}];

        this.legs = legs.map((leg) => {
            const start = Day.parse(leg.start);

            return {
                name: leg.name,
                input: leg.input,
                start: start,
                end: start ? Day.add(start, Math.max(0, (leg.span ?? 1) - 1)) : null
            };
        });

        this.leg = this.legs[0];
        this.view = Day.startOfMonth(this.leg.start || this.floor);
        this.focused = this.leg.start || this.floor;

        this.build();
        this.listen();
    }

    /**
     * The active leg's window, reachable as if there were only one.
     *
     * Everything that picks and drags was written against a single range, and
     * it still is: switching legs switches what these two point at rather than
     * asking every one of those places to know which leg it is working on.
     */
    Object.defineProperty(DatePicker.prototype, 'start', {
        get: function () { return this.leg.start; },
        set: function (day) { this.leg.start = day; }
    });

    Object.defineProperty(DatePicker.prototype, 'end', {
        get: function () { return this.leg.end; },
        set: function (day) { this.leg.end = day; }
    });

    /** The field the panel is anchored to: the one being edited. */
    Object.defineProperty(DatePicker.prototype, 'input', {
        get: function () { return this.leg.input; }
    });

    /**
     * The earliest day the active leg may take.
     *
     * A return cannot be taken before the outbound leaves, so the leg after the
     * first starts where the one before it does.
     */
    Object.defineProperty(DatePicker.prototype, 'min', {
        get: function () {
            const index = this.legs.indexOf(this.leg);
            const before = index > 0 ? this.legs[index - 1] : null;

            return before?.start && before.start.getTime() > this.floor.getTime()
                ? before.start
                : this.floor;
        }
    });

    DatePicker.Day = Day;

    DatePicker.prototype.build = function () {
        const root = document.createElement('div');

        // The modifier the drag handles are drawn under: only a window that can
        // actually be pulled wider advertises that it can.
        root.className = 'datepicker'
            + (this.settings.drag ? ' datepicker--drag' : '')
            // Sized for fares whether or not any have arrived yet.
            + (this.settings.showPrices ? ' datepicker--priced' : '');
        root.id = this.id;
        root.hidden = true;
        // A dialog rather than a listbox: it is a grid of days with its own
        // controls, and Escape should close it.
        root.setAttribute('role', 'dialog');
        root.setAttribute('aria-label', 'Choose dates');
        root.setAttribute('aria-modal', 'false');

        const months = document.createElement('div');

        months.className = 'datepicker__months';
        root.appendChild(months);

        this.months = [];

        for (let i = 0; i < this.settings.months; i++) {
            const month = document.createElement('div');
            const head = document.createElement('div');
            const title = document.createElement('h2');
            const grid = document.createElement('table');
            const body = document.createElement('tbody');

            month.className = 'datepicker__month';
            head.className = 'datepicker__head';
            title.className = 'datepicker__title';
            title.id = this.id + '-title-' + i;

            // The arrows sit at the outer edges: back on the first month, on on
            // the last. With one month on screen both live on the same head.
            if (i === 0) {
                head.appendChild(this.navButton('prev', 'Previous month'));
            }

            head.appendChild(title);

            if (i === this.settings.months - 1) {
                head.appendChild(this.navButton('next', 'Next month'));
            }

            grid.className = 'datepicker__grid';
            // The implicit roles of a table are replaced once it is a grid, so
            // every row and cell says what it is.
            grid.setAttribute('role', 'grid');
            grid.setAttribute('aria-labelledby', title.id);
            grid.appendChild(this.weekdayRow());
            grid.appendChild(body);

            month.append(head, grid);
            months.appendChild(month);
            this.months.push({title: title, body: body});
        }

        if (!this.settings.autoApply) {
            const foot = document.createElement('div');
            const apply = document.createElement('button');

            foot.className = 'datepicker__foot';
            apply.type = 'button';
            apply.className = 'datepicker__apply';

            // Two lines, as the reference has: what the button does, and what
            // the days now chosen would cost. The fare is the cheapest inside
            // the window rather than the one under the first day -- the window
            // is an offer to leave on any of them, so its price is the best of
            // them.
            const label = document.createElement('span');
            const price = document.createElement('span');

            label.className = 'datepicker__apply-label';
            label.textContent = this.settings.applyLabel;
            price.className = 'datepicker__apply-price';
            this.applyLabel = label;

            apply.append(label, price);
            foot.appendChild(apply);
            root.appendChild(foot);
            this.applyButton = apply;
            this.applyPrice = price;
        }

        // What changed, for anyone who cannot see it change.
        const live = document.createElement('p');

        live.className = 'visually-hidden';
        live.setAttribute('aria-live', 'polite');
        root.appendChild(live);
        this.live = live;

        this.root = root;
        this.settings.parent.appendChild(root);

        this.legs.forEach((leg) => {
            leg.input.setAttribute('aria-haspopup', 'dialog');
            leg.input.setAttribute('aria-expanded', 'false');
            leg.input.setAttribute('aria-controls', this.id);
        });

        this.render();
    };

    /**
     * Say which field the calendar is currently working on.
     *
     * Marked on the field rather than only in the calendar, because the panel
     * looks the same either way and the two dates sit side by side -- without
     * this there is nothing on screen saying which of them a click will move.
     *
     * aria-expanded goes with it, on every field rather than the active one:
     * left alone, the field last edited would go on claiming to have the
     * calendar open after the visitor had crossed to the other one.
     */
    DatePicker.prototype.markActive = function () {
        this.legs.forEach((leg) => {
            const on = this.isOpen && leg === this.leg;

            leg.input.setAttribute('aria-expanded', on ? 'true' : 'false');
            leg.input.classList.toggle('is-picking', on);
            leg.input.closest('.searchbar__field')?.classList.toggle('is-picking', on);
        });
    };

    DatePicker.prototype.navButton = function (way, label) {
        const button = document.createElement('button');

        button.type = 'button';
        button.className = 'datepicker__nav datepicker__nav--' + way;
        button.dataset.step = way === 'prev' ? '-1' : '1';
        button.setAttribute('aria-label', label);

        return button;
    };

    DatePicker.prototype.weekdayRow = function () {
        const head = document.createElement('thead');
        const row = document.createElement('tr');

        row.setAttribute('role', 'row');

        for (let i = 0; i < 7; i++) {
            const index = (this.settings.firstDay + i) % 7;
            const cell = document.createElement('th');
            const abbr = document.createElement('abbr');

            cell.setAttribute('role', 'columnheader');
            // The short name is an abbreviation of the real one, so a screen
            // reader says "Monday" rather than spelling out "Mo".
            abbr.title = WEEKDAYS[index];
            abbr.textContent = WEEKDAYS_SHORT[index];
            cell.appendChild(abbr);

            // Saturday and Sunday, greyed. Most of what makes a calendar
            // scannable at a glance.
            if (index === 0 || index === 6) {
                cell.className = 'is-weekend';
            }

            row.appendChild(cell);
        }

        head.appendChild(row);

        return head;
    };

    /**
     * Draw the visible months.
     *
     * Called when the view moves, never during a drag: a drag repaints classes
     * and leaves the cells where they are.
     */
    DatePicker.prototype.render = function () {
        // A list, not a map keyed by date. Two months side by side share days:
        // November 2026 begins on a Sunday, so its grid opens with 26 to 31
        // October -- the same six days October's own grid ends with. Keyed by
        // date, the second copy replaced the first, and everything that follows
        // -- the fare, the selection, the focus ring -- was written to whichever
        // grid happened to register last.
        this.cells = [];

        this.months.forEach((month, index) => {
            const first = Day.addMonths(this.view, index);

            month.title.textContent = Day.title(first);
            month.body.textContent = '';

            // Back up to the first day of the week the 1st falls in, so the
            // grid starts on a whole week.
            const lead = (first.getUTCDay() - this.settings.firstDay + 7) % 7;
            let cursor = Day.add(first, -lead);

            for (let week = 0; week < 6; week++) {
                const row = document.createElement('tr');

                row.setAttribute('role', 'row');

                for (let i = 0; i < 7; i++) {
                    row.appendChild(this.cell(cursor, first));
                    cursor = Day.add(cursor, 1);
                }

                month.body.appendChild(row);

                // A month needs six rows only when it starts late and runs
                // long; drawing an empty one leaves a gap under February.
                if (cursor.getUTCMonth() !== first.getUTCMonth() && week >= 4) {
                    break;
                }
            }
        });

        this.paintPrices();
        this.paint();
    };

    /**
     * The fares under the days.
     *
     * Separate from paint(), which runs on every mouse move while a window is
     * being dragged: prices do not change as the pointer does, and rewriting
     * seventy of them per frame to say the same thing is work for nothing.
     */
    /** A stored fare as what the party in the form would pay for it. */
    DatePicker.prototype.forParty = function (price) {
        if (price === undefined || price === null) {
            return undefined;
        }

        return price.base * this.shares.fare + price.tax * this.shares.tax;
    };

    /**
     * Reprice for a different party, without asking the server again.
     *
     * The fares are held as base and tax, so a change of passengers is
     * arithmetic on what is already here rather than another round trip.
     */
    DatePicker.prototype.setShares = function (shares) {
        this.shares = shares;
        this.paintPrices();
        this.paint();
    };

    DatePicker.prototype.paintPrices = function () {
        // The active leg's own fares: the outbound flies from origin to
        // destination and the return flies back, so the two legs are priced on
        // different routes and the cells show whichever is being chosen.
        const prices = this.leg.prices ?? this.prices;

        if (!this.cells) {
            return;
        }

        // Nothing to show yet, so nothing is shown. Cleared rather than left
        // alone: the fares are dropped when the cabin changes, and economy
        // figures sitting under a business search until the new ones arrive
        // would be wrong for as long as that took.
        if (!prices) {
            this.cells.forEach((cell) => {
                const label = cell.querySelector('.datepicker__price');

                if (label) {
                    label.textContent = '';
                }

                cell.classList.remove('is-cheap');
            });

            return;
        }

        const known = Object.values(prices).map((price) => this.forParty(price));
        // A day worth crossing the calendar for. Within a tenth of the cheapest
        // fare on the route rather than only the single lowest, because two
        // days that differ by a pound are the same answer.
        const bar = known.length ? Math.min.apply(null, known) * 1.1 : 0;

        this.cells.forEach((cell) => {
            const label = cell.querySelector('.datepicker__price');

            if (!label) {
                return;
            }

            // Nothing on a day that cannot be chosen. A return calendar starts
            // at the departure, and pricing the days before it offers a fare on
            // a flight this trip cannot take.
            const price = cell.classList.contains('is-disabled')
                ? undefined
                : this.forParty(prices[cell.dataset.day]);

            label.textContent = price === undefined ? '' : this.settings.currency + Math.round(price);
            cell.classList.toggle('is-cheap', price !== undefined && price <= bar);
        });
    };

    /**
     * Hand the calendar its prices, once they arrive.
     *
     * The grid is rebuilt rather than repainted, because a calendar that opened
     * without prices has no elements to put them in.
     */
    DatePicker.prototype.setPrices = function (prices, legName) {
        const leg = legName ? this.legs.find((one) => one.name === legName) : null;

        if (leg) {
            leg.prices = prices;
        } else {
            this.prices = prices;
        }

        // No re-render and no repositioning: the cells were built with room for
        // a fare, so filling them in changes nothing about the size or place of
        // anything.
        this.paintPrices();
        this.paint();
    };

    DatePicker.prototype.cell = function (day, month) {
        const cell = document.createElement('td');
        const key = Day.iso(day);

        cell.setAttribute('role', 'gridcell');
        cell.id = this.id + '-' + key;
        cell.dataset.day = key;
        cell.tabIndex = -1;

        const number = document.createElement('span');

        number.className = 'datepicker__day';
        number.textContent = String(day.getUTCDate());
        cell.appendChild(number);

        if (this.settings.showPrices) {
            const price = document.createElement('span');

            price.className = 'datepicker__price';
            cell.appendChild(price);
        }

        // The full date, because "15" on its own says nothing once focus is
        // moving around a grid.
        cell.setAttribute('aria-label', Day.long(day));

        if (day.getUTCMonth() !== month.getUTCMonth()) {
            cell.classList.add('is-outside');
        }

        if (day.getTime() < this.min.getTime()) {
            cell.classList.add('is-disabled');
            cell.setAttribute('aria-disabled', 'true');
        }

        this.cells.push(cell);

        return cell;
    };

    /** Classes only, so this is safe to call on every mouse move. */
    DatePicker.prototype.paint = function () {
        const today = Day.today();
        // Exactly one cell is tabbable, which is what a roving tabindex means.
        // A day at the seam between the two months is drawn twice, so marking
        // "the focused day" would leave two stops on the same date -- and the
        // second of them greyed out as another month's spare copy.
        const roving = this.cellFor(this.focused);

        const others = this.legs.filter((leg) => leg !== this.leg && leg.start);
        const trip = this.tripSpan();

        this.cells.forEach((cell) => {
            const day = Day.parse(cell.dataset.day);
            const inRange = this.covers(this.leg, day);
            const inOther = others.some((leg) => this.covers(leg, day));

            cell.classList.toggle('is-today', Day.same(day, today));
            cell.classList.toggle('is-start', Day.same(day, this.start));
            cell.classList.toggle('is-end', Day.same(day, this.end));
            cell.classList.toggle('is-in-range', inRange);
            cell.classList.toggle('is-focused', Day.same(day, this.focused));

            // The leg not being edited, dimmed, and the days the trip covers
            // between the two of them. Both are there to show the shape of the
            // trip while one end of it is being changed.
            cell.classList.toggle('is-other', inOther);
            cell.classList.toggle('is-other-start', others.some((leg) => Day.same(day, leg.start)));
            cell.classList.toggle('is-other-end', others.some((leg) => Day.same(day, leg.end)));
            cell.classList.toggle(
                'is-between',
                Boolean(trip) && !inRange && !inOther
                    && day.getTime() > trip.from.getTime() && day.getTime() < trip.to.getTime(),
            );

            cell.setAttribute('aria-selected', inRange ? 'true' : 'false');
            cell.tabIndex = cell === roving ? 0 : -1;
        });

        if (this.applyButton) {
            this.applyButton.disabled = !this.start;
        }

        this.paintApplyPrice();
    };

    /**
     * What the button says, and what the days chosen would cost.
     *
     * The label follows the trip rather than the field: a return set makes it a
     * round trip whichever end is being edited, which is what the reference
     * does. The fare is the cheapest inside each window added together -- a
     * window is an offer to fly on any of its days, so its price is the best of
     * them.
     */
    DatePicker.prototype.paintApplyPrice = function () {
        if (!this.applyPrice) {
            return;
        }

        const set = this.legs.filter((leg) => leg.start);

        if (this.applyLabel && this.settings.legLabels) {
            this.applyLabel.textContent = set.length > 1
                ? this.settings.legLabels.round
                : this.settings.legLabels.one;
        }

        let total = 0;

        for (const leg of set) {
            const best = this.cheapestIn(leg);

            if (best === null) {
                this.applyPrice.textContent = '';

                return;
            }

            total += best;
        }

        this.applyPrice.textContent = set.length === 0
            ? ''
            : 'from ' + this.settings.currency + Math.round(total);
    };

    /** The lowest fare across one leg's window, or null when there is none. */
    DatePicker.prototype.cheapestIn = function (leg) {
        const prices = leg.prices ?? this.prices;

        if (!prices || !leg.start) {
            return null;
        }

        const end = leg.end ?? leg.start;
        let best = null;

        for (let day = leg.start; day.getTime() <= end.getTime(); day = Day.add(day, 1)) {
            const price = this.forParty(prices[Day.iso(day)]);

            if (price !== undefined && (best === null || price < best)) {
                best = price;
            }
        }

        return best;
    };

    /** Whether a leg's window covers a day. */
    DatePicker.prototype.covers = function (leg, day) {
        if (!leg.start) {
            return false;
        }

        const end = leg.end ?? leg.start;

        return day.getTime() >= leg.start.getTime() && day.getTime() <= end.getTime();
    };

    /** The whole trip, first day to last, when there is more than one leg set. */
    DatePicker.prototype.tripSpan = function () {
        const set = this.legs.filter((leg) => leg.start);

        if (set.length < 2) {
            return null;
        }

        return {
            from: set.reduce((a, leg) => Day.min(a, leg.start), set[0].start),
            to: set.reduce((a, leg) => Day.max(a, leg.end ?? leg.start), set[0].end ?? set[0].start)
        };
    };

    DatePicker.prototype.selectable = function (day) {
        return Boolean(day) && day.getTime() >= this.min.getTime();
    };

    /** A day pulled back inside the window the search will actually run. */
    DatePicker.prototype.reachable = function (fixed, day) {
        const reach = this.settings.maxSpan - 1;
        let capped = day;

        if (Number.isFinite(reach)) {
            if (Day.diff(day, fixed) > reach) {
                capped = Day.add(fixed, reach);
            } else if (Day.diff(fixed, day) > reach) {
                capped = Day.add(fixed, -reach);
            }
        }

        return capped.getTime() < this.min.getTime() ? this.min : capped;
    };

    /**
     * Both ends, exactly as given.
     *
     * A null end is a range that has been opened and not yet closed -- the
     * state a departure-to-return sits in between its two clicks -- so it
     * cannot be quietly filled in with the start.
     */
    DatePicker.prototype.setRange = function (start, end) {
        this.start = start;
        this.end = end;
        this.focused = start || this.focused;
        this.enforceOrder();
        this.paint();
    };

    /**
     * Keep the legs in the order they are flown.
     *
     * A return cannot be taken before the outbound leaves, so moving the
     * departure past it takes it along rather than leaving a trip nobody could
     * fly. The window keeps its width -- what moved was when it starts, not how
     * flexible it is.
     */
    DatePicker.prototype.enforceOrder = function () {
        for (let i = 1; i < this.legs.length; i++) {
            const before = this.legs[i - 1];
            const leg = this.legs[i];

            if (!leg.start || !before.start || leg.start.getTime() >= before.start.getTime()) {
                continue;
            }

            const span = Day.diff(leg.end ?? leg.start, leg.start);

            leg.start = before.start;
            leg.end = Day.add(before.start, span);
        }
    };

    /**
     * What a plain click does.
     *
     * In single and window mode a click is one day. In range mode the first
     * click opens a range and the second closes it, which is the departure and
     * return the rebook dialog asks for.
     */
    DatePicker.prototype.select = function (day) {
        if (!this.selectable(day)) {
            return;
        }

        this.touched = true;

        if (this.settings.single) {
            this.setRange(day, day);
            this.commit();

            return;
        }

        if (this.settings.drag || !this.start || this.end) {
            // Window mode, or the start of a fresh range.
            this.setRange(day, this.settings.drag ? day : null);

            if (this.settings.drag && this.settings.autoApply) {
                this.commit();
            }

            return;
        }

        const start = Day.min(this.start, day);
        const end = Day.max(this.start, day);

        this.setRange(start, this.reachable(start, end));
        this.commit();
    };

    DatePicker.prototype.commit = function () {
        if (this.settings.autoApply) {
            this.hide(true);
        }
    };

    DatePicker.prototype.moveView = function (step) {
        this.view = Day.addMonths(this.view, step);
        this.render();
        this.announce(this.months.map(m => m.title.textContent).join(' and '));
    };

    DatePicker.prototype.announce = function (text) {
        this.live.textContent = text;
    };

    /** Move the roving focus, bringing the month with it when it has to. */
    DatePicker.prototype.moveFocus = function (days) {
        const next = Day.add(this.focused, days);

        this.focusOn(next);
    };

    DatePicker.prototype.focusOn = function (day) {
        this.focused = day;

        const last = Day.addMonths(this.view, this.settings.months - 1);
        const beforeView = day.getTime() < this.view.getTime();
        const afterView = day.getTime() >= Day.addMonths(last, 1).getTime();

        if (beforeView || afterView) {
            this.view = Day.startOfMonth(beforeView
                ? day
                : Day.addMonths(Day.startOfMonth(day), -(this.settings.months - 1)));
            this.render();
            this.announce(Day.title(Day.startOfMonth(day)));
        } else {
            this.paint();
        }

        const cell = this.cellFor(day);

        if (cell) {
            cell.focus();
        }
    };

    /**
     * The cell for a day, preferring the month it belongs to.
     *
     * A day at the seam between two months is drawn twice, and the copy in the
     * neighbouring grid is greyed out -- focusing that one would move the ring
     * to a day that reads as unavailable.
     */
    DatePicker.prototype.cellFor = function (day) {
        const key = Day.iso(day);
        const matches = this.cells.filter((cell) => cell.dataset.day === key);

        return matches.find((cell) => !cell.classList.contains('is-outside')) ?? matches[0] ?? null;
    };

    DatePicker.prototype.dayAt = function (node) {
        const cell = node instanceof Element ? node.closest('td[data-day]') : null;

        return cell && this.root.contains(cell) ? Day.parse(cell.dataset.day) : null;
    };

    DatePicker.prototype.listen = function () {
        const root = this.root;

        this.legs.forEach((leg) => {
            leg.input.addEventListener('click', () => this.show(leg));
            leg.input.addEventListener('keydown', (event) => {
                if (event.key === 'ArrowDown' || event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    this.show(leg);
                    this.focusOn(this.start || this.focused);
                } else if (event.key === 'Escape') {
                    this.hide(false);
                }
            });
        });

        root.addEventListener('click', (event) => {
            const nav = event.target.closest('.datepicker__nav');

            if (nav) {
                this.moveView(Number(nav.dataset.step));

                return;
            }

            if (this.applyButton && event.target.closest('.datepicker__apply')) {
                this.hide(true);
            }
        });

        // mousedown, not click: a drag has no click at the end of it, and the
        // press is where a drag begins.
        root.addEventListener('mousedown', (event) => {
            const day = this.dayAt(event.target);

            if (!day || !this.selectable(day)) {
                return;
            }

            // Keeps the drag from selecting the day numbers as text, and the
            // field from losing focus to the cell.
            event.preventDefault();

            if (!this.settings.drag) {
                this.select(day);

                return;
            }

            const wide = this.start && this.end && !Day.same(this.start, this.end);
            let fixed = day;

            // Grabbing one end of the window pivots on the other.
            if (wide && Day.same(day, this.start)) {
                fixed = this.end;
            } else if (wide && Day.same(day, this.end)) {
                fixed = this.start;
            }

            this.drag = {fixed: fixed, held: day, moved: false};
        });

        root.addEventListener('mouseover', (event) => {
            const day = this.dayAt(event.target);

            if (!day) {
                return;
            }

            if (this.drag) {
                if (!this.drag.moved && Day.same(day, this.drag.held)) {
                    return;
                }

                this.drag.moved = true;
                this.touched = true;

                const to = this.reachable(this.drag.fixed, day);

                this.setRange(Day.min(this.drag.fixed, to), Day.max(this.drag.fixed, to));

                return;
            }

            // Two-click range: show what the second click would give.
            if (!this.settings.single && !this.settings.drag && this.start && !this.end) {
                this.previewTo(day);
            }
        });

        document.addEventListener('mouseup', () => {
            if (!this.drag) {
                return;
            }

            const held = this.drag.held;
            const moved = this.drag.moved;

            this.drag = null;

            if (!moved) {
                this.select(held);
            }
        });

        root.addEventListener('keydown', (event) => this.onGridKey(event));

        // Focus can reach a day without going through focusOn -- a Tab into the
        // grid, or anything that calls focus() on a cell. `focused` is what the
        // roving tabindex and Enter both read, so it follows the document
        // rather than only its own moves.
        root.addEventListener('focusin', (event) => {
            const day = this.dayAt(event.target);

            if (day && !Day.same(day, this.focused)) {
                this.focused = day;
                this.paint();
            }
        });

        // Outside, and not on the field that owns it.
        document.addEventListener('mousedown', (event) => {
            const ownField = this.legs.some((leg) => leg.input === event.target);

            if (!this.isOpen || root.contains(event.target) || ownField) {
                return;
            }

            this.hide(true);
        });
    };

    /** The range the second click of a two-click range would make. */
    DatePicker.prototype.previewTo = function (day) {
        const start = Day.min(this.start, day);
        const end = Day.max(this.start, day);

        this.cells.forEach((cell) => {
            const at = Day.parse(cell.dataset.day);
            const inside = at.getTime() >= start.getTime() && at.getTime() <= end.getTime();

            cell.classList.toggle('is-in-range', inside);
            cell.classList.toggle('is-end', Day.same(at, end) && !Day.same(at, this.start));
        });
    };

    DatePicker.prototype.onGridKey = function (event) {
        const steps = {ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7};

        if (!this.dayAt(event.target)) {
            return;
        }

        if (Object.prototype.hasOwnProperty.call(steps, event.key)) {
            event.preventDefault();
            this.moveFocus(steps[event.key]);

            return;
        }

        if (event.key === 'Home' || event.key === 'End') {
            event.preventDefault();

            // The start and end of the focused week.
            const offset = (this.focused.getUTCDay() - this.settings.firstDay + 7) % 7;

            this.focusOn(Day.add(this.focused, event.key === 'Home' ? -offset : 6 - offset));

            return;
        }

        if (event.key === 'PageUp' || event.key === 'PageDown') {
            event.preventDefault();

            const step = event.key === 'PageUp' ? -1 : 1;
            const month = Day.addMonths(Day.startOfMonth(this.focused), step);
            const last = Day.diff(Day.addMonths(month, 1), month);

            this.focusOn(Day.add(month, Math.min(this.focused.getUTCDate(), last) - 1));

            return;
        }

        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            // The day under the key, not the one this thinks is focused: they
            // agree, and the one the visitor is actually on is the truth.
            this.select(this.dayAt(event.target) ?? this.focused);

            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            this.hide(false);
            this.input.focus();
        }
    };

    DatePicker.prototype.show = function (leg) {
        const next = leg ?? this.leg;
        const swapping = this.isOpen && next !== this.leg;

        // Moving between the two fields is not a fresh open: the panel stays
        // where it is and changes which leg the clicks land on.
        if (swapping) {
            this.leg = next;
            this.touched = false;
            this.focused = this.start || this.min;
            // Rebuilt, not repainted: which days are out of reach belongs to
            // the leg, and it is decided as the cells are made. A return cannot
            // be taken before the outbound leaves, so the floor moves with it.
            this.render();
            this.place();
            this.markActive();

            if (this.settings.onOpen) {
                this.settings.onOpen(this);
            }

            return;
        }

        if (this.isOpen) {
            return;
        }

        this.leg = next;

        // Reopening is a fresh decision: a picker opens seeded whether or not
        // the field has a date, so one that is opened and dismissed must close
        // having done nothing.
        this.touched = false;
        this.isOpen = true;
        this.root.hidden = false;
        this.markActive();
        this.view = Day.startOfMonth(this.start || this.min);
        this.focused = this.start || this.min;
        this.render();
        this.place();

        if (this.settings.onOpen) {
            this.settings.onOpen(this);
        }
    };

    DatePicker.prototype.hide = function (commit) {
        if (!this.isOpen) {
            return;
        }

        this.isOpen = false;
        this.root.hidden = true;
        this.markActive();

        if (commit && this.touched && this.start && this.settings.onApply) {
            this.settings.onApply(this.start, this.end || this.start, this);
        }

        if (commit && this.settings.onCommit) {
            this.settings.onCommit(this.legs, this);
        }
    };

    /**
     * Under the field, and inside the window.
     *
     * Measured against whatever the panel is positioned within rather than
     * always against the page: on the search form that is the page, but in the
     * rebook dialog the panel is rendered inside the modal so that it stacks
     * above the backdrop instead of behind it, and there the offsets are the
     * modal's own.
     */
    /**
     * What the panel hangs from: every field it serves, taken together.
     *
     * Not the field being edited. Anchoring to that moved the whole calendar
     * sideways each time the visitor crossed from the departure to the return,
     * which is the opposite of what it should feel like -- one calendar the two
     * fields share, not one that follows the cursor around.
     */
    DatePicker.prototype.anchor = function () {
        const rects = this.legs.map((leg) => leg.input.getBoundingClientRect());

        const left = Math.min(...rects.map((rect) => rect.left));
        const right = Math.max(...rects.map((rect) => rect.right));

        return {
            left: left,
            width: right - left,
            bottom: Math.max(...rects.map((rect) => rect.bottom))
        };
    };

    DatePicker.prototype.place = function () {
        const anchor = this.anchor();
        const root = this.root;
        const host = root.offsetParent;
        const base = host && host !== document.body
            ? host.getBoundingClientRect()
            : {left: -window.scrollX, top: -window.scrollY};

        root.style.top = (anchor.bottom - base.top + 8) + 'px';

        const width = root.offsetWidth;
        const centred = anchor.left + anchor.width / 2 - width / 2;
        const room = document.documentElement.clientWidth - width - 8;

        root.style.left = (Math.max(8, Math.min(centred, room)) - base.left) + 'px';
    };

    window.TripDatePicker = DatePicker;
})(window, document);

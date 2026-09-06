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
            onApply: null,
            onOpen: null
        }, options || {});

        this.input = input;
        this.settings = settings;
        this.id = 'datepicker-' + (++sequence);
        this.min = Day.parse(settings.min) || Day.today();
        this.isOpen = false;
        this.touched = false;
        this.drag = null;
        this.prices = settings.prices;

        const start = Day.parse(settings.start);

        this.start = start;
        this.end = start ? Day.add(start, Math.max(0, settings.span - 1)) : null;
        this.view = Day.startOfMonth(start || this.min);
        this.focused = start || this.min;

        this.build();
        this.listen();
    }

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

        this.input.setAttribute('aria-haspopup', 'dialog');
        this.input.setAttribute('aria-expanded', 'false');
        this.input.setAttribute('aria-controls', this.id);

        this.render();
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
    DatePicker.prototype.paintPrices = function () {
        if (!this.prices || !this.cells) {
            return;
        }

        const known = Object.values(this.prices);
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
                : this.prices[cell.dataset.day];

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
    DatePicker.prototype.setPrices = function (prices) {
        this.prices = prices;

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

        this.cells.forEach((cell) => {
            const day = Day.parse(cell.dataset.day);
            const inRange = this.start && this.end
                && day.getTime() >= this.start.getTime()
                && day.getTime() <= this.end.getTime();

            cell.classList.toggle('is-today', Day.same(day, today));
            cell.classList.toggle('is-start', Day.same(day, this.start));
            cell.classList.toggle('is-end', Day.same(day, this.end));
            cell.classList.toggle('is-in-range', Boolean(inRange));
            cell.classList.toggle('is-focused', Day.same(day, this.focused));
            cell.setAttribute('aria-selected', inRange ? 'true' : 'false');
            cell.tabIndex = Day.same(day, this.focused) ? 0 : -1;
        });

        if (this.applyButton) {
            this.applyButton.disabled = !this.start;
        }

        this.paintApplyPrice();
    };

    /** What the chosen days cost, on the button that accepts them. */
    DatePicker.prototype.paintApplyPrice = function () {
        if (!this.applyPrice) {
            return;
        }

        const best = this.cheapestInRange();

        this.applyPrice.textContent = best === null
            ? ''
            : 'from ' + this.settings.currency + Math.round(best);
    };

    /** The lowest fare across the chosen window, or null when there is none. */
    DatePicker.prototype.cheapestInRange = function () {
        if (!this.prices || !this.start) {
            return null;
        }

        const end = this.end ?? this.start;
        let best = null;

        for (let day = this.start; day.getTime() <= end.getTime(); day = Day.add(day, 1)) {
            const price = this.prices[Day.iso(day)];

            if (price !== undefined && (best === null || price < best)) {
                best = price;
            }
        }

        return best;
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
        this.paint();
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

        this.input.addEventListener('click', () => this.show());
        this.input.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowDown' || event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                this.show();
                this.focusOn(this.start || this.focused);
            } else if (event.key === 'Escape') {
                this.hide(false);
            }
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

        // Outside, and not on the field that owns it.
        document.addEventListener('mousedown', (event) => {
            if (!this.isOpen || root.contains(event.target) || event.target === this.input) {
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
            this.select(this.focused);

            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            this.hide(false);
            this.input.focus();
        }
    };

    DatePicker.prototype.show = function () {
        if (this.isOpen) {
            return;
        }

        // Reopening is a fresh decision: a picker opens seeded whether or not
        // the field has a date, so one that is opened and dismissed must close
        // having done nothing.
        this.touched = false;
        this.isOpen = true;
        this.root.hidden = false;
        this.input.setAttribute('aria-expanded', 'true');
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
        this.input.setAttribute('aria-expanded', 'false');

        if (commit && this.touched && this.start && this.settings.onApply) {
            this.settings.onApply(this.start, this.end || this.start, this);
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
    DatePicker.prototype.place = function () {
        const anchor = this.input.getBoundingClientRect();
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

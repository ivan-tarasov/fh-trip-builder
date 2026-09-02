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

    /*[ Buy tickets AJAX + sweetalert2 ]
    ===========================================================*/
    try {
        $(".empty-link").click(function () {
            Swal.fire({
                icon: 'info',
                title: 'Oops...',
                text: 'This is a placeholder link, and clicking on it does not lead to any effect.'
            });
        });

        $("button[id^=addTrip_]").click(function () {
            // Comma-separated segment ids per direction (an itinerary can have
            // more than one leg). jQuery coerces a single-id value to a Number,
            // so normalise to a string before sending.
            let departIds = String($(this).data('flight-departing-ids') ?? '');
            let returnIds = String($(this).data('flight-returning-ids') ?? '');

            Swal.fire({
                title: 'Add this trip?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Add trip',
                showLoaderOnConfirm: true,
                preConfirm: () => {
                    return fetch('/ajax/add-trip', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                            'X-CSRF-Token': csrfToken()
                        },
                        body: new URLSearchParams({depart_ids: departIds, return_ids: returnIds})
                    })
                        .then(response => {
                            if (!response.ok) {
                                throw new Error(response.statusText)
                            }
                            return response.json()
                        })
                        .catch(error => {
                            Swal.showValidationMessage(
                                `Request failed: ${error}`
                            )
                        });
                },
                allowOutsideClick: () => !Swal.isLoading()
            }).then((result) => {
                if (result.isConfirmed) {
                    if (result.value.status == 'success') {
                        $(this).prop('disabled', true);

                        Swal.fire({
                            title: `${result.value.message}`,
                            icon: 'success',
                            showConfirmButton: false,
                            timer: 2000
                        });
                    } else {
                        Swal.fire({
                            title: `${result.value.status} => ${result.value.message}`,
                            icon: 'error',
                        })
                    }
                }
            })
        });

        $("button[id^=deleteBooking_]").click(function () {
            let bookingID = $(this).data('booking-id');

            Swal.fire({
                title: "You're about to delete this booking. Are you sure?",
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Delete booking',
                showLoaderOnConfirm: true,
                preConfirm: () => {
                    return fetch('/ajax/delete-booking', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                            'X-CSRF-Token': csrfToken()
                        },
                        body: new URLSearchParams({booking_id: bookingID ?? ''})
                    })
                        .then(response => {
                            if (!response.ok) {
                                throw new Error(response.statusText)
                            }
                            return response.json()
                        })
                        .catch(error => {
                            Swal.showValidationMessage(
                                `Request failed: ${error}`
                            )
                        });
                },
                allowOutsideClick: () => !Swal.isLoading()
            }).then((result) => {
                if (result.isConfirmed) {
                    if (result.value.status == 'success') {
                        $(this).prop('disabled', true);

                        Swal.fire({
                            title: `${result.value.message}`,
                            icon: 'success',
                            showConfirmButton: false,
                            timer: 3000
                        });

                        setTimeout(function () {
                            location.reload();
                        }, 1000);
                    } else {
                        Swal.fire({
                            title: `${result.value.status} => ${result.value.message}`,
                            icon: 'error',
                        })
                    }
                }
            })
        });
    } catch (err) {
        console.log(err);
    }

    function scrollToAnchor(aid) {
        let aTag = $("#top");
        $('html,body').animate({scrollTop: aTag.offset().top}, 0);
    }

    $("#linktotop").click(function () {
        scrollToAnchor('top');
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

            const card = button.closest('.saved-item');

            if (card) {
                card.remove();
            }

            const remaining = document.querySelectorAll('.saved-item').length;
            const empty = document.querySelector('.js-saved-empty');

            if (remaining === 0 && empty) {
                empty.classList.remove('d-none');
                const list_ = document.querySelector('.js-saved-list');

                if (list_) {
                    list_.remove();
                }
            }
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
            const still = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

            window.scrollTo({ top: 0, behavior: still ? 'auto' : 'smooth' });

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
                    // The fragment brings its own "show more", so the old one
                    // is replaced rather than updated.
                    holder.insertAdjacentHTML('beforebegin', html);
                    holder.remove();

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
                    // Put the link back the way it was: clicking it navigates,
                    // which is the no-JS behaviour and still gets more results.
                    link.classList.remove('is-loading');

                    if (label) {
                        label.textContent = 'Show more results';
                    }

                    if (spinner) {
                        spinner.hidden = true;
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

                // ion.rangeSlider writes a double as "from;to" over whatever we
                // set, and the filters read "from-to". Take the value from the
                // widget itself, which is the one thing that is never stale.
                if (slider && input.dataset.to !== undefined) {
                    input.value = slider.result.from + '-' + slider.result.to;
                }

                if (input.dataset.to === undefined) {
                    if (parseInt(input.value, 10) >= max) {
                        input.disabled = true;
                    }

                    return;
                }

                const pair = String(input.value).split(/[;-]/);

                if (parseInt(pair[0], 10) <= min && parseInt(pair[1], 10) >= max) {
                    input.disabled = true;
                }
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

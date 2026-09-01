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

    $(function () {
        document.querySelectorAll('[data-toggle="tooltip"]').forEach(function (el) {
            new bootstrap.Tooltip(el);
        });
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
    document.querySelectorAll('.js-like').forEach(function (button) {
        const key = button.dataset.flightKey;

        button.classList.toggle('is-active', savedFlights().indexOf(key) !== -1);

        button.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();

            const list = savedFlights();
            const at = list.indexOf(key);

            if (at === -1) {
                list.unshift(key);
            } else {
                list.splice(at, 1);
            }

            button.classList.toggle('is-active', at === -1);
            storeSavedFlights(list);
        });
    });

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

    document.querySelectorAll('.js-share').forEach(function (button) {
        button.addEventListener('click', function (event) {
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
    });

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

    $('.js-filter-slider').each(function () {
        const input = this;
        const kind = input.dataset.kind;
        const output = document.getElementById(input.dataset.output);
        const min = parseInt(input.dataset.min, 10);
        const max = parseInt(input.dataset.max, 10);
        // Two handles when the server supplied an upper one.
        const isRange = input.dataset.to !== undefined;

        const show = function (from, to) {
            if (!output) {
                return;
            }

            if (!isRange) {
                // At the top of the range nothing is excluded, so say so
                // rather than showing a number that reads like a limit.
                output.textContent = from >= max ? 'Any' : sliderLabel(kind, from);

                return;
            }

            output.textContent = from <= min && to >= max
                ? 'Any'
                : sliderLabel(kind, from) + ' \u2013 ' + sliderLabel(kind, to);
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

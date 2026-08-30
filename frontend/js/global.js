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

    /*[ Whole-card selection ]
    ===========================================================*/
    // Clicking anywhere on a card selects it, except on the controls that do
    // their own thing. Done here rather than with a stretched-link overlay,
    // which would block hovering the timeline, the logos and the price note.
    document.querySelectorAll('.flight-card').forEach(function (card) {
        card.addEventListener('click', function (event) {
            if (event.target.closest('a, button, input, label, [data-bs-toggle]')) {
                return;
            }

            const select = card.querySelector('.card-select');

            if (select) {
                select.click();
            }
        });
    });

    /*[ Saved flights + share link ]
    ===========================================================*/
    const SAVED_KEY = 'tb_saved_flights';

    function savedFlights() {
        try {
            return JSON.parse(localStorage.getItem(SAVED_KEY) || '[]');
        } catch (e) {
            return [];
        }
    }

    function storeSavedFlights(list) {
        try {
            localStorage.setItem(SAVED_KEY, JSON.stringify(list));
        } catch (e) {
            // Private browsing or a full quota: the heart still toggles for
            // this page view, it just will not be remembered.
        }
    }

    // Saved flights live in this browser only — there is no account to sync to.
    document.querySelectorAll('.js-like').forEach(function (button) {
        const key = button.dataset.flightKey;

        button.classList.toggle('is-active', savedFlights().indexOf(key) !== -1);

        button.addEventListener('click', function (event) {
            // Keep the click off the card, which would navigate away.
            event.preventDefault();
            event.stopPropagation();

            const list = savedFlights();
            const at = list.indexOf(key);

            if (at === -1) {
                list.push(key);
            } else {
                list.splice(at, 1);
            }

            button.classList.toggle('is-active', at === -1);
            storeSavedFlights(list);
        });
    });

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

    $("#airlinesSelectAll").click(function () {
        $('input:checkbox[name="airlines[]"]').attr('checked', 'checked');
    });

    $("#airlinesSelectClear").click(function () {
        $('input:checkbox[name="airlines[]"]').removeAttr('checked');
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

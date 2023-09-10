(function ($) {
    'use strict';

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
            let tripID = [$(this).data('flight-departing-id'), $(this).data('flight-returning-id')];

            Swal.fire({
                title: 'Add this trip?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Add trip',
                showLoaderOnConfirm: true,
                preConfirm: () => {
                    return fetch(`/ajax/add-trip?depart_id=${tripID[0]}&return_id=${tripID[1]}`)
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
                    return fetch(`/ajax/delete-booking?booking_id=${bookingID}`)
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
        $('[data-toggle="tooltip"]').tooltip();
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

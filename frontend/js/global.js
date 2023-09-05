(function ($) {
    'use strict';

    try {
        const singleDatePickers = $('.js-single-datepicker');

        singleDatePickers.each(function () {
            const $this = $(this);
            const elementID = $this.attr('id');
            const dropId = $this.data('drop');
            const today = moment().format('MMMM D, YYYY');
            let query = $this.data('value');

            if (!query) {
                query = moment($("#round_departing_date_value").data('value')).format('MMMM D, YYYY');
            } else {
                query = moment(query).format('MMMM D, YYYY');
            }

            const commonConfig = {
                autoApply: true,
                showCustomRangeLabel: false,
                autoUpdateInput: true,
                startDate: query,
                minDate: today,
                opens: "center",
                drops: "auto",
                locale: {
                    format: 'MMMM D, YYYY',
                    separator: " – ",
                    firstDay: 1
                }
            };

            commonConfig.singleDatePicker = (elementID == 'oneway_depart_date');

            // Initialize the date range picker
            $this.daterangepicker(commonConfig);
        });

        $(singleDatePickers).on('apply.daterangepicker', function (ev, picker) {
            $("#depart_date_value").val(picker.startDate.format('YYYY-MM-DD'));

            if (ev.target.id == 'roundtrip_dates') {
                $("#return_date_value").val(picker.endDate.format('YYYY-MM-DD'));
            }
        });

        let departDate_roundtrip = '';
        let departDate_oneway = '';

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

    /*[ Select 2 Config ]
    ===========================================================*/
    try {
        var selectSimple = $('.js-select-simple');

        selectSimple.each(function () {
            var that = $(this);
            var selectBox = that.find('select');
            var selectDropdown = that.find('.select-dropdown');
            selectBox.select2({
                dropdownParent: selectDropdown
            });
        });

    } catch (err) {
        console.log(err);
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
            var tripID = [$(this).data('flight-departing-id'), $(this).data('flight-returning-id')];

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
    } catch (err) {
        console.log(err);
    }


    function scrollToAnchor(aid) {
        var aTag = $("#top");
        $('html,body').animate({scrollTop: aTag.offset().top}, 'slow');
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

})(jQuery);

document.addEventListener('DOMContentLoaded', () => {
    const departingAirportInput = $('#departing_airport');
    const arrivalAirportInput = $('#arrival_airport');

    departingAirportInput.autocomplete();
    arrivalAirportInput.autocomplete();
}, false);

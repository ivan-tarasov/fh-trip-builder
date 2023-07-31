(function ($) {
    'use strict';

    try {
        var singleDate = $('.js-single-datepicker');

        singleDate.each(function () {
            var that = $(this);
            var dropdownParent = '#dropdown-datepicker' + that.data('drop');
                        var today = moment().format('MMMM D, YYYY');
            var query = that.data('value');

            if (query)
                query = moment(query).format('MMMM D, YYYY');
            else
                query = moment($("#round_departing_date_value").data('value')).format('MMMM D, YYYY');

            that.daterangepicker({
                "minDate": today,
                "startDate": query,
                "singleDatePicker": true,
                "autoUpdateInput": true,
                "parentEl": dropdownParent,
                "opens": 'center',
                "locale": {
                    "format": 'MMMM D, YYYY'
                }
            });

            $("#"+this.id+"_value")
                .attr('data-value', query)
                .val(moment(query).format('YYMMDD'));
        });

        $(singleDate).on('apply.daterangepicker', function(ev, picker) {
            $("#"+this.id+"_value")
                .val(picker.startDate.format('YYMMDD'))
                .attr('data-value', picker.startDate.format('YYYY-MM-DD'));
            });

    } catch(er) {console.log(er);}
        
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
        $(".empty-link").click(function() {
            Swal.fire({
                icon: 'info',
                title: 'Oops...',
                text: 'This is a placeholder link, and clicking on it does not lead to any effect.'
            });
        });

        $("button[id^=addTrip_]").click(function() {
            var tripID = [$(this).data('flight-departing-id'),$(this).data('flight-returning-id')];

            Swal.fire({
                title: 'Add this trip?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Add trip',
                showLoaderOnConfirm: true,
                preConfirm: () => {
                    return fetch(`/add-trip.php?departing_id=${tripID[0]}&returning_id=${tripID[1]}`)
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
        $('html,body').animate({scrollTop: aTag.offset().top},'slow');
    }

    $("#linktotop").click(function() {
        scrollToAnchor('top');
    });

    $(function () {
        $('[data-toggle="tooltip"]').tooltip();
    });

    $('.btn-submit').click(function() {
        var form_id = $(this).data('form');
        var values = [];

        $(`#${form_id} input[type="hidden"]:not('.query_hash')`).sort(function(a, b) {
            return $(a).data('order') - $(b).data('order');
        }).each(function() {
            values.push($(this).val());
        });

        var combinedValue = values.join('');

        $(`#${form_id} .query_hash`).val(combinedValue);
    });

    $("#airlinesSelectAll").click(function() {
        $('input:checkbox[name="filterAirlines[]"]').attr('checked','checked');
    });

    $("#airlinesSelectClear").click(function() {
        $('input:checkbox[name="filterAirlines[]"]').removeAttr('checked');
    });

})(jQuery);

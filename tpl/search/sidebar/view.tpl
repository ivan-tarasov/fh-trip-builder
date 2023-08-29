<!-- SIDEBAR -->
{{ sb_filter_timerange }}
<div class="col-xl-3 col-md-12 order-md-1 mb-4">

    <!-- Get fare alerts BUTTON -->
    <div class="d-grid gap-2">
        <button type="button" class="btn btn-outline-primary btn-lg btn-block empty-link mb-4">
            <i class="fas pr-1 fa-bell"></i>
            Get fare alerts
        </button>
    </div>
    <!-- End / Get fare alerts BUTTON -->

    <!--form method="post" id="searchForm" -->
        <div class="card mb-5">
            <div class="card-body">
                <div class="accordion bg-white" id="sidebarAccordion">
                    <!-- 1 - Sort -->
                    <div class="accordion-item border-0">
                        <h2 class="accordion-header" id="sidebar_1_sort">
                            <button type="button" class="accordion-button border-bottom bg-white" data-bs-toggle="collapse" data-bs-target="#sidebar_1_sort_content" aria-expanded="true" aria-controls="sidebar_1_sort_content">
                                <i class="fas fa-lg fa-sort-alpha-down pe-2"></i>
                                Sort
                            </button>
                        </h2>
                        <div id="sidebar_1_sort_content" class="accordion-collapse collapse show" aria-labelledby="sidebar_1_sort">
                            <div class="accordion-body px-1 py-3">
                                <form method="post" action="{{ form_url }}" id="form_sort">
                                    {{ sb_filter_sort }}
                                    <!-- {{ sb_update_button }} -->
                                </form>
                            </div>
                        </div>
                    </div>
                    <!-- 2 - Time ranges -->
                    <div class="accordion-item border-0 %SHOW_TRIP_OPTIONS%">
                        <h2 class="accordion-header" id="sidebar_2_range">
                            <button type="button" class="accordion-button border-bottom bg-white collapsed" data-bs-toggle="collapse" data-bs-target="#sidebar_2_range_content" aria-expanded="false" aria-controls="sidebar_2_range_content">
                                <i class="fas fa-lg fa-clock pe-2"></i>
                                Time preference
                            </button>
                        </h2>
                        <div id="sidebar_2_range_content" class="accordion-collapse collapse" aria-labelledby="sidebar_2_range">
                            <div class="accordion-body">
                                <form method="post">
                                    <span class="d-block pb-2">Flight to <strong>{{ arrive_city }}</strong></span>
                                    <input type="text" class="js-range-slider" id="time_range_departure" name="time_range[depart]" value="" />
                                    <span class="%HIDE_RETURN_RANGE%">
                                        <hr class="mt-3 mb-3" />
                                        <span class="d-block pb-2">Flight to <strong>{{ depart_city }}</strong></span>
                                        <input type="text" class="js-range-slider" id="time_range_returning" name="time_range[return]" value="" />
                                    </span>
                                    {{ sb_update_button }}
                                </form>
                            </div>
                        </div>
                    </div>
                    <!-- 3 - Stops -->
                    <!--div class="accordion-item border-0">
                        <h2 class="accordion-header" id="sidebar_3_stops">
                            <button type="button"
                                    class="accordion-button border-bottom bg-white collapsed"
                                    data-bs-toggle="collapse"
                                    data-bs-target="#sidebar_3_stops_content"
                                    aria-expanded="false"
                                    aria-controls="sidebar_3_stops_content"
                            >
                                <i class="fas fa-lg fa-map-marker-alt pe-2"></i>
                                Stops
                            </button>
                        </h2>
                        <div id="sidebar_3_stops_content" class="accordion-collapse collapse"
                             aria-labelledby="sidebar_3_stops">
                            <div class="accordion-body">
                                <div class="form-check">
                                    <input type="radio" name="stops" id="stop1" class="form-check-input mt-0" checked/>
                                    <label class="form-check-label" for="stop1">1 stop</label>
                                </div>
                                <div class="form-check">
                                    <input type="radio" name="stops" id="stop2" class="form-check-input mt-0"/>
                                    <label class="form-check-label" for="stop2">2 stops</label>
                                </div>
                                <div class="form-check">
                                    <input type="radio" name="stops" id="stop3" class="form-check-input mt-0"/>
                                    <label class="form-check-label" for="stop3">3 stops</label>
                                </div>
                                <div class="form-check">
                                    <input type="radio" name="stops" id="stop4" class="form-check-input mt-0"/>
                                    <label class="form-check-label" for="stop4">4 stops</label>
                                </div>
                                {{ sb_update_button }}
                            </div>
                        </div>
                    </div-->
                    <!-- 4 - Comfort -->
                    <div class="accordion-item border-0">
                        <h2 class="accordion-header" id="sidebar_4_comfort">
                            <button type="button" class="accordion-button border-bottom bg-white collapsed" data-bs-toggle="collapse" data-bs-target="#sidebar_4_comfort_content" aria-expanded="false" aria-controls="sidebar_4_comfort_content">
                                <i class="far fa-lg fa-smile pe-2"></i>
                                Comfort
                            </button>
                        </h2>
                        <div id="sidebar_4_comfort_content" class="accordion-collapse collapse" aria-labelledby="sidebar_4_comfort">
                            <div class="accordion-body">
                                <form method="post">
                                    <div class="form-check form-switch ms-2">
                                        <input class="form-check-input mt-0" type="checkbox" role="switch" value="" id="noVisaStops">
                                        <label class="form-check-label" for="noVisaStops">
                                            No stops with visa
                                            <span data-toggle="tooltip" data-placement="right" title="We will hide tickets where a separate visa is needed. But this does not mean that we have found all the cases. You will have to check the visa requirements yourself.">
                                                <i class="fas ps-1 fa-info-circle"></i>
                                            </span>
                                        </label>
                                    </div>
                                    <div class="form-check form-switch ms-2">
                                        <input class="form-check-input mt-0" type="checkbox" role="switch" value="" id="noAirportChange">
                                        <label class="form-check-label" for="noAirportChange">No airport change</label>
                                    </div>
                                    <div class="form-check form-switch ms-2">
                                        <input class="form-check-input mt-0" type="checkbox" role="switch" value="" id="noNightTransfers">
                                        <label class="form-check-label" for="noNightTransfers">No night transfers</label>
                                    </div>
                                    {{ sb_update_button }}
                                </form>
                            </div>
                        </div>
                    </div>
                    <!-- 5 - Flight times -->
                    <!-- 6 - Dates -->
                    <!-- 7 - Airports -->
                    <!-- 8 - Baggage -->
                    <div class="accordion-item border-0">
                        <h2 class="accordion-header" id="sidebar_8_baggage">
                            <button type="button" class="accordion-button border-bottom bg-white collapsed" data-bs-toggle="collapse" data-bs-target="#sidebar_8_baggage_content" aria-expanded="false" aria-controls="sidebar_8_baggage_content">
                                <i class="fas fa-lg fa-suitcase pe-2"></i>
                                Baggage
                            </button>
                        </h2>
                        <div id="sidebar_8_baggage_content" class="accordion-collapse collapse" aria-labelledby="sidebar_8_baggage">
                            <div class="accordion-body">
                                <form method="post">
                                    <div class="form-check form-switch ms-2">
                                        <input class="form-check-input mt-0" type="checkbox" role="switch" value="" id="baggageIncluded">
                                        <label class="form-check-label" for="baggageIncluded">Baggage included</label>
                                    </div>
                                    {{ sb_update_button }}
                                </form>
                            </div>
                        </div>
                    </div>
                    <!-- 9 - Airlines -->
                    <div class="accordion-item border-0">
                        <h2 class="accordion-header" id="sidebar_9_airlines">
                            <button type="button" class="accordion-button bg-white collapsed" data-bs-toggle="collapse" data-bs-target="#sidebar_9_airlines_content" aria-expanded="false" aria-controls="sidebar_9_airlines_content">
                                <i class="fas fa-lg fa-plane pe-2"></i>
                                Airlines
                            </button>
                        </h2>
                        <div id="sidebar_9_airlines_content" class="accordion-collapse collapse"
                             aria-labelledby="sidebar_9_airlines">
                            <div class="accordion-body">
                                <form method="post">
                                    <button type="button" class="btn btn-sm btn-link d-block ps-0" id="airlinesSelectAll">
                                        <i class="fas fa-check-double pe-2"></i>Select all airlines
                                    </button>
                                    <button type="button" class="btn btn-sm btn-link d-block ps-0" id="airlinesSelectClear">
                                        <i class="far fa-minus-square pe-2"></i>Clear selection
                                    </button>
                                    <hr class="mt-2 mb-3"/>
                                    {{ sb_filter_airlines }}
                                    {{ sb_update_button }}
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- {{ sb_update_button }} -->
    <!--/form-->
</div>
<!-- End / SIDEBAR -->

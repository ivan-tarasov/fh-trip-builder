<style>
    .bg-img {
        background-image: url("{{ bg_image_url }}");
        background-size: cover;
        background-position: center;
    }
</style>

<main role="main" class="flex-shrink-0 py-5">
    <div class="container">
        <div class="row">
            <div class="col-5">
                <div class="card card-4">
                    <div class="card-body">
                        <!-- Tab links panel -->
                        <ul class="nav tab-list" id="myTab" role="tablist">
                            <li class="tab-list__item">
                                <button class="nav-link tab-list__link active" id="tab_roundtrip" data-bs-toggle="tab" data-bs-target="#roundtrip" type="button" role="tab" aria-controls="home" aria-selected="true">
                                    <i class="fas fa-exchange"></i>
                                    round-trip
                                </button>
                            </li>
                            <li class="tab-list__item">
                                <button class="nav-link tab-list__link" id="tab_oneway" data-bs-toggle="tab" data-bs-target="#oneway" type="button" role="tab" aria-controls="home" aria-selected="false">
                                    <i class="fas fa-plane-arrival"></i>
                                    one-way trip
                                </button>
                            </li>
                        </ul>
                        <!-- End / Tab links panel -->
                        <!-- Tab content -->
                        <div class="tab-content">
                            <!-- ROUND TRIP -->
                            <div class="tab-pane fade show active" id="roundtrip" role="tabpanel" aria-labelledby="home-tab" tabindex="0">
                                <form method="get" action="{{ form_action }}" id="searchFormRound">
                                    <div class="input-group input-group-big">
                                        <label class="label">From:</label>
                                        <input type="text" id="round_departing_airport" class="input--style-1" data-filter="{{ api_airports_autofill }}#QUERY#" placeholder="Start typing..." required="required" autocomplete="off" />
                                        <input type="hidden" id="round_departing_airport_value" name="{{ input_from }}" value="" data-order="1"/>
                                        <i class="zmdi zmdi-search input-group-symbol"></i>
                                        <script>
                                            document.addEventListener('DOMContentLoaded', e => {
                                                $('#round_departing_airport').autocomplete()
                                            }, false);
                                        </script>
                                    </div>
                                    <div class="input-group input-group-big">
                                        <label for="round_arrival_airport" class="label">To:</label>
                                        <input type="text" id="round_arrival_airport" class="input--style-1" data-filter="{{ api_airports_autofill }}#QUERY#" placeholder="Start typing..." required="required" autocomplete="off" />
                                        <input type="hidden" id="round_arrival_airport_value" name="{{ input_to }}" value="" data-order="3"/>
                                        <i class="zmdi zmdi-search input-group-symbol"></i>
                                        <script>
                                            document.addEventListener('DOMContentLoaded', e => {
                                              $('#round_arrival_airport').autocomplete()
                                            }, false);
                                        </script>
                                    </div>
                                    <div class="row row-space">
                                        <div class="col-lg-6 col-sm-12">
                                            <div class="input-group input-group-big">
                                                <label for="round_departing_date" class="label">Depart:</label>
                                                <input type="text" id="round_departing_date" class="input--style-1 js-single-datepicker" placeholder="yyyy-mm-dd" data-drop="1" autocomplete="off" required="required" />
                                                <input type="hidden" id="round_departing_date_value" name="{{ input_from_date }}" value="" data-order="2"/>
                                                <div class="dropdown-datepicker" id="dropdown-datepicker1"></div>
                                            </div>
                                        </div>
                                        <div class="col-lg-6 col-sm-12">
                                            <div class="input-group input-group-big">
                                                <label for="round_returning_date" class="label">Return:</label>
                                                <input type="text" id="round_returning_date" class="input--style-1 js-single-datepicker" placeholder="yyyy-mm-dd" data-drop="2" autocomplete="off" required="required" />
                                                <input type="hidden" id="round_returning_date_value" name="{{ input_to_date }}" value="" data-order="4"/>
                                                <div class="dropdown-datepicker" id="dropdown-datepicker2"></div>
                                            </div>
                                        </div>
                                    </div>
                                    <input type="hidden" name="{{ input_triptype }}" value="{{ input_triptype_roundtrip }}" />
                                    <div class="radio-row">
                                        <label class="radio-container m-r-45">
                                            Economy
                                            <input type="radio" name="class" value="economy" checked="checked" />
                                            <span class="radio-checkmark"></span>
                                        </label>
                                        <label class="radio-container">
                                            Business
                                            <input type="radio" name="class" value="busines" />
                                            <span class="radio-checkmark"></span>
                                        </label>
                                    </div>
                                    <button class="btn-submit m-t-15" type="submit">search</button>
                                </form>
                            </div>
                            <!-- ONE-WAY -->
                            <div class="tab-pane fade" id="oneway" role="tabpanel" aria-labelledby="home-tab" tabindex="0">
                                <form method="get" action="{{ form_action }}" id="searchFormOneway">
                                    <div class="input-group input-group-big">
                                        <label for="oneway_departing_airport" class="label">From:</label>
                                        <input type="text" id="oneway_departing_airport" class="input--style-1" data-filter="{{ api_airports_autofill }}#QUERY#" placeholder="Start typing..." required="required" autocomplete="off" />
                                        <input type="hidden" id="oneway_departing_airport_value" name="{{ input_from }}" value="" data-order="1"/>
                                        <i class="zmdi zmdi-search input-group-symbol"></i>
                                        <script>
                                            document.addEventListener('DOMContentLoaded', e => {
                                              $('#oneway_departing_airport').autocomplete()
                                            }, false);
                                        </script>
                                    </div>
                                    <div class="input-group input-group-big">
                                        <label for="oneway_arrival_airport" class="label">To:</label>
                                        <input type="text" id="oneway_arrival_airport" class="input--style-1" data-filter="{{ api_airports_autofill }}#QUERY#" placeholder="Start typing..." required="required" autocomplete="off" />
                                        <input type="hidden" id="oneway_arrival_airport_value" name="{{ input_to }}" value="" data-order="3"/>
                                        <i class="zmdi zmdi-search input-group-symbol"></i>
                                        <script>
                                            document.addEventListener('DOMContentLoaded', e => {
                                              $('#oneway_arrival_airport').autocomplete()
                                            }, false);
                                        </script>
                                    </div>
                                    <div class="input-group input-group-big">
                                        <label for="oneway_departing_date" class="label">Depart:</label>
                                        <input type="text" id="oneway_departing_date" class="input--style-1 js-single-datepicker" placeholder="yyyy-mm-dd" data-drop="3" autocomplete="off" required="required" />
                                        <input type="hidden" id="oneway_departing_date_value" name="{{ input_from_date }}" value="" data-order="2" />
                                        <div class="dropdown-datepicker" id="dropdown-datepicker3"></div>
                                    </div>
                                    <input type="hidden" name="{{ input_triptype }}" value="{{ input_triptype_oneway }}" />
                                    <div class="radio-row">
                                        <label class="radio-container m-r-45">
                                            Economy
                                            <input type="radio" checked="checked" name="class" value="economy">
                                            <span class="radio-checkmark"></span>
                                        </label>
                                        <label class="radio-container m-r-45">
                                            First Class
                                            <input type="radio" name="class">
                                            <span class="radio-checkmark"></span>
                                        </label>
                                        <label class="radio-container">
                                            Business
                                            <input type="radio" name="class">
                                            <span class="radio-checkmark"></span>
                                        </label>
                                    </div>
                                    <button class="btn-submit m-t-15" type="submit" data-form="searchFormOneway">search</button>
                                </form>
                            </div>
                        </div>
                        <!-- End / Tab content -->
                    </div>
                </div>
            </div>
            <div class="col-4 mt-4">
                <div class="card card-4">
                    <div class="card-body">
                        <h3 class="text-white pb-1">Top searches</h3>
                        <div class="list-group pt-3">
                            {{ top_searches }}
                        </div>
                    </div>
                </div>

                <div class="card card-4 mt-2">
                    <div class="card-body">
                        <h3 class="text-white pb-1">Top airlines</h3>
                        {{ top_airlines }}
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

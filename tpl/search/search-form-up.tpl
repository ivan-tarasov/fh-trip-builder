<div class="bg-black">
    <div class="wrapper">
        <div class="card card-4 container">
            <div class="">

                <!-- Tab links panel -->
                <ul class="nav tab-list" id="myTab" role="tablist">
                    <li class="tab-list__item">
                        <button class="nav-link tab-list__link{{ tab_rt_button }}"
                                id="tab_roundtrip"
                                data-bs-toggle="tab"
                                data-bs-target="#roundtrip"
                                type="button"
                                role="tab"
                                aria-controls="home"
                                aria-selected="{{ tab_rt_aria }}"
                        >
                            <i class="fas fa-exchange"></i>
                            round-trip
                        </button>
                    </li>
                    <li class="tab-list__item">
                        <button class="nav-link tab-list__link{{ tab_ow_button }}"
                                id="tab_oneway"
                                data-bs-toggle="tab"
                                data-bs-target="#oneway"
                                type="button"
                                role="tab"
                                aria-controls="home"
                                aria-selected="{{ tab_ow_aria }}"
                        >
                            <i class="fas fa-plane-arrival"></i>
                            one-way trip
                        </button>
                    </li>
                </ul>
                <!-- End / Tab links panel -->

                <div class="tab-content">
                    <!-- ROUND TRIP -->
                    <div class="tab-pane fade{{ tab_rt_div }}" id="roundtrip" role="tabpanel"
                         aria-labelledby="home-tab">
                        <form method="get" action="{{ search_page_url }}" id="searchFormRound">
                            <div class="row row-space">

                                <div class="col-xl-6">
                                    <div class="input-group input-group-big">
                                        <label class="label">origin:</label>
                                        <input type="text"
                                               value="{{ depart_city }}"
                                               id="round_departing_airport"
                                               class="input--style-1"
                                               data-filter="{{ airports_autofill }}#QUERY#"
                                               placeholder="City or airport"
                                               required="required"
                                               autocomplete="off"
                                        />
                                        <input type="hidden"
                                               id="round_departing_airport_value"
                                               name="{{ input_from }}"
                                               value="{{ depart_code }}"
                                               data-order="1"
                                        />
                                        <i class="zmdi zmdi-search input-group-symbol"></i>
                                        <script>
                                            document.addEventListener('DOMContentLoaded', e => {
                                              $('#round_departing_airport').autocomplete()
                                            }, false);
                                        </script>
                                    </div>
                                </div>

                                <div class="col-xl-6">
                                    <div class="input-group input-group-big">
                                        <label class="label">destination:</label>
                                        <input type="text"
                                               value="{{ arrive_city }}"
                                               id="round_arrival_airport"
                                               class="input--style-1"
                                               data-filter="{{ airports_autofill }}#QUERY#"
                                               placeholder="City or airport"
                                               required="required"
                                               autocomplete="off"/>
                                        <input type="hidden"
                                               id="round_arrival_airport_value"
                                               name="{{ input_to }}"
                                               value="{{ arrive_code }}"
                                               data-order="3"/>
                                        <i class="zmdi zmdi-search input-group-symbol"></i>
                                        <script>
                                            document.addEventListener('DOMContentLoaded', e => {
                                              $('#round_arrival_airport').autocomplete()
                                            }, false);
                                        </script>
                                    </div>
                                </div>

                                <div class="col-6">
                                    <div class="input-group input-group-big">
                                        <label class="label">Departing date:</label>
                                        <input class="input--style-1 js-single-datepicker" type="text"
                                               id="round_departing_date" data-value="{{ depart_date }}"
                                               placeholder="yyyy-mm-dd" data-drop="1" autocomplete="off"
                                               required="required"/>
                                        <input type="hidden" id="round_departing_date_value" name="{{ input_from_date }}" value="" data-value=""
                                               data-order="2"/>
                                        <div class="dropdown-datepicker" id="dropdown-datepicker1"></div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="input-group input-group-big">
                                        <label class="label">Returning date:</label>
                                        <input class="input--style-1 js-single-datepicker" type="text"
                                               id="round_returning_date" data-value="{{ return_date }}"
                                               placeholder="yyyy-mm-dd" data-drop="2" autocomplete="off"
                                               required="required"/>
                                        <input type="hidden" id="round_returning_date_value" name="{{ input_to_date }}" value="" data-value=""
                                               data-order="4"/>
                                        <div class="dropdown-datepicker" id="dropdown-datepicker2"></div>
                                    </div>
                                </div>
                                <input type="hidden" name="{{ input_triptype }}" value="{{ input_triptype_roundtrip }}" />
                            </div>

                            <div class="row radio-row">
                                <div class="col-xl-4 pt-1">
                                    <label class="radio-container m-r-45">
                                        Economy
                                        <input type="radio" checked="checked" name="class" value="economy">
                                        <span class="radio-checkmark"></span>
                                    </label>
                                    <label class="radio-container">
                                        Business
                                        <input type="radio" name="class">
                                        <span class="radio-checkmark"></span>
                                    </label>
                                </div>
                                <div class="col-xl-8">
                                    <button class="btn-submit btn-submit-small" type="submit"
                                            data-form="searchFormRound">search
                                    </button>
                                </div>
                            </div>

                        </form>
                    </div>

                    <!-- ONE-WAY -->
                    <div class="tab-pane fade{{ tab_ow_div }}" id="oneway" role="tabpanel" aria-labelledby="home-tab">
                        <form method="get" action="{{ search_page_url }}" id="searchFormOneway">
                            <input type="hidden" name="hash" id="oneway_query_hash" class="query_hash"/>

                            <div class="row row-space">
                                <div class="col-xl-4">
                                    <div class="input-group input-group-big">
                                        <label class="label">origin:</label>
                                        <input class="input--style-1" type="text" id="oneway_departing_airport"
                                               value="{{ depart_city }}" data-filter="{{ airports_autofill }}#QUERY#"
                                               placeholder="City or airport" required="required" autocomplete="off"/>
                                        <input type="hidden" id="oneway_departing_airport_value"
                                               name="{{ input_from }}" value="{{ depart_code }}" data-order="1"/>
                                        <i class="zmdi zmdi-search input-group-symbol"></i>
                                        <script>
                                            document.addEventListener('DOMContentLoaded', e => {
                                              $('#oneway_departing_airport').autocomplete()
                                            }, false);
                                        </script>
                                    </div>
                                </div>

                                <div class="col-xl-4">
                                    <div class="input-group input-group-big">
                                        <label class="label">destination:</label>
                                        <input class="input--style-1" type="text" id="oneway_arrival_airport"
                                               value="{{ arrive_city }}" data-filter="{{ airports_autofill }}#QUERY#"
                                               placeholder="City or airport" required="required" autocomplete="off"/>
                                        <input type="hidden" id="oneway_arrival_airport_value"
                                               name="{{ input_to }}" value="{{ arrive_code }}" data-order="3"/>
                                        <i class="zmdi zmdi-search input-group-symbol"></i>
                                        <script>
                                            document.addEventListener('DOMContentLoaded', e => {
                                              $('#oneway_arrival_airport').autocomplete()
                                            }, false);
                                        </script>
                                    </div>
                                </div>

                                <div class="col-xl-4">
                                    <div class="input-group input-group-big">
                                        <label class="label">Departing date:</label>
                                        <input class="input--style-1 js-single-datepicker" type="text"
                                               id="oneway_departing_date" placeholder="yyyy-mm-dd"
                                               value="{{ depart_date }}" data-drop="3" required="required"/>
                                        <input type="hidden" id="oneway_departing_date_value" name="{{ input_from_date }}" value="" data-order="2"/>
                                        <div class="dropdown-datepicker" id="dropdown-datepicker3"></div>
                                    </div>
                                </div>
                                <input type="hidden" name="{{ input_triptype }}" value="{{ input_triptype_oneway }}" />
                            </div>

                            <div class="row radio-row">
                                <div class="col-xl-4 pt-1">
                                    <label class="radio-container m-r-45">
                                        Economy
                                        <input type="radio" checked="checked" name="class" value="economy">
                                        <span class="radio-checkmark"></span>
                                    </label>
                                    <label class="radio-container">
                                        Business
                                        <input type="radio" name="class">
                                        <span class="radio-checkmark"></span>
                                    </label>
                                </div>
                                <div class="col-xl-8">
                                    <button class="btn-submit btn-submit-small" type="submit"
                                            data-form="searchFormOneway">search
                                    </button>
                                </div>
                            </div>

                        </form>
                    </div>

                </div>
            </div>
        </div>
    </div>
</div>

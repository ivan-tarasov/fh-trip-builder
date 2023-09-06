<div class="bg-black">
    <div class="wrapper">
        <div class="card card-4 container">
            <nav class="mx-2 my-3">
                <div class="nav nav-pills" id="nav-tab" role="tablist">
                    <button class="nav-link text-uppercase text-white{{ tab_rt_button }}" id="tab-roundtrip" data-bs-toggle="tab" data-bs-target="#roundtrip" type="button" role="tab" aria-controls="roundtrip" aria-selected="{{ tab_rt_aria }}">
                        <i class="fas fa-exchange pe-1"></i>
                        Round trip
                    </button>
                    <button class="nav-link text-uppercase text-white{{ tab_ow_button }}" id="tab-oneway" data-bs-toggle="tab" data-bs-target="#oneway" type="button" role="tab" aria-controls="oneway" aria-selected="{{ tab_ow_aria }}">
                        <i class="fas fa-plane-arrival pe-1"></i>
                        One way
                    </button>
                </div>
            </nav>

            <form method="get" action="{{ search_page_url }}" id="searchForm" class="py-1">
                <div class="row">
                    <div class="col">
                        <div class="form-floating">
                            <input type="text"
                                   value="{{ depart_city }}"
                                   class="form-control"
                                   id="departing_airport"
                                   data-filter="{{ airports_autofill }}#QUERY#"
                                   placeholder="Start typing..."
                                   required="required"
                                   autocomplete="off"/>
                            <input type="hidden" id="departing_airport_value" name="{{ input_from }}" value="{{ depart_code }}" />
                            <label for="departing_airport">From:</label>
                        </div>
                    </div>
                    <div class="col">
                        <div class="form-floating">
                            <input type="text"
                                   value="{{ arrive_city }}"
                                   class="form-control"
                                   id="arrival_airport"
                                   data-filter="{{ airports_autofill }}#QUERY#"
                                   placeholder="Start typing..."
                                   required="required"
                                   autocomplete="off"/>
                            <input type="hidden"
                                   id="arrival_airport_value"
                                   name="{{ input_to }}"
                                   value="{{ arrive_code }}" />
                            <label for="arrival_airport">To</label>
                        </div>
                    </div>

                    <div class="col">
                        <div class="tab-content" id="nav-tabContent">
                            <div class="tab-pane p-0 m-0{{ tab_rt_div }}" id="roundtrip" role="tabpanel" aria-labelledby="roundtrip-tab" tabindex="0">
                                <div class="row g-2 mb-3">
                                    <div class="col-md">
                                        <div class="form-floating">
                                            <input type="text" class="form-control js-single-datepicker"
                                                   id="roundtrip_dates" placeholder="yyyy-mm-dd" data-drop="1"
                                                   data-startDate="2023-09-09"
                                                   autocomplete="off" required="required" />
                                            <label for="roundtrip_dates">Depart and Return dates</label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="tab-pane p-0 m-0{{ tab_ow_div }}" id="oneway" role="tabpanel" aria-labelledby="oneway-tab" tabindex="0">
                                <div class="form-floating pb-3">
                                    <div class="form-floating">
                                        <!--div class="dropdown-datepicker" id="dropdown-datepicker3"></div-->
                                        <input type="text" class="form-control js-single-datepicker"
                                               id="oneway_depart_date"
                                               placeholder="yyyy-mm-dd" data-drop="3" autocomplete="off"
                                               data-value="{{ depart_date }}"
                                               required="required"/>
                                        <label for="oneway_depart_date">Depart date</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col">
                        <div class="row g-2 mb-3">
                            <div class="col-md">
                                <div class="form-floating">
                                    <select class="form-select form-select-lg" id="passengers_adult">
                                        <option value="1" selected>1</option>
                                        <option value="2">2</option>
                                        <option value="3">3</option>
                                        <option value="4">4</option>
                                        <option value="5">5</option>
                                        <option value="6">6</option>
                                        <option value="7">7</option>
                                        <option value="8">8</option>
                                        <option value="9">9 (maximum)</option>
                                    </select>
                                    <label for="passengers_adult">Adult</label>
                                </div>
                            </div>
                            <div class="col-md">
                                <div class="form-floating">
                                    <select class="form-select form-select-lg" id="passengers_child">
                                        <option selected>–</option>
                                        <option value="1">1</option>
                                        <option value="2">2</option>
                                        <option value="3">3</option>
                                        <option value="4">4</option>
                                        <option value="5">5</option>
                                        <option value="6">6</option>
                                        <option value="7">7</option>
                                        <option value="8">8</option>
                                        <option value="9">9 (maximum)</option>
                                    </select>
                                    <label for="passengers_child">Child</label>
                                </div>
                            </div>
                            <div class="col-md">
                                <div class="form-floating">
                                    <select class="form-select form-select-lg" id="passengers_infant">
                                        <option selected>–</option>
                                        <option value="1">1</option>
                                        <option value="2">2</option>
                                        <option value="3">3</option>
                                        <option value="4">4 – maximum</option>
                                    </select>
                                    <label for="passengers_infant">Infant</label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col">
                        <div class="form-floating mb-3">
                            <select class="form-select" id="floatingSelectGrid">
                                <option selected>Economy</option>
                                <option value="1">Premium Economy</option>
                                <option value="2">Business</option>
                                <option value="3">First</option>
                            </select>
                            <label for="floatingSelectGrid">Cabin class</label>
                        </div>
                    </div>

                    <div class="col">
                        <button class="w-100 btn btn-lg btn-primary" type="submit">
                            Search flights
                            <i class="fa-solid fa-plane ps-2"></i>
                        </button>
                    </div>
                </div>
                <input type="hidden" id="depart_date_value" name="{{ input_from_date }}" value="{{ depart_date }}"/>
                <input type="hidden" id="return_date_value" name="{{ input_to_date }}" value="{{ return_date }}"/>
                <input type="hidden" id="hidden_triptype" name="triptype" value="{{ search_triptype }}"/>
            </form>
        </div>
    </div>
</div>

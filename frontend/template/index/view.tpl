<style>
    .bg-img {
        background-image: url("{{ bg_image_url }}");
        background-size: cover;
        background-position: center;
        position: relative; /* Make sure the container has a positioning context */
    }

    #nav-tab {
        padding-bottom: 1px;
    }

    #nav-tab,
    #nav-tab button {
        border: unset;
    }

    #nav-tab .nav-link {
        background-color: var(--bs-black);
        color: var(--bs-light);
    }

    #nav-tab .nav-link:hover {
        background-color: var(--bs-secondary-bg);
        color: var(--bs-emphasis-color)
    }

    #nav-tab .active {
        background-color: var(--bs-tertiary-bg);
        color: var(--bs-emphasis-color);
    }

    .container-between {
        padding-top: 3rem;
    }

    @media (min-width: 1200px) {
        .container-up {
            padding-bottom: 200px;
        }

        .container-between {
            padding-top: 0;
            position: relative;
            top: -185px;
            max-height: 200px;
            margin-top: 0;
        }
    }
</style>
<div class="bg-primary">
    <div class="container container-up mb-5">
        <div class="row py-2 mb-0 pe-lg-0 pt-lg-0 align-items-center rounded-4 shadow-lg bg-light bg-img">
            <div class="row align-items-center py-5">
                <div class="col-lg-7 text-center text-lg-start"></div>
                <div class="col-md-10 mx-auto col-lg-5">

                    <nav class="mx-2">
                        <div class="nav nav-tabs" id="nav-tab" role="tablist">
                            <button class="nav-link text-uppercase active" id="tab-roundtrip" data-bs-toggle="tab"
                                    data-bs-target="#roundtrip" type="button" role="tab" aria-controls="roundtrip"
                                    aria-selected="true" disabled>
                                <i class="fas fa-exchange pe-1"></i>
                                Round trip
                            </button>
                            <button class="nav-link text-uppercase" id="tab-oneway" data-bs-toggle="tab"
                                    data-bs-target="#oneway" type="button" role="tab" aria-controls="oneway"
                                    aria-selected="false">
                                <i class="fas fa-plane-arrival pe-1"></i>
                                One way
                            </button>
                        </div>
                    </nav>

                    <form method="get" action="{{ form_action }}" id="searchForm"
                          class="p-4 p-md-5 rounded-3 bg-body-tertiary">
                        <div class="form-floating mb-3">
                            <input type="text" class="form-control" id="departing_airport"
                                   data-filter="{{ api_airports_autofill }}#QUERY#" placeholder="Start typing..."
                                   required="required" autocomplete="off"/>
                            <input type="hidden" id="departing_airport_value" name="{{ input_from }}" value=""/>
                            <label for="departing_airport">From:</label>
                        </div>
                        <div class="form-floating mb-3">
                            <input type="text" class="form-control" id="arrival_airport"
                                   data-filter="{{ api_airports_autofill }}#QUERY#" placeholder="Start typing..."
                                   required="required" autocomplete="off"/>
                            <input type="hidden" id="arrival_airport_value" name="{{ input_to }}" value=""/>
                            <label for="arrival_airport">To</label>
                        </div>

                        <div class="tab-content" id="nav-tabContent">
                            <div class="tab-pane p-0 m-0 show active" id="roundtrip" role="tabpanel"
                                 aria-labelledby="roundtrip-tab" tabindex="0">
                                <div class="row g-2 mb-3">
                                    <div class="col-md">
                                        <div class="form-floating">
                                            <input type="text" class="form-control js-single-datepicker"
                                                   id="roundtrip_dates" placeholder="yyyy-mm-dd" data-drop="1"
                                                   autocomplete="off" required="required"/>
                                            <label for="roundtrip_dates">Depart and Return dates</label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="tab-pane p-0 m-0" id="oneway" role="tabpanel" aria-labelledby="oneway-tab"
                                 tabindex="0">
                                <div class="form-floating pb-3">
                                    <div class="form-floating">
                                        <!--div class="dropdown-datepicker" id="dropdown-datepicker3"></div-->
                                        <input type="text" class="form-control js-single-datepicker"
                                               id="oneway_depart_date"
                                               placeholder="yyyy-mm-dd" data-drop="3" autocomplete="off"
                                               required="required"/>
                                        <label for="oneway_depart_date">Depart date</label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-md">
                                <div class="form-floating">
                                    <select class="form-select" id="passengers_adt">
                                        <option value="1" selected>1</option>
                                        <option value="2">2</option>
                                        <option value="3">3</option>
                                        <option value="4">4</option>
                                        <option value="5">5</option>
                                        <option value="6">6</option>
                                        <option value="7">7</option>
                                        <option value="8">8</option>
                                        <option value="9">9 max</option>
                                    </select>
                                    <label for="passengers_adult">Adult <small>(12+)</small></label>
                                </div>
                            </div>
                            <div class="col-md">
                                <div class="form-floating">
                                    <select class="form-select" id="passengers_chd">
                                        <option selected>–</option>
                                        <option value="1">1</option>
                                        <option value="2">2</option>
                                        <option value="3">3</option>
                                        <option value="4">4</option>
                                        <option value="5">5</option>
                                        <option value="6">6</option>
                                        <option value="7">7</option>
                                        <option value="8">8</option>
                                        <option value="9">9 max</option>
                                    </select>
                                    <label for="passengers_child">Child <small>(2–11)</small></label>
                                </div>
                            </div>
                            <div class="col-md">
                                <div class="form-floating">
                                    <select class="form-select" id="passengers_inf">
                                        <option selected>–</option>
                                        <option value="1">1</option>
                                        <option value="2">2</option>
                                        <option value="3">3</option>
                                        <option value="4">4 max</option>
                                    </select>
                                    <label for="passengers_infant">Infants <small>(0-2)</small></label>
                                </div>
                            </div>
                            <div class="col-md">
                                <div class="form-floating">
                                    <select class="form-select" id="passengers_inl">
                                        <option selected>–</option>
                                        <option value="1">1</option>
                                        <option value="2">2</option>
                                        <option value="3">3</option>
                                        <option value="4">4 max</option>
                                    </select>
                                    <label for="passengers_infant">Infants on lap</label>
                                </div>
                            </div>
                        </div>

                        <div class="form-floating mb-3">
                            <select class="form-select" id="floatingSelectGrid">
                                <option selected>Economy</option>
                                <option value="1">Premium Economy</option>
                                <option value="2">Business</option>
                                <option value="3">First</option>
                            </select>
                            <label for="floatingSelectGrid">Cabin class</label>
                        </div>

                        <input type="hidden" id="depart_date_value" name="{{ input_from_date }}" value="{{ today_date }}"/>
                        <input type="hidden" id="return_date_value" name="{{ input_to_date }}" value=""/>
                        <input type="hidden" id="hidden_triptype" name="triptype" value="roundtrip"/>

                        <button class="w-100 btn btn-lg btn-primary" type="submit">
                            Search flights
                            <i class="fa-solid fa-plane ps-2"></i>
                        </button>
                        <!--hr class="my-4">
                        <small class="text-body-secondary">By clicking Sign up, you agree to the terms of use.</small-->
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container container-between">
    <div class="row row-cols-1 row-cols-lg-3 align-items-stretch g-4">
        {{ poi_cards }}
    </div>
</div>

<div class="container px-4 py-5" id="icon-grid">
    <h2 class="pb-2 mb-4 border-bottom">Top searches</h2>

    <div class="list-group">
        {{ top_searches }}
    </div>
</div>

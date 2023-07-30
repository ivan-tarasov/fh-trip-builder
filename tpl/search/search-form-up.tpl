<div class="bg-black">
  <div class="wrapper">
    <div class="card card-4 container">
      <div class="">

        <!-- Tab links panel -->
        <ul class="nav tab-list" id="myTab" role="tablist">
          <li class="tab-list__item">
            <button class="nav-link tab-list__link%TAB_ROUNDTRIP_BTN%" id="tab_roundtrip" data-bs-toggle="tab" data-bs-target="#roundtrip" type="button" role="tab" aria-controls="home" aria-selected="%TAB_ROUNDTRIP_ARIA%">
              <i class="fas fa-exchange"></i>
              round-trip
            </a>
          </li>
          <li class="tab-list__item">
            <button class="nav-link tab-list__link%TAB_ONEWAY_BTN%" id="tab_oneway" data-bs-toggle="tab" data-bs-target="#oneway" type="button" role="tab" aria-controls="home" aria-selected="%TAB_ONEWAY_ARIA%">
              <i class="fas fa-plane-arrival"></i>
              one-way trip
            </button>
          </li>
        </ul>
        <!-- End / Tab links panel -->

        <div class="tab-content">
          <!-- ROUND TRIP -->
          <div class="tab-pane fade%TAB_ROUNDTRIP_DIV%" id="roundtrip" role="tabpanel" aria-labelledby="home-tab">
            <form method="get" action="search.php" id="searchFormRound">
							<input type="hidden" name="hash" id="round_query_hash" class="query_hash" />

              <div class="row row-space">

                <div class="col-xl-6">
                  <div class="input-group input-group-big">
                    <label class="label">origin:</label>
                    <input class="input--style-1" type="text" id="round_departing_airport" value="%SEARCH_CITY_DEPARTURE%" data-prefetch="%API_PATH_AIRPORTS%" placeholder="City or airport" required="required" autocomplete="off" />
                    <input type="hidden" id="round_departing_airport_value" value="%SEARCH_CITY_DEPARTURE%" data-order="1" />
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
                    <input class="input--style-1" type="text" id="round_arrival_airport" value="%SEARCH_CITY_ARRIVAL%" data-prefetch="%API_PATH_AIRPORTS%" placeholder="City or airport" required="required" autocomplete="off" />
                    <input type="hidden" id="round_arrival_airport_value" value="%SEARCH_CITY_ARRIVAL%" data-order="3" />
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
                    <input class="input--style-1 js-single-datepicker" type="text" id="round_departing_date" data-value="%SEARCH_DATE_DEPARTURE%" placeholder="yyyy-mm-dd" data-drop="1" autocomplete="off" required="required" />
                    <input type="hidden" id="round_departing_date_value" value="" data-value="" data-order="2" />
                    <div class="dropdown-datepicker" id="dropdown-datepicker1"></div>
                  </div>
                </div>
                <div class="col-6">
                  <div class="input-group input-group-big">
                    <label class="label">Returning date:</label>
                    <input class="input--style-1 js-single-datepicker" type="text" id="round_returning_date" data-value="%SEARCH_DATE_RETURNING%" placeholder="yyyy-mm-dd" data-drop="2" autocomplete="off" required="required" />
										<input type="hidden" id="round_returning_date_value" value="" data-value="" data-order="4" />
										<div class="dropdown-datepicker" id="dropdown-datepicker2"></div>
                  </div>
                </div>

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
                  <button class="btn-submit btn-submit-small" type="submit" data-form="searchFormRound">search</button>
                </div>
              </div>

            </form>
          </div>

          <!-- ONE-WAY -->
          <div class="tab-pane fade%TAB_ONEWAY_DIV%" id="oneway" role="tabpanel" aria-labelledby="home-tab">
            <form method="get" action="search.php" id="searchFormOneway">
              <input type="hidden" name="hash" id="oneway_query_hash" class="query_hash" />

              <div class="row row-space">
                <div class="col-xl-4">
                  <div class="input-group input-group-big">
                    <label class="label">origin:</label>
                    <input class="input--style-1" type="text" id="oneway_departing_airport" value="%SEARCH_CITY_DEPARTURE%" data-prefetch="%API_PATH_AIRPORTS%" placeholder="City or airport" required="required" autocomplete="off" />
                    <input type="hidden" id="oneway_departing_airport_value" value="%SEARCH_CITY_DEPARTURE%" data-order="1" />
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
                    <input class="input--style-1" type="text" id="oneway_arrival_airport" value="%SEARCH_CITY_ARRIVAL%" data-prefetch="%API_PATH_AIRPORTS%" placeholder="City or airport" required="required" autocomplete="off" />
                    <input type="hidden" id="oneway_arrival_airport_value" value="%SEARCH_CITY_ARRIVAL%" data-order="3" />
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
                    <input class="input--style-1 js-single-datepicker" type="text" id="oneway_departing_date" placeholder="yyyy-mm-dd" value="%SEARCH_DATE_DEPARTURE%" data-drop="3" required="required" />
									  <input type="hidden" id="oneway_departing_date_value" value="" data-order="2" />
									  <div class="dropdown-datepicker" id="dropdown-datepicker3"></div>
                  </div>
                </div>

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
                  <button class="btn-submit btn-submit-small" type="submit" data-form="searchFormOneway">search</button>
                </div>
              </div>

            </form>
          </div>

        </div>
      </div>
    </div>
  </div>
</div>

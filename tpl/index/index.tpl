  <div class="bg-img-%MAIN_BG_IMAGE% py-5">
    <div class="wrapper wrapper--w680">
      <div class="card card-4">
        <div class="card-body">
          <!-- Tab links panel -->
          <ul class="nav tab-list" id="myTab" role="tablist">
            <li class="tab-list__item">
              <button class="nav-link tab-list__link active" id="tab_roundtrip" data-bs-toggle="tab" data-bs-target="#roundtrip" type="button" role="tab" aria-controls="home" aria-selected="true">
                <i class="fas fa-exchange"></i>
                round-trip
              </a>
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
              <form method="get" action="search.php" id="searchFormRound">
								<input type="hidden" name="hash" id="round_query_hash" class="query_hash" />
                <div class="input-group input-group-big">
                  <label class="label">origin:</label>
                  <input class="input--style-1" type="text" id="round_departing_airport" data-prefetch="%API_PATH_AIRPORTS%" placeholder="City or airport" required="required" autocomplete="off" />
                  <input type="hidden" id="round_departing_airport_value" value="" data-order="1" />
                  <i class="zmdi zmdi-search input-group-symbol"></i>
                  <script>
                  document.addEventListener('DOMContentLoaded', e => {
                    $('#round_departing_airport').autocomplete()
                  }, false);
                  </script>
                </div>
                <div class="input-group input-group-big">
                  <label class="label">destination:</label>
                  <input class="input--style-1" type="text" id="round_arrival_airport" data-prefetch="%API_PATH_AIRPORTS%" placeholder="City or airport" required="required" autocomplete="off" />
                  <input type="hidden" id="round_arrival_airport_value" value="" data-order="3" />
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
                      <label class="label">Departing date:</label>
                      <input class="input--style-1 js-single-datepicker" type="text" id="round_departing_date" placeholder="yyyy-mm-dd" data-drop="1" autocomplete="off" required="required" />
											<input type="hidden" id="round_departing_date_value" value="" data-order="2" />
                      <div class="dropdown-datepicker" id="dropdown-datepicker1"></div>
                    </div>
                  </div>
                  <div class="col-lg-6 col-sm-12">
                    <div class="input-group input-group-big">
                      <label class="label">Returning date:</label>
                      <input class="input--style-1 js-single-datepicker" type="text" id="round_returning_date" placeholder="yyyy-mm-dd" data-drop="2" autocomplete="off" required="required" />
											<input type="hidden" id="round_returning_date_value" value="" data-order="4" />
                      <div class="dropdown-datepicker" id="dropdown-datepicker2"></div>
                    </div>
                  </div>
                </div>
                <div class="radio-row">
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
                <button class="btn-submit m-t-15" type="submit" data-form="searchFormRound">search</button>
              </form>
            </div>
            <!-- ONE-WAY -->
            <div class="tab-pane fade" id="oneway" role="tabpanel" aria-labelledby="home-tab" tabindex="0">
              <form method="get" action="search.php" id="searchFormOneway">
								<input type="hidden" name="hash" id="oneway_query_hash" class="query_hash" />
                <div class="input-group input-group-big">
                  <label class="label">origin:</label>
									<input class="input--style-1" type="text" id="oneway_departing_airport" data-prefetch="%API_PATH_AIRPORTS%" placeholder="City or airport" required="required" autocomplete="off" />
                  <input type="hidden" id="oneway_departing_airport_value" value="" data-order="1" />
                  <i class="zmdi zmdi-search input-group-symbol"></i>
                  <script>
                  document.addEventListener('DOMContentLoaded', e => {
                    $('#oneway_departing_airport').autocomplete()
                  }, false);
                  </script>
                </div>
                <div class="input-group input-group-big">
                  <label class="label">destination:</label>
									<input class="input--style-1" type="text" id="oneway_arrival_airport" data-prefetch="%API_PATH_AIRPORTS%" placeholder="City or airport" required="required" autocomplete="off" />
                  <input type="hidden" id="oneway_arrival_airport_value" value="" data-order="3" />
                  <i class="zmdi zmdi-search input-group-symbol"></i>
                  <script>
                  document.addEventListener('DOMContentLoaded', e => {
                    $('#oneway_arrival_airport').autocomplete()
                  }, false);
                  </script>
                </div>
                <div class="input-group input-group-big">
                  <label class="label">Departing date:</label>
                  <input class="input--style-1 js-single-datepicker" type="text" id="oneway_departing_date" placeholder="yyyy-mm-dd" data-drop="3" autocomplete="off" required="required" />
									<input type="hidden" id="oneway_departing_date_value" value="" data-order="2" />
									<div class="dropdown-datepicker" id="dropdown-datepicker3"></div>
                </div>
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
  </div>

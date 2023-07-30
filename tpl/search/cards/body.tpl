    <div class="text-left badge-flight">&nbsp;%FLIGHT_BADGE%</div>
    <div class="card mb-0 shadow-sm">
      <div class="card-body">
        <div class="row">
          <!-- PRICE SECTION -->
          <div class="col-sm-3 border-end px-4 pt-3">
            <!-- Price total -->
            <div class="card-title fs-2">
              <span data-toggle="tooltip" data-placement="top" title="The final price consists of the ticket price of $%FLIGHT_PRICE% plus taxes: $%FLIGHT_PRICE_GST% GST and $%FLIGHT_PRICE_QST% QST">
                $%FLIGHT_PRICE_TOTAL%
              </span>
            </div>
            <!-- End / Price total -->
            <!-- Tax section -->
            <dl class="row pt-2">
              <dt class="col-lg-4">Price</dt>
              <dd class="col-lg-8">$%FLIGHT_PRICE%</dd>
              <dt class="col-lg-4">GST</dt>
              <dd class="col-lg-8">$%FLIGHT_PRICE_GST%</dd>
              <dt class="col-lg-4">QST</dt>
              <dd class="col-lg-8">$%FLIGHT_PRICE_QST%</dd>
            </dl>
            <!-- End / Tax section -->
            <!-- Buy button -->
              <div class="d-lg-grid gap-2">
                <button type="button" class="btn btn-buy btn-block my-4" id="addTrip_%DEPARTING_FLIGHT_DB_ID%_%RETURNING_FLIGHT_DB_ID%" data-flight-departing-id="%DEPARTING_FLIGHT_DB_ID%" data-flight-returning-id="%RETURNING_FLIGHT_DB_ID%">
                  <i class="fas fa-xl fa-plus-circle float-end"></i>
                  Buy ticket
                </button>
              </div>
            <!-- Buy button -->
          </div>
          <!-- End / PRICE SECTION -->
          <!-- FLIGHTS -->
          <div class="col-sm-9 px-5 pt-2 pb-3">
            <!-- Airlines and buttons section -->
            <div class="row pb-4">
              <!-- Airlines logos -->
              <div class="col-sm-8">
                <div class="media">
                  %AIRLINE_LOGOS%
                </div>
              </div>
              <!-- End / Airlines logos -->
              <!-- Void buttons -->
              <div class="col-sm-4 text-end pe-0">
                <div class="btn-group btn-group-sm" role="group" aria-label="Right buttons group">
                <button type="button" class="btn btn-link empty-link" data-toggle="tooltip" data-placement="top" title="Add to compare">
                  <i class="fas fa-plus"></i>
                </button>
                <button type="button" class="btn btn-link empty-link" data-toggle="tooltip" data-placement="top" title="Price alert">
                  <i class="fas fa-bell"></i>
                </button>
                <button type="button" class="btn btn-link empty-link" data-toggle="tooltip" data-placement="top" title="Share this trip">
                  <i class="fas fa-share"></i>
                </button>
                </div>
              </div>
              <!-- End / Void buttons -->
            </div>
            <!-- End / Airlines and buttons section -->
            <!-- Flights info section -->
            %CARD_FLIGHT%
            <!-- End / Flights info section -->
          </div>
          <!-- End / FLIGHTS -->
        </div>
      </div>
    </div>

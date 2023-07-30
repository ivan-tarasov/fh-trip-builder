<!-- Booking ID and date created -->
<h3 class="justify-content-between align-items-center fs-6 %ROUNDTRIP_HIDE_ID%">
  <span class="text-muted"><i class="fas fa-hashtag pr-1"></i>%BOOKING_ID%</span>
  <!-- <span class="badge bg-secondary rounded-pill">%BOOKING_CREATED%</span> -->
</h3>
<!-- End / Booking ID and date created -->
<!-- TICKETS CARD -->
<span class="list-group-item d-flex gap-3 py-3 %ROUNDTRIP_MERGE%" aria-current="true">
  <!-- Airline logo -->
	<div class="align-items-start">
  	<button type="button" class="btn btn-link px-0 me-3 mt-1" data-toggle="tooltip" data-placement="top" title="%AIRLINE_TITLE%">
    	<img src="%AIRLINE_LOGO%" class="rounded-circle" style="max-height: 48px;" />
  	</button>
	</div>
  <!-- End / Airline logo -->
  <div class="d-flex gap-2 w-100 justify-content-between">
    <!-- Departure and arrival -->
    <div class="col-xl-3">
      <ul class="list-inline pt-2">
        <li class="list-inline-item h6">%DEPARTURE_CITY%</li>
        <li class="list-inline-item"><i class="fas fa-long-arrow-alt-right"></i></li>
        <li class="list-inline-item h6">%ARRIVAL_CITY%</li>
      </ul>
      <abbr title="Airline name">%AIRLINE_TITLE%</abbr>
    </div>
    <!-- End / Departure and arrival -->
    <!-- Flight number -->
    <div class="col-xl-1 pt-2"><mark class="px-2">%AIRLINE_CODE% %FLIGHT_NUMBER%</mark></div>
    <!-- End / Flight number -->
    <!-- Departure time -->
    <div class="col-xl-2 pt-2"><i class="fas fa-lg fa-plane-departure pe-2"></i>%DEPARTURE_TIME%</div>
    <div class="col-xl-1 pt-2">
			<div class="small %DISPLAY_NONE%">
				<p># %BOOKING_ID%</p>
				<p>%BOOKING_CREATED%</p>
			</div>
		</div>
    <!-- Departure time -->
    <div class="col-xl-2 text-end pe-3">
      <ul class="list-unstyled font-monospace float-right pt-1 %DISPLAY_NONE%">
        <li class="fs-5" data-toggle="tooltip" data-placement="top" title="The final price consists of the ticket price of $%FLIGHT_PRICE% plus taxes: $%FLIGHT_PRICE_GST% GST and $%FLIGHT_PRICE_QST% QST">$%FLIGHT_PRICE_TOTAL%</li>
        <li class="small text-muted">Ticket $%FLIGHT_PRICE%</li>
        <li class="small text-muted">Taxes $%FLIGHT_PRICE_TAXES%</li>
        <!--li class="small text-muted">GST $%FLIGHT_PRICE_GST%</li-->
        <!--li class="small text-muted">QST $%FLIGHT_PRICE_QST%</li-->
      </ul>
    </div>
		<!-- End / Departure time -->
    <!-- Action buttons -->
    <div class="col-xl-2 border-start ps-4">
      <div class="ps-0 pt-1 %DISPLAY_NONE%">
        <!--button type="button" class="btn btn-sm btn-link empty-link d-block pt-0" id="airlinesSelectAll"><i class="fas fa-info-circle pr-2"></i>Booking details</button-->
        <button type="button" class="btn btn-sm btn-link empty-link d-block" id="airlinesSelectClear"><i class="fas fa-exchange-alt pe-2"></i>Exchange ticket</button>
        <button type="button" class="btn btn-sm btn-link text-danger empty-link d-block" id="airlinesSelectClear"><i class="fas fa-lg fa-times pe-2"></i>Return ticket</button>
      </div>
    </div>
		<!-- End / Action buttons -->
  </div>
</span>
<!-- TICKETS CARD -->

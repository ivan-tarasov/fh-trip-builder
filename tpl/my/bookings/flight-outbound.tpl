<!-- Booking ID and date created -->
<h3 class="justify-content-between align-items-center fs-6 pt-3">
    <span class="text-muted"><i class="fas fa-hashtag pr-1"></i>{{ booking_id }}</span>
</h3>
<!-- End / Booking ID and date created -->
<!-- TICKETS CARD -->
<span class="list-group-item d-flex gap-3 py-3 pb-0 mb-0 border-bottom-0" aria-current="true">
    <!-- Airline logo -->
    <div class="align-items-start">
        <button type="button" class="btn btn-link px-0 me-3 mt-1" data-toggle="tooltip" data-placement="top" title="{{ airline_name }}">
            <img src="{{ airline_logo_url }}" class="rounded-circle" style="max-height: 48px;" />
        </button>
    </div>
    <!-- End / Airline logo -->
    <div class="d-flex gap-2 w-100 justify-content-between">
        <!-- Departure and arrival -->
        <div class="col-xl-3">
            <ul class="list-inline pt-2">
                <li class="list-inline-item h6">{{ depart_city }}</li>
                <li class="list-inline-item"><i class="fas fa-long-arrow-alt-right"></i></li>
                <li class="list-inline-item h6">{{ arrive_city }}</li>
            </ul>
            <abbr title="Airline name">{{ airline_name }}</abbr>
        </div>
        <!-- End / Departure and arrival -->
        <!-- Flight number -->
        <div class="col-xl-1 pt-2"><mark class="px-2">{{ flight_number }}</mark></div>
        <!-- End / Flight number -->
        <!-- Departure time -->
        <div class="col-xl-2 pt-2"><i class="fas fa-lg fa-plane-departure pe-2"></i>{{ depart_time }}</div>
        <div class="col-xl-1 pt-2">
			<div class="small %DISPLAY_NONE%">
				<p># {{ booking_id }}</p>
				<p>{{ booking_created }}</p>
			</div>
		</div>
        <!-- Departure time -->
        <div class="col-xl-2 text-end pe-3">
            <ul class="list-unstyled font-monospace float-right pt-1">
                <li class="fs-5" data-toggle="tooltip" data-placement="top" title="The final price consists of the ticket price of $%FLIGHT_PRICE% plus taxes: $%FLIGHT_PRICE_GST% GST and $%FLIGHT_PRICE_QST% QST">
                    ${{ price_total }}
                </li>
                <li class="small text-muted">Ticket ${{ price_base }}</li>
                <li class="small text-muted">Taxes ${{ price_tax }}</li>
            </ul>
        </div>
        <!-- End / Departure time -->
        <!-- Action buttons -->
        <div class="col-xl-2 border-start ps-4">
            <div class="ps-0 pt-1">
                <button type="button" class="btn btn-sm btn-link empty-link d-block" id="airlinesSelectClear">
                    <i class="fas fa-exchange-alt pe-2"></i>Exchange ticket
                </button>
                <button type="button" class="btn btn-sm btn-link text-danger empty-link d-block" id="airlinesSelectClear">
                    <i class="fas fa-lg fa-times pe-2"></i>Return ticket
                </button>
            </div>
        </div>
        <!-- End / Action buttons -->
    </div>
</span>
<!-- TICKETS CARD -->

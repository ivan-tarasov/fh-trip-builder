<!-- TICKETS CARD -->
<span class="list-group-item d-flex gap-3 py-3 pt-0 pb-3 mb-1 border-top-0" aria-current="true">
    <!-- Airline logo -->
	<div class="align-items-start">
        <button type="button" class="btn btn-link px-0 me-3 mt-1" data-toggle="tooltip" data-placement="top" title="%AIRLINE_TITLE%">
            <img src="{{ airline_logo_url }}" class="rounded-circle" style="max-height: 48px;"/>
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
            <abbr title="Airline name">{{ airline_title }}</abbr>
        </div>
        <!-- End / Departure and arrival -->
        <!-- Flight number -->
        <div class="col-xl-1 pt-2"><mark class="px-2">{{ flight_number }}</mark></div>
        <!-- End / Flight number -->
        <!-- Departure time -->
        <div class="col-xl-2 pt-2"><i class="fas fa-lg fa-plane-departure pe-2"></i>{{ depart_time }}</div>
        <div class="col-xl-1 pt-2"><div class="d-none"></div></div>
        <div class="col-xl-2 pe-3"><div class="d-none"></div></div>
        <div class="col-xl-2 border-start ps-4"><div class="d-none"></div></div>
    </div>
</span>
<!-- TICKETS CARD -->

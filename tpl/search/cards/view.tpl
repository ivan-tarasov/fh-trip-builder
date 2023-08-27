<!-- Flights cards -->
<div class="col-xl-9 col-md-12 order-md-1">
    <div class="page-wrapper">
        <div class="wrapper pb-0">
            <div class="mb-4 py-3 ps-3 bg-success-subtle text-success border-start border-success border-5 rounded-end">
                From <strong>{{ depart_city }}</strong> to <strong>{{ arrive_city }}</strong> with selected filters found
                <strong>{{ total_flights }}</strong>
            </div>

            {{ flight_cards }}

            {{ pagination_bar }}
        </div>
    </div>
</div>
<!-- End / Flights cards -->

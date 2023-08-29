<div class="row">
    <div class="col">
        <!-- <div class="col-sm-2"> -->
        <p class="h3">{{ depart_time }}</p>
        <p class="h6">{{ depart_city }}</p>
        <p class="h6 small">{{ depart_date }}</p>
    </div>
    <!-- <div class="col pt-1 text-center"> -->
    <div class="col-sm-6 pt-1 text-center">
        <div class="btn-group btn-group-sm w-100" role="group" aria-label="Travel time">
            <button type="button"
                    class="btn btn-link text-start ms-1 text-secondary"
                    data-toggle="tooltip"
                    data-placement="top"
                    title="Departure from airport &laquo;{{ depart_airport }}&raquo; ({{ depart_city }}) at {{ depart_time }} local time"
            >
                <i class="fas fa-2x fa-plane-departure"></i>
            </button>
            <button type="button" class="btn text-bg-white border-0 text-secondary-emphasis disabled">
                <i class="far fa-xl fa-clock pe-1"></i> {{ flight_duration }}
            </button>
            <button type="button"
                    class="btn btn-link text-end me-1 text-secondary"
                    data-toggle="tooltip"
                    data-placement="top"
                    title="Arrival to airport &laquo;{{ arrive_airport }}&raquo; ({{ arrive_city }}) at {{ arrive_time }} local time"
            >
                <i class="fas fa-2x fa-plane-arrival"></i>
            </button>
        </div>
        <div class="progress pt-1 mt-2" style="height: 1px; margin: 0 5px;">
            <div class="progress-bar" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
        </div>
        <button type="button"
                class="btn btn-link float-start text-secondary text-decoration-none"
                data-toggle="tooltip"
                data-placement="bottom"
                title="Departure from airport &laquo;{{ depart_airport }}&raquo; ({{ depart_city }}) at {{ depart_time }} local time"
        >
            {{ depart_code }}
        </button>
        <button type="button"
                class="btn btn-link float-end text-secondary text-decoration-none"
                data-toggle="tooltip"
                data-placement="bottom"
                title="Arrival to airport &laquo;{{ arrive_airport }}&raquo; ({{ arrive_city }}) at {{ arrive_time }} local time"
        >
            {{ arrive_code }}
        </button>
    </div>
    <div class="col text-lg-end">
        <!-- <div class="col-sm-2 text-lg-end"> -->
        <p class="h3">{{ arrive_time }}</p>
        <p class="h6">{{ arrive_city }}</p>
        <p class="h6 small">{{ arrive_date }}</p>
    </div>
</div>

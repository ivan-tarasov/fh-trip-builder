<!--a href="{{ search_url }}" class="list-group-item list-group-item-action">
    <div class="d-flex w-100 justify-content-between">
        <span class="fw-lighter mb-1">
            {{ from_name }}
            <li class="list-inline-item px-2"><i class="fas fa-long-arrow-alt-right"></i></li>
            {{ to_name }}
        </span>
        <small>{{ search_count }} searches</small>
    </div>
    <p class="mb-1">{{ depart_date }}{{ return_date }}</p>
</a-->

<div class="list-group-item list-group-item-action">
    <div class="row align-items-center">

        <div class="col">
            <i class="fa-solid fa-hashtag"></i>
            <i class="fa-solid fa-{{ search_rank }}"></i>
        </div>
        <div class="col-lg-2 text-end">
            {{ from_name }}
        </div>
        <div class="col text-center">
            <i class="fa fa-arrow-right-{{ flight_direction }}"></i>
        </div>
        <div class="col-lg-2">
            {{ to_name }}
        </div>
        <div class="col-lg-3">
            {{ depart_date }}{{ return_date }}
        </div>
        <div class="col-lg-2 text-end">
            {{ search_count }} searches
        </div>

        <div class="col-lg-2 mx-auto d-grid gap-2">
            <a href="{{ search_url }}" type="button" class="btn btn-primary btn-sm">Search flights</a>
        </div>

    </div>
</div>

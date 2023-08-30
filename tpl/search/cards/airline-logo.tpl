<button type="button" class="btn btn-link position-relative ps-0 me-5" data-toggle="tooltip" data-placement="top" title="{{ airline_title }}">
    <img src="{{ logo_url }}" class="align-self-start rounded-circle ps-0 pe-0" style="max-height: 64px;" title="" alt="{{ airline_title }} – {{ flight_number }}" />
    <span class="position-absolute top-50 start-100 translate-middle badge bg-secondary ms-2">
        {{ flight_number }}
        <span class="visually-hidden">Flight number</span>
    </span>
</button>

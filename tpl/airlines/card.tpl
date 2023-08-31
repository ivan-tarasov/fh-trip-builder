<div class="col d-flex align-items-start mb-4">
    <div class="icon-square text-body-emphasis d-inline-flex align-items-center justify-content-center fs-4 flex-shrink-0 me-3">
        <img src="{{ airline_logo_img }}" class="rounded-circle me-3" alt="{{ airline_title }}" title="{{ airline_title }}" />
    </div>
    <div>
        <h3 class="fs-2 text-body-emphasis">{{ airline_title }}</h3>
        <dl class="row mt-2">
            <dt class="col-sm-1"><i class="fa-solid fa-phone"></i></dt>
            <dd class="col-sm-11">{{ airline_phone_number }}</dd>
            <dt class="col-sm-1"><i class="fa-solid fa-globe"></i></dt>
            <dd class="col-sm-11"><a href="{{ airline_url }}" target="_blank">{{ airline_url }}</a></dd>
        </dl>
    </div>
</div>

<div class="col">
    <div class="card card-cover h-100 overflow-hidden text-bg-dark rounded-4 shadow-lg" style="background-image: url('{{ poi_image_url }}');">
        <div class="d-flex flex-column h-100 p-5 pb-3 text-white text-shadow-1">
            <h3 class="pt-5 mt-5 mb-4 display-6 lh-1 fw-bold"><a class="text-white empty-link">{{ poi_title }}</a></h3>
            <ul class="d-flex list-unstyled mt-auto">
                <li class="me-auto">
                    <img src="https://github.com/ivan-tarasov.png" alt="Post author" width="32" height="32" class="rounded-circle border border-white">
                </li>
                <li class="d-flex align-items-center">
                    <i class="fa-solid fa-location-dot pe-1"></i>
                    <small>{{ poi_city }}, {{ poi_country }}</small>
                </li>
            </ul>
        </div>
    </div>
</div>

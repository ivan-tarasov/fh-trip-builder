<!-- No results found -->
<div class="col-xl-9 col-md-12 order-md-1">
    <div class="page-wrapper">
        <div class="wrapper pb-5">
            <h1 class="mt-0">
                No flights found
                <i class="far fa-lg fa-frown-open"></i>
            </h1>
            <p class="pt-2 text-muted">from <strong>{{ depart_city }}</strong> to <strong>{{ arrive_city }}</strong>, {{ depart_date }}{{ return_date }}</p>
            <hr class="mt-3 mb-3"/>
            <p class="h5 pb-5">Aww yeah, seems we are not found any flight for this direction. Try to change filter or
                destination. And, sure, you can always <a href="#">subscribe</a> to this direction to get alert for new
                flight.</p>
            <img src="{{ static_img_dir }}/no-results.png" alt="No results found" title="No results found" class="img-fluid" />
        </div>
    </div>
</div>
<!-- End / No results found -->

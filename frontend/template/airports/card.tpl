<div class="col mb-4">
    <div class="card">
        <img src="{{ airport_map_img }}"  alt=""/>
        <div class="card-body">
            <h3 class="card-title border-bottom pb-3 mt-2 fs-5">{{ airport_title }}</h3>
            <div class="card-text">
                <dl class="row">
                    <dt class="col-sm-4">Country</dt>
                    <dd class="col-sm-8">{{ airport_country }}</dd>
                    <dt class="col-sm-4">City</dt>
                    <dd class="col-sm-8">{{ airport_city }}</dd>
                    <dt class="col-sm-4">IATA code</dt>
                    <dd class="col-sm-8">{{ airport_iata_code }}</dd>
                    <dt class="col-sm-4">Timezone</dt>
                    <dd class="col-sm-8">{{ airport_timezone }}</dd>
                    <dt class="col-sm-4">Coordinates</dt>
                    <dd class="col-sm-8">
                        <a href="https://www.google.com/maps/place/{{ airport_latitude }}+{{ airport_longitude }}" target="_blank">
                            <i class="fab fa-google"></i>
                            {{ airport_latitude }}, {{ airport_longitude }}
                        </a>
                    </dd>
                    <dt class="col-sm-4">Altitude</dt>
                    <dd class="col-sm-8">{{ airport_altitude }} meters</dd>
                </dl>
            </div>
        </div>
        <!--div class="card-footer">
            <small class="text-muted">Search count: X,XXX</small>
        </div-->
    </div>
</div>

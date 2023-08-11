<div class="col mb-4">
    <div class="card">
        <img src="{{ airport-map-img }}"  alt=""/>
        <div class="card-body">
            <h3 class="card-title border-bottom pb-3 mt-2 fs-5">{{ airport-title }}</h3>
            <div class="card-text">
                <dl class="row">
                    <dt class="col-sm-4">Country</dt>
                    <dd class="col-sm-8">{{ airport-country }}</dd>
                    <dt class="col-sm-4">City</dt>
                    <dd class="col-sm-8">{{ airport-city }}</dd>
                    <dt class="col-sm-4">IATA code</dt>
                    <dd class="col-sm-8">{{ airport-iata-code }}</dd>
                    <dt class="col-sm-4">Region code</dt>
                    <dd class="col-sm-8">{{ airport-region-code }}</dd>
                    <dt class="col-sm-4">Timezone</dt>
                    <dd class="col-sm-8">{{ airport-timezone }}</dd>
                    <dt class="col-sm-4">Coordinates</dt>
                    <dd class="col-sm-8">
                        <a href="https://www.google.com/maps/place/{{ airport-latitude }}+{{ airport-longitude }}" target="_blank">
                            <i class="fab fa-google"></i>
                            {{ airport-latitude }}, {{ airport-longitude }}
                        </a>
                    </dd>
                </dl>
            </div>
        </div>
        <!-- <div class="card-footer">
          <small class="text-muted">Last updated 3 mins ago</small>
        </div> -->
    </div>
</div>

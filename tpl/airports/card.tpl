<div class="col mb-4">
  <div class="card">
    <img
      src="https://static-maps.yandex.ru/1.x/?ll=%AIRPORT_LONGITUDE%,%AIRPORT_LATITUDE%&z=%MAP_ZOOM%&l=map&lang=%MAP_LANGUAGE%&size=%MAP_SIZE%"
    />
    <div class="card-body">
      <h3 class="card-title border-bottom pb-3 mt-2 fs-5">%AIRPORT_TITLE%</h3>
      <div class="card-text">
        <dl class="row">
          <dt class="col-sm-4">Countrie</dt>
          <dd class="col-sm-8">%AIRPORT_COUNTRY%</dd>
          <dt class="col-sm-4">City</dt>
          <dd class="col-sm-8">%AIRPORT_CITY%</dd>
          <dt class="col-sm-4">IATA code</dt>
          <dd class="col-sm-8">%AIRPORT_CODE%</dd>
          <dt class="col-sm-4">Region code</dt>
          <dd class="col-sm-8">%AIRPORT_REGION_CODE%</dd>
          <dt class="col-sm-4">Timezone</dt>
          <dd class="col-sm-8">%AIRPORT_TIMEZONE%</dd>
          <dt class="col-sm-4">Coordinates</dt>
          <dd class="col-sm-8">
            <a
              href="https://www.google.com/maps/place/%AIRPORT_LATITUDE%+%AIRPORT_LONGITUDE%"
              target="_blank"
            >
              <i class="fab fa-google"></i>
              %AIRPORT_LATITUDE%, %AIRPORT_LONGITUDE%
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

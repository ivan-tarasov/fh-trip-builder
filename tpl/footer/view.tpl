<footer class="footer mt-auto py-3 bg-dark text-light-emphasis">
    <div class="container">
        <div class="container py-4 py-md-5 px-4 px-md-3">
            <div class="row">
                <!-- Info cell -->
                <div class="col-lg-4">
                    <a href="/"
                       class="d-flex align-items-center mb-2 mb-lg-0 text-white text-decoration-none me-5 pb-3">
                        <i class="fas fa-2x fa-plane-departure pe-3"></i>
                        <strong class="lead">Trip Builder</strong>
                    </a>
                    <ul class="list-unstyled small">
                        <li class="mb-2">
                            Software made by <a href="https://tarasov.ca/" target="_blank" class="link-light">Ivan
                                Tarasov</a> as <a href="https://www.flighthub.com/" target="_blank" class="link-light">FlightHub</a>
                            PHP Coding <a href="Trip_Builder_1.pdf" target="_blank" class="link-light">Assignment</a>.
                            Code licensed <a href="https://bitbucket.org/karapuzoff/trip-builder/src/master/LICENSE.txt"
                                             target="_blank" class="link-light" rel="license noopener">MIT</a>, docs are
                            available <a href="https://bitbucket.org/karapuzoff/trip-builder/src/master/README.md"
                                         target="_blank" class="link-light">here</a>.
                            <i class="far fa-lg fa-copyright pe-1"></i>2023
                        </li>
                        <li class="mb-2">Generated in <code>{{ EXECUTION_TIMER }} seconds</code>,
                            <!--DB requests: {{ DATABASE_REQUESTS }}, API calls: {{ API_CALLS_COUNT }}, -->flights in DB: <code>{{ FLIGHTS_COUNT }}</code>
                        </li>
                        <li class="mb-2">Currently {{ APP_VERSION }}</li>
                    </ul>
                </div>
                <!-- End / Info cell -->
                <!-- Main menu cell -->
                <div class="col-6 col-lg-2 offset-lg-1 mb-4 text-white">
                    <h5>Navigation</h5>
                    <ul class="list-unstyled pt-3">
                        {{ FOOTER_MENU_MAIN }}
                    </ul>
                </div>
                <!-- End / Main menu cell -->
                <!-- ... cell -->
                <div class="col-6 col-lg-2 mb-4 text-white">
                    <h5>Repository</h5>
                    <ul class="list-unstyled pt-3">
                        {{ FOOTER_MENU_GIT }}
                    </ul>
                </div>
                <!-- End / ... cell -->
                <!-- ... cell -->
                <div class="col-6 col-lg-2 mb-3 text-white">
                    <h5>Socials</h5>
                    <ul class="list-unstyled pt-3">
                        {{ FOOTER_MENU_SOCIAL }}
                    </ul>
                </div>
                <!-- End / ... cell -->
                <!-- Go to top link -->
                <div class="col-6 col-lg-1 mb-3 text-end">
                    <a id="linktotop" title="Back to form" alt="Back to form"><i
                                class="fas fa-3x fa-level-up-alt ps-2 mb-2"></i></a>
                </div>
                <!-- End / Go to top link -->
            </div>
        </div>
    </div>
</footer>

<!-- Bootstrap -->
<!-- <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js" integrity="sha384-JjSmVgyd0p3pXB1rRibZUAYoIIy6OrQ6VrjIEaFf/nJGzIxFDsf4x0xIM+B07jRM" crossorigin="anonymous"></script> -->
<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-Fy6S3B9q64WdZWQUiU+q4/2Lc9npb8tCaSX9FK7E8HnRr0Jz8D6OP9dO5Vg3Q9ct" crossorigin="anonymous"></script> -->
<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-kenU1KFdBIe4zVF0s0G1M5b4hcpxyD9F7jL+jjXkk+Q2h455rYXK/7HAuoJl+0I4" crossorigin="anonymous"></script> -->
<script src="//cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-w76AqPfDkMBDXo30jS1Sgez6pr3x5MlQ1ZAGC+nuZB+EYdgRZgiwxhTBTkF7CXvN"
        crossorigin="anonymous"></script>

<!-- Vendor JS-->
<script src="frontend/select2/select2.min.js"></script>
<script src="frontend/jquery-validate/jquery.validate.min.js"></script>
<script src="frontend/bootstrap-wizard/jquery.bootstrap.wizard.min.js"></script>
<script src="frontend/datepicker/moment.min.js"></script>
<script src="frontend/datepicker/daterangepicker.js"></script>
<script src="frontend/autocomplete/bootstrap-autocomplete.js"></script>
<script src="//cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="//use.fontawesome.com/releases/v6.1.1/js/all.js" type="text/javascript" defer
        integrity="sha384-xBXmu0dk1bEoiwd71wOonQLyH+VpgR1XcDH3rtxrLww5ajNTuMvBdL5SOiFZnNdp"
        crossorigin="anonymous"></script>
<script src="//cdnjs.cloudflare.com/ajax/libs/ion-rangeslider/2.3.0/js/ion.rangeSlider.min.js"></script>

<!-- Main JS-->
<script src="js/global.js"></script>

</body>
</html>

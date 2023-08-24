<footer class="footer mt-auto py-3 bg-dark text-light-emphasis">
    <div class="container">
        <div class="container py-4 py-md-5 px-4 px-md-3">
            <div class="row">
                <!-- Info cell -->
                <div class="col-lg-4">
                    <a href="/"
                       class="d-flex align-items-center mb-2 mb-lg-0 text-white text-decoration-none me-5 pb-3">
                        <i class="fas fa-2x fa-plane-departure pe-3"></i>
                        <strong class="lead">{{ app-name }}</strong>
                    </a>
                    <ul class="list-unstyled small">
                        <li class="mb-2">
                            Software made by <a href="{{ app-author-website }}" target="_blank" class="link-light">{{ app-author-name }}</a>
                            as <a href="https://www.flighthub.com/" target="_blank" class="link-light">FlightHub</a>
                            PHP Coding <a href="Trip_Builder_1.pdf" target="_blank" class="link-light">Assessment</a>.
                            Code licensed <a href="{{ app-license-url }}" target="_blank" class="link-light" rel="license noopener">{{ app-license-type }}</a>,
                            documentation are available <a href="{{ app-documentation-url }}" target="_blank" class="link-light">here</a>.
                            <i class="far fa-lg fa-copyright pe-1"></i>{{ copyright-years }}
                        </li>
                        <li class="mb-2">Generated in <code>{{ execution-time }} seconds</code>,
                            DB requests: <code>{{ database-requests }}</code>, flights in DB: <code>{{ flights-count }}</code>
                        </li>
                        <li class="mb-2">Currently {{ app-version }}</li>
                    </ul>
                </div>
                <!-- End / Info cell -->
                <!-- Main menu cell -->
                <div class="col-6 col-lg-2 offset-lg-1 mb-4 text-white">
                    <h5>Navigation</h5>
                    <ul class="list-unstyled pt-3">
                        {{ main-menu }}
                    </ul>
                </div>
                <!-- End / Main menu cell -->
                <!-- ... cell -->
                <div class="col-6 col-lg-2 mb-4 text-white">
                    <h5>Repository</h5>
                    <ul class="list-unstyled pt-3">
                        {{ git-menu }}
                    </ul>
                </div>
                <!-- End / ... cell -->
                <!-- ... cell -->
                <div class="col-6 col-lg-2 mb-3 text-white">
                    <h5>Socials</h5>
                    <ul class="list-unstyled pt-3">
                        {{ social-menu }}
                    </ul>
                </div>
                <!-- End / ... cell -->
                <!-- Go to top link -->
                <div class="col-6 col-lg-1 mb-3 text-end">
                    <a id="linktotop" title="Back to form" alt="Back to form"><i class="fas fa-3x fa-level-up-alt ps-2 mb-2"></i></a>
                </div>
                <!-- End / Go to top link -->
            </div>
        </div>
    </div>
</footer>

<!-- Bootstrap -->
<script type="module">
    import bootstrap from 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.1/+esm';
</script>

<!-- Vendor JS-->
<!--script src="{{ app_vendor_folder }}/select2/select2.min.js"></script-->
<script src="{{ app_vendor_folder }}/jquery-validate/jquery.validate.min.js"></script>
<script src="{{ app_vendor_folder }}/bootstrap-wizard/jquery.bootstrap.wizard.min.js"></script>
<script src="{{ app_vendor_folder }}/datepicker/moment.min.js"></script>
<script src="{{ app_vendor_folder }}/datepicker/daterangepicker.js"></script>
<script src="{{ app_vendor_folder }}/autocomplete/bootstrap-autocomplete.js"></script>
<script src="//cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="//cdnjs.cloudflare.com/ajax/libs/ion-rangeslider/2.3.0/js/ion.rangeSlider.min.js"></script>
<script src="//use.fontawesome.com/releases/v6.1.1/js/all.js" type="text/javascript" defer
        integrity="sha384-xBXmu0dk1bEoiwd71wOonQLyH+VpgR1XcDH3rtxrLww5ajNTuMvBdL5SOiFZnNdp"
        crossorigin="anonymous"></script>

<!-- Main JS-->
<script src="{{ app_js_folder }}/global.js"></script>

</body>
</html>

<hr />

<div class="bg-primary-subtle">
    <div class="container my-5 wrapper--w768 rounded-4 bg-light">
        <div class="row align-items-center p-3">
            <div class="hstack gap-3">
                <svg fill="none" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" data-test-id="icon" style="display:inline-block" height="64" width="128"><path d="M23.25 5.25H.75v13.5h22.5V5.25z" fill="url(#EmojiLoveLetter__a)"></path><path d="M12 7.5.75 18.75h22.5L12 7.5Z" fill="#99A8AE"></path><path d="M12 7.95.75 18.75h22.5L12 7.95Z" fill="url(#EmojiLoveLetter__b)"></path><path d="M11.505 14.686.75 5.25h22.5l-10.756 9.436a.75.75 0 0 1-.99 0z" fill="#99A8AE"></path><path d="M11.517 14.295.75 5.25h22.5l-10.768 9.045a.75.75 0 0 1-.965 0z" fill="url(#EmojiLoveLetter__c)"></path><path d="M11.517 14.295.75 5.25h22.5l-10.768 9.045a.75.75 0 0 1-.965 0z" fill="url(#EmojiLoveLetter__d)"></path><path d="M12 9.085c-1.294-2.136-4.875-1.302-4.875 1.38 0 1.8 1.651 3.003 4.875 5.66 3.223-2.657 4.875-3.86 4.875-5.66 0-2.682-3.582-3.516-4.875-1.38z" fill="url(#EmojiLoveLetter__e)"></path><defs><linearGradient id="EmojiLoveLetter__a" x1="12" x2="12" y1="18.75" y2="5.25" gradientUnits="userSpaceOnUse"><stop stop-color="#E6EBED" offset="0"></stop><stop stop-color="#DBE2E5" offset="0.512"></stop><stop stop-color="#CCD5D9" offset="1"></stop></linearGradient><linearGradient id="EmojiLoveLetter__b" x1="12" x2="12" y1="18.75" y2="7.95" gradientUnits="userSpaceOnUse"><stop stop-color="#E6EBED" offset="0"></stop><stop stop-color="#E3E9EB" offset="0.26"></stop><stop stop-color="#DAE1E4" offset="0.454"></stop><stop stop-color="#CFD8DD" offset="0.576"></stop></linearGradient><linearGradient id="EmojiLoveLetter__c" x1="12" x2="12" y1="14.153" y2="-19.352" gradientUnits="userSpaceOnUse"><stop stop-color="#B9C2C7" offset="0"></stop><stop stop-color="#C2CACF" offset="0.046"></stop><stop stop-color="#DCE2E5" offset="0.203"></stop><stop stop-color="#E6EBED" offset="0.297"></stop></linearGradient><linearGradient id="EmojiLoveLetter__d" x1="12" x2="12" y1="14.47" y2="5.25" gradientUnits="userSpaceOnUse"><stop stop-color="#E6EBED" offset="0"></stop><stop stop-color="#E3E9EC" offset="0.582"></stop><stop stop-color="#DAE3E8" offset="1"></stop></linearGradient><linearGradient id="EmojiLoveLetter__e" x1="12" x2="12" y1="16.124" y2="7.875" gradientUnits="userSpaceOnUse"><stop stop-color="#DB0100" offset="0"></stop><stop stop-color="#F31317" offset="0.584"></stop><stop stop-color="#FF1C23" offset="1"></stop></linearGradient></defs></svg>
                Leave the mail, or you will be left without cool letters about travel
                <input type="text" aria-label="Last name" class="form-control me-auto" placeholder="Add your e-mail here...">
                <button type="button" class="btn btn-outline-success">
                    <i class="fa-solid fa-check"></i>
                </button>
            </div>
        </div>
    </div>
</div>

<footer class="footer mt-auto py-3 bg-dark text-light-emphasis">
    <div class="container">
        <div class="container py-4 py-md-5 px-4 px-md-3">
            <div class="row">
                <!-- Info cell -->
                <div class="col-lg-4">
                    <a href="/"
                       class="d-flex align-items-center mb-2 mb-lg-0 text-white text-decoration-none me-5 pb-3">
                        <i class="fas fa-2x fa-plane-departure pe-3"></i>
                        <strong class="lead">{{ app_name }}</strong>
                    </a>
                    <ul class="list-unstyled small">
                        <li>
                            Software made by <a href="{{ app_author_website }}" target="_blank" class="link-light">{{ app_author_name }}</a>
                            as <a href="https://www.flighthub.com/" target="_blank" class="link-light">FlightHub</a>
                            PHP Coding <a href="Trip_Builder_1.pdf" target="_blank" class="link-light">Assessment</a>.
                        </li>
                        <li>
                            Code licensed <a href="{{ app_license_url }}" target="_blank" class="link-light" rel="license noopener">{{ app_license_type }}</a>,
                            documentation are available <a href="{{ app_documentation_url }}" target="_blank" class="link-light">here</a>.
                        </li>
                        <li class="my-2">
                            Generated in <code>{{ execution_time }} seconds</code>,
                            DB requests: <code>{{ database_requests }}</code>, flights in DB: <code>{{ flights_count }}</code>
                        </li>
                        <li>Currently {{ app_version }}</li>
                        <li class="my-2">
                            <i class="far fa-lg fa-copyright pe-1"></i>{{ copyright_years }}
                        </li>
                    </ul>
                </div>
                <!-- End / Info cell -->
                <!-- Main menu cell -->
                <div class="col-6 col-lg-2 offset-lg-1 mb-4 text-white">
                    <h5>Navigation</h5>
                    <ul class="list-unstyled pt-3">
                        {{ main_menu }}
                    </ul>
                </div>
                <!-- End / Main menu cell -->
                <!-- ... cell -->
                <div class="col-6 col-lg-2 mb-4 text-white">
                    <h5>Repository</h5>
                    <ul class="list-unstyled pt-3">
                        {{ git_menu }}
                    </ul>
                </div>
                <!-- End / ... cell -->
                <!-- ... cell -->
                <div class="col-6 col-lg-2 mb-3 text-white">
                    <h5>Socials</h5>
                    <ul class="list-unstyled pt-3">
                        {{ social_menu }}
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
<!--script src="{{ app_vendor_folder }}/datepicker/moment.min.js"></script>
<script src="{{ app_vendor_folder }}/datepicker/daterangepicker.js"></script-->
<script src="//cdn.jsdelivr.net/momentjs/latest/moment.min.js" type="text/javascript"></script>
<script src="//cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.min.js" type="text/javascript"></script>
<script src="{{ app_vendor_folder }}/autocomplete/bootstrap-autocomplete.js"></script>
<script src="//cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="//cdnjs.cloudflare.com/ajax/libs/ion-rangeslider/2.3.0/js/ion.rangeSlider.min.js"></script>
<script src="//use.fontawesome.com/releases/v6.1.1/js/all.js" type="text/javascript" defer integrity="sha384-xBXmu0dk1bEoiwd71wOonQLyH+VpgR1XcDH3rtxrLww5ajNTuMvBdL5SOiFZnNdp" crossorigin="anonymous"></script>
<script src="{{ app_js_folder }}/global.js"></script>

</body>
</html>

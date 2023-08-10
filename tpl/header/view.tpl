<!DOCTYPE html>
<html lang="en" class="h-100">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
    <meta name="description" content="{{ app-name }} – {{ app-meta-description }}" />
    <meta name="keywords" content="{{ app-name }}, {{ app-meta-keywords }}" />
    <meta name="author" content="{{ app-meta-author-name }} <{{ app-meta-author-email }}>"/>

    <title>{{ page-title}} - {{ app-name }}</title>

    <!-- Icons font CSS-->
    <link href="/frontend/vendor/mdi-font/css/material-design-iconic-font.min.css" rel="stylesheet" media="all" />
    <link href="//use.fontawesome.com/releases/v6.1.1/css/all.css" rel="stylesheet" type='text/css'
          integrity="sha384-/frq1SRXYH/bSyou/HUp/hib7RVN1TawQYja658FEOodR/FQBKVqT9Ol+Oz3Olq5" crossorigin="anonymous" />

    <!-- Bootstrap -->
    <link href="//cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-GLhlTQ8iRABdZLl6O3oVMWSktQOp6b7In1Zl3/Jr59b6EGGoI1aFkw7cmDA6j6gD" crossorigin="anonymous" />

    <!-- Vendor CSS-->
    <link href="/frontend/vendor/select2/select2.min.css" rel="stylesheet" media="all" />
    <link href="/frontend/vendor/datepicker/daterangepicker.css" rel="stylesheet" media="all" />
    <link href="//cdnjs.cloudflare.com/ajax/libs/ion-rangeslider/2.3.0/css/ion.rangeSlider.min.css" rel="stylesheet" />

    <!-- Main CSS-->
    <link href="/frontend/css/main.css" rel="stylesheet" media="all" />

    <!-- Jquery JS-->
    <script src="//code.jquery.com/jquery-3.3.1.min.js"></script>

    <!-- {{ metric-counters }} -->
</head>

<body class="bg-light d-flex flex-column h-100">
<header class="p-3 bg-dark text-white" id="top">
    <div class="container">
        <div class="d-flex flex-wrap align-items-center justify-content-center justify-content-lg-start">
            <!-- Header logo -->
            <a href="/" class="d-flex align-items-center mb-2 mb-lg-0 text-white text-decoration-none me-5">
                <i class="fas fa-2x fa-plane-departure pe-3"></i>
                <strong class="lead">{{ app-name }}</strong>
            </a>
            <!-- End / Header logo -->
            <!-- Main menu items -->
            <ul class="nav col-12 col-lg-auto me-lg-auto mb-2 justify-content-center mb-md-0">
                {{ menu-items }}
            </ul>
            <!-- End / Main menu items -->
            <!-- Right menu items -->

            <!-- Right menu items -->
            <div class="text-end">
                <button type="button" class="btn btn-dark empty-link text-white">
                    <img src="/frontend/images/user/avatar-01.jpg" width="32" class="rounded-circle me-2"
                         alt="User profile avatar"/>
                    User profile
                </button>
                <button type="button" class="btn btn-dark empty-link text-white">
                    <i class="fas fa-lg fa-globe"></i>
                </button>
            </div>
            <!-- Right menu items -->
        </div>
    </div>
</header>

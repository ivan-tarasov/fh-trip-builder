<!DOCTYPE html>
<html lang="en" class="h-100">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
    <meta name="description" content="{{ app_name }} – {{ app_meta_description }}" />
    <meta name="keywords" content="{{ app_name }}, {{ app_meta_keywords }}" />
    <meta name="author" content="{{ app_meta_author_name }} <{{ app_meta_author_email }}>"/>

    <title>{{ page_title}} - {{ app_name }}</title>

    <link href="{{ app_vendor_folder }}/mdi-font/css/material-design-iconic-font.min.css" rel="stylesheet" media="all" />
    <link href="{{ app_vendor_folder }}/datepicker/daterangepicker.css" rel="stylesheet" media="all" />
    <link href="//cdnjs.cloudflare.com/ajax/libs/ion-rangeslider/2.3.0/css/ion.rangeSlider.min.css" rel="stylesheet" />
    <link href="//use.fontawesome.com/releases/v6.1.1/css/all.css" rel="stylesheet" type='text/css' integrity="sha384-/frq1SRXYH/bSyou/HUp/hib7RVN1TawQYja658FEOodR/FQBKVqT9Ol+Oz3Olq5" crossorigin="anonymous" />
    <link href="//cdn.jsdelivr.net/npm/bootstrap@5.3.1/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="{{ app_css_folder }}/main.css" rel="stylesheet" media="all" />

    <script src="//code.jquery.com/jquery-3.3.1.min.js"></script>

    {{ metric_counters }}
</head>

<body class="bg-light d-flex flex-column h-100">
<header class="p-3 bg-dark text-white" id="top">
    <div class="container">
        <div class="d-flex flex-wrap align-items-center justify-content-center justify-content-lg-start">
            <!-- Header logo -->
            <a href="/" class="d-flex align-items-center mb-2 mb-lg-0 text-white text-decoration-none me-5">
                <i class="fas fa-2x fa-plane-departure pe-3"></i>
                <strong class="lead">{{ app_name }}</strong>
            </a>
            <!-- End / Header logo -->
            <!-- Main menu items -->
            <ul class="nav col-12 col-lg-auto me-lg-auto mb-2 justify-content-center mb-md-0">
                {{ menu_items }}
            </ul>
            <!-- End / Main menu items -->
            <!-- Right menu items -->

            <!-- Right menu items -->
            <div class="text-end">
                <button type="button" class="btn btn-dark empty-link text-white">
                    <img src="{{ user_avatar }}" width="32" class="rounded-circle me-2" alt="User profile avatar"/>
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

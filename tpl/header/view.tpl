<!DOCTYPE html>
<html lang="en" class="h-100">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <meta name="description" content="Trip Builder – FlightHub PHP Coding Assignment">
  <meta name="author" content="Ivan Tarasov <ivan@tarasov.ca>" />
  <meta name="keywords" content="Trip Builder, FlightHub, assignment, php">

  <title>{{PAGE_TITLE}} - Trip Builder</title>

  <!-- Icons font CSS-->
  <link href="frontend/mdi-font/css/material-design-iconic-font.min.css" rel="stylesheet" media="all">
  <link href="//use.fontawesome.com/releases/v6.1.1/css/all.css" rel="stylesheet" type='text/css' integrity="sha384-/frq1SRXYH/bSyou/HUp/hib7RVN1TawQYja658FEOodR/FQBKVqT9Ol+Oz3Olq5" crossorigin="anonymous" />

  <!-- Bootstrap -->
  <!-- <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css" integrity="sha384-ggOyR0iXCbMQv3Xipma34MD+dH/1fQ784/j6cY/iJTQUOhcWr7x9JvoRxT2MZw1T" crossorigin="anonymous"> -->
  <!-- <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css" integrity="sha384-xOolHFLEh07PJGoPkLv1IbcEPTNtaed2xpHsD9ESMhqIYd0nLMwNLD69Npy4HI+N" crossorigin="anonymous"> -->
  <!-- <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-rbsA2VBKQhggwzxH7pPCaAqO46MgnOM80zW1RWuH61DGLwZJEdK2Kadq2F9CUG65" crossorigin="anonymous"> -->
  <link href="//cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-GLhlTQ8iRABdZLl6O3oVMWSktQOp6b7In1Zl3/Jr59b6EGGoI1aFkw7cmDA6j6gD" crossorigin="anonymous">

  <!-- Vendor CSS-->
  <link href="frontend/select2/select2.min.css" rel="stylesheet" media="all">
  <link href="frontend/datepicker/daterangepicker.css" rel="stylesheet" media="all">
  <link href="//cdnjs.cloudflare.com/ajax/libs/ion-rangeslider/2.3.0/css/ion.rangeSlider.min.css" rel="stylesheet" />

  <!-- Main CSS-->
  <link href="css/main.css" rel="stylesheet" media="all">

  <!-- Jquery JS-->
  <script src="//code.jquery.com/jquery-3.3.1.min.js"></script>

  <!-- {{METRIKA_COUNTERS}} -->
</head>

<body class="bg-light d-flex flex-column h-100">
  <header class="p-3 bg-dark text-white" id="top">
    <div class="container">
      <div class="d-flex flex-wrap align-items-center justify-content-center justify-content-lg-start">
        <!-- Header logo -->
        <a href="/" class="d-flex align-items-center mb-2 mb-lg-0 text-white text-decoration-none me-5">
          <i class="fas fa-2x fa-plane-departure pe-3"></i>
          <strong class="lead">Trip Builder</strong>
        </a>
        <!-- End / Header logo -->
        <!-- Main menu items -->
        <ul class="nav col-12 col-lg-auto me-lg-auto mb-2 justify-content-center mb-md-0">
          {{HEADER_MENU_ITEMS}}
        </ul>
        <!-- End / Main menu items -->
        <!-- Right menu items -->

        <!-- Right menu items -->
        <div class="text-end">
          <button type="button" class="btn btn-dark empty-link text-white">
            <img src="/images/user/avatar-01.jpg" width="32" class="rounded-circle me-2" alt="User profile avatar" />
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

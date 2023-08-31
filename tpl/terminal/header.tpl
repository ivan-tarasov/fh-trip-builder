<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <meta name="description" content="Trip Builder – FlightHub PHP Coding Assessment">
  <meta name="author" content="Ivan Tarasov <ivan@tarasov.ca>" />
  <meta name="keywords" content="Trip Builder, FlightHub, assessment, php">
  <title>Terminal – Trip Builder</title>
  <link href="//cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-rbsA2VBKQhggwzxH7pPCaAqO46MgnOM80zW1RWuH61DGLwZJEdK2Kadq2F9CUG65" crossorigin="anonymous">
  <link href="//use.fontawesome.com/releases/v6.1.1/css/all.css" rel="stylesheet" type='text/css' integrity="sha384-/frq1SRXYH/bSyou/HUp/hib7RVN1TawQYja658FEOodR/FQBKVqT9Ol+Oz3Olq5" crossorigin="anonymous" />
  <style>
    @import 'https://fonts.googleapis.com/css?family=Inconsolata';
    html { min-height: 100%; }
    body {
      box-sizing: border-box;
      height: 100%;
      background-color: #000000;
      /*background-image: radial-gradient(#11581E, #041607), url("https://media.giphy.com/media/oEI9uBYSzLpBK/giphy.gif");*/
      background-image: radial-gradient(#11581E, #041607);
      background-repeat: no-repeat;
      background-size: cover;
      font-family: 'Inconsolata', Helvetica, sans-serif;
      font-size: 1.5rem;
      color: rgba(128, 255, 128, 0.8);
      text-shadow:
          0 0 1ex rgba(51, 255, 51, 1),
          0 0 2px rgba(255, 255, 255, 0.8);
    }
    .noise {
      pointer-events: none;
      position: absolute;
      width: 100%;
      height: 100%;
      /*background-image: url("https://media.giphy.com/media/oEI9uBYSzLpBK/giphy.gif");*/
      background-repeat: no-repeat;
      background-size: cover;
      z-index: -1;
      opacity: .02;
    }
    .overlay {
      pointer-events: none;
      position: absolute;
      width: 100%;
      height: 100%;
      background:
          repeating-linear-gradient(
          180deg,
          rgba(0, 0, 0, 0) 0,
          rgba(0, 0, 0, 0.3) 50%,
          rgba(0, 0, 0, 0) 100%);
      background-size: auto 4px;
      z-index: 1;
    }
    .overlay::before {
      content: "";
      pointer-events: none;
      position: absolute;
      display: block;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      width: 100%;
      height: 100%;
      background-image: linear-gradient(
          0deg,
          transparent 0%,
          rgba(32, 128, 32, 0.2) 2%,
          rgba(32, 128, 32, 0.8) 3%,
          rgba(32, 128, 32, 0.2) 3%,
          transparent 100%);
      background-repeat: no-repeat;
      animation: scan 7.5s linear 0s infinite;
    }
    @keyframes scan {
      0%        { background-position: 0 -100vh; }
      35%, 100% { background-position: 0 100vh; }
    }
    .terminal {
      box-sizing: inherit;
      position: absolute;
      height: 100%;
      max-width: 100%;
      padding: 4rem;
      /*text-transform: uppercase;*/
    }
    .output {
      margin-bottom: 1em;
      color: rgba(128, 255, 128, 0.8);
      text-shadow:
          0 0 1px rgba(51, 255, 51, 0.4),
          0 0 2px rgba(255, 255, 255, 0.8);
    }
    .output::before { content: "> "; }
    .output.img::before { content: ""; margin-left: 1.1em; }
    a { color: #fff; text-decoration: none; }
    a::before { content: "["; }
    a::after { content: "]"; }
    .strong { color: white; }
  </style>
</head>
<body>
  <main>
    <div class="noise"></div>
    <div class="overlay"></div>
    <div class="terminal">

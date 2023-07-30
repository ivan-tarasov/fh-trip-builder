<h1 class="mb-4">Setup application <span class="strong">Trip Builder</span> [v%SOFTWARE_VERSION%]</h1>
<div class="mb-3">
  <p>This automated setup script prepares the software for work.</p>
  <p>
    First, we need to create the necessary database tables within a <span class="strong">MySQL database</span> and
    fill it with data. Next, we will generate flights data for the <span class="strong">API emulator</span> to
    demonstrate that the application functions correctly and has a visually pleasing design. Please note that the
    duration of the process may vary depending on the number of flights that will be added.
    <span class="strong">Let's begin the process...</span>
  </p>
</div>
<div class="output">
<div class="input-group d-inline">
  How many flights you want to add to DB:
  <input type="text" class="form-control d-inline w-25" id="generate_flights" placeholder="eg. 5000, 20000, 50000" />
</div>(Default: %FLIGHTS_TO_GENERATE%)
</div>

<button id="ajax-button" type="button" class="btn btn-primary btn-lg"><i class="fa-regular fa-lg fa-circle-play me-2"></i>Start setup</button>
<span id="progressbar" class="output d-inline ms-3 d-none">
  Progress: [<span id="progress_bar">______________________________</span>] <span id="progress_percents">0</span>%
</span>

<div id="ajax-output" class="mt-4"></div>

<script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
<script>
$(document).ready(function() {
  function getData(data) {

    const es = new EventSource('ajax.php?generate_flights='+data);

    // a message is received
    es.addEventListener('message', function(e) {
      var result = JSON.parse( e.data );

      //console.log(result);

      if (e.lastEventId == 'ERROR') {
        $('#ajax-output').append(result.message);
        es.close();
      } else if (e.lastEventId == 'CLOSE') {
        window.location.replace('/');
        es.close();
      } else {
        /*var progressbar = result.progress+'%';*/
        if (result.progress !== 0) {
          $('#progress_percents').text(result.progress);
          $('#progress_bar').text(result.progressbar);
        }

        /*
        $('#modalProgressbar')
          .removeClass('bg-success')
          .addClass('progress-bar-striped progress-bar-animated')
          .width(progressbar)
          .html(progressbar);/**/

        if (e.lastEventId != 'START' && result.rtype == 'step')
          $('#ajax-output').append(result.message);
        else
          $('#progress_'+e.lastEventId).append(result.message);


        //$('#ajax-output').scrollTop(100000);
      }

      return false;
    });
  }

  $("#ajax-button").click(function() {
    $(this).addClass('disabled');
    $('#progressbar').removeClass('d-none');

    var data = $('#generate_flights').val();
    setTimeout(function() {
      getData(data);
    }, 1000);
  });
});
</script>

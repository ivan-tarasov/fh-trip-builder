<script>
$( document ).ready(function() {
  var clock_range = [%CLOCK_RANGE%];
  var range = ['%RANGE_FROM_DEPARTING%', '%RANGE_TO_DEPARTING%', '%RANGE_FROM_RETURNING%', '%RANGE_TO_RETURNING%'];

  $("#time_range_departure").ionRangeSlider({
    skin: "round",
    step: 1,
    type: "double",
    grid: false,
    from: clock_range.indexOf(range[0]),
    to: clock_range.indexOf(range[1]),
    values: clock_range
  });

  $("#time_range_returning").ionRangeSlider({
    skin: "round",
    step: 1,
    type: "double",
    grid: false,
    from: clock_range.indexOf(range[2]),
    to: clock_range.indexOf(range[3]),
    values: clock_range
  });

});
</script>

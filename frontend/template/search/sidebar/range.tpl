<script>
$( document ).ready(function() {
  var clock_range = [{{ clock_range }}];
  var range = ['{{ range_depart_from }}', '{{ range_depart_to }}', '{{ range_return_from }}', '{{ range_return_to }}'];

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

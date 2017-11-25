<?php
error_reporting(-1);
ini_set('display_errors', 'On');
date_default_timezone_set('America/New_York');
require_once '../includes/db.php';
require_once '../includes/class.Meter.php';
require_once 'includes/find_nearest.php';
$null_data = true;
$charts = 0;
$colors = ['#00a185', '#bdc3c7', '#33a7ff'];
$_GET['meter0'] = 415;
$_GET['meter1'] = 786;
foreach ($_GET as $key => $value) { // how many charts are there?
  if (substr($key, 0, 5) === 'meter') {
    $charts++;
  }
}
// Each chart at minimium should be a parameter in the query string e.g. meter1=326
for ($i = 0; $i < $charts; $i++) { 
  $var_name = "meter{$i}";
  $$var_name = false;
  $var_name = "dasharr{$i}";
  $$var_name = false;
  $var_name = "fill{$i}";
  $$var_name = false;
  $var_name = "color{$i}";
  $$var_name = false;
}
// other expected GET parameters
$title_img = true;//false;
$title_txt = true;//false;
$start = 0;
$time_frame = 'week';
foreach ($_GET as $key => $value) {
  $$key = $value; // replaced expected GET parameters with their actual value if they're present
}
switch ($time_frame) {
  case 'hour':
    $from = strtotime(date('Y-m-d H') . ':00:00'); // Start of hour
    $to = strtotime(date('Y-m-d H') . ":59:59") + 1; // End of the hour
    $res = 'live';
    $increment = 60;
    break;
  case 'week':
    if (date('w') === '0') { // If it is sunday
      $from = strtotime('this sunday'); // Start of the week
      $to = strtotime('next sunday')-1; // End of the week
    } else {
      $from = strtotime('last sunday'); // Start of the week
      $to = strtotime('next sunday')-1; // End of the week
    }
    $res = 'hour';
    $increment = 3600;
    break;
  default://case 'day':
    $from = strtotime(date('Y-m-d') . " 00:00:00"); // Start of day
    $to = strtotime(date('Y-m-d') . " 23:59:59") + 1; // End of day
    $res = 'quarterhour';
    $increment = 900;
    break;
}
$meter = new Meter($db);
$times = range($from, $to, $increment);
$values = [];
$max = PHP_INT_MIN;
$min = PHP_INT_MAX;
for ($i = 0; $i < $charts; $i++) { 
  $var_name = "meter{$i}";
  if ($$var_name === false) {
    continue;
  }
  $data = $meter->getDataFromTo($$var_name, $from, $to, $res, $null_data);
  if (empty($data)) {
    $$var_name = false;
    continue;
  }
  foreach ($times as $time) {
    $best_guess = find_nearest($data, $time, $null_data);
    $values[$i][] = $best_guess;
    if ($best_guess !== null && $best_guess > $max) {
      $max = $best_guess;
    }
    if ($best_guess !== null && $best_guess < $min) {
      $min = $best_guess;
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <link href="https://fonts.googleapis.com/css?family=Roboto:400,700" rel="stylesheet">
  <link rel="stylesheet" href="style.css">
  <title>Time Series</title>
  <style>
  svg {
    outline: 1px solid red;
    margin-left: 25px;
  }
  .Grid {
    margin-left: 25px;
  }
  </style>
</head>
<body><?php
if ($title_img || $title_txt) {
  echo '<div class="Grid Grid--gutters Grid--center">';
  if ($title_img) {
    echo "<div class='Grid-cell' style='flex: 0 0 8%;padding-left:0px;'><img src='https://placehold.it/150x150' /></div>";
  }
  if ($title_txt) {
    echo "<div class='Grid-cell'><h1 style='display:inline'>Meter name</h1></div>";
  }
  echo '</div>';
}
?>
<svg id="svg"></svg>
<script src="https://cdnjs.cloudflare.com/ajax/libs/d3/4.12.0/d3.min.js"></script>
<script>
'use strict';
var times = <?php echo str_replace('"', '', json_encode(array_map(function($t) {return 'new Date('.($t*1000).')';}, $times))) ?>;
var values = <?php echo json_encode($values) ?>;
var svg_width = Math.max(document.documentElement.clientWidth, window.innerWidth || 0) - 50,
    svg_height = 500;
var svg = d3.select('#svg'),
    margin = {top: 20, right: 200, bottom: 30, left: 50},
    chart_width = svg_width - margin.left - margin.right,
    chart_height = svg_height - margin.top - margin.bottom,
    g = svg.append("g").attr("transform", "translate(" + margin.left + "," + margin.top + ")");
svg.attr('width', svg_width).attr('height', svg_height);
var xScale = d3.scaleTime().domain([times[0], times[times.length-1]]).range([0, chart_width]);
var yScale = d3.scaleLinear().domain([<?php echo $min ?>, <?php echo $max ?>]).range([chart_height, 0]); // fixed domain for each chart that is the global min/max
var lineGenerator = d3.line()
  .defined(function(d) { return d !== null; })
  .x(function(d, i) { return xScale(times[i]); })
  .y(yScale)
  .curve(d3.curveCardinal);
values.forEach(function(curve, i) {
  var line = lineGenerator(curve);
  g.append('path').attr('d', line).attr("fill", "none")
      .attr("stroke", "steelblue")
      .attr("stroke-linejoin", "round")
      .attr("stroke-linecap", "round")
      .attr("stroke-width", 1.5);
});
svg.append("g")
  .attr("transform", "translate(0," + chart_height + ")")
  .call(d3.axisBottom(xScale))
svg.append("g")
  .call(d3.axisLeft(y));
</script>
</body>
</html>
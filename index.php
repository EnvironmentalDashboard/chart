<?php
error_reporting(-1);
ini_set('display_errors', 'On');
date_default_timezone_set('America/New_York');
require_once '../includes/db.php';
require_once '../includes/class.Meter.php';
require_once 'includes/find_nearest.php';
$null_data = true;
$now = time();
$charts = 0;
$colors = ['#00a185', '#bdc3c7', '#33a7ff'];
$_GET['meter0'] = 415;
$_GET['meter1'] = 411;
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
$time_frame = 'day';
foreach ($_GET as $key => $value) {
  $$key = $value; // replaced expected GET parameters with their actual value if they're present
}
switch ($time_frame) {
  case 'hour':
    $from = strtotime(date('Y-m-d H') . ':00:00'); // Start of hour
    $to = strtotime(date('Y-m-d H') . ":59:59") + 1; // End of the hour
    $res = 'live';
    $increment = 60;
    $xaxis_format = '%I:%M %p';
    $pct_thru = ($now - $from) / 3600;
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
    $xaxis_format = '%d %b %I %p';
    $pct_thru = ($now - $from) / 604800;
    break;
  default://case 'day':
    $from = strtotime(date('Y-m-d') . " 00:00:00"); // Start of day
    $to = strtotime(date('Y-m-d') . " 23:59:59") + 1; // End of day
    $res = 'quarterhour';
    $increment = 900;
    $xaxis_format = '%I:%M %p';
    $pct_thru = ($now - $from) / 86400;
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
    /*outline: 1px solid red;*/
    margin-left: 25px;
  }
  .Grid {
    margin-left: 25px;
  }
  .x.axis path {
    display: none;
  }
  .overlay {
    fill: none;
    pointer-events: all;
  }
  circle {
    fill: red;
  }
  svg {
    outline: 1px solid red
  }
  rect {
    fill: none;
    cursor: crosshair;
    pointer-events: all;
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
<svg id="svg"><image id="charachter" xlink:href="https://oberlindashboard.org/oberlin/time-series/images/main_frames/frame_18.gif"></image></svg>
<script src="https://cdnjs.cloudflare.com/ajax/libs/d3/4.12.0/d3.min.js"></script>
<script>
'use strict';
var times = <?php echo str_replace('"', '', json_encode(array_map(function($t) {return 'new Date('.($t*1000).')';}, $times))) ?>;
var values = <?php echo json_encode($values) ?>;
var svg_width = Math.max(document.documentElement.clientWidth, window.innerWidth || 0) - 50,
    svg_height = 500;
var charachter_width = svg_width/6,
    charachter = document.getElementById('charachter');
charachter.setAttribute('x', svg_width - charachter_width);
charachter.setAttribute('width', charachter_width);
var color = d3.scaleOrdinal(d3.schemeCategory10);
var svg = d3.select('#svg'),
    margin = {top: 0, right: charachter_width, bottom: 20, left: 40},
    chart_width = svg_width - margin.left - margin.right,
    chart_height = svg_height - margin.top - margin.bottom,
    g = svg.append("g").attr("transform", "translate(" + margin.left + "," + margin.top + ")");
svg.attr('width', svg_width).attr('height', svg_height);
var xScale = d3.scaleTime().domain([times[0], times[times.length-1]]).range([0, chart_width]);
var yScale = d3.scaleLinear().domain([<?php echo $min ?>, <?php echo $max ?>]).range([chart_height, 0]); // fixed domain for each chart that is the global min/max
// draw lines
var lineGenerator = d3.line()
  .defined(function(d) { return d !== null; }) // points are only defined if they are not null
  .x(function(d, i) { return xScale(times[i]); }) // x coord
  .y(yScale) // y coord
  .curve(d3.curveCatmullRom); // smoothing
var current_path = null;
values.forEach(function(curve, i) {
  // draw curve for each array in values
  var line = lineGenerator(curve);
  var path = g.append('path').attr('d', line);
  if (i === 0) {
    current_path = path;
  }
  path.attr("fill", "none").attr("stroke", color(i))
    .attr("stroke-linejoin", "round")
    .attr("stroke-linecap", "round")
    .attr("stroke-width", 2);
});
// create x and y axis
var xaxis = d3.axisBottom(xScale).ticks(10, '<?php echo $xaxis_format ?>');
var yaxis = d3.axisLeft(yScale).ticks(8);
svg.append("g")
  .call(xaxis)
  .attr("transform", "translate("+margin.left+"," + (chart_height+margin.top) + ")");
svg.append("g")
  .call(yaxis)
  .attr("transform", "translate("+margin.left+","+margin.top+")");
// indicator ball
var circle = svg.append("circle")
    .attr("cx", -10)
    .attr("cy", -10)
    .attr("transform", "translate("+margin.left+"," + margin.top + ")")
    .attr("r", 4);
svg.append("rect") // circle moves when mouse is over this rect
    .attr("width", chart_width)
    .attr("height", chart_height)
    .attr("transform", "translate("+margin.left+"," + margin.top + ")")
    .on("mousemove", mousemoved);
function mousemoved() {
  var p = closestPoint(current_path.node(), d3.mouse(this));
  circle.attr("cx", p['x']).attr("cy", p['y']); // set the circle to be the mouse's x coord and the curves y coord
}
function closestPoint(pathNode, point) {
  // https://stackoverflow.com/a/12541696/2624391
  // var mouseDate = xScale.invert(point[0]);
  var x_pct = point[0] / (chart_width * <?php echo $pct_thru ?> );
  var pathLength = pathNode.getTotalLength();
  var BBox = pathNode.getBBox();
  var scale = pathLength/BBox.width;
  var beginning = point[0], end = pathLength, target, pos;
  while (true) {
    target = Math.floor((beginning + end) / 2);
    pos = pathNode.getPointAtLength(target);
    if ((target === end || target === beginning) && pos.x !== point[0]) {
      break;
    }
    if (pos.x > point[0]) {
      end = target;
    }
    else if (pos.x < point[0]) {
      beginning = target;
    }
    else {
      break; //position found
    }
  }
  return pos
}
</script>
</body>
</html>
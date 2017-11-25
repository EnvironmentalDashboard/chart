<?php
error_reporting(-1);
ini_set('display_errors', 'On');
date_default_timezone_set('America/New_York');
require_once '../includes/db.php';
require_once '../includes/class.Meter.php';
require_once 'includes/find_nearest.php';
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
  $data = $meter->getDataFromTo($$var_name, $from, $to, $res, false);
  if (empty($data)) {
    $$var_name = false;
    continue;
  }
  foreach ($times as $time) {
    $best_guess = find_nearest($data, $time);
    $values[$i][] = $best_guess;
    if ($best_guess > $max) {
      $max = $best_guess;
    }
    if ($best_guess < $min) {
      $min = $best_guess;
    }
  }
}
// https://codepen.io/beacrea/pen/reywxz?q=d3%20line%20graph&order=popularity&depth=everything&show_forks=false
// http://bl.ocks.org/cgroll/c5e7bdb5dffb12818623
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <link href="https://fonts.googleapis.com/css?family=Roboto:400,700" rel="stylesheet">
  <link rel="stylesheet" href="style.css">
  <title>Time Series</title>
  <style>
#svg-container {
  box-shadow: 0 0 0 1px #eee;
  box-sizing: border-box;
}

path,
line {
  fill: none;
  shape-rendering: crispEdges;
  stroke: #E8E8E8;
}

.path-line {
  shape-rendering: initial;
}
.path-line.path-line1 {
  stroke: #0baadd;
}
.path-line.path-line2 {
  stroke: #47cf73;
}

.path-area {
  stroke: none;
}
.path-area.path-area1 {
  fill: rgba(11, 170, 221, 0.7);
  stroke: rgba(11, 170, 221, 0.7);
}
.path-area.path-area2 {
  fill: rgba(71, 207, 115, 0.55);
  stroke: rgba(71, 207, 115, 0.55);
}

.tick text {
  font-family: 'Roboto', sans-serif;
  font-size: 10px;
  fill: #999;
}

  </style>
</head>
<body><?php
if ($title_img || $title_txt) {
  echo '<div class="Grid Grid--gutters Grid--center">';
  if ($title_img) {
    echo "<div class='Grid-cell' style='flex: 0 0 8%;'><img src='https://placehold.it/150x150' /></div>";
  }
  if ($title_txt) {
    echo "<div class='Grid-cell'><h1 style='display:inline'>Meter name</h1></div>";
  }
  echo '</div>';
}
?>
<div id="svg-container" style="width:100%">
  <svg id="svg"></svg>
</div>
<script src="https://d3js.org/d3.v4.min.js"></script>
<script>
'use strict';

// forked from https://codepen.io/vincentbollaert/pen/MKxJGj
var graphContainer = d3.select('#svg-container');
var svg = d3.select('#svg');
var margin = { top: 50, right: 50, bottom: 50, left: 50 };
var duration = 500;
var width = undefined,
    height = undefined,
    innerWidth = undefined,
    innerHeight = undefined;
var xScale = undefined,
    yScale = undefined;

var times = <?php echo str_replace('"', '', json_encode(array_map(function($t) {return 'new Date('.($t*1000).')';}, $times))) ?>;
var values = <?php echo json_encode($values) ?>;

(function init() {
  getDimentions();
  getScaleDomains();
  getScaleRanges();
  renderGraph(values, times);
})();

d3.select(window).on('resize', resize);

function resize() {
  destroyGraph();
  getDimentions();
  getScaleRanges();
  renderGraph(values, times);
}

function renderGraph(values, times) {
  var line = d3.line().x(function (d, i) {
    return xScale(times[i]);
  }).y(function (d) {
    return yScale(d);
  }).curve(d3.curveCatmullRom.alpha(0.5));

  var area = d3.area().x(function (d, i) {
    return xScale(times[i]);
  }).y0(innerHeight).y1(function (d) {
    return yScale(d);
  }).curve(d3.curveCatmullRom.alpha(0.5));

  // var xAxis = d3.axisBottom(xScale).tickFormat(function (d, i) {
  //   return times[i];
  // });
  var xAxis = d3.axisBottom(xScale).ticks(d3.timeMinute.every(15));//.tickFormat(d3.timeMinute.every(15), '%a %d %I:%M'); // https://github.com/d3/d3-scale/blob/master/README.md#time_ticks

  var yAxis = d3.axisLeft(yScale).ticks(4);

  svg.attr('width', width).attr('height', height);

  var inner = svg.selectAll('g.inner').data([null]);
  inner.exit().remove();
  inner.enter().append('g').attr('class', 'inner').attr('transform', 'translate(' + margin.top + ', ' + margin.right + ')');

  var xa = svg.selectAll('g.inner').selectAll('g.x.axis').data([null]);
  xa.exit().remove();
  xa.enter().append('g').attr('class', 'x axis').attr('transform', 'translate(0, ' + innerHeight + ')').call(xAxis);

  var ya = svg.selectAll('g.inner').selectAll('g.y.axis').data([null]);
  ya.exit().remove();
  ya.enter().append('g').attr('class', 'y axis').call(yAxis);


  values.forEach(function(curve, i) {
    var pathLine = svg.selectAll('g.inner').selectAll('.path-line' + i).data([null]);
    pathLine.exit().remove();
    pathLine.enter().append('path').attr('class', 'path-line path-line' + i).attr('d', function () {
      return line(createZeroDataArray(curve));
    }).transition().duration(duration).ease(d3.easePoly.exponent(2)).attr('d', function () {
      return line(curve);
    });

    var pathArea = svg.selectAll('g.inner').selectAll('.path-area' + i).data([null]);
    pathArea.exit().remove();
    pathArea.enter().append('path').attr('class', 'path-area path-area' + i).attr('d', function () {
      return area(createZeroDataArray(curve));
    }).transition().duration(duration).ease(d3.easePoly.exponent(2)).attr('d', area(curve));
  });
}

function getDimentions() {
  width = graphContainer.node().clientWidth;
  height = 500;
  innerWidth = width - margin.left - margin.right;
  innerHeight = height - margin.top - margin.bottom;
}

function getScaleRanges() {
  xScale.range([0, innerWidth]).paddingInner(1);
  yScale.range([innerHeight, 0]).nice();
}

function getScaleDomains() {
  xScale = d3.scaleBand().domain([times[0], times[times.length-1]]);
  // xScale = d3.scaleTime().domain([times[0], times[times.length-1]]);
  yScale = d3.scaleLinear().domain([<?php echo $min ?>, <?php echo $max ?>]);
}

function destroyGraph() {
  svg.selectAll('*').remove();
}

function createZeroDataArray(arr) {
  var newArr = [];
  arr.forEach(function (item, index) {
    return newArr[index] = 0;
  });
  return newArr;
}
</script>
</body>
</html>
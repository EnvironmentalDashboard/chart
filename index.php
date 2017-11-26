<?php
error_reporting(-1);
ini_set('display_errors', 'On');
date_default_timezone_set('America/New_York');
require_once '../includes/db.php';
require_once '../includes/class.Meter.php';
require_once 'includes/find_nearest.php';
require_once 'includes/median.php';
require_once 'includes/change_res.php';
define('NULL_DATA', true); // set to false to fill in data
$meter = new Meter($db); // has methods to get data from db easily
$now = time();
if (!isset($_GET['meter0'])) { // at minimum this script needs a meter id to chart
  $_GET['meter0'] = 415; // default meter
}
$charts = 0;
foreach ($_GET as $key => $value) { // count the number of charts
  if (substr($key, 0, 5) === 'meter') {
    $charts++;
  }
}
// each chart should be a parameter in the query string e.g. meter1=326 along optional other customizable variables
for ($i = 0; $i < $charts; $i++) { // define the variables to be extract()'d
  $var_name = "meter{$i}";
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
extract($_GET, EXTR_IF_EXISTS); // imports GET array into the current symbol table (i.e. makes each entry of GET a variable) if the variable already exists
// fish or squirrel?
$units0 = $meter->getUnits($meter0);
if ($units0 === 'Gallons / hour' || $units0 === 'Liters / hour' || $units0 === 'Liters' || $units0 === 'Milligrams per liter' || $units0 === 'Gallons per minute') {
  $charachter = 'fish';
  $number_of_frames = 49;
} else {
  $charachter = 'squirrel';
  $number_of_frames = 46;
}
// which time scale?
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
$times = range($from, $to, $increment); // each array in $values should be count($times) long such that the float in the $values array corresponds to the time in the $times array with the same index
$values = [];
$max = PHP_INT_MIN;
$min = PHP_INT_MAX;
// get data from db for each chart and format so that it matches $times
for ($i = 0; $i < $charts; $i++) { 
  $var_name = "meter{$i}";
  if ($$var_name === false) {
    continue;
  }
  $data = $meter->getDataFromTo($$var_name, $from, $to, $res, NULL_DATA);
  if (empty($data)) {
    $$var_name = false;
    continue;
  }
  foreach ($times as $time) {
    $best_guess = find_nearest($data, $time, NULL_DATA);
    $values[$i][] = $best_guess;
    if ($best_guess !== null && $best_guess > $max) {
      $max = $best_guess;
    }
    if ($best_guess !== null && $best_guess < $min) {
      $min = $best_guess;
    }
  }
}
// calculate the typical line
$orb_values = [];
// print_r($values);
if ($time_frame === 'day' || $time_frame === 'week') {
  $num_points = count($times);
  // See if a configuration for the relative data exists in the db, and if not, have a default
  $stmt = $db->prepare('SELECT relative_values.grouping FROM relative_values INNER JOIN meters ON meters.id = ? LIMIT 1');
  $stmt->execute([$meter0]);
  $json = $stmt->fetchColumn();
  if (strlen($json) > 0) {
    $json = json_decode($json, true);
  } else {
    $json = json_decode('[{"days":[1,2,3,4,5],"npoints":8},{"days":[1,7],"npoints":5}]', true);
  }
  $day_of_week = date('w') + 1;
  foreach ($json as $grouping) {
    if (in_array($day_of_week, $grouping['days'])) {
      $days = $grouping['days']; // The array that has the current day in it
      $npoints = (array_key_exists('npoints', $grouping) ? $grouping['npoints'] : 5); // you can only use npoints, not start
      break;
    }
  }

  $prev_lines = array_fill(0, $npoints, []); // holds npoints arrays that each represent a line
  $prev_linesi = 0;
  $typical_line = []; // formed by taking the median of each sub array value in $prev_lines
  $last = null;
  if ($time_frame === 'day') {
    $stmt = $db->prepare( // get npoints days worth of data
    'SELECT value, recorded FROM meter_data
    WHERE meter_id = ? AND value IS NOT NULL AND resolution = ?
    AND DAYOFWEEK(FROM_UNIXTIME(recorded)) IN ('.implode(',', $days).')
    ORDER BY recorded DESC LIMIT ' . intval($npoints)*24*4); // npoints*24*4 = 4 points per hour, 24 per day
    $stmt->execute([$meter0, 'quarterhour']);
    foreach (array_reverse($stmt->fetchAll()) as $row) { // need to order by DESC for the LIMIT to select the most recent records but actually we want it to be ASC
      $day_of_week = date('w', $row['recorded']);
      if ($last !== $day_of_week && $last !== null) {
        ++$prev_linesi;
      }
      $prev_lines[$prev_linesi][] = (float) $row['value'];
      $last = $day_of_week;
    }
    for ($i=0; $i < $npoints; $i++) { // make sure all arrays are same size
      $prev_lines[$i] = change_res($prev_lines[$i], $num_points);
    }
    $sec = $from;
    $inc = ($to - $from) / $num_points;
    for ($i=0; $i < $num_points; $i++) { 
      $array_val = [];
      for ($j=0; $j < $npoints; $j++) { 
        $array_val[] = $prev_lines[$j][$i];
      }
      $cur = $values[0][$i];
      $rv = $number_of_frames - Meter::relativeValue($array_val, $cur, 0, $number_of_frames);
      $orb_values[] = round($rv);
      $median = median($array_val);
      $typical_line[$i] = $median;
      if ($median > $max) {
        $max = $median;
      }
      if ($median < $min) {
        $min = $median;
      }
      $sec += $inc;
    }
  } else { // week
    $stmt = $db->prepare( // https://stackoverflow.com/a/7786588/2624391
    'SELECT value, recorded FROM meter_data
    WHERE meter_id = ? AND value IS NOT NULL AND resolution = ?
    ORDER BY recorded DESC LIMIT ' . $npoints*24*7);
    $stmt->execute([$meter0, 'hour']);
    // echo "<!--";
    foreach (array_reverse($stmt->fetchAll()) as $row) { // need to reorder for same reason as above
      $day_of_week = date('w', $row['recorded']);
      if ($day_of_week == '0' && $day_of_week !== $last && $last !== null) {
        ++$prev_linesi;
      }
      $prev_lines[$prev_linesi][] = (float) $row['value'];
      $last = $day_of_week;
    }
    $npoints = $prev_linesi;
    for ($i=0; $i < $npoints; $i++) { // make sure all arrays are same size
      $prev_lines[$i] = change_res($prev_lines[$i], $num_points);
    }
    $sec = $from;
    $inc = ($to - $from) / $num_points;
    for ($i=0; $i < $num_points; $i++) { 
      $array_val = [];
      for ($j=0; $j < $npoints; $j++) { 
        $array_val[] = $prev_lines[$j][$i];
      }
      $cur = $values[0][$i];
      $rv = $number_of_frames - Meter::relativeValue($array_val, $cur, 0, $number_of_frames);
      $orb_values[] = round($rv);
      $median = median($array_val);
      $typical_line[$i] = $median;
      // echo "\n\n\n\n\nIteration $i\n";
      // print_r($array_val);
      // echo "\n{$rv}\n";
      // var_dump($cur);
      if ($median > $max) {
        $max = $median;
      }
      if ($median < $min) {
        $min = $median;
      }
      $sec += $inc;
    }
  }
  $prev_lines = null; // this is a pretty big variable, free for gc
  // foreach ($prev_lines as $l) {
  //   $values[] = $l;
  // }
  // $values[] = $orb_values;
  $values[] = $typical_line;
}
// die();
parse_str($_SERVER['QUERY_STRING'], $qs);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <link href="https://fonts.googleapis.com/css?family=Roboto:400,700" rel="stylesheet">
  <link rel="stylesheet" href="style.css?v=4">
  <title>Time Series</title>
  <style>
  svg {
    /*outline: 1px solid red;*/
    margin: 40px 0px 0px 25px;
    /*padding: 20px 0px 20px 0px;*/
  }
  .Grid {
    margin-left: 25px;
  }
  .domain {
    display: none;
  }
  .overlay {
    fill: none;
    pointer-events: all;
  }
  rect {
    fill: none;
    cursor: crosshair;
    pointer-events: all;
  }
  .btn {
    text-decoration: none;
    padding: 7px 20px;
    margin: 20px 15px 50px 0px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.12), 0 1px 2px rgba(0,0,0,0.24);
    /*color: #333;*/
    color: #fff;
    border-radius: 2px;
    background: #3498db;
  }
  .dropdown {
    display: none
  }
  text {
    stroke: #333;
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
    echo "<div class='Grid-cell'><h1 style='display:inline'>".$meter->getBuildingName($meter0).' '.$meter->getName($meter0)."</h1></div>";
  }
  echo '</div>';
}
?>
<div class="Grid" style="display:flex;justify-content: space-between;">
  <div>
    <a href="?<?php echo http_build_query(array_replace($qs, ['time_frame' => 'hour'])); ?>" class="btn">Graph overlay</a>
  </div>
  <div>
    <a href="?<?php echo http_build_query(array_replace($qs, ['time_frame' => 'hour'])); ?>" class="btn">Hour</a>
    <a href="?<?php echo http_build_query(array_replace($qs, ['time_frame' => 'day'])); ?>" class="btn">Day</a>
    <a href="?<?php echo http_build_query(array_replace($qs, ['time_frame' => 'week'])); ?>" class="btn">Week</a>
  </div>
  <div><?php 
    foreach ($db->query('SELECT id, name FROM meters WHERE scope != \'Whole Building\'
      AND building_id IN (SELECT building_id FROM meters WHERE id = '.intval($meter0).')
      AND ((gauges_using > 0 OR for_orb > 0 OR timeseries_using > 0)
      OR bos_uuid IN (SELECT DISTINCT meter_uuid FROM relative_values WHERE permission = \'orb_server\' AND meter_uuid != \'\'))') as $related_meter) {
      echo "<a href='?".http_build_query(array_replace($qs, ['meter0' => $related_meter['id']]))." class='btn'>{$related_meter['name']}</a>";
    }
    foreach ($db->query('SELECT id, resource FROM meters WHERE scope = \'Whole Building\'
      AND building_id IN (SELECT building_id FROM meters WHERE id = '.intval($meter0).')
      AND ((gauges_using > 0 OR for_orb > 0 OR timeseries_using > 0) OR bos_uuid IN (SELECT DISTINCT meter_uuid FROM relative_values WHERE permission = \'orb_server\' AND meter_uuid != \'\'))
      ORDER BY units DESC') as $row) {
        echo "<a class='btn' href='?";
        echo http_build_query(array_replace($qs, ['meter0' => $row['id']]));
        echo "'>{$row['resource']}</a> \n";
      }
    ?>
  </div>
</div>
<svg id="svg"><image id="charachter" xlink:href="https://oberlindashboard.org/oberlin/time-series/images/main_frames/frame_18.gif"></image></svg>
<script src="https://cdnjs.cloudflare.com/ajax/libs/d3/4.12.0/d3.min.js"></script>
<script>
'use strict';
var times = <?php echo str_replace('"', '', json_encode(array_map(function($t) {return 'new Date('.($t*1000).')';}, $times))) ?>;
var values = <?php echo json_encode($values) ?>;
var orb_values = <?php echo json_encode($orb_values) ?>;
var svg_width = Math.max(document.documentElement.clientWidth, window.innerWidth || 0) - 50,
    svg_height = 500;
var charachter_width = svg_width/5,
    charachter_height = charachter_width*(598/449),
    charachter = document.getElementById('charachter');
var svg = d3.select('#svg'),
    margin = {top: 0, right: charachter_width, bottom: 20, left: 40},
    chart_width = svg_width - margin.left - margin.right,
    chart_height = svg_height - margin.top - margin.bottom,
    g = svg.append("g").attr("transform", "translate(" + margin.left + "," + margin.top + ")");
svg.attr('width', svg_width).attr('height', svg_height);
charachter.setAttribute('x', svg_width - charachter_width);
charachter.setAttribute('y', svg_height-(charachter_height));
charachter.setAttribute('width', charachter_width);
charachter.setAttribute('height', charachter_height);
var color = d3.scaleOrdinal(d3.schemeCategory10);
var xScale = d3.scaleTime().domain([times[0], times[times.length-1]]).range([0, chart_width]);
var yScale = d3.scaleLinear().domain([<?php echo $min ?>, <?php echo $max ?>]).range([chart_height, 0]); // fixed domain for each chart that is the global min/max
var imgScale = d3.scaleLinear().domain([0, Math.ceil(chart_width*<?php echo $pct_thru+0.01 ?>)]).range([0, <?php echo $number_of_frames ?>]).clamp(true);
// draw lines
var lineGenerator = d3.line()
  .defined(function(d) { return d !== null; }) // points are only defined if they are not null
  .x(function(d, i) { return xScale(times[i]); }) // x coord
  .y(yScale) // y coord
  .curve(d3.curveCatmullRom); // smoothing
var areaGenerator = d3.area()
  .defined(function(d) { return d !== null; }) // points are only defined if they are not null
  .x(function(d, i) { return xScale(times[i]); }) // x coord
  .y1(yScale) // y coord
  .y0(yScale(0))
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
  g.append("path")
    .datum(curve)
    .attr("fill", color(i))
    .attr("opacity", "0.1")
    .attr("d", areaGenerator);
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
// change charachter frame when mouse moves
var image = d3.select('#charachter');
// indicator ball
var circle = svg.append("circle")
  .attr("cx", -10)
  .attr("cy", -10)
  .attr("transform", "translate("+margin.left+"," + margin.top + ")")
  .attr("r", 8)
  .attr("fill", color(0));
svg.append("rect") // circle moves when mouse is over this rect
  .attr("width", chart_width)
  .attr("height", chart_height)
  .attr("transform", "translate("+margin.left+"," + margin.top + ")")
  .on("mousemove", mousemoved);
var text = svg.append('text')
  .attr('x', svg_width - charachter_width).attr('y', 50);
function mousemoved() {
  var m = d3.mouse(this),
      p = closestPoint(current_path.node(), m);
  circle.attr("cx", p['x']).attr("cy", p['y']);
  var index = Math.round(imgScale(m[0]))
  image.attr("xlink:href", "https://oberlindashboard.org/oberlin/time-series/images/main_frames/frame_"+index+".gif");
  text.text(d3.format('.2s')(yScale.invert(p['y'])));
}
function closestPoint(pathNode, point) {
  // https://stackoverflow.com/a/12541696/2624391 and http://bl.ocks.org/duopixel/3824661
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
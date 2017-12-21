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
define('MIN', 60);
define('QUARTERHOUR', 900);
define('HOUR', 3600);
define('DAY', 86400);
define('WEEK', 604800);
$meter = new Meter($db); // has methods to get data from db easily
$now = time();
if (!isset($_GET['meter0'])) { // at minimum this script needs a meter id to chart
  $_GET['meter0'] = 415; // default meter
}
$charts = 0; // the number of $meter variables e.g. $meter0, $meter1, ...
foreach ($_GET as $key => $value) { // count the number of charts
  if (substr($key, 0, 5) === 'meter') {
    $charts++;
  }
}
$total_charts = $charts; // the number of arrays in $values
// each chart should be a parameter in the query string e.g. meter1=326 along optional other customizable variables
for ($i = 0; $i < $charts; $i++) { // whitelist/define the variables to be extract()'d
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
$resource0 = $meter->getResourceType($meter0);
if ($resource0 === 'Water') {
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
    $increment = MIN;
    $xaxis_format = '%I:%M %p';
    $pct_thru = ($now - $from) / HOUR;
    $double_time = $from - HOUR;
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
    $increment = HOUR;
    $xaxis_format = '%d %b %I %p';
    $pct_thru = ($now - $from) / WEEK;
    $double_time = $from - WEEK;
    break;
  default://case 'day':
    $from = strtotime(date('Y-m-d') . " 00:00:00"); // Start of day
    $to = strtotime(date('Y-m-d') . " 23:59:59") + 1; // End of day
    $res = 'quarterhour';
    $increment = QUARTERHOUR;
    $xaxis_format = '%I:%M %p';
    $pct_thru = ($now - $from) / DAY;
    $double_time = $from - DAY;
    break;
}
$times = range($from, $to, $increment); // each array in $values should be count($times) long such that the float in the $values array corresponds to the time in the $times array with the same index
$num_points = count($times);
$values = [];
$max = PHP_INT_MIN;
$min = PHP_INT_MAX;
// get data from db for each chart and format so that it matches $times
for ($i = 0; $i < $charts; $i++) { // we will draw $charts number of charts plus a historical chart and typical chart (typical only on day/week res)
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
$typical_time_frame = ($time_frame === 'day' || $time_frame === 'week'); // there is only enough data to do the relative value calculation with these resolutions
if ($typical_time_frame) {
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
  $values[] = $typical_line; // typical line is 2nd to last chart in $values
  $total_charts++;
}
// get historical data for first meter
$data = $meter->getDataFromTo($meter0, $double_time, $from, $res, NULL_DATA);
if (!empty($data)) {
  foreach (array_slice(range($double_time, $from, $increment), -$num_points) as $time) { // need new $times that is exactly $num_points long
    $best_guess = find_nearest($data, $time, NULL_DATA);
    $values[$total_charts][] = $best_guess; // historical chart is last chart in $values
    if ($best_guess !== null && $best_guess > $max) {
      $max = $best_guess;
    }
    if ($best_guess !== null && $best_guess < $min) {
      $min = $best_guess;
    }
  }
}
if (!$typical_time_frame) { // charachter mood should be difference of current and past data
  $max_diff = PHP_INT_MIN;
  $min_diff = PHP_INT_MAX;
  $j = count($values) - 1;
  $tmp = [];
  for ($i = 0; $i < $num_points; $i++) { 
    $diff = $values[0][$i] - $values[$j][$i];
    if ($diff > $max) { // subtract historical from current
      $max = $diff;
    }
    if ($diff < $min) {
      $min = $diff;
    }
    $tmp[] = $diff;
  }
  foreach ($tmp as $val) {
    $orb_values[] = Meter::convertRange($val, $min_diff, $max_diff, 0, $number_of_frames);
  }
}
if ($min > 0) { // && it's a resource that starts from 0
  $min = 0;
}
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
  body {
    background: #ECEFF1;
  }
  svg {
    /*outline: 1px solid red;*/
    /*margin: 40px 0px 0px 25px;*/
    margin-top: 1.5vw;
    /*padding: 20px 0px 20px 0px;*/
    background: #ECEFF1;
    box-shadow: 0 -1px 3px rgba(0,0,0,0.12), 0 -1px 2px rgba(0,0,0,0.24);
  }
  .Grid {
    margin-left: 5px;
  }
  .domain {
    display: none;
  }
  .overlay {
    fill: none;
    pointer-events: all;
  }
  #hover-space {
    fill: none;
    cursor: crosshair;
    pointer-events: all;
  }
  .btn {
    text-decoration: none;
    padding: 7px 20px;
    padding: .7vw 2vw;
    margin: 0px
    box-shadow: 0 1px 3px rgba(0,0,0,0.12), 0 1px 2px rgba(0,0,0,0.24);
    /*color: #333;*/
    color: #fff;
    border-radius: 2px;
    background: #3498db;
    font-size: 1.4vw;
  }
  .dropdown {
    position: absolute;
    list-style: none;
    padding: 7px 20px;
    padding: .7vw 2vw;
    box-shadow: 0 1px 3px rgba(0,0,0,0.12), 0 1px 2px rgba(0,0,0,0.24);
    background: #fff;
    margin-top: 13px;
    padding: 0px
  }
  .dropdown li {
    padding: 8px 16px;
    color: #333;
    text-align: center;
  }
  .dropdown li:hover {
    background: #3498db;
    color: #fff;
  }
  .dropdown a {
    text-decoration: none;
    font-size: 1.3vw;
  }
  text {
    stroke: #37474F;
    fill: #37474F;
    font-weight: 400;
    font-size: 1vmax;
  }
  #current-reading {
    font-size: 3vw;
    text-anchor: start;
    alignment-baseline: hanging
  }
  #accum {
    font-size: 3vw;
    text-anchor: end;
    alignment-baseline: hanging
  }
  #background {
    fill: #F9FCFE
  }
  #menu {
    fill: #DFE3E4;
  }
  .menu-option {
    stroke: #37474F;
    fill: #37474F;
    font-size: 1.5vw;
    cursor: pointer;
  }
  </style>
</head>
<body><?php
if ($title_img || $title_txt) {
  echo '<div class="Grid Grid--gutters Grid--center" style=\'margin:0px\'>';
  if ($title_img) {
    echo "<div class='Grid-cell' style='flex: 0 0 8%;padding:0px;'><img src='https://placehold.it/150x150' /></div>";
  }
  if ($title_txt) {
    echo "<div class='Grid-cell'><h1 style='display:inline'>".$meter->getBuildingName($meter0).' '.$meter->getName($meter0)."</h1></div>";
  }
  echo '</div>';
}
?>
<div class="Grid" style="display:flex;justify-content: space-between;margin: 1.3vw 0px 0px 0px">
  <div>
    <a href="#" id="chart-overlay" class="btn">Graph overlay</a>
    <ul class="dropdown" style="display: none" id="chart-dropdown">
      <a href="#" id="historical-toggle"><li id="historical-toggle-text">Show previous <?php echo $time_frame ?></li></a>
      <?php echo ($typical_time_frame) ? '<a href="#" id="typical-toggle" data-show="1"><li id="typical-toggle-text">Hide typical</li></a>' : ''; ?>
      <?php for ($i = 1; $i < $charts; $i++) {
        $v = "meter{$i}";
        if ($$v !== false) {
          $chart_name = $meter->getName($$v);
          echo "<a href='#' data-show='{$i}'><li>{$chart_name}</li></a>";
        }
      } ?>
    </ul>
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
<svg id="svg">
  <defs>
    <linearGradient id="shadow">
      <stop class="stop1" stop-color="#ECEFF1" offset="0%"/>
      <stop class="stop2" stop-color="#B0BEC5" offset="100%"/>
    </linearGradient>
  </defs>
  <rect id="background" />
  <image id="fishbg" xlink:href="" style="display:none"></image>
  <image id="charachter" xlink:href="https://oberlindashboard.org/oberlin/time-series/images/main_frames/frame_18.gif"></image>
</svg>
<script src="https://cdnjs.cloudflare.com/ajax/libs/d3/4.12.0/d3.min.js"></script>
<script>
'use strict';
<?php if ($typical_time_frame) { ?>
var typical_shown = false;
document.getElementById('typical-toggle').addEventListener('click', function(e) {
  e.preventDefault();
  if (typical_shown) {
    document.getElementById('chart<?php echo $total_charts-1 ?>').style.display = 'none';
    document.getElementById('typical-toggle-text').innerHTML = 'Show typical';
    typical_shown = false;
  } else {
    document.getElementById('chart<?php echo $total_charts-1 ?>').style.display = '';
    document.getElementById('typical-toggle-text').innerHTML = 'Hide typical';
    typical_shown = true;
  }
});
<?php } ?>
var dropdown_menu = document.getElementById('chart-dropdown');
var dropdown_menu_shown = false;
document.getElementById('chart-overlay').addEventListener('click', function(e) {
  e.preventDefault();
  if (dropdown_menu_shown) {
    dropdown_menu.setAttribute('style', 'display:none');
    dropdown_menu_shown = false;
  } else {
    dropdown_menu.setAttribute('style', '');
    dropdown_menu_shown = true;
  }
});
var historical_shown = false;
document.getElementById('historical-toggle').addEventListener('click', function(e) {
  e.preventDefault();
  if (historical_shown) {
    document.getElementById('chart<?php echo $total_charts ?>').style.display = 'none';
    document.getElementById('historical-toggle-text').innerHTML = 'Show previous <?php echo $time_frame ?>';
    historical_shown = false;
  } else {
    document.getElementById('chart<?php echo $total_charts ?>').style.display = '';
    document.getElementById('historical-toggle-text').innerHTML = 'Hide previous <?php echo $time_frame ?>';
    historical_shown = true;
  }
});
var times = <?php echo str_replace('"', '', json_encode(array_map(function($t) {return 'new Date('.($t*1000).')';}, $times))) ?>,
    values = <?php echo json_encode($values) ?>,
    values0length = values[0].length,
    orb_values = <?php echo json_encode($orb_values) ?>,
    svg_width = Math.max(document.documentElement.clientWidth, window.innerWidth || 0),
    svg_height = svg_width / 2.75;
for (var i = values[0].length-1; i >= 0; i--) { // calc real width
  if (values[0][i] !== null) {
    break;
  }
  values0length--;
}
if (svg_width < 2000) {
  var charachter_width = svg_width/5,
      charachter_height = charachter_width*(598/449);
} else {
  var charachter_width = 449,
      charachter_height = 598;
}
var charachter = document.getElementById('charachter');
var svg = d3.select('#svg'),
    margin = {top: 25, right: charachter_width, bottom: 25, left: 40},
    chart_width = svg_width - margin.left - margin.right,
    chart_height = svg_height - margin.top - margin.bottom,
    g = svg.append("g").attr("transform", "translate(" + margin.left + "," + margin.top + ")");
svg.attr('width', svg_width).attr('height', svg_height);
charachter.setAttribute('x', svg_width - charachter_width);
charachter.setAttribute('y', svg_height-charachter_height-margin.bottom);
charachter.setAttribute('width', charachter_width);
charachter.setAttribute('height', charachter_height);
var menu_height = (svg_height-charachter_height-margin.bottom-margin.top)/2.5,
    button_offset = margin.right/5,
    current_state = 0;
svg.append('rect').attr('id', 'menu').attr('y', svg_height-charachter_height-margin.bottom-(menu_height)).attr('x', svg_width - charachter_width).attr('width', charachter_width).attr('height', menu_height);
svg.append('text').attr('y', svg_height-charachter_height-margin.bottom-10).attr('x', svg_width - charachter_width + (button_offset)).attr('width', charachter_width).text('☺').attr('class', 'menu-option').attr('data-option', 0).on('click', menu_click);
svg.append('text').attr('y', svg_height-charachter_height-margin.bottom-10).attr('x', svg_width - charachter_width + (button_offset*2)).attr('width', charachter_width).text('kWh').attr('class', 'menu-option').attr('data-option', 1).on('click', menu_click);
svg.append('text').attr('y', svg_height-charachter_height-margin.bottom-10).attr('x', svg_width - charachter_width + (button_offset*3)).attr('width', charachter_width).text('CO2').attr('class', 'menu-option').attr('data-option', 2).on('click', menu_click);
svg.append('text').attr('y', svg_height-charachter_height-margin.bottom-10).attr('x', svg_width - charachter_width + (button_offset*4)).attr('width', charachter_width).text('$').attr('class', 'menu-option').attr('data-option', 3).on('click', menu_click);
svg.append('rect').attr('y', 0).attr('x', svg_width - charachter_width).attr('width', '3px').attr('height', svg_height - margin.bottom).attr('fill', 'url(#shadow)');
svg.append('text').attr('x', -svg_height).attr('y', 1).attr('transform', 'rotate(-90)').attr('font-size', '1.3vw').attr('font-color', '#333').attr('alignment-baseline', 'hanging').text('<?php echo $units0 ?>');
var bg = document.getElementById('background');
bg.setAttribute('width', chart_width);
bg.setAttribute('height', chart_height);
bg.setAttribute("transform", "translate(" + margin.left + "," + margin.top + ")");
var color = d3.scaleOrdinal(d3.schemeCategory10);
var xScale = d3.scaleTime().domain([times[0], times[times.length-1]]).range([0, chart_width]);
var yScale = d3.scaleLinear().domain([<?php echo $min ?>, <?php echo $max ?>]).range([chart_height, 0]); // fixed domain for each chart that is the global min/max
var imgScale = d3.scaleLinear().domain([0, 1]).range([0, orb_values.length]).clamp(true); // 0,1 or 0, chart_width*(values0length/values[0].length)
var values0Scale = d3.scaleLinear().domain([0, 1]).range([0, values0length]).clamp(true);
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
  .y0(chart_height)
  .curve(d3.curveCatmullRom); // smoothing
var current_path = null;
values.forEach(function(curve, i) {
  // draw curve for each array in values
  var line = lineGenerator(curve);
  var path_g = g.append('g').attr('id', 'chart'+i);
  var path = path_g.append('path').attr('d', line);
  if (i === 0) {
    current_path = path;
  }
  path.attr("fill", "none").attr("stroke", color(i))
    .attr("stroke-linejoin", "round")
    .attr("stroke-linecap", "round")
    .attr("stroke-width", 2);
  <?php echo ($typical_time_frame) ? 'if (i !== '.($total_charts-1).') {' : ''; ?>
  var area = areaGenerator(curve);
  path_g.append("path")
    .attr("d", area)
    .attr("fill", color(i))
    .attr("opacity", "0.1");
  <?php echo ($typical_time_frame) ? '}' : ''; ?>
  if (i === <?php echo $total_charts ?>) {
    path_g.attr('style', 'display:none');
  }
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
var image = d3.select('#charachter'),
    fishbg = d3.select('#fishbg');
// indicator ball
var circle = svg.append("circle")
  .attr("cx", -100)
  .attr("cy", -100)
  .attr("transform", "translate("+margin.left+"," + margin.top + ")")
  .attr("r", 8)
  .attr("fill", color(0));
svg.append("rect") // circle moves when mouse is over this rect
  .attr("width", chart_width)
  .attr("height", chart_height)
  .attr('id', 'hover-space')
  .attr("transform", "translate("+margin.left+"," + margin.top + ")")
  .on("mousemove", mousemoved);
var current_reading = svg.append('text').attr('id', 'current-reading').attr('x', svg_width - charachter_width + 5).attr('y', menu_height/4);
var accum = svg.append('text').attr('id', 'accum').attr('x', svg_width - 5).attr('y', menu_height/4);
svg.append('text').attr('x', svg_width - charachter_width + 5).attr('y', menu_height*1.5).attr('text-anchor', 'start').attr('alignment-baseline', 'hanging').text("<?php echo $units0 ?>");
var accum_units = svg.append('text').attr('x', svg_width - 5).attr('y', menu_height*1.5).attr('text-anchor', 'end').attr('alignment-baseline', 'hanging').text("Kilowatt-hours today");

var timeout = null,
    timeout2 = null,
    interval = null;
function control_center() {
  clearTimeout(timeout);
  clearTimeout(timeout2);
  clearInterval(interval);
  fishbg.attr('style', 'display:none');
  if (Math.random() > 0.9) { // Randomly either play through the data or play movie
    play_data();
  } else {
    play_movie();
  }
}
control_center();

function mousemoved() {
  clearTimeout(timeout);
  clearTimeout(timeout2);
  clearInterval(interval);
  timeout = setTimeout(control_center, 3000);
  var m = d3.mouse(this),
      p = closestPoint(current_path.node(), m);
  circle.attr("cx", p['x']).attr("cy", p['y']);
  var frac = m[0]/(chart_width*(values0length/values[0].length));
  var index = Math.round(imgScale(frac));
  // console.log(index) this may not be the right thing to use
  if (orb_values[index] !== undefined) {
    animate_to(orb_values[index]);
  }
  current_reading.text(d3.format('.2s')(yScale.invert(p['y'])));
  var total_kw = 0,
      kw_count = 0,
      index = values0Scale(frac);
  // console.log(index/values0length);
  for (var i = 0; i <= index; i++) {
    total_kw += values[0][i];
    kw_count++;
  }
  accum.text(accumulation((xScale.invert(p['x']) - times[0])/1000, total_kw/kw_count, current_state));
}

var total_kw = 0,
    kw_count = 0;
for (var i = values[0].length - 1; i >= 0; i--) {
  if (values[0][i] !== null) {
    total_kw += values[0][i];
    kw_count++;
  }
}
var avg_kw_at_end = total_kw/kw_count,
    time_elapsed = (times[times.length-1].getTime()/1000)-(times[0].getTime()/1000),
    kwh_anim = svg.append('g').attr('style', 'display:none');
function menu_click() {
  current_state = parseInt(this.getAttribute('data-option'));
  accum.text(accumulation(time_elapsed, avg_kw_at_end, current_state));
  if (current_state === 0) {
    accum_units.text('Kilowatt-hours today');
    kwh_anim.attr('style', 'display:none');
  } else if (current_state === 1) {
    accum_units.text('Kilowatt-hours today');
    kwh_anim.attr('style', '');
  } else if (current_state === 2) {
    accum_units.text('Pounds of CO2 today');
    kwh_anim.attr('style', 'display:none');
  } else if (current_state === 3) {
    accum_units.text('Dollars spent today');
    kwh_anim.attr('style', 'display:none');
  }
}
// draw kwh animation background
kwh_anim.append('rect').attr('width', charachter_width).attr('height', chart_height/2).attr('x', chart_width + margin.left).attr('y', svg_height - margin.bottom - charachter_height).attr('fill', '#B4E3F4');
kwh_anim.append('rect').attr('width', charachter_width).attr('height', chart_height).attr('x', chart_width + margin.left).attr('y', '67%').attr('fill', 'rgb(129, 176, 64)');
kwh_anim.append('image').attr('xlink:href', 'https://oberlindashboard.org/oberlin/cwd/img/ground.svg').attr('width', charachter_width).attr('x', chart_width + margin.left).attr('y', '49%');
kwh_anim.append('image').attr('xlink:href', 'https://oberlindashboard.org/oberlin/cwd/img/ground.svg').attr('width', charachter_width).attr('x', chart_width + margin.left).attr('y', '54%');
kwh_anim.append('image').attr('xlink:href', 'https://oberlindashboard.org/oberlin/cwd/img/ground.svg').attr('width', charachter_width).attr('x', chart_width + margin.left).attr('y', '56%');
kwh_anim.append('image').attr('xlink:href', 'https://oberlindashboard.org/oberlin/cwd/img/houses.png').attr('width', charachter_width/1.5).attr('x', chart_width + margin.left + (charachter_width/5)).attr('y', '65%');
var grass = kwh_anim.append('g');
grass.append('polygon').attr('fill', '#fff').attr('points', '844.979,307.962 833.616,307.962 835.062,305.668 844.979,305.668');
grass.append('polygon').attr('fill', '#fff').attr('points', '824.112,305.668 833.944,305.668 832.524,307.962 824.112,307.962');
grass.append('polygon').attr('fill', '#fff').attr('points', '823.266,305.668 823.266,307.962 818.732,307.962 818.732,305.668');
grass.append('polygon').attr('fill', '#fff').attr('points', '817.831,307.962 809.42,307.962 807.727,305.668 817.831,305.668');
grass.append('path').attr('fill', '#fff').attr('points', 'M792.896,305.668h13.709l1.722,2.294h-15.239C792.614,307.907,792.552,307.143,792.896,305.668z');
grass.append('polygon').attr('fill', '#fff').attr('points', '845.359,299.36 824.112,299.36 824.112,297.147 845.359,297.147');
grass.append('polygon').attr('fill', '#fff').attr('points', '818.732,299.36 818.732,297.147 823.266,297.147 823.266,299.36');
grass.append('path').attr('fill', '#fff').attr('points', 'M817.831,299.36h-14.638l-0.792-1.175c-0.164-0.218-0.355-0.236-0.574-0.054             c-0.272,0.164-0.318,0.354-0.136,0.573l0.382,0.656h-8.575c-0.528-0.037-0.592-0.774-0.191-2.212h24.524V299.36             L817.831,299.36z');
grass.append('path').attr('fill', '#231F20').attr('points', 'M846.22,299.363c0.312,0.044,0.459,0.196,0.459,0.459c0.044,0.307-0.086,0.459-0.393,0.459h-8.33             l-2.36,4.524h9.707c0.396,0,0.546,0.197,0.458,0.591l0.065,0.262v2.295c0.307,0,0.46,0.153,0.46,0.459             c0.043,0.306-0.089,0.46-0.396,0.46h-12.852c-4.11,5.684-7.083,10.012-8.918,12.984v85.772c0,0.229-0.11,0.373-0.329,0.459             c-0.219,0.043-0.373,0-0.459-0.131l-0.066-0.262v-84.272l-0.062,0.065c-0.131,0.22-0.329,0.285-0.591,0.197             c-0.312-0.138-0.372-0.329-0.196-0.597l0.854-1.438V308.87h-4.524v12.329l0.787,1.377c0.175,0.262,0.131,0.479-0.131,0.656             c-0.229,0.175-0.415,0.13-0.591-0.137l-0.065-0.132v84.664c1.442,0.744,2.711,0.787,3.805,0.131             c0.131-0.087,0.271-0.104,0.396-0.062c0.132,0.043,0.22,0.131,0.263,0.262c0.088,0.218,0.043,0.396-0.131,0.525             c-1.399,0.875-2.932,0.875-4.592,0c-0.354,0.175-0.566,0.087-0.654-0.271l-0.197-0.13c-0.175-0.131-0.229-0.307-0.188-0.521             c0.045-0.271,0.176-0.368,0.396-0.324V321.46c-1.925-3.146-4.876-7.345-8.854-12.591h-16.33c-0.188,0-0.312-0.088-0.396-0.263             c-0.604-0.396-0.689-1.376-0.262-2.953c-0.219-0.087-0.308-0.262-0.264-0.524c0-0.219,0.132-0.329,0.395-0.329h13.837             l-3.214-4.524h-9.707c-0.173,0-0.305-0.087-0.394-0.262c-0.567-0.394-0.655-1.377-0.271-2.952             c-0.219-0.088-0.307-0.262-0.262-0.524c0-0.22,0.131-0.329,0.394-0.329h25.313v-2.688c0-0.307,0.15-0.459,0.459-0.459             c0-0.175,0.088-0.329,0.263-0.46c0.699-0.306,1.944-0.458,3.737-0.458c0.307,0,0.479,0.131,0.521,0.393             c0.048,0.439,0.193,1.049,0.456,1.837v-0.853c0-0.307,0.152-0.459,0.459-0.459c0.271-0.044,0.396,0.087,0.396,0.393v2.754             H845.7c0.394,0,0.547,0.197,0.459,0.59l0.132,0.262v2.231L846.22,299.363z M845.368,297.134h-21.247v2.229h21.247V297.134z              M844.974,305.659h-9.896l-1.441,2.295h11.353L844.974,305.659L844.974,305.659z M836.974,300.282H824.12v4.524h10.427             C835.683,302.795,836.492,301.287,836.974,300.282z M833.957,305.659h-9.837v2.295h8.394L833.957,305.659z M822.35,294.444             l-0.396-1.376c-1.398,0-2.425,0.13-3.081,0.393l-0.132,0.066v2.688h4.521v-1.804c-0.01,0.285-0.162,0.426-0.459,0.426             C822.546,294.883,822.392,294.75,822.35,294.444z M818.741,299.363h4.521v-2.229h-4.521V299.363z M818.741,307.954h4.521             v-2.295h-4.521V307.954z M823.267,300.282h-4.521v4.524h4.521V300.282z M824.12,320.152c1.661-2.579,4.262-6.338,7.804-11.278             h-7.804V320.152z M817.824,297.134h-24.526c-0.396,1.442-0.329,2.186,0.196,2.229h8.591l-0.394-0.656             c-0.188-0.217-0.145-0.415,0.131-0.59c0.219-0.174,0.415-0.153,0.59,0.066l0.787,1.18h14.624L817.824,297.134L817.824,297.134             z M806.61,305.659h-13.708c-0.354,1.487-0.283,2.252,0.188,2.295h15.225L806.61,305.659z M809.43,307.954h8.396v-2.295             h-10.104L809.43,307.954z M817.824,300.282h-13.969l3.214,4.524h10.755V300.282z M817.824,319.758v-10.885h-7.738             C813.496,313.376,816.075,317.005,817.824,319.758z');
grass.append('path').attr('fill', '#fff').attr('points', 'M823.266,308.863v12.809l-0.847,1.42c-0.182,0.273-0.118,0.479,0.191,0.604             c0.255,0.09,0.455,0.021,0.601-0.191l0.055-0.083V407.7l0.062,0.271h-0.109c-0.055-0.127-0.146-0.218-0.271-0.271             c-0.128-0.036-0.268-0.009-0.396,0.082c-1.092,0.646-2.354,0.604-3.812-0.146v-84.654l0.082,0.104             c0.164,0.273,0.354,0.318,0.562,0.136c0.273-0.165,0.318-0.382,0.146-0.648l-0.792-1.366v-12.344L823.266,308.863             L823.266,308.863z');
grass.append('path').attr('fill', '#fff').attr('points', 'M823.266,296.219h-4.521v-2.704l0.137-0.054c0.655-0.256,1.688-0.383,3.086-0.383l0.382,1.366             c0.062,0.309,0.211,0.437,0.471,0.382c0.312,0,0.459-0.124,0.469-0.37L823.266,296.219L823.266,296.219z');
var powerlines = kwh_anim.append('g').attr('transform', 'translate(550,100)');
powerlines.append('path').attr('d', 'M886.57,361.189v0.104l-0.771,0.848c0.164-0.018,0.246,0.036,0.246,0.164c-0.055-0.019-0.062,0-0.021,0.062         c-0.138,0.055-0.282,0.091-0.477,0.104l-7.312-0.021l-3.578,1.639l8.548,0.054c0.292,0,0.364,0.098,0.219,0.273l-0.812,0.979         c0.229-0.018,0.312,0.036,0.244,0.165c-0.107,0.098-0.271,0.144-0.489,0.144l-11.361-0.062         c-5.681,2.149-9.877,3.814-12.59,4.998l-34.028,36.869c-0.128,0.073-0.291,0.154-0.486,0.246l-0.402-0.104h-0.146         c-0.029,0.067-0.137,0.146-0.3,0.225c-0.966,0.128-1.814,0.255-2.562,0.382c-0.781-0.073-1.477-0.2-2.059-0.382         c-0.454,0.056-0.629,0.018-0.52-0.108h-0.164c-0.188-0.104-0.188-0.21,0-0.301c0.127-0.164,0.328-0.229,0.602-0.188         l34.574-36.896c-0.638-1.188-1.757-2.787-3.354-4.812l-14.609-0.104c-0.2-0.062-0.282-0.104-0.245-0.144         c-0.292-0.104-0.292-0.271,0-0.485c0.146-0.2,0.438-0.4,0.874-0.604c-0.164-0.072-0.2-0.136-0.104-0.188         c-0.021-0.036,0.01-0.045,0.08-0.027c0.06-0.108,0.221-0.146,0.483-0.108l12.289,0.062l-1.199-1.694l-8.566-0.054         c-0.199-0.021-0.291-0.063-0.271-0.139c-0.382-0.187-0.091-0.55,0.874-1.097c-0.184-0.054-0.195-0.116-0.057-0.188         c0.057-0.128,0.221-0.175,0.491-0.144l22.34,0.191l0.955-1.01c0.058-0.128,0.229-0.188,0.548-0.165         c0.021-0.073,0.146-0.144,0.384-0.191c0.689-0.104,1.839-0.152,3.438-0.137c0.221-0.036,0.312,0.009,0.303,0.137         c-0.128,0.146-0.188,0.382-0.188,0.71l0.245-0.328c0.07-0.146,0.271-0.195,0.573-0.164c0.198-0.031,0.28,0.015,0.244,0.143         l-1.019,1.01l18.95,0.107C886.635,360.962,886.697,361.043,886.57,361.189z M864.449,364.057l9.229,0.081         c1.657-0.776,2.896-1.338,3.688-1.666l-11.279-0.136L864.449,364.057z M865.296,362.391l-3.987-0.081l-1.584,1.748l4.021,0.027         L865.296,362.391z M860.517,362.282l-12.344-0.082l1.174,1.771l9.531,0.061L860.517,362.282z M859.042,369.929         c2.386-1.02,6.045-2.442,10.979-4.289l-6.977-0.054L859.042,369.929z M857.458,365.586l-6.909-0.056         c1.383,1.729,2.376,3.141,2.979,4.229L857.458,365.586z').attr('opacity', 0.2);
powerlines.append('polygon').attr('fill', '#fff').attr('points', '844.979,307.962 833.616,307.962 835.062,305.668 844.979,305.668');
powerlines.append('polygon').attr('fill', '#fff').attr('points', '824.112,305.668 833.944,305.668 832.524,307.962 824.112,307.962');
powerlines.append('polygon').attr('fill', '#fff').attr('points', '823.266,305.668 823.266,307.962 818.732,307.962 818.732,305.668');
powerlines.append('polygon').attr('fill', '#fff').attr('points', '817.831,307.962 809.42,307.962 807.727,305.668 817.831,305.668');
powerlines.append('path').attr('fill', '#fff').attr('d', 'M792.896,305.668h13.709l1.722,2.294h-15.239C792.614,307.907,792.552,307.143,792.896,305.668z');
powerlines.append('polygon').attr('fill', '#fff').attr('points', '845.359,299.36 824.112,299.36 824.112,297.147 845.359,297.147');
powerlines.append('polygon').attr('fill', '#fff').attr('points', '818.732,299.36 818.732,297.147 823.266,297.147 823.266,299.36');
powerlines.append('path').attr('fill', '#fff').attr('d', 'M817.831,299.36h-14.638l-0.792-1.175c-0.164-0.218-0.355-0.236-0.574-0.054             c-0.272,0.164-0.318,0.354-0.136,0.573l0.382,0.656h-8.575c-0.528-0.037-0.592-0.774-0.191-2.212h24.524V299.36             L817.831,299.36z');
powerlines.append('path').attr('fill', '#231F20').attr('d', 'M846.22,299.363c0.312,0.044,0.459,0.196,0.459,0.459c0.044,0.307-0.086,0.459-0.393,0.459h-8.33             l-2.36,4.524h9.707c0.396,0,0.546,0.197,0.458,0.591l0.065,0.262v2.295c0.307,0,0.46,0.153,0.46,0.459             c0.043,0.306-0.089,0.46-0.396,0.46h-12.852c-4.11,5.684-7.083,10.012-8.918,12.984v85.772c0,0.229-0.11,0.373-0.329,0.459             c-0.219,0.043-0.373,0-0.459-0.131l-0.066-0.262v-84.272l-0.062,0.065c-0.131,0.22-0.329,0.285-0.591,0.197             c-0.312-0.138-0.372-0.329-0.196-0.597l0.854-1.438V308.87h-4.524v12.329l0.787,1.377c0.175,0.262,0.131,0.479-0.131,0.656             c-0.229,0.175-0.415,0.13-0.591-0.137l-0.065-0.132v84.664c1.442,0.744,2.711,0.787,3.805,0.131             c0.131-0.087,0.271-0.104,0.396-0.062c0.132,0.043,0.22,0.131,0.263,0.262c0.088,0.218,0.043,0.396-0.131,0.525             c-1.399,0.875-2.932,0.875-4.592,0c-0.354,0.175-0.566,0.087-0.654-0.271l-0.197-0.13c-0.175-0.131-0.229-0.307-0.188-0.521             c0.045-0.271,0.176-0.368,0.396-0.324V321.46c-1.925-3.146-4.876-7.345-8.854-12.591h-16.33c-0.188,0-0.312-0.088-0.396-0.263             c-0.604-0.396-0.689-1.376-0.262-2.953c-0.219-0.087-0.308-0.262-0.264-0.524c0-0.219,0.132-0.329,0.395-0.329h13.837             l-3.214-4.524h-9.707c-0.173,0-0.305-0.087-0.394-0.262c-0.567-0.394-0.655-1.377-0.271-2.952             c-0.219-0.088-0.307-0.262-0.262-0.524c0-0.22,0.131-0.329,0.394-0.329h25.313v-2.688c0-0.307,0.15-0.459,0.459-0.459             c0-0.175,0.088-0.329,0.263-0.46c0.699-0.306,1.944-0.458,3.737-0.458c0.307,0,0.479,0.131,0.521,0.393             c0.048,0.439,0.193,1.049,0.456,1.837v-0.853c0-0.307,0.152-0.459,0.459-0.459c0.271-0.044,0.396,0.087,0.396,0.393v2.754             H845.7c0.394,0,0.547,0.197,0.459,0.59l0.132,0.262v2.231L846.22,299.363z M845.368,297.134h-21.247v2.229h21.247V297.134z              M844.974,305.659h-9.896l-1.441,2.295h11.353L844.974,305.659L844.974,305.659z M836.974,300.282H824.12v4.524h10.427             C835.683,302.795,836.492,301.287,836.974,300.282z M833.957,305.659h-9.837v2.295h8.394L833.957,305.659z M822.35,294.444             l-0.396-1.376c-1.398,0-2.425,0.13-3.081,0.393l-0.132,0.066v2.688h4.521v-1.804c-0.01,0.285-0.162,0.426-0.459,0.426             C822.546,294.883,822.392,294.75,822.35,294.444z M818.741,299.363h4.521v-2.229h-4.521V299.363z M818.741,307.954h4.521             v-2.295h-4.521V307.954z M823.267,300.282h-4.521v4.524h4.521V300.282z M824.12,320.152c1.661-2.579,4.262-6.338,7.804-11.278             h-7.804V320.152z M817.824,297.134h-24.526c-0.396,1.442-0.329,2.186,0.196,2.229h8.591l-0.394-0.656             c-0.188-0.217-0.145-0.415,0.131-0.59c0.219-0.174,0.415-0.153,0.59,0.066l0.787,1.18h14.624L817.824,297.134L817.824,297.134             z M806.61,305.659h-13.708c-0.354,1.487-0.283,2.252,0.188,2.295h15.225L806.61,305.659z M809.43,307.954h8.396v-2.295             h-10.104L809.43,307.954z M817.824,300.282h-13.969l3.214,4.524h10.755V300.282z M817.824,319.758v-10.885h-7.738             C813.496,313.376,816.075,317.005,817.824,319.758z');
powerlines.append('path').attr('fill', '#fff').attr('d', 'M823.266,308.863v12.809l-0.847,1.42c-0.182,0.273-0.118,0.479,0.191,0.604             c0.255,0.09,0.455,0.021,0.601-0.191l0.055-0.083V407.7l0.062,0.271h-0.109c-0.055-0.127-0.146-0.218-0.271-0.271             c-0.128-0.036-0.268-0.009-0.396,0.082c-1.092,0.646-2.354,0.604-3.812-0.146v-84.654l0.082,0.104             c0.164,0.273,0.354,0.318,0.562,0.136c0.273-0.165,0.318-0.382,0.146-0.648l-0.792-1.366v-12.344L823.266,308.863             L823.266,308.863z');
powerlines.append('path').attr('fill', '#fff').attr('d', 'M823.266,296.219h-4.521v-2.704l0.137-0.054c0.655-0.256,1.688-0.383,3.086-0.383l0.382,1.366             c0.062,0.309,0.211,0.437,0.471,0.382c0.312,0,0.459-0.124,0.469-0.37L823.266,296.219L823.266,296.219z');
powerlines.append('path').attr('d', 'M624.023,200.2c64.021,63.933,133.396,96.833,208.1,98.7').attr('fill', 'none').attr('stroke', '#000').attr('stroke-linecap', 'round').attr('stroke-linejoin', 'round').attr('stroke-miterlimit', '3');
kwh_anim.append('image').attr('xlink:href', 'https://oberlindashboard.org/oberlin/cwd/img/powerline.svg').attr('x', chart_width + margin.left).attr('y', '50%').attr('width', charachter_width/3);
// end kwh animation

var frames = [],
    last_frame = 0;
function animate_to(frame) {
  if (frame > last_frame) {
    while (++last_frame < frame) {
      frames.push(last_frame);
    }
  } else if (frame < last_frame) {
    while (--last_frame > frame) {
      frames.push(last_frame);
    }
  }
}
setInterval(function() { // outside is best for performance
  if (frames.length > 0) {
    image.attr("xlink:href", "https://oberlindashboard.org/oberlin/time-series/images/main_frames/frame_"+frames.shift()+".gif");
  }
}, 8);

function play_data() {
  var end_i = Math.floor(current_path.node().getBBox().width),
      i = 0, total_kw = 0;
  interval = setInterval(function() { // will go for end_i iterations
    var p = closestPoint(current_path.node(), [i, -1]); // -1 is a dummy value
    circle.attr("cx", p['x']).attr("cy", p['y']);
    current_reading.text(d3.format('.2s')(yScale.invert(p['y'])));
    var index = Math.round(imgScale(i/end_i));
    if (orb_values[index] !== undefined) {
      animate_to(orb_values[index]);
    }
    // console.log(values[0][Math.floor((i/end_i)*values0length)], Math.floor((i/end_i)*values0length), values0length);
    total_kw += values[0][Math.floor((i/end_i)*values0length)];
    i++;
    accum.text(accumulation((xScale.invert(p['x']) - times[0])/1000, total_kw/i, current_state));
    if (i >= end_i) {
      clearInterval(interval);
    }
  }, 35);
}

function httpGetAsync(theUrl, callback)
{
    var xmlHttp = new XMLHttpRequest();
    xmlHttp.onreadystatechange = function() { 
        if (xmlHttp.readyState == 4 && xmlHttp.status == 200)
            callback(xmlHttp.responseText);
    }
    xmlHttp.open("GET", theUrl, true); // true for asynchronous 
    xmlHttp.send(null);
}
var movies_played = 0;
function play_movie() {
  frames = [];
  var frac = circle.attr('cx')/current_path.node().getBBox().width,
      index = Math.round(imgScale(frac));
  if (orb_values[index] !== undefined) {
    var url = 'https://oberlindashboard.org/oberlin/time-series/movie.php?relative_value=' + convertRange(orb_values[index], 0, <?php echo $number_of_frames ?>, 0, 100) + '&count=' + (++movies_played) + '&charachter=<?php echo $charachter ?>';
    httpGetAsync(url, function(response) {
      var split = response.split('$SEP$');
      var len = split[1];
      var name = split[0];
      var fishbg_name = split[2];
      image.attr("xlink:href", "https://oberlindashboard.org/oberlin/time-series/images/"+name+".gif");
      if (fishbg_name != 'none') {
        fishbg.attr("xlink:href", "https://oberlindashboard.org/oberlin/time-series/images/"+fishbg_name+".gif").attr('style', '');
      }
      timeout2 = setTimeout(control_center, len);
    });
  }
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

function accumulation(time_sofar, avg_kw, current_state) { // how calculate kwh
  var kwh = (time_sofar/3600)*avg_kw; // the number of hours in time period * the average kw reading
  // console.log('time elapsed in hours: '+(time_sofar/3600)+"\navg_kw: "+ avg_kw+"\nkwh: "+kwh);
  if (current_state === 0 || current_state === 1) {
    return Math.round(kwh).toLocaleString(); // kWh = time elapsed in hours * kilowatts so far
  }
  else if (current_state === 2) {
    return Math.round(kwh*1.22).toLocaleString(); // pounds of co2 per kwh https://www.eia.gov/tools/faqs/faq.cfm?id=74&t=11
  } else if (current_state === 3) {
    return '$' + Math.round(kwh*0.11).toLocaleString(); // average cost of kwh http://www.npr.org/sections/money/2011/10/27/141766341/the-price-of-electricity-in-your-state
  }
}

function convertRange(val, old_min, old_max, new_min, new_max) {
  if (old_max == old_min) {
    return 0;
  }
  return (((new_max - new_min) * (val - old_min)) / (old_max - old_min)) + new_min;
}
</script>
</body>
</html>
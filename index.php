<?php
error_reporting(-1);
ini_set('display_errors', 'On');
date_default_timezone_set('America/New_York');
// do this before importing/defining anything to whitelist select variables to be imported with extract()
if (!isset($_GET['meter0'])) { // at minimum this script needs a meter id to chart
  // $_GET['meter0'] = 415; // default meter
  require 'create.php';
  exit();
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
$title_img = false;
$title_txt = false;
$start = 0;
$time_frame = 'day';
extract($_GET, EXTR_IF_EXISTS); // imports GET array into the current symbol table (i.e. makes each entry of GET a variable) if the variable already exists
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
$log = [];
$meter = new Meter($db); // has methods to get data from db easily
$now = time();
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
    $xaxis_ticks = 8;
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
    $xaxis_format = '%A';
    $pct_thru = ($now - $from) / WEEK;
    $double_time = $from - WEEK;
    $xaxis_ticks = 7;
    break;
  default://case 'day':
    $from = strtotime(date('Y-m-d') . " 00:00:00"); // Start of day
    $to = strtotime(date('Y-m-d') . " 23:59:59") + 1; // End of day
    $res = 'quarterhour';
    $increment = QUARTERHOUR;
    $xaxis_format = '%I:%M %p';
    $pct_thru = ($now - $from) / DAY;
    $double_time = $from - DAY;
    $xaxis_ticks = 13;
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
    if ($i === 0) { // if there's no data for meter0, we can't draw the chart
      $error = $$var_name;
      require "create.php";
      exit();
    } else { // just ignore it
      $$var_name = false;
      $charts--;
      $log[] = "Meter {$$var_name} has no data";
      continue;
    }
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
    AND DAYOFWEEK(FROM_UNIXTIME(recorded)) IN ('.implode(',', $days).') AND recorded < ?
    ORDER BY recorded DESC LIMIT ' . (intval($npoints)*24*4)); // to get npoints days of quarterhour data, npoints*24*4 = 4 points per hour, 24 per day
    $stmt->execute([$meter0, 'quarterhour', $from]);
    foreach (array_reverse($stmt->fetchAll()) as $row) { // need to order by DESC for the LIMIT to select the most recent records but actually we want it to be ASC
      $day_of_week = date('w', $row['recorded']);
      if ($last !== $day_of_week && $last !== null) {
        ++$prev_linesi;
      }
      $prev_lines[$prev_linesi][] = (float) $row['value'];
      $last = $day_of_week;
    }
    // die();
    if ($prev_linesi+1 !== $npoints) {
      $log[] = "Not enough data to calculate a median line using the previous {$npoints} typical days; using the previous " . ($prev_linesi+1) . " typical days instead";
      $npoints = $prev_linesi + 1;
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
    WHERE meter_id = ? AND value IS NOT NULL AND resolution = ? AND recorded < ?
    ORDER BY recorded DESC LIMIT ' . ($npoints*24*7)); // to get npoints weeks of hour data, npoints*24*7 = 24 points per day, 7 days per week
    $stmt->execute([$meter0, 'hour', $from]);
    // echo "<!--";
    foreach (array_reverse($stmt->fetchAll()) as $row) { // need to reorder for same reason as above
      $day_of_week = date('w', $row['recorded']);
      if ($day_of_week == '0' && $day_of_week !== $last && $last !== null) {
        ++$prev_linesi;
      }
      $prev_lines[$prev_linesi][] = (float) $row['value'];
      $last = $day_of_week;
    }
    if ($prev_linesi+1 !== $npoints) {
      $log[] = "Not enough data to calculate a median line using the previous {$npoints} weeks; using the previous " . ($prev_linesi+1) . " weeks instead";
      $npoints = $prev_linesi + 1;
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
if ($min > 0) { // && it's a resource that starts from 0, but do this later
  $min = 0;
}
parse_str($_SERVER['QUERY_STRING'], $qs);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <link href="https://fonts.googleapis.com/css?family=Roboto:400,700" rel="stylesheet">
  <link rel="stylesheet" href="style.css?v=<?php echo @time() ?>">
  <title>Time Series</title>
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
  <div>
    <a href="#" id="other-meters" class="btn">Other meters</a>
    <ul class="dropdown" style="display: none" id="meters-dropdown">
      <?php 
      foreach ($db->query('SELECT id, name FROM meters WHERE scope != \'Whole Building\'
        AND building_id IN (SELECT building_id FROM meters WHERE id = '.intval($meter0).')
        AND ((gauges_using > 0 OR for_orb > 0 OR timeseries_using > 0)
        OR bos_uuid IN (SELECT DISTINCT meter_uuid FROM relative_values WHERE permission = \'orb_server\' AND meter_uuid != \'\'))') as $related_meter) {
        echo "<a href='?".http_build_query(array_replace($qs, ['meter0' => $related_meter['id']]))."'><li>{$related_meter['name']}</li></a>";
      }
      foreach ($db->query('SELECT id, resource FROM meters WHERE scope = \'Whole Building\'
        AND building_id IN (SELECT building_id FROM meters WHERE id = '.intval($meter0).')
        AND ((gauges_using > 0 OR for_orb > 0 OR timeseries_using > 0) OR bos_uuid IN (SELECT DISTINCT meter_uuid FROM relative_values WHERE permission = \'orb_server\' AND meter_uuid != \'\'))
        ORDER BY units DESC') as $row) {
          echo "<a href='?";
          echo http_build_query(array_replace($qs, ['meter0' => $row['id']]));
          echo "'><li>{$row['resource']}</li></a> \n";
        }
      ?>
    </ul>
  </div>
</div>
<svg id="svg">
  <defs>
    <linearGradient id="shadow">
      <stop class="stop1" stop-color="#ECEFF1" offset="0%"/>
      <stop class="stop2" stop-color="#B0BEC5" offset="100%"/>
    </linearGradient>
    <linearGradient id="dirt_grad" x1="0%" y1="0%" x2="0%" y2="100%">
      <stop offset="30%" style="stop-color:rgba(129, 176, 64, 0);stop-opacity:1" />
      <stop offset="80%" style="stop-color:#795548;stop-opacity:1" />
    </linearGradient>
  </defs>
  <rect id="background" />
</svg>
<script src="https://cdnjs.cloudflare.com/ajax/libs/d3/4.12.0/d3.min.js"></script>
<script>
'use strict';
console.log(<?php echo json_encode($log); ?>);
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
var dropdown_menu = document.getElementById('chart-dropdown'),
    dropdown_menu_shown = false;
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
var dropdown_menu2 = document.getElementById('meters-dropdown'),
    dropdown_menu2_shown = false;
document.getElementById('other-meters').addEventListener('click', function(e) {
  e.preventDefault();
  if (dropdown_menu2_shown) {
    dropdown_menu2.setAttribute('style', 'display:none');
    dropdown_menu2_shown = false;
  } else {
    dropdown_menu2.setAttribute('style', '');
    dropdown_menu2_shown = true;
  }
});
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
var charachter_width = svg_width/5,
    charachter_height = charachter_width*(598/449);
var svg = d3.select('#svg').attr('height', svg_height).attr('width', svg_width).attr('viewBox', '0 0 ' + svg_width + ' ' + svg_height).attr('preserveAspectRatio', 'xMidYMid meet').attr('width', svg_width).attr('height', svg_height),
    margin = {top: svg_width/60, right: charachter_width, bottom: svg_width/60, left: svg_width/35},
    chart_width = svg_width - margin.left - margin.right,
    chart_height = svg_height - margin.top - margin.bottom,
    g = svg.append("g").attr("transform", "translate(" + margin.left + "," + margin.top + ")"),
    <?php if ($charachter === 'fish') {
    echo "blue_anim_bg = svg.append('rect').attr('x', margin.left + chart_width).attr('y', svg_height - margin.bottom - charachter_height).attr('width', charachter_width).attr('height', charachter_height).attr('fill', '#3498db'),\n";
    } ?>
    charachter = svg.append('image').attr('x', svg_width - charachter_width).attr('y', svg_height-charachter_height-margin.bottom).attr('width', charachter_width).attr('height', charachter_height);
var menu_height = (svg_height-charachter_height-margin.bottom-margin.top)/2.5,
    current_state = 0;
var icon_rect = svg.append('rect').attr('class', 'menu-option').attr('y', svg_height-charachter_height-margin.bottom-(svg_height*.01)).attr('x', svg_width - charachter_width).attr('width', charachter_width*.23).attr('height', '1%').attr('data-option', 0).on('click', menu_click).style('fill', '#3498db');
var kwh_rect = svg.append('rect').attr('class', 'menu-option').attr('y', svg_height-charachter_height-margin.bottom-(svg_height*.01)).attr('x', svg_width - (charachter_width*.74)).attr('width', charachter_width*.23).attr('height', '1%').attr('data-option', 1).on('click', menu_click);
var co2_rect = svg.append('rect').attr('class', 'menu-option').attr('y', svg_height-charachter_height-margin.bottom-(svg_height*.01)).attr('x', svg_width - (charachter_width*.48)).attr('width', charachter_width*.23).attr('height', '1%').attr('data-option', 2).on('click', menu_click);
var $rect = svg.append('rect').attr('class', 'menu-option').attr('y', svg_height-charachter_height-margin.bottom-(svg_height*.01)).attr('x', svg_width - (charachter_width*.22)).attr('width', charachter_width*.23).attr('height', '1%').attr('data-option', 3).on('click', menu_click);
var user_icon = svg.append('svg').attr('height', svg_width*.016).attr('width', svg_width*.016).attr('viewBox', '0 0 1792 1792').attr('x', svg_width - (charachter_width*.93)).attr('y', svg_height-charachter_height-margin.bottom-(svg_width*.02)).attr('data-option', 0).on('click', menu_click);
var icon = user_icon.append('path').attr('d', 'M896 0q182 0 348 71t286 191 191 286 71 348q0 181-70.5 347t-190.5 286-286 191.5-349 71.5-349-71-285.5-191.5-190.5-286-71-347.5 71-348 191-286 286-191 348-71zm619 1351q149-205 149-455 0-156-61-298t-164-245-245-164-298-61-298 61-245 164-164 245-61 298q0 250 149 455 66-327 306-327 131 128 313 128t313-128q240 0 306 327zm-235-647q0-159-112.5-271.5t-271.5-112.5-271.5 112.5-112.5 271.5 112.5 271.5 271.5 112.5 271.5-112.5 112.5-271.5z').attr('fill', '#3498db');
var kwh_text = svg.append('text').attr('y', svg_height-charachter_height-margin.bottom-(svg_width*.007)).attr('x', svg_width - (charachter_width*.7)).attr('width', charachter_width).text('kWh').attr('class', 'menu-text').attr('data-option', 1).on('click', menu_click);
var co2_text = svg.append('text').attr('y', svg_height-charachter_height-margin.bottom-(svg_width*.007)).attr('x', svg_width - (charachter_width*.435)).attr('width', charachter_width).text('CO2').attr('class', 'menu-text').attr('data-option', 2).on('click', menu_click);
var $text = svg.append('text').attr('y', svg_height-charachter_height-margin.bottom-(svg_width*.007)).attr('x', svg_width - (charachter_width*.13)).attr('width', charachter_width).text('$').attr('class', 'menu-text').attr('data-option', 3).on('click', menu_click);

svg.append('rect').attr('y', 0).attr('x', svg_width - charachter_width).attr('width', '3px').attr('height', svg_height - margin.bottom).attr('fill', 'url(#shadow)');
svg.append('text').attr('x', -svg_height).attr('y', 1).attr('transform', 'rotate(-90)').attr('font-size', '1.3vw').attr('font-color', '#333').attr('alignment-baseline', 'hanging').text('<?php echo $units0 ?>');
var bg = d3.select('#background');
bg.attr('width', chart_width);
bg.attr('height', chart_height);
bg.attr("transform", "translate(" + margin.left + "," + margin.top + ")");
var color = d3.scaleOrdinal(d3.schemeCategory10);
var format = d3.format('.3s');
var xScale = d3.scaleTime().domain([times[0], times[times.length-1]]).range([0, chart_width]);
var yScale = d3.scaleLinear().domain([<?php echo $min ?>, <?php echo $max ?>]).range([chart_height, 0]); // fixed domain for each chart that is the global min/max
var imgScale = d3.scaleLinear().domain([0, 1]).range([0, values0length]).clamp(true); // do orb_values.length-1 instead of values0length?
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
var current_path = null,
    current_path_len = 0,
    compared_path = null;
values.forEach(function(curve, i) {
  // draw curve for each array in values
  var line = lineGenerator(curve);
  var path_g = g.append('g').attr('id', 'chart'+i);
  var path = path_g.append('path').attr('d', line);
  if (i === 0) {
    current_path = path;
    current_path_len = current_path.node().getBBox().width;
  } else if (i === <?php echo ($typical_time_frame) ? $total_charts-1 : $total_charts; ?>) {
    compared_path = path;
  }
  path.attr("fill", "none").attr("stroke", color(i))
    .attr("stroke-width", svg_width/700);
  <?php echo ($typical_time_frame) ? 'if (i !== '.($total_charts-1).') {' : ''; ?>
  var area = areaGenerator(curve);
  path_g.append("path")
    .attr("d", area)
    .attr("fill", color(i))
    .attr("opacity", "0.1");
  <?php echo ($typical_time_frame) ? '}' : ''; ?>
  if (i === <?php echo $total_charts ?>) {
    path_g.style('display', 'none');
  }
});
// create x and y axis
var xaxis = d3.axisBottom(xScale).ticks(<?php echo $xaxis_ticks; ?>, '<?php echo $xaxis_format ?>');
var yaxis = d3.axisLeft(yScale).ticks(8, "s");
svg.append("g")
  .call(xaxis)
  .attr("transform", "translate("+margin.left+"," + (chart_height+margin.top) + ")");
svg.append("g")
  .call(yaxis)
  .attr("transform", "translate("+margin.left+","+margin.top+")").attr('id', 'yaxis_ticks');
// change charachter frame when mouse moves
var fishbg = svg.append('image').style('display', 'none').attr('x', margin.left + chart_width + 2).attr('y', svg_height - margin.bottom - charachter_height + 20).attr('width', charachter_width); // +2/+20 are weird hacks; image not sized right
// indicator ball
var circle = svg.append("circle").attr("cx", -100).attr("cy", -100).attr("transform", "translate("+margin.left+"," + margin.top + ")")
  .attr("r", svg_width/200).attr("stroke", color(0)).attr('stroke-width', svg_width/500).attr("fill", "#fff"),
    circle2 = svg.append("circle").attr("cx", -100).attr("cy", -100).attr("transform", "translate("+margin.left+"," + margin.top + ")")
  .attr("r", svg_width/200).attr("stroke", color(<?php echo $total_charts-1 ?>)).attr('stroke-width', svg_width/500).attr("fill", "#fff");
svg.append("rect") // circle moves when mouse is over this rect
  .attr("width", chart_width)
  .attr("height", chart_height)
  .attr('id', 'hover-space')
  .attr("transform", "translate("+margin.left+"," + margin.top + ")")
  .on("mousemove", mousemoved);
var current_reading = svg.append('text').attr('id', 'current-reading').attr('x', svg_width - charachter_width + 5).attr('y', menu_height/4).text('0').style('font-weight', 700);
var accum = svg.append('text').attr('id', 'accum').attr('x', svg_width - 5).attr('y', menu_height/4).text('0').style('font-weight', 700);
svg.append('text').attr('x', svg_width - charachter_width + 5).attr('y', menu_height*1.5).attr('text-anchor', 'start').attr('alignment-baseline', 'hanging').text("<?php echo $units0 ?>").style('font-size', '1.25vw');
var accum_units = svg.append('text').attr('x', svg_width - 5).attr('y', menu_height*1.5).attr('text-anchor', 'end').attr('alignment-baseline', 'hanging').text("Kilowatt-hours today").style('font-size', '1.25vw');
//draw legend
var x = margin.left,
    i = 0;
<?php
$legend = [];
for ($i = 0; $i < $charts; $i++) {
  $var_name = "meter{$i}";
  $stmt = $db->prepare('SELECT name FROM meters WHERE id = ?');
  $stmt->execute([$$var_name]);
  $legend[] = $stmt->fetchColumn();
}
if ($typical_time_frame) {
  $legend[] = 'Typical use';
}
$legend[] = "Previous {$time_frame}";
echo json_encode($legend);
?>.forEach(function(name) {
  svg.append('rect').attr('y', 5).attr('x', x).attr('height', margin.top - (svg_width/200)).attr('width', margin.top - (svg_width/200)).attr('fill', color(i++));
  x += margin.top;
  var el = svg.append('text').attr('y', margin.top / 1.5).attr('x', x).text(name);
  x += el.node().getBBox().width + (svg_width/50);
});

var timeout = null,
    timeout2 = null,
    interval = null;
function control_center() {
  clearTimeout(timeout);
  clearTimeout(timeout2);
  clearInterval(interval);
  fishbg.style('display', 'none');
  if (Math.random() > 0.6) { // randomly either play through the data or play movie
    play_data();
  } else {
    play_movie();
  }
}

function mousemoved() {
  clearTimeout(timeout);
  clearTimeout(timeout2);
  clearInterval(interval);
  timeout = setTimeout(control_center, 3000);
  var m = d3.mouse(this),
      // frac = m[0]/(chart_width*(values0length/values[0].length));
      frac = m[0]/current_path_len;
  if (frac < 1) {
    var p = closestPoint(current_path.node(), m),
      p2 = closestPoint(compared_path.node(), m);
    circle.attr("cx", p['x']).attr("cy", p['y']);
    circle2.attr('cx', p2['x']).attr('cy', p2['y']);
    var index = Math.round(imgScale(frac));
    animate_to(orb_values[index]);
    current_reading.text(d3.format('.2s')(yScale.invert(p['y'])));
    var total_kw = 0,
        kw_count = 0,
        index = values0Scale(frac);
    for (var i = 0; i <= index; i++) {
      total_kw += values[0][i];
      kw_count++;
    }
    accum.text(accumulation((xScale.invert(p['x']) - times[0])/1000, total_kw/kw_count, current_state));
    tree_leaves(frac, Math.floor(index));
  }
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
    anim_container = svg.append('g'),
    grass = anim_container.append('g').style('display', 'none'),
    kwh_anim = anim_container.append('g').style('display', 'none'),
    co2_anim = anim_container.append('g').style('display', 'none'),
    money_anim = anim_container.append('g').style('display', 'none');
function menu_click() {
  if (current_state === 0) {
    icon_rect.style('fill', '#37474F');
    icon.style('fill', '#37474F');
  } else if (current_state === 1) {
    kwh_rect.style('fill', '#37474F');
    kwh_text.style('fill', '#37474F');
  } else if (current_state === 2) {
    co2_rect.style('fill', '#37474F');
    co2_text.style('fill', '#37474F');
  } else if (current_state === 3) {
    $rect.style('fill', '#37474F');
    $text.style('fill', '#37474F');
  }
  current_state = parseInt(this.getAttribute('data-option'));
  accum.text(accumulation(time_elapsed, avg_kw_at_end, current_state));
  if (current_state === 0) {
    accum_units.text('Kilowatt-hours today');
    kwh_anim.style('display', 'none');
    co2_anim.style('display', 'none');
    money_anim.style('display', 'none');
    grass.style('display', 'none');
    icon_rect.style('fill', '#3498db');
    icon.style('fill', '#3498db');
  } else if (current_state === 1) {
    accum_units.text('Kilowatt-hours today');
    kwh_anim.style('display', 'initial');
    co2_anim.style('display', 'none');
    money_anim.style('display', 'none');
    grass.style('display', 'initial');
    kwh_rect.style('fill', '#3498db');
    kwh_text.style('fill', '#3498db');
  } else if (current_state === 2) {
    accum_units.text('Pounds of CO2 today');
    kwh_anim.style('display', 'none');
    co2_anim.style('display', 'initial');
    money_anim.style('display', 'none');
    grass.style('display', 'initial');
    co2_rect.style('fill', '#3498db');
    co2_text.style('fill', '#3498db');
  } else if (current_state === 3) {
    accum_units.text('Dollars spent today');
    kwh_anim.style('display', 'none');
    co2_anim.style('display', 'none');
    money_anim.style('display', 'initial');
    grass.style('display', 'initial');
    $rect.style('fill', '#3498db');
    $text.style('fill', '#3498db');
  }
}
// draw backgrounds for different buttons in menu
grass.append('rect').attr('width', charachter_width).attr('height', chart_height/2).attr('x', chart_width + margin.left).attr('y', svg_height - margin.bottom - charachter_height).attr('fill', '#B4E3F4');
grass.append('rect').attr('width', charachter_width).attr('height', chart_height).attr('x', chart_width + margin.left).attr('y', '60%').attr('fill', 'rgb(129, 176, 64)');
grass.append('image').attr('xlink:href', 'https://oberlindashboard.org/oberlin/cwd/img/ground.svg').attr('width', charachter_width).attr('x', chart_width + margin.left).attr('y', '52%');
grass.append('image').attr('xlink:href', 'https://oberlindashboard.org/oberlin/cwd/img/ground.svg').attr('width', charachter_width).attr('x', chart_width + margin.left).attr('y', '57%');

kwh_anim.append('image').attr('xlink:href', 'https://oberlindashboard.org/oberlin/cwd/img/houses.png').attr('width', charachter_width/1.5).attr('x', chart_width + margin.left + (charachter_width/5)).attr('y', '65%');
kwh_anim.append('image').attr('xlink:href', 'https://oberlindashboard.org/oberlin/cwd/img/powerline.svg').attr('x', chart_width + margin.left).attr('y', '50%').attr('width', charachter_width/3);

co2_anim.append('image').attr('xlink:href', 'https://oberlindashboard.org/oberlin/cwd/img/power_plant.png').attr('width', charachter_width*.7).attr('x', margin.left + chart_width + 5).attr('y', '70%');
co2_anim.append('image').attr('xlink:href', 'https://oberlindashboard.org/oberlin/cwd/img/smokestack/smokestack1.png').attr('width', charachter_width*.6).attr('x', margin.left + chart_width + (charachter_width*.1)).attr('y', '43%');
co2_anim.append('image').attr('xlink:href', 'https://oberlindashboard.org/oberlin/cwd/img/smokestack/smokestack1.png').attr('width', charachter_width*.6).attr('x', margin.left + chart_width + (charachter_width*.2)).attr('y', '43%');
co2_anim.append('image').attr('xlink:href', 'https://oberlindashboard.org/oberlin/cwd/img/smokestack/smokestack1.png').attr('width', charachter_width*.6).attr('x', margin.left + chart_width + (charachter_width*.3)).attr('y', '43%');
function smoke_animation() {
  var smoke1 = co2_anim.append('image').attr('xlink:href', 'https://oberlindashboard.org/oberlin/cwd/img/smoke.png').attr('x', margin.left + chart_width + (charachter_width*.1)).attr('y', '55%'),
      smoke2 = co2_anim.append('image').attr('xlink:href', 'https://oberlindashboard.org/oberlin/cwd/img/smoke.png').attr('x', margin.left + chart_width + (charachter_width*.2)).attr('y', '55%'),
      smoke3 = co2_anim.append('image').attr('xlink:href', 'https://oberlindashboard.org/oberlin/cwd/img/smoke.png').attr('x', margin.left + chart_width + (charachter_width*.3)).attr('y', '55%'),
      smoke1tran = smoke1.transition(),
      smoke2tran = smoke2.transition(),
      smoke3tran = smoke3.transition();
  smoke1tran.attr("transform", "translate(300, -300)").style('opacity', 0).duration(4000);
  smoke2tran.attr("transform", "translate(300, -300)").style('opacity', 0).duration(4000);
  smoke3tran.attr("transform", "translate(300, -300)").style('opacity', 0).duration(4000).on('end', smoke_animation);
}
smoke_animation();

money_anim.append('image').attr('xlink:href', 'https://oberlindashboard.org/oberlin/cwd/img/tree.svg').attr('width', charachter_width).attr('x', margin.left + chart_width).attr('y', '20%');
money_anim.append('ellipse').attr('cx', margin.left + chart_width + (charachter_width/2)).attr('cy', '80%').attr('rx', 100).attr('ry', 50).attr('fill', 'url(#dirt_grad)');
var current_leaves = [];
function tree_leaves(frac, index) {
  current_leaves.forEach(function(leaf) {
    leaf.remove();
  });
  current_leaves = [];
  // var frac = circle.attr('cx')/current_path_len,
  //     index = Math.round(imgScale(frac));
  if (orb_values[index] !== undefined) {
    var rv = convertRange(orb_values[index], 0, <?php echo $number_of_frames ?>, 0, 100);
    for (var i = Math.round(rv); i >= 0; i--) {
      var leaf = money_anim.append('image').attr('xlink:href', 'https://oberlindashboard.org/oberlin/cwd/img/banknote.svg').attr('height', svg_width*.02).attr('height', svg_width*.02).attr('x', getRandomInt(margin.left + chart_width, svg_width)).attr('y', getRandomInt(svg_height - charachter_height, 0.5*svg_height-margin.bottom));
      current_leaves.push(leaf);
    }
  }
  function getRandomInt(min, max) { return Math.floor(Math.random() * (max - min + 1) + min); }
}
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
    charachter.attr("xlink:href", "https://oberlindashboard.org/oberlin/time-series/images/main_frames/frame_"+frames.shift()+".gif");
  }
}, 8);

function play_data() {
  anim_container.style('display', 'initial');
  var end_i = Math.floor(current_path_len),
      i = 0, total_kw = 0;
  interval = setInterval(function() { // will go for end_i iterations
    var p = closestPoint(current_path.node(), [i, -1]), // -1 is a dummy value
        p2 = closestPoint(compared_path.node(), [i, -1]);
    circle.attr("cx", p['x']).attr("cy", p['y']);
    circle2.attr("cx", p2['x']).attr("cy", p2['y']);
    current_reading.text(d3.format('.2s')(yScale.invert(p['y'])));
    var index = Math.round(imgScale(i/end_i));
    animate_to(orb_values[index]);
    tree_leaves(i/end_i, index);
    // console.log(values[0][Math.floor((i/end_i)*values0length)], Math.floor((i/end_i)*values0length), values0length);
    total_kw += values[0][Math.floor((i/end_i)*values0length)];
    i++;
    accum.text(accumulation((xScale.invert(p['x']) - times[0])/1000, total_kw/i, current_state));
    if (i >= end_i) {
      control_center();
    }
  }, (1/end_i)*<?php echo 30000 * $pct_thru ?>); // (1/end_i)*7000 will make the loop go for 7 seconds
}
play_data(); // start by playing data

var movies_played = 0;
function play_movie() {
  frames = [];
  anim_container.style('display', 'none');
  var frac = circle.attr('cx')/current_path_len,
      index = Math.round(imgScale(frac));
  if (orb_values[index] !== undefined) {
    var rv = convertRange(orb_values[index], 0, <?php echo $number_of_frames ?>, 0, 100);
    // console.log(rv);
    var url = 'https://oberlindashboard.org/oberlin/time-series/movie.php?relative_value=' + rv + '&count=' + (++movies_played) + '&charachter=<?php echo $charachter ?>';
    var xmlHttp = new XMLHttpRequest(); // https://stackoverflow.com/a/4033310/2624391
    xmlHttp.onreadystatechange = function() {
      if (xmlHttp.readyState == 4 && xmlHttp.status == 200) {
        var split = xmlHttp.responseText.split('$SEP$');
        console.log(split);
        var len = split[1];
        var name = split[0];
        var fishbg_name = split[2];
        charachter.attr("xlink:href", "https://oberlindashboard.org/oberlin/time-series/images/"+name+".gif");
        if (fishbg_name != 'none') {
          fishbg.attr("xlink:href", "https://oberlindashboard.org/oberlin/time-series/images/"+fishbg_name+".gif").style('display', 'initial');
        }
        timeout2 = setTimeout(play_data, len);
      }
    }
    xmlHttp.open("GET", url, true); // true for asynchronous 
    xmlHttp.send(null);
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
    return format(kwh); // kWh = time elapsed in hours * kilowatts so far
  }
  else if (current_state === 2) {
    return format(kwh*1.22); // pounds of co2 per kwh https://www.eia.gov/tools/faqs/faq.cfm?id=74&t=11
  } else if (current_state === 3) {
    return '$' + format(kwh*0.11); // average cost of kwh http://www.npr.org/sections/money/2011/10/27/141766341/the-price-of-electricity-in-your-state
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
<?php
error_reporting(-1);
ini_set('display_errors', 'On');
date_default_timezone_set('America/New_York');
// do this before importing/defining anything to whitelist select variables to be imported with extract()
if (!isset($_GET['meter0']) || !is_numeric($_GET['meter0'])) { // at minimum this script needs a meter id to chart
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
if ($charts > 47) { // 47 = count($colors), so cutoff here (although 47 is a lot of meters, prob couldnt do this in the first place lol)
  $charts = 47;
}
// each chart should be a parameter in the query string e.g. meter1=326 along optional other customizable variables
for ($i = 0; $i < $charts; $i++) { // whitelist/define the variables to be extract()'d
  $var_name = "meter{$i}";
  $$var_name = false;
}
// other expected GET parameters
$title_img = false;
$title_txt = false;
$start = 0; // if set by user, $min will be set to this
$time_frame = 'day';
extract($_GET, EXTR_IF_EXISTS); // imports GET array into the current symbol table (i.e. makes each entry of GET a variable) if the variable already exists
$colors = ['#1f77b4', '#ff7f0e', '#2ca02c', '#d62728', '#9467bd', '#8c564b', '#e377c2', '#7f7f7f', '#bcbd22', '#17becf', '#7fc97f', '#beaed4', '#fdc086', '#ffff99', '#386cb0', '#f0027f', '#bf5b17', '#666666', '#e41a1c', '#377eb8', '#4daf4a', '#984ea3', '#ff7f00', '#ffff33', '#a65628', '#f781bf', '#999999', '#66c2a5', '#fc8d62', '#8da0cb', '#e78ac3', '#a6d854', '#ffd92f', '#e5c494', '#b3b3b3', '#8dd3c7', '#ffffb3', '#bebada', '#fb8072', '#80b1d3', '#fdb462', '#b3de69', '#fccde5', '#d9d9d9', '#bc80bd', '#ccebc5', '#ffed6f'];
for ($i=0; $i < $charts; $i++) { 
  if (isset($_GET["color{$i}"])) {
    $colors[$i] = $_GET["color{$i}"];
  }
}
require_once '../includes/db.php';
require_once '../includes/class.Meter.php';
require_once 'includes/normalize.php';
require_once 'includes/median.php';
require_once 'includes/change_res.php';
define('NULL_DATA', true); // set to false to fill in data
define('MIN', 60);
define('QUARTERHOUR', 900);
define('HOUR', 3600);
define('DAY', 86400);
define('WEEK', 604800);
define('HISTORICAL_CHART_INDEX', 1); // historical chart is 2nd (index 1) in charts
define('TYPICAL_CHART_INDEX', 2);
$log = [];
$meter = new Meter($db); // has methods to get data from db easily
$now = time();
if (isset($_GET['reset'])) {
  require_once '../includes/class.BuildingOS.php';
  $bos = new BuildingOS($db, $db->query("SELECT id FROM api WHERE user_id = {$user_id} LIMIT 1")->fetchColumn());
  $bos->resetMeter($meter0, 'quarterhour');
  $bos->resetMeter($meter0, 'hour');
  $stmt = $db->prepare('UPDATE meters SET quarterhour_last_updated = ?, hour_last_updated = ? WHERE id = ?');
  $stmt->execute([$now, $now, $meter0]);
  header('Location: https://environmentaldashboard.org'.substr($_SERVER['REQUEST_URI'], 0, -9));
  exit();
}
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
    $xaxis_format = '%-I:%M %p';
    $pct_thru = ($now - $from) / HOUR;
    $double_time = $from - HOUR;
    $xaxis_ticks = 5;
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
    $xaxis_format = '%-I %p';
    $pct_thru = ($now - $from) / DAY;
    $double_time = $from - DAY;
    $xaxis_ticks = 13;
    break;
}
$times = range($from, $to, $increment); // each array in $values should be count($times) long such that the float in the $values array corresponds to the time in the $times array with the same index
$num_points = count($times);
$values = [];
$max = PHP_INT_MIN; // global max/min of all charts to determine how to scale y axis
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
  $result = normalize($data, $times, $min, $max, NULL_DATA);
  $values[] = $result[0];
  $min = $result[1]; // normalize() will return its original argument (i.e. $min) if it is lower than anything encountered in $data
  $max = $result[2];
  if ($i === 0) {
    // get historical data for first meter
    $data = $meter->getDataFromTo($meter0, $double_time, $from, $res, NULL_DATA);
    if (!empty($data)) {
      $result = normalize($data, array_slice(range($double_time, $from, $increment), -$num_points), $min, $max, NULL_DATA);
      $values[] = $result[0];
      $min = $result[1];
      $max = $result[2];
      if ($time_frame === 'hour') { // the hour time frame doesnt have typical data, use previous hour as comparison 
        $bands = $result[0];
      }
    }
    // calculate the typical line
    require 'includes/typical_line.php';
  }
}

if ($min > 0) { // && it's a resource that starts from 0, but do this later
  $min = 0;
}
if ($start !== 0) {
  $min = $start;
}
parse_str($_SERVER['QUERY_STRING'], $qs);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <link href="https://fonts.googleapis.com/css?family=Roboto:400,500,700" rel="stylesheet">
  <link rel="stylesheet" href="style.css?v=<?php echo @time() ?>">
  <title>Time Series</title>
</head>
<body><?php
if ($title_img || $title_txt) {
  echo '<div class="Grid Grid--gutters Grid--center" style=\'margin:0px\'>';
  if ($title_img) {
    $building_img = $meter->getBuildingImage($meter0);
    if ($building_img == null) {
      $building_img = 'https://placehold.it/150x150';
    }
    echo "<div class='Grid-cell' style='flex: 0 0 8%;padding:0px;'><img src='{$building_img}' /></div>";
  }
  if ($title_txt) {
    echo "<div class='Grid-cell'><h1>".$meter->getBuildingName($meter0).' '.$meter->getName($meter0)."</h1></div>";
  }
  echo '</div>';
}
?>
<div class="Grid" style="display:flex;justify-content: space-between;margin: 1.3vw 0px 0px 0px">
  <div>
    <a href="#" id="chart-overlay" class="btn">Graph overlay &#9662;</a>
    <ul class="dropdown" style="display: none;" id="chart-dropdown">
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
    <?php
      foreach ($db->query('SELECT id, resource FROM meters WHERE scope = \'Whole Building\'
        AND building_id IN (SELECT building_id FROM meters WHERE id = '.intval($meter0).')
        AND (id IN (SELECT meter_id FROM saved_chart_meters) OR id IN (SELECT meter_id FROM gauges) OR bos_uuid IN (SELECT elec_uuid FROM orbs) OR bos_uuid IN (SELECT water_uuid FROM orbs) OR bos_uuid IN (SELECT DISTINCT meter_uuid FROM relative_values WHERE permission = \'orb_server\' AND meter_uuid != \'\'))
        ORDER BY units DESC') as $row) {
          echo "<a href='?";
          echo http_build_query(array_replace($qs, ['meter0' => $row['id']]));
          echo "' class='btn'>{$row['resource']}</a> \n";
        }
      $other_meters = '';
      foreach ($db->query('SELECT id, name FROM meters WHERE scope != \'Whole Building\'
        AND building_id IN (SELECT building_id FROM meters WHERE id = '.intval($meter0).')
        AND (id IN (SELECT meter_id FROM saved_chart_meters) OR id IN (SELECT meter_id FROM gauges) OR bos_uuid IN (SELECT elec_uuid FROM orbs) OR bos_uuid IN (SELECT water_uuid FROM orbs)
        OR bos_uuid IN (SELECT DISTINCT meter_uuid FROM relative_values WHERE permission = \'orb_server\' AND meter_uuid != \'\'))') as $related_meter) {
        $other_meters .= "<a href='?".http_build_query(array_replace($qs, ['meter0' => $related_meter['id']]))."'><li>{$related_meter['name']}</li></a>";
      }
      if ($other_meters !== '') {
        echo '<a href="#" id="other-meters" class="btn">More meters &#9662;</a>';
        echo '<ul class="dropdown" style="display: none;" id="meters-dropdown">'.$other_meters.'</ul>';
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
    <linearGradient id="shadow2" x1="0%" y1="0%" x2="0%" y2="100%">
      <stop offset="0%" style="stop-color:#fff;stop-opacity:1" />
      <stop offset="100%" style="stop-color:#777;stop-opacity:1" />
    </linearGradient>
    <linearGradient id="dirt_grad" x1="0%" y1="0%" x2="0%" y2="100%">
      <stop offset="30%" style="stop-color:rgba(129, 176, 64, 0);stop-opacity:1" />
      <stop offset="80%" style="stop-color:#795548;stop-opacity:1" />
    </linearGradient>
  </defs>
  <rect id="background" />
</svg>
<?php if (!isset($_GET['reset'])) {
  echo "<p style='margin-top: 10px;margin-right:3px;font-size: 10px;text-align: right;'>Seeing gaps in data? <a href=\"{$_SERVER['REQUEST_URI']}&reset=on\" style='color:#999;'>Reset meter</a>.</p>";
} ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/d3/4.12.0/d3.min.js"></script>
<script>
'use strict';
console.log(<?php echo json_encode($log); ?>);
// buttons outside of time series <svg>
<?php if ($typical_time_frame) { ?>
var typical_shown = false;
document.getElementById('typical-toggle').addEventListener('click', function(e) {
  e.preventDefault();
  if (typical_shown) {
    document.getElementById('chart1').style.display = 'none';
    document.getElementById('typical-toggle-text').innerHTML = 'Show typical';
    typical_shown = false;
  } else {
    document.getElementById('chart1').style.display = '';
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
    dropdown_menu.setAttribute('style', 'display:none;left: 5px;');
    dropdown_menu_shown = false;
    this.innerHTML = 'Graph overlay &#9662;';
  } else {
    dropdown_menu.setAttribute('style', 'left: 5px;');
    dropdown_menu_shown = true;
    this.innerHTML = 'Graph overlay &#9652;';
  }
});
<?php if ($other_meters !== '') { ?>
var dropdown_menu2 = document.getElementById('meters-dropdown'),
    dropdown_menu2_shown = false;
document.getElementById('other-meters').addEventListener('click', function(e) {
  e.preventDefault();
  if (dropdown_menu2_shown) {
    dropdown_menu2.setAttribute('style', 'display:none;right: 5px;');
    dropdown_menu2_shown = false;
    this.innerHTML = 'More meters &#9662;';
  } else {
    dropdown_menu2.setAttribute('style', 'right: 5px;');
    dropdown_menu2_shown = true;
    this.innerHTML = 'More meters &#9652;';
  }
});
<?php } ?>
var historical_shown = false;
document.getElementById('historical-toggle').addEventListener('click', function(e) {
  e.preventDefault();
  if (historical_shown) {
    document.getElementById('chart1').style.display = 'none';
    document.getElementById('historical-toggle-text').innerHTML = 'Show previous <?php echo $time_frame ?>';
    historical_shown = false;
  } else {
    document.getElementById('chart1').style.display = '';
    document.getElementById('historical-toggle-text').innerHTML = 'Hide previous <?php echo $time_frame ?>';
    historical_shown = true;
  }
});
// set vars
var times = <?php echo str_replace('"', '', json_encode(array_map(function($t) {return 'new Date('.($t*1000).')';}, $times))) ?>,
    values = <?php echo json_encode($values) ?>,
    values0length = values[0].length,
    bands = <?php echo json_encode($bands) ?>,
    rv = 0,
    accum = 0,
    colors = <?php echo json_encode($colors) ?>,
    svg_width = Math.max(document.documentElement.clientWidth, window.innerWidth || 0),
    svg_height = svg_width / <?php echo (isset($_GET['height'])) ? $_GET['height'] : '2.75' ?>;
for (var i = values[0].length-1; i >= 0; i--) { // calc real width
  if (values[0][i] !== null) {
    break;
  }
  values0length--;
}
var charachter_width = svg_width/5,
    charachter_height = charachter_width*(598/449);
// create svg
var svg = d3.select('#svg').attr('height', svg_height).attr('width', svg_width).attr('viewBox', '0 0 ' + svg_width + ' ' + svg_height).attr('preserveAspectRatio', 'xMidYMid meet').attr('width', svg_width).attr('height', svg_height),
    margin = {top: svg_width/60, right: charachter_width, bottom: svg_width/60, left: svg_width/35},
    chart_width = svg_width - margin.left - margin.right,
    chart_height = svg_height - margin.top - margin.bottom,
    g = svg.append("g").attr("transform", "translate(" + margin.left + "," + margin.top + ")"),
    <?php if ($charachter === 'fish') {
    echo "blue_anim_bg = svg.append('rect').attr('x', margin.left + chart_width).attr('y', svg_height - margin.bottom - charachter_height).attr('width', charachter_width).attr('height', charachter_height).attr('fill', '#3498db'),\n";
    // fishbg is the real fish animation, the gif put in charachter is a background of the ocean floor
    echo "fishbg = svg.append('image').style('display', 'none').attr('x', margin.left + chart_width + 2).attr('y', svg_height - margin.bottom - charachter_height + 20).attr('width', charachter_width),\n"; // +2/+20 are weird hacks; image not sized right
    } ?>
    charachter = svg.append('image').attr('x', svg_width - charachter_width).attr('y', svg_height-charachter_height-margin.bottom).attr('width', charachter_width).attr('height', charachter_height); // charachter to right of chart
 
// menu above charachter
var menu_height = (svg_height-charachter_height-margin.bottom-margin.top)/2.5,
    current_state = 0; // see menu_click()
var icon_rect = svg.append('rect').attr('class', 'menu-option').attr('y', svg_height-charachter_height-margin.bottom-(svg_height*.01)).attr('x', svg_width - charachter_width).attr('width', charachter_width*.23).attr('height', '1%').style('fill', '#3498db');
var kwh_rect = svg.append('rect').attr('class', 'menu-option').attr('y', svg_height-charachter_height-margin.bottom-(svg_height*.01)).attr('x', svg_width - (charachter_width*.74)).attr('width', charachter_width*.23).attr('height', '1%');
var co2_rect = svg.append('rect').attr('class', 'menu-option').attr('y', svg_height-charachter_height-margin.bottom-(svg_height*.01)).attr('x', svg_width - (charachter_width*.48)).attr('width', charachter_width*.23).attr('height', '1%');
var $rect = svg.append('rect').attr('class', 'menu-option').attr('y', svg_height-charachter_height-margin.bottom-(svg_height*.01)).attr('x', svg_width - (charachter_width*.22)).attr('width', charachter_width*.23).attr('height', '1%');
var user_icon = svg.append('svg').attr('height', svg_width*.016).attr('width', svg_width*.016).attr('viewBox', '0 0 1792 1792').attr('x', svg_width - (charachter_width*.93)).attr('y', svg_height-charachter_height-margin.bottom-(svg_width*.02));
var icon = user_icon.append('path').attr('d', 'M896 0q182 0 348 71t286 191 191 286 71 348q0 181-70.5 347t-190.5 286-286 191.5-349 71.5-349-71-285.5-191.5-190.5-286-71-347.5 71-348 191-286 286-191 348-71zm619 1351q149-205 149-455 0-156-61-298t-164-245-245-164-298-61-298 61-245 164-164 245-61 298q0 250 149 455 66-327 306-327 131 128 313 128t313-128q240 0 306 327zm-235-647q0-159-112.5-271.5t-271.5-112.5-271.5 112.5-112.5 271.5 112.5 271.5 271.5 112.5 271.5-112.5 112.5-271.5z').attr('fill', '#3498db');
var kwh_text = svg.append('text').attr('y', svg_height-charachter_height-margin.bottom-(svg_width*.007)).attr('x', svg_width - (charachter_width*.7)).attr('width', charachter_width).text('kWh').attr('class', 'menu-text');
var co2_text = svg.append('text').attr('y', svg_height-charachter_height-margin.bottom-(svg_width*.007)).attr('x', svg_width - (charachter_width*.435)).attr('width', charachter_width).text('CO2').attr('class', 'menu-text');
var $text = svg.append('text').attr('y', svg_height-charachter_height-margin.bottom-(svg_width*.007)).attr('x', svg_width - (charachter_width*.13)).attr('width', charachter_width).text('$').attr('class', 'menu-text');
svg.append('rect').attr('y', svg_height-charachter_height-margin.bottom-(svg_height*.06)).attr('x', svg_width - charachter_width).attr('width', charachter_width*.23).attr('height', '6%').attr('fill', 'transparent').attr('data-option', 0).on('click', menu_click).attr('cursor', 'pointer');
svg.append('rect').attr('y', svg_height-charachter_height-margin.bottom-(svg_height*.06)).attr('x', svg_width - (charachter_width*.74)).attr('width', charachter_width*.23).attr('height', '6%').attr('fill', 'transparent').attr('data-option', 1).on('click', menu_click).attr('cursor', 'pointer');
svg.append('rect').attr('y', svg_height-charachter_height-margin.bottom-(svg_height*.06)).attr('x', svg_width - (charachter_width*.48)).attr('width', charachter_width*.23).attr('height', '6%').attr('fill', 'transparent').attr('data-option', 2).on('click', menu_click).attr('cursor', 'pointer');
svg.append('rect').attr('y', svg_height-charachter_height-margin.bottom-(svg_height*.06)).attr('x', svg_width - (charachter_width*.22)).attr('width', charachter_width*.23).attr('height', '6%').attr('fill', 'transparent').attr('data-option', 3).on('click', menu_click).attr('cursor', 'pointer');
// kwh, co2, money animations
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
// draw backgrounds for different buttons in menu
// grass is in all animations
grass.append('rect').attr('width', charachter_width).attr('height', chart_height/2).attr('x', chart_width + margin.left).attr('y', svg_height - margin.bottom - charachter_height).attr('fill', '#B4E3F4');
grass.append('rect').attr('width', charachter_width).attr('height', chart_height).attr('x', chart_width + margin.left).attr('y', '60%').attr('fill', 'rgb(129, 176, 64)');
grass.append('image').attr('xlink:href', 'https://environmentaldashboard.org/cwd-files/img/ground.svg').attr('width', charachter_width).attr('x', chart_width + margin.left).attr('y', '52%');
grass.append('image').attr('xlink:href', 'https://environmentaldashboard.org/cwd-files/img/ground.svg').attr('width', charachter_width).attr('x', chart_width + margin.left).attr('y', '57%');
// kwh animation
kwh_anim.append('image').attr('xlink:href', 'https://environmentaldashboard.org/cwd-files/img/houses.png').attr('width', charachter_width/1.5).attr('x', chart_width + margin.left + (charachter_width/5)).attr('y', '65%');
kwh_anim.append('image').attr('xlink:href', 'https://environmentaldashboard.org/cwd-files/img/powerline.svg').attr('x', chart_width + margin.left).attr('y', '50%').attr('width', charachter_width/3);
kwh_anim.append('rect').attr('height', svg_height/4).attr('width', svg_height/50).attr('fill', 'white').attr('stroke-width', svg_width/1000).attr('stroke','black').attr('y', '75%').attr('x', margin.left+chart_width+(charachter_width*.8)).attr('stroke-linejoin', 'round');
kwh_anim.append('rect').attr('height', svg_height/90).attr('width', svg_height/6).attr('fill', 'white').attr('stroke-width', svg_width/1000).attr('stroke','black').attr('y', '73%').attr('x', (margin.left+chart_width+(charachter_width*.8))-((svg_height/12)-(svg_height/90)));
kwh_anim.append('rect').attr('height', svg_height/90).attr('width', svg_height/6).attr('fill', 'white').attr('stroke-width', svg_width/1000).attr('stroke','black').attr('y', '75%').attr('x', (margin.left+chart_width+(charachter_width*.8))-((svg_height/12)-(svg_height/90)));
kwh_anim.append('line').attr('x1', (margin.left+chart_width+(charachter_width*.8))).attr('y1', '80%').attr('x2', (margin.left+chart_width+(charachter_width*.68))).attr('y2', '74%').attr('stroke', 'black').attr('stroke-width', svg_width/1000);
kwh_anim.append('line').attr('x1', (margin.left+chart_width+(charachter_width*.84))).attr('y1', '80%').attr('x2', (margin.left+chart_width+(charachter_width*.93))).attr('y2', '74%').attr('stroke', 'black').attr('stroke-width', svg_width/1000);
var startx = (svg_width-charachter_width)*1.04,
    starty = (svg_height*.5),
    cp1x = (svg_width-charachter_width)*1.1, // control point 1 x coord
    cp1y = svg_height*.6,
    cp2x = svg_width*.9,
    cp2y = svg_height*.8,
    endx = margin.left+chart_width+(charachter_width*.8),
    endy = svg_height*.75;
var wire = kwh_anim.append('path').attr('stroke', 'black').attr('stroke-width', svg_width/1000).attr('fill', 'transparent').attr('d', 'M'+startx+' '+starty+' C '+cp1x+' '+cp1y+', '+cp2x+' '+cp2y+', '+endx+' '+endy),
    electric_node = kwh_anim.append('circle').attr('fill', 'yellow').attr("r", svg_width/300).attr('cx', -100).attr('cy', -100);
var i = 0,
    path = wire.node(),
    len = Math.floor(path.getTotalLength()),
    add = true;
function electric_anim() {
  var point = path.getPointAtLength(i);
  if (add) {
    i++;
  } else {
    i--;
  }
  electric_node.attr('cx', point['x']).attr('cy', point['y']);
  if (add && i > len) {
    add = false;
  } else if (!add && i < 1) {
    add = true;
  }
}
var electricity_timer = null;


// co2 animation
co2_anim.append('image').attr('xlink:href', 'https://environmentaldashboard.org/cwd-files/img/power_plant.png').attr('width', charachter_width*.7).attr('x', margin.left + chart_width + 5).attr('y', '70%');
co2_anim.append('image').attr('xlink:href', 'https://environmentaldashboard.org/cwd-files/img/smokestack/smokestack1.png').attr('width', charachter_width*.6).attr('x', margin.left + chart_width + (charachter_width*.1)).attr('y', '43%');
co2_anim.append('image').attr('xlink:href', 'https://environmentaldashboard.org/cwd-files/img/smokestack/smokestack1.png').attr('width', charachter_width*.6).attr('x', margin.left + chart_width + (charachter_width*.2)).attr('y', '43%');
co2_anim.append('image').attr('xlink:href', 'https://environmentaldashboard.org/cwd-files/img/smokestack/smokestack1.png').attr('width', charachter_width*.6).attr('x', margin.left + chart_width + (charachter_width*.3)).attr('y', '43%');
var smoke1 = co2_anim.append('image').attr('xlink:href', 'https://environmentaldashboard.org/cwd-files/img/smoke.png').attr('x', margin.left + chart_width + (charachter_width*.1)).attr('y', '55%');
var smoke2 = co2_anim.append('image').attr('xlink:href', 'https://environmentaldashboard.org/cwd-files/img/smoke.png').attr('x', margin.left + chart_width + (charachter_width*.2)).attr('y', '55%');
var smoke3 = co2_anim.append('image').attr('xlink:href', 'https://environmentaldashboard.org/cwd-files/img/smoke.png').attr('x', margin.left + chart_width + (charachter_width*.3)).attr('y', '55%');
var current_smoke = [];
function co2_animation(index) {
  // console.log(values[0][index]); // current reading
  // pct_done = index/(pct_thru(1));
  if (current_smoke.length < accum) {
    for (var i = (Math.round(accum) - current_smoke.length) - 1; i >= 0; i--) {
      var cloud = co2_anim.append('image').attr('xlink:href', 'https://environmentaldashboard.org/cwd-files/img/smoke.png').attr('x', margin.left + chart_width + (charachter_width*(getRandomInt(0,100)/100))).attr('y', getRandomInt(20, 40)+'%').attr('width', getRandomInt(30,80));
      current_smoke.push(cloud);
    }
  } else if (current_smoke.length > 0) {
    for (var i = current_smoke.length - 1; i >= accum; i--) {
      current_smoke[i].remove();
      current_smoke.pop();
    }
  }
  var smoke1tran = smoke1.transition().duration(4000),
      smoke2tran = smoke2.transition().duration(4000),
      smoke3tran = smoke3.transition().duration(4000);
  var x = -getRandomInt(2200, 2380), x2 = -getRandomInt(2200, 2380), x3 = -getRandomInt(2200, 2380);
  // -2200
  smoke1tran.tween("attr:transform", function() {
    var i = d3.interpolateString("translate(0,0) scale(1)", "translate("+x+",-800) scale(3)");
    return function(t) { smoke1.attr("transform", i(t)); };
  });
  smoke2tran.tween("attr:transform", function() {
    var i = d3.interpolateString("translate(0,0) scale(1)", "translate("+x2+",-800) scale(3)");
    return function(t) { smoke2.attr("transform", i(t)); };
  });
  smoke3tran.tween("attr:transform", function() {
    var i = d3.interpolateString("translate(0,0) scale(1)", "translate("+x3+",-800) scale(3)");
    return function(t) { smoke3.attr("transform", i(t)); };
  });
}
// money animation
money_anim.append('image').attr('xlink:href', 'https://environmentaldashboard.org/cwd-files/img/tree.svg').attr('width', charachter_width).attr('x', margin.left + chart_width).attr('y', '20%');
money_anim.append('ellipse').attr('cx', margin.left + chart_width + (charachter_width/2)).attr('cy', '80%').attr('rx', 100).attr('ry', 50).attr('fill', 'url(#dirt_grad)');
var current_leaves = [];
function tree_leaves() {
  if (current_leaves.length < accum) {
    for (var i = (Math.round(accum) - current_leaves.length) - 1; i >= 0; i--) {
      var leaf = money_anim.append('image').attr('xlink:href', 'https://environmentaldashboard.org/cwd-files/img/dollar.svg').attr('height', svg_width*.02).attr('height', svg_width*.02).attr('x', getRandomInt(margin.left + chart_width, svg_width)).attr('y', getRandomInt(svg_height - charachter_height, 0.5*svg_height-margin.bottom));
      if (Math.random() > 0.7) {
        leaf.transition().duration(2000).attr('y', getRandomInt(chart_height, chart_height/.8));
      }
      current_leaves.push(leaf);
    }
  } else if (current_leaves.length > 0) {
    for (var i = current_leaves.length - 1; i >= accum; i--) {
      current_leaves[i].remove();
      current_leaves.pop();
    }
  }
}
// end animations

svg.append('rect').attr('y', 0).attr('x', svg_width - charachter_width).attr('width', '3px').attr('height', svg_height - margin.bottom).attr('fill', 'url(#shadow)'); // shadow between charachter and chart
// svg.append('rect').attr('y', svg_height-margin.bottom-3).attr('x', margin.left).attr('width', chart_width).attr('height', 3).attr('fill', 'url(#shadow2)');
svg.append('text').attr('x', -svg_height + 20).attr('y', 3).attr('transform', 'rotate(-90)').attr('font-size', '1.3vw').attr('font-color', '#333').attr('alignment-baseline', 'hanging').text('<?php echo $units0 ?>'); // units on left yaxis
var bg = d3.select('#background'); // style defined in style.css
bg.attr('width', chart_width).attr('height', chart_height).attr("transform", "translate(" + margin.left + "," + margin.top + ")");
// d3 scales
var format = d3.format('.3s');
var xScale = d3.scaleTime().domain([times[0], times[times.length-1]]).range([0, chart_width]);
var yScale = d3.scaleLinear().domain([<?php echo $min ?>, <?php echo $max ?>]).range([chart_height, 0]); // fixed domain for each chart that is the global min/max
var pct_thru = d3.scaleLinear().domain([0, 1]).range([0, values0length]).clamp(true); // do orb_values.length-1 instead of values0length?
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
  } else if (i === <?php echo ($typical_time_frame) ? TYPICAL_CHART_INDEX : HISTORICAL_CHART_INDEX; ?>) {
    compared_path = path;
  }
  path.attr("fill", "none").attr("stroke", colors[i])
    .attr("stroke-width", svg_width/700);
  <?php echo ($typical_time_frame) ? 'if (i !== '.TYPICAL_CHART_INDEX.') {' : ''; ?>
  var area = areaGenerator(curve);
  path_g.append("path")
    .attr("d", area)
    .attr("fill", colors[i])
    .attr("opacity", "0.1");
  <?php
  echo ($typical_time_frame) ? '}' : '';
  if ($typical_time_frame) {
    echo "if (i === 1) { path_g.style('display', 'none'); }\n";
  } ?>
});
// create x and y axis
var xaxis = d3.axisBottom(xScale).ticks(<?php echo $xaxis_ticks; ?>, '<?php echo $xaxis_format ?>');
var yaxis = d3.axisLeft(yScale).ticks(8, "s");
svg.append("g")
  .call(xaxis)
  .attr("transform", "translate("+(margin.left)+"," + (chart_height+margin.top-5) + ")").attr('id', 'xaxis_ticks');
svg.append("g")
  .call(yaxis)
  .attr("transform", "translate("+margin.left+","+margin.top+")").attr('id', 'yaxis_ticks').attr('font-size', margin.bottom);
document.getElementById('xaxis_ticks').childNodes[1].setAttribute('transform', 'translate(20,0)');
// indicator ball
var circle = svg.append("circle").attr("cx", -100).attr("cy", -100).attr("transform", "translate("+margin.left+"," + margin.top + ")")
  .attr("r", svg_width/200).attr("stroke", colors[0]).attr('stroke-width', svg_width/500).attr("fill", "#fff"),
    circle2 = svg.append("circle").attr("cx", -100).attr("cy", -100).attr("transform", "translate("+margin.left+"," + margin.top + ")")
  .attr("r", svg_width/200).attr("stroke", colors[<?php echo ($typical_time_frame) ? TYPICAL_CHART_INDEX : HISTORICAL_CHART_INDEX ?>]).attr('stroke-width', svg_width/500).attr("fill", "#fff");
svg.append("rect") // circle moves when mouse is over this rect
  .attr("width", chart_width)
  .attr("height", chart_height)
  .attr('id', 'hover-space')
  .attr("transform", "translate("+margin.left+"," + margin.top + ")")
  .on("mousemove", mousemoved);
var current_reading = svg.append('text').attr('id', 'current-reading').attr('x', svg_width - charachter_width + 5).attr('y', menu_height/4).text('0').style('font-weight', 700);
var accum_text = svg.append('text').attr('id', 'accum').attr('x', svg_width - 5).attr('y', menu_height/4).text('0').style('font-weight', 700);
svg.append('text').attr('x', svg_width - charachter_width + 5).attr('y', menu_height*1.3).attr('text-anchor', 'start').attr('alignment-baseline', 'hanging').text("<?php echo $units0 ?>").style('font-size', '1.25vw').attr('class', 'bolder');
var accum_units = svg.append('text').attr('x', svg_width - 5).attr('y', menu_height*1.3).attr('text-anchor', 'end').attr('alignment-baseline', 'hanging').text("<?php echo ($charachter === 'squirrel') ? 'Kilowatt-hours' : 'Gallons so far'; ?>").style('font-size', '1.25vw').attr('class', 'bolder');
svg.append('text').attr('x', svg_width - 5).attr('y', menu_height*1.8).attr('text-anchor', 'end').attr('alignment-baseline', 'hanging').text('<?php echo ($time_frame === 'day') ? 'today' : $time_frame;  ?>').attr('class', 'bolder').attr('font-size', '1.25vw');
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
  if ($i === 0 && $typical_time_frame) {
    $legend[] = "Previous {$time_frame}";
    $legend[] = 'Typical use';
  }
}
echo json_encode($legend);
?>.forEach(function(name) {
  svg.append('rect').attr('y', 0).attr('x', x).attr('height', margin.top - (svg_width/200)).attr('width', margin.top - (svg_width/200)).attr('fill', colors[i++]);
  x += margin.top;
  var el = svg.append('text').attr('y', margin.top / 1.7).attr('x', x).text(name);
  x += el.node().getBBox().width + (svg_width/50);
});

var timeout = null, // fires when the mouse is idle for 3s; calls control_center()
    timeout2 = null, // fires when a movie has finished playing; calls play_data()
    interval = null; // iterates over data in play_data() until the mouse moves
function control_center() { // called every time the mouse is idle for 3s and at the end of play_data()
  clearTimeout(timeout);
  clearTimeout(timeout2);
  clearInterval(interval);
  setTimeout(function() {
    <?php if ($charachter === 'fish') { echo "fishbg.style('display', 'none');\n"; } ?>
    var rand = Math.random();
    if (rand > 0.93) {
      control_center(); // wait another second
    }
    else if (rand > 0.6) {
      play_data();
    } else {
      play_movie();
    }
  }, 1000);
}

function mousemoved() {
  clearTimeout(timeout);
  clearTimeout(timeout2);
  clearInterval(interval);
  anim_container.style('display', 'initial');
  timeout = setTimeout(control_center, 3000);
  var m = d3.mouse(this),
      frac = m[0]/current_path_len;
  if (frac < 1) {
    var p = closestPoint(current_path.node(), m),
        p2 = closestPoint(compared_path.node(), m);
    circle.attr("cx", p['x']).attr("cy", p['y']);
    circle2.attr('cx', p2['x']).attr('cy', p2['y']);
    var index = Math.round(pct_thru(frac));
    var elapsed = xScale.invert(p['x']),
        current = yScale.invert(p['y']);
    var typical = typical_data(elapsed);
    set_relative_value(typical, current);
    set_accumulation(rv, elapsed);
    animate_to(<?php echo $number_of_frames ?> - Math.round(convertRange(rv, 0, 100, 0, <?php echo $number_of_frames ?>)));
    current_reading.text(d3.format('.2s')(current));
    var total_kw = 0,
        kw_count = 0;
    for (var i = 0; i <= index; i++) {
      total_kw += values[0][i];
      kw_count++;
    }
    accum_text.text(accumulation((elapsed.getTime() - times[0].getTime())/1000, total_kw/kw_count, current_state));
    if (current_state === 1) {
      clearInterval(electricity_timer);
      electricity_timer = setInterval(electric_anim, (100-rv)/5);
    }
    else if (current_state === 2) {
      co2_animation(Math.floor(index));
    }
    else if (current_state === 3) {
      tree_leaves(Math.floor(index));
    }
  }
}

function menu_click() {
  if (current_state === 0) { // current_state 0 is the dynamic charachter behaviour
    icon_rect.style('fill', '#37474F');
    icon.style('fill', '#37474F');
  } else if (current_state === 1) { // current_state 1 is the kwh animation
    kwh_rect.style('fill', '#37474F');
    kwh_text.style('fill', '#37474F');
    clearInterval(electricity_timer);
  } else if (current_state === 2) { // current_state 2 is the co2 animation
    co2_rect.style('fill', '#37474F');
    co2_text.style('fill', '#37474F');
  } else if (current_state === 3) { // current_state 3 is the money animation
    $rect.style('fill', '#37474F');
    $text.style('fill', '#37474F');
  }
  current_state = parseInt(this.getAttribute('data-option'));
  accum_text.text(accumulation(time_elapsed, avg_kw_at_end, current_state));
  if (current_state === 0) {
    accum_units.text('<?php echo ($charachter === 'squirrel') ? 'Kilowatt-hours' : 'Gallons so far'; ?>');
    kwh_anim.style('display', 'none');
    co2_anim.style('display', 'none');
    money_anim.style('display', 'none');
    grass.style('display', 'none');
    icon_rect.style('fill', '#3498db');
    icon.style('fill', '#3498db');
  } else if (current_state === 1) {
    accum_units.text('<?php echo ($charachter === 'squirrel') ? 'Kilowatt-hours' : 'Gallons so far'; ?>');
    kwh_anim.style('display', 'initial');
    co2_anim.style('display', 'none');
    money_anim.style('display', 'none');
    grass.style('display', 'initial');
    kwh_rect.style('fill', '#3498db');
    kwh_text.style('fill', '#3498db');
    electricity_timer = setInterval(electric_anim, (100-rv)/5);
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
// dynamic charachter behaviour
var frames = [],
    last_frame = 0;
function animate_to(frame) {
  if (frames.length < 100) {
    if (frame > last_frame) {
      while (++last_frame < frame) {
        frames.push(last_frame);
      }
    } else if (frame < last_frame) {
      while (--last_frame > frame) {
        frames.push(last_frame);
      }
    } else {
      frames.push(frame);
    }
  }
}
setInterval(function() { // outside is best for performance
  if (frames.length > 0) {
    charachter.attr("xlink:href", "https://environmentaldashboard.org/chart/images/<?php echo ($charachter === 'squirrel') ? 'main' : 'second'; ?>_frames/frame_"+frames.shift()+".gif");
  }
}, 8);

function play_data() {
  <?php if ($charachter === 'fish') { echo "fishbg.style('display', 'none');"; } ?>
  anim_container.style('display', 'initial');
  var end_i = Math.ceil(current_path_len), total_kw = 0,
      i = 0, i2 = 0; // i2 is to jerk circle2 representing the typical use forward if the circle representing current use has to because of null data
  interval = setInterval(function() { // will go for end_i iterations
    var p = closestPoint(current_path.node(), [i, -1]), // -1 is a dummy value
        p2 = closestPoint(compared_path.node(), [i2, -1]);
    while (p2['x'] < p['x']) { // if there's null data and circle skips ahead, make sure circle2 also skips
      p2 = closestPoint(compared_path.node(), [++i2, -1]);
    }
    circle.attr("cx", p['x']).attr("cy", p['y']);
    circle2.attr("cx", p2['x']).attr("cy", p2['y']);
    current_reading.text(d3.format('.2s')(yScale.invert(p['y'])));
    var index = Math.round(pct_thru(i/end_i));
    var elapsed = xScale.invert(p['x']),
        current = yScale.invert(p['y']);
    var typical = typical_data(elapsed);
    set_relative_value(typical, current);
    animate_to(<?php echo $number_of_frames ?> - Math.round(convertRange(rv, 0, 100, 0, <?php echo $number_of_frames ?>)));
    if (current_state === 2) {
      co2_animation(index);
    }
    else if (current_state === 3) {
      tree_leaves(index);
    }
    // console.log(values[0][Math.floor((i/end_i)*values0length)], Math.floor((i/end_i)*values0length), values0length);
    total_kw += values[0][Math.floor((i/end_i)*values0length)];
    i++;
    accum_text.text(accumulation((xScale.invert(p['x']) - times[0])/1000, total_kw/i, current_state));
    if (i > end_i) {
      control_center();
    }
  }, (1/end_i)*<?php echo 30000 * $pct_thru ?>); // (1/end_i)*7000 will make the loop go for 7 seconds
}
play_data(); // start by playing data

var movies_played = 0;
function play_movie() {
  frames = [];
  anim_container.style('display', 'none');
  // console.log(rv);
  var url = 'https://environmentaldashboard.org/chart/movie.php?relative_value=' + rv + '&count=' + (++movies_played) + '&charachter=<?php echo $charachter ?>';
  var xmlHttp = new XMLHttpRequest(); // https://stackoverflow.com/a/4033310/2624391
  xmlHttp.onreadystatechange = function() {
    if (xmlHttp.readyState == 4 && xmlHttp.status == 200) {
      var split = xmlHttp.responseText.split('$SEP$');
      console.log(split);
      var len = split[1];
      var name = split[0];
      charachter.attr("xlink:href", "https://environmentaldashboard.org/chart/images/"+name+".gif");
      <?php if ($charachter === 'fish') { ?>
      var fishbg_name = split[2];
      if (fishbg_name != 'none') {
        fishbg.attr("xlink:href", "https://environmentaldashboard.org/chart/images/"+fishbg_name+".gif").style('display', 'initial');
      }
      <?php } ?>
      timeout2 = setTimeout(play_data, len);
    }
  }
  xmlHttp.open("GET", url, true); // true for asynchronous 
  xmlHttp.send(null);
}

function typical_data(time) {
  <?php if ($time_frame === 'week') { ?>
  var week = time.getDay(),
      hrs = time.getHours();
  var hash = week.toString() + hrs.toString();
  return bands[hash];
  <?php } elseif ($time_frame === 'day') { ?>
  var mins = time.getMinutes(),
      hrs = time.getHours();
  mins = Math.round(mins / 15) * 15; // round to nearest 15 minute
  if (mins < 10) {
    mins = '0' + mins;
  }
  else if (mins == 60) {
    mins = '00';
    hrs = hrs + 1;
  }
  var hash = hrs.toString() + mins.toString();
  return bands[hash];
  <?php } else { // hour ?>
  return [bands[Math.round(xScale(time))]];
  <?php } ?>
}

function set_relative_value(typical, current) {
  var count = typical.length;
  // console.log(typical, current); // this is important
  var copy = typical.slice();
  copy.push(current);
  copy.sort(function(a,b) {return a-b;});
  rv = (copy.indexOf(current) / (count)) * 100;
}

var last_time = times[0];
// var powerScale = d3.scalePow().exponent(0.5).domain([0, 10000]).range([0, 100]);
function set_accumulation(rv, time) {
  var diff = (time-last_time)/10000;
  // console.log(time-last_time);
  accum += (diff*rv)/10000;
  last_time = time;
  // console.log(accum);
  // console.log(powerScale(accum));
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

function getRandomInt(min, max) { return Math.floor(Math.random() * (max - min + 1) + min); } // how is this not built into js?

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

function convertRange(val, old_min, old_max, new_min, new_max) {
  if (old_max == old_min) {
    return 0;
  }
  return (((new_max - new_min) * (val - old_min)) / (old_max - old_min)) + new_min;
}
</script>
</body>
</html>
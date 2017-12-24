<?php
function change_res($data, $result_size) {
  $count = count($data);
  $return = [];
  if (!$count) {
    return $return;
  }
  for ($i = 0; $i < $result_size; $i++) {
    $index_fraction = Meter::convertRange($i, 0, $result_size-1, 0, $count-1);
    $floor = floor($index_fraction); // index of current data point
    $ceil = ceil($index_fraction); // index of next point
    $current_point = $data[$floor];
    $next_point = $data[$ceil];
    $pct = $index_fraction - $floor;
    $diff = $next_point - $current_point;
    if ($current_point === null || $next_point === null) {
      $return[$i] = null;
    } else {
      $return[$i] = $current_point+($pct*$diff);
    }
  }
  return $return;
}
?>
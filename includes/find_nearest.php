<?php
/**
 * Input: array of arrays that each have a 'value' and 'recorded' key
 * Output: the value in $arr recorded on $sec. if no value exists, give best approximation
 * Set null flag to false to give best guess for null data
 */
function find_nearest($arr, $sec, $allow_null = true) { 
  static $i = 0; // static so it keeps its place in the array
  static $last_non_null = 0;
  $count = count($arr);
  while ($i < $count) {

    if ($arr[$i]['recorded'] == $sec) { // found an exact match
      if ($allow_null) {
        return ($arr[$i]['value'] === null) ? null : floatval($arr[$i]['value']);
      } else {
        if ($arr[$i]['value'] === null) {
          return $last_non_null;
        } else {
          $return = floatval($arr[$i]['value']);
          $last_non_null = $return;
          return $return;
        }
      }
    }
    if ($arr[$i]['recorded'] > $sec) { // iterate until you reach a point that was recorded after $sec
      if ($i > 0) { // if it's not the first iteration
        $next_val = $arr[$i]['value'];
        $last_val = $arr[$i-1]['value'];
        if ($next_val === null || $last_val === null) {
          return ($allow_null) ? null : $last_non_null;
        }
        $next_time = $arr[$i]['recorded'];
        $last_time = $arr[$i-1]['recorded'];
        $frac = Meter::convertRange($sec, $last_time, $next_time, 0, 1);
        $now_val = $last_val + (($next_val-$last_val)*$frac);
        $i = ($i === $count-1) ? 0 : $i+1;
        return $now_val;
      } else { // first index was recorded before $sec
        return ($allow_null) ? null : floatval($arr[0]['value']);
      }
    }
    if ($i === $count-1) {
      $i = 0;
      $last_non_null = 0;
      return ($allow_null) ? null : floatval($arr[$count-1]['value']); // all of the data in this array was recorded before $sec
    } else {
      $i++;
    }

  }
}
?>
<?php
/**
 * Input: array of arrays that each have a 'value' and 'recorded' key
 * Output: the value in $arr recorded on $sec. if no value exists, give best approximation
 */
function find_nearest($arr, $sec, $null = true) { 
  static $i = 0; // static so it keeps its place in the array
  $count = count($arr);
  while ($i < $count) {

    if ($arr[$i]['recorded'] == $sec) { // found an exact match
      return floatval($arr[$i]['value']);
    }
    if ($arr[$i]['recorded'] > $sec) { // iterate until you reach a point that was recorded after $sec
      if ($i > 0) { // if it's not the first iteration
        $next_time = $arr[$i]['recorded'];
        $last_time = $arr[$i-1]['recorded'];
        $next_val = $arr[$i]['value'];
        $last_val = $arr[$i-1]['value'];
        $frac = Meter::convertRange($sec, $last_time, $next_time, 0, 1);
        $now_val = $last_val + (($next_val-$last_val)*$frac);
        $i = ($i === $count-1) ? 0 : $i+1;
        return $now_val;
      } else { // first index was recorded before $sec
        return ($null) ? null : floatval($arr[0]['value']);
      }
    }
    if ($i === $count-1) {
      $i = 0;
      return ($null) ? null : floatval($arr[$count-1]['value']); // all of the data in this array was recorded before $sec
    } else {
      $i++;
    }

  }
}
?>
<?php
/**
 * Input: array of arrays that each have a 'value' and 'recorded' key as well as an array of times that each need their closest value
 * Output: 1d array that has a one to one correspondance with $times such that each value of the output array is the best guess for the value of the meter at the time corresponding to the item in $times
 * Set null flag to false to give best guess for null data
 */
function normalize($arr, $times, $min, $max, $allow_null = true) {
  $count = count($arr);
  $last_non_null = 0;
  $ret = [];
  $i = 0;
  foreach ($times as $time) {
    if ($i === $count) {
      $ret[] = ($allow_null) ? null : $last_non_null;
      continue;
    }
    $float = floatval($arr[$i]['value']);
    if ($arr[$i]['recorded'] == $time) { // found an exact match
      if ($allow_null) {
        $ret[] = ($arr[$i]['value'] === null) ? null : $float;
      } else {
        if ($arr[$i]['value'] === null) {
          $ret[] = $last_non_null;
        } else {
          $ret[] = $float;
          $last_non_null = $float;
        }
      }
      if ($arr[$i]['value'] !== null) {
        if ($float > $max) {
          $max = $float;
        } if ($float < $min) {
          $min = $float;
        }
      }
      $i++;
    } elseif ($arr[$i]['recorded'] < $time) { // the value we want (indicated by $time) is at a higher index in the $arr
      // echo "Couldnt find match for {$time} (iteration < time @ {$arr[$i]['recorded']})\n";die;
      while ($arr[$i]['recorded'] < $time) { // try to catch up
        if ($i === $count-1) {
          break;
        }
        $i++;
      }
      if ($arr[$i]['recorded'] == $time) { // if we did find another point in the array that matches $time
        if ($arr[$i]['value'] === null) {
          $ret[] = ($allow_null) ? null : $last_non_null;
        } else {
          $ret[] = $arr[$i]['value'];
          if ($float > $max) {
            $max = $float;
          } if ($float < $min) {
            $min = $float;
          }
        }
        $i++;
      } else { // if the there's no $arr[$i]['value'] that has a corresponding $arr[$i]['recorded'] == $time
        $ret[] = ($allow_null) ? null : $last_non_null;  
      }
    } else { // the value we want is at a lower index of the array, meaning it doesn't exist
      // echo "Couldnt find match for {$time} (iteration > time @ {$arr[$i]['recorded']})\n";
      $ret[] = ($allow_null) ? null : $last_non_null;
    }
  }
  return [$ret, $min, $max];
}
?>
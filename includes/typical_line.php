<?php
// calculate the typical line
$typical_line = []; // formed by taking the median of each sub array value in $bands
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
  if ($time_frame === 'day') {
    $hash_arr = array_map(function($t) { return date('Gi', $t); }, $times); // 'Gi' = hours and minutes (mins padded with 0s)
    $bands = array_fill_keys($hash_arr, array_fill(0, $npoints, null));
    $counter = array_fill_keys($hash_arr, 0);
    $stmt = $db->prepare( // get npoints days worth of data
    'SELECT value, recorded FROM meter_data
    WHERE meter_id = ? AND resolution = ?
    AND DAYOFWEEK(FROM_UNIXTIME(recorded)) IN ('.implode(',', $days).') AND recorded < ?
    ORDER BY recorded DESC LIMIT ' . (intval($npoints)*24*4)); // to get npoints days of quarterhour data, npoints*24*4 = 4 points per hour, 24 per day
    $stmt->execute([$meter0, 'quarterhour', $from]);
    if ($stmt->rowCount() > 0) {
      foreach ($stmt->fetchAll() as $row) {
        $hash = date('Gi', $row['recorded']);
        $bands[$hash][$counter[$hash]++] = (float) $row['value'];
      }
      foreach ($hash_arr as $time) {
        $filtered = array_values(array_filter($bands[$time], function($e) {return $e!==null;} ));
        if (count($filtered) > 0) {
          $median = median($filtered);
          $typical_line[] = $median;
          if ($median > $max) {
            $max = $median;
          }
          if ($median < $min) {
            $min = $median;
          }
          sort($filtered);
          $bands[$time] = $filtered;
        } else {
          $typical_line[] = null;
          $bands[$time] = [];
        }
      }
      $typical_line = change_res($typical_line, $num_points);
    }
  } else { // week
    $hash_arr = array_map(function($t) { return date('wG', $t); }, $times); // 'wG' = week, hours
    $bands = array_fill_keys($hash_arr, array_fill(0, $npoints, null));
    $counter = array_fill_keys($hash_arr, 0);
    $stmt = $db->prepare( // https://stackoverflow.com/a/7786588/2624391
    'SELECT value, recorded FROM meter_data
    WHERE meter_id = ? AND value IS NOT NULL AND resolution = ? AND recorded < ?
    ORDER BY recorded DESC LIMIT ' . ($npoints*24*7)); // to get npoints weeks of hour data, npoints*24*7 = 24 points per day, 7 days per week
    $stmt->execute([$meter0, 'hour', $from]);
    if ($stmt->rowCount() > 0) {
      foreach ($stmt->fetchAll() as $row) {
        $hash = date('wG', $row['recorded']);
        $bands[$hash][$counter[$hash]++] = (float) $row['value'];
      }
      foreach ($hash_arr as $time) {
        $filtered = array_values(array_filter($bands[$time], function($e) {return $e!==null;} ));
        if (count($filtered) > 0) {
          $median = median($filtered);
          $typical_line[] = $median;
          if ($median > $max) {
            $max = $median;
          }
          if ($median < $min) {
            $min = $median;
          }
          sort($filtered);
          $bands[$time] = $filtered;
        } else {
          $typical_line[] = null;
          $bands[$time] = [];
        }
      }
      $typical_line = change_res($typical_line, $num_points);
    }
  }
  // $accumulation = [];
  // foreach ($bands as $band) {
  // }
  if (count($typical_line) > 0) {
    $values[] = $typical_line; // typical line is always 3rd array in $values
    // array_splice($values, 2, 0, [$typical_line]);
  }
}
?>
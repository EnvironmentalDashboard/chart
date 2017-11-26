<?php
function median($arr) {
  if (empty($arr)) {
    return 0;
  }
  $count = count($arr);
  $mid = floor(($count-1)/2);
  if ($count % 2) {
    $median = $arr[$mid];
  } else {
    $low = $arr[$mid];
    $high = $arr[$mid+1];
    $median = (($low+$high)/2);
  }
  return $median;
}
?>
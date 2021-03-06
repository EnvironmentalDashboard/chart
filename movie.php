<?php
header("Content-Type: text/plain");
require '../includes/db.php';
$rv = $_GET['relative_value'];
$count = $_GET['count'];
if ($count < 1) {
  $keyword = 'Point';
} else if ($count < 3) {
  $keyword = 'Emo';
} else if ($count < 4) {
  $keyword = 'Story';
} else {
  $keyword = null;
}
if ($_GET['charachter'] === 'squirrel') {
  $keyword2 = 'SQ\_'; // escaple _! https://stackoverflow.com/a/8236824/2624391
} else if ($_GET['charachter'] === 'fish') {
  $keyword2 = 'Wally\_';
} else {
  $keyword2 = null;
}
if ($rv > 80) { $bin = 'bin1'; $bg_name = 'bg3'; }
else if ($rv > 60) { $bin = 'bin2'; $bg_name = 'bg2'; }
else if ($rv > 40) { $bin = 'bin3'; $bg_name = 'bg2'; }
else if ($rv > 20) { $bin = 'bin4'; $bg_name = 'bg1'; }
else { $bin = 'bin5'; $bg_name = 'bg1'; }

if ($keyword === null && $keyword2 === null) {
  $stmt = $db->prepare("SELECT name, length FROM time_series WHERE length > 0 AND {$bin} > 0 AND user_id = ? ORDER BY {$bin} * rand() * rand() * rand() * rand() * rand() * rand() DESC LIMIT 1");
  $stmt->execute(array($user_id));
} elseif ($keyword === null) {
  $stmt = $db->prepare("SELECT name, length FROM time_series WHERE length > 0 AND {$bin} > 0 AND user_id = ? AND name LIKE ? ORDER BY {$bin} * rand() * rand() * rand() * rand() * rand() * rand() DESC LIMIT 1");
  $stmt->execute(array($user_id, "%{$keyword2}%"));
} elseif ($keyword2 === null) {
  $stmt = $db->prepare("SELECT name, length FROM time_series WHERE length > 0 AND {$bin} > 0 AND user_id = ? AND name LIKE ? ORDER BY {$bin} * rand() * rand() * rand() * rand() * rand() * rand() DESC LIMIT 1");
  $stmt->execute(array($user_id, "%{$keyword}%"));
} else {
  $stmt = $db->prepare("SELECT name, length FROM time_series WHERE length > 0 AND {$bin} > 0 AND user_id = ? AND (name LIKE ? AND name LIKE ?) ORDER BY {$bin} * rand() * rand() * rand() * rand() * rand() * rand() DESC LIMIT 1"); // Multiply by random numbers to reduce the influence of the bin but still use it for weighting the randomness
  $stmt->execute(array($user_id, "%{$keyword}%", "%{$keyword2}%"));
}
$result = $stmt->fetch();
if (is_array($result)) {
  if ($_GET['charachter'] === 'fish') {
    array_push($result, $bg_name);
  } else {
    array_push($result, 'none');
  }
  echo implode('$SEP$', $result);
} else { // choose a random default
  echo 'SQ_Fill_NeutralActionsEyeroll$SEP$18160$SEP$none';
}
?>
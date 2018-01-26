<?php
require '/var/www/repos/includes/db.php';

foreach (glob("*.gif") as $filename) {
  $filename = pathinfo($filename, PATHINFO_FILENAME);
  $stmt = $db->prepare('SELECT length FROM time_series WHERE name = ?');
  $stmt->execute(array($filename));
  $len = $stmt->fetch()['length'];
  if ($len === null) { // No match
    continue;
  }
  $new_len = intval(`python /var/www/repos/time-series/gifduration/gifduration.py {$filename}.gif`);
  $stmt = $db->prepare('UPDATE time_series SET length = ? WHERE name = ?');
  $stmt->execute(array($new_len, $filename));
}
?>
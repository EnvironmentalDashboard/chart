<?php
require_once '../includes/db.php';
$stmt = $db->prepare('INSERT INTO saved_charts (id) VALUES (NULL)');
$stmt->execute();
$id = $db->lastInsertId();
$redirect = [];
$i = 0;
foreach ($_GET as $key => $value) {
  if (substr($key, 0, 5) === 'meter' && is_numeric($value)) {
  	$redirect["meter{$i}"] = $value;
  	$stmt = $db->prepare('INSERT INTO saved_chart_meters (meter_id, chart_id) VALUES (?, ?)');
  	$stmt->execute([$value, $id]);
  }
}
header('Location: https://environmentaldashboard.org/chart/?'.http_build_query($redirect));
?>
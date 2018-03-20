<?php
require_once '../includes/db.php';
$dropdown_html = '';
$buildings = $db->query("SELECT * FROM buildings WHERE user_id = {$user_id} ORDER BY name ASC");
foreach ($buildings->fetchAll() as $building) {
  $stmt = $db->prepare('SELECT id, name FROM meters WHERE building_id = ? AND ((gauges_using > 0 OR for_orb > 0 OR timeseries_using > 0) OR bos_uuid IN (SELECT DISTINCT meter_uuid FROM relative_values WHERE permission = \'orb_server\')) ORDER BY name');
  $stmt->execute(array($building['id']));
  $once = true;
  foreach($stmt->fetchAll() as $meter) {
    if ($once) {
      $once = false;
      $dropdown_html .= "<optgroup label='{$building['name']}'>";
    }
    $dropdown_html .= "<option value='{$meter['id']}'>{$meter['name']}</option>";
  }
  if (!$once) {
    $dropdown_html .= '</optgroup>';
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <link href="https://fonts.googleapis.com/css?family=Roboto:400,700" rel="stylesheet">
  <link rel="stylesheet" href="forms.css?v=<?php echo @time() ?>">
  <title>Create Time Series</title>
</head>
<body>
<div style="width: 50%;min-width: 200px;margin: 0 auto;margin-top: 5%">
  <h1><?php echo (isset($error)) ? "There are no data for meter {$error}, please select another" : 'This time series is not configured.' ?></h1>
  <p>At minimum, a time series needs 1 meter ID to chart. Please select a meter from the list below:</p>
  <form action="index.php" method="GET">
    <div class="select" style="width: 100%">
      <select aria-label="Select a meter" name="meter0">
        <?php echo $dropdown_html ?>
      </select>
    </div>
    <div id="more_selects"></div>
    <p><a href="#" onclick="add_dropdown()">Add another meter</a></p>
    <label class="control checkbox">
      <input type="checkbox" name="title_img">
      <span class="control-indicator"></span>
      Title image
    </label>
    <label class="control checkbox">
      <input type="checkbox" name="title_txt">
      <span class="control-indicator"></span>
      Title text
    </label>
    <button type="submit">Submit</button>
  </form>
</div>
<script>
var other_selects = document.getElementById('more_selects'),
    cur_meter = 1,
    select_html = <?php echo json_encode($dropdown_html) ?>;
function add_dropdown() {
  other_selects.insertAdjacentHTML('beforeend', '<div class="select" style="width: 100%"><select aria-label="Select a meter" name="meter'+(cur_meter++)+'">' + select_html + '</select></div>');
}
</script>
</body>
</html>
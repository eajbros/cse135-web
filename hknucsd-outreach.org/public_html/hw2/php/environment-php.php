<?php
header("Cache-Control: no-cache");
header("Content-Type: text/html");
?>
<!DOCTYPE html>
<html>
<head>
  <title>Environment Variables</title>
  <script defer src="https://collector.hknucsd-outreach.org/collector.js?v=<?=time()?>" ></script>
</head>
<body>
  <h1 align="center">Environment Variables</h1>
  <hr>

<?php
ksort($_SERVER);
foreach ($_SERVER as $key => $value) {
    echo "<b>$key:</b> $value<br />\n";
}
?>

</body>
</html>
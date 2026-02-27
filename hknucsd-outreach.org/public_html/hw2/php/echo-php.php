<?php
header("Cache-Control: no-cache");
header("Content-Type: text/html");

$protocol = $_SERVER['SERVER_PROTOCOL'] ?? '';
$method   = $_SERVER['REQUEST_METHOD'] ?? '';
$query    = $_SERVER['QUERY_STRING'] ?? '';
$ua       = $_SERVER['HTTP_USER_AGENT'] ?? '';
$ip       = $_SERVER['REMOTE_ADDR'] ?? '';
$host     = $_SERVER['HTTP_HOST'] ?? '';
$time     = date("r");

/* Read raw body (works for POST / PUT / DELETE) */
$body = file_get_contents("php://input");
?>
<!DOCTYPE html>
<html>
<head>
  <title>General Request Echo</title>
  <script defer src="https://collector.hknucsd-outreach.org/collector.js?v=<?=time()?>" ></script>
</head>
<body>
  <h1 align="center">General Request Echo</h1>
  <hr>

  <p><b>Hostname:</b> <?= htmlspecialchars($host) ?></p>
  <p><b>Time:</b> <?= htmlspecialchars($time) ?></p>
  <p><b>User Agent:</b> <?= htmlspecialchars($ua) ?></p>
  <p><b>IP Address:</b> <?= htmlspecialchars($ip) ?></p>

  <p><b>HTTP Protocol:</b> <?= htmlspecialchars($protocol) ?></p>
  <p><b>HTTP Method:</b> <?= htmlspecialchars($method) ?></p>
  <p><b>Query String:</b> <?= htmlspecialchars($query) ?></p>
  <p><b>Message Body:</b> <?= htmlspecialchars($body) ?></p>

</body>
</html>
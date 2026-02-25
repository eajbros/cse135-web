<?php
header("Cache-Control: no-cache");
header("Content-Type: text/html");

$date = date("r");
$ip = $_SERVER['REMOTE_ADDR'];
?>
<!DOCTYPE html>
<html>
<head>
  <title>Hello CGI World</title>
</head>
<body>
  <h1 align="center">Hello HTML World</h1><hr/>
  <p>Hello World</p>
  <p>This page was generated with the PHP programming language</p>
  <p>This program was generated at: <?= $date ?></p>
  <p>Your current IP Address is: <?= $ip ?></p>
</body>
</html>

<?php
session_save_path("/tmp");

session_start();

$_SESSION = [];

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        "",
        time() - 3600,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

session_destroy();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>PHP Session Destroyed</title>
  <script defer src="https://collector.hknucsd-outreach.org/collector.js?v=<?=time()?>" ></script>
</head>
<body>
  <h1>Session Destroyed</h1>

  <a href="./php-cgiform.html">Back to the PHP CGI Form</a><br>
  <a href="./state-php-1.php">Back to Page 1</a><br>
  <a href="./state-php-2.php">Back to Page 2</a>
</body>
</html>

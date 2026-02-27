<?php
session_save_path("/tmp");
session_start(); 

$name = $_SESSION["username"] ?? null;
if ($name === null) {
    $incoming = $_REQUEST["username"] ?? null;
    if ($incoming !== null && $incoming !== "") {
        $name = $incoming;
        $_SESSION["username"] = $name; 
    }
}

function h($s) {
    return htmlspecialchars($s ?? "", ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>PHP Sessions</title>
  <script defer src="https://collector.hknucsd-outreach.org/collector.js?v=<?=time()?>" ></script>
</head>
<body>
  <h1>PHP Sessions Page 1</h1>

  <?php if ($name): ?>
    <p><b>Name:</b> <?= h($name) ?></p>
  <?php else: ?>
    <p><b>Name:</b> You do not have a name set</p>
  <?php endif; ?>

  <br><br>

  <a href="./state-php-2.php">Session Page 2</a><br>
  <a href="./php-cgiform.html">PHP CGI Form</a><br>

  <form style="margin-top:30px" action="./state-php-destroy.php" method="get">
    <button type="submit">Destroy Session</button>
  </form>
</body>
</html>

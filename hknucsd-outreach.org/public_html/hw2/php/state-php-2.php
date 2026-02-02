<?php
session_save_path("/tmp");
session_start();

$name = $_SESSION["username"] ?? null;

function h($s) {
    return htmlspecialchars($s ?? "", ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>PHP Sessions</title>
</head>
<body>
  <h1>PHP Sessions Page 2</h1>

  <?php if ($name): ?>
    <p><b>Name:</b> <?= h($name) ?></p>
  <?php else: ?>
    <p><b>Name:</b> You do not have a name set</p>
  <?php endif; ?>

  <br><br>
  <a href="./php-sessions-1.php">Session Page 1</a><br>
  <a href="./php-cgiform.html">PHP CGI Form</a><br>
</body>
</html>

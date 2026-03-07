<?php
require_once 'auth.php';
require_login();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Dashboard</title>
</head>
<body>
  <h1>Dashboard</h1>
  <p>Welcome, <?= htmlspecialchars($_SESSION['username']) ?>.</p>
  <p>This page is protected.</p>
  <a href="/report.php">View Data Table</a>
  <a href="/charts.php">View Charts</a>
  <a href="/logout.php">Logout</a>
</body>
</html>
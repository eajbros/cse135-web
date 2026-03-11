<?php
require_once 'auth.php';
require_login();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Dashboard</title>
  <style>
    body { font-family: Arial, sans-serif; margin: 0; padding: 20px; }
    nav { background: #333; color: white; padding: 15px; margin: -20px -20px 20px; display: flex; justify-content: space-between; }
    nav a { color: white; text-decoration: none; margin: 0 15px; }
    h1 { color: #333; }
    .role { background: #f0f0f0; padding: 5px 10px; border-radius: 3px; font-size: 0.9em; }
    a { color: #2563eb; }
    table { width: 100%; border-collapse: collapse; margin: 20px 0; }
    th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
    th { background: #f0f0f0; }
  </style>
</head>
<body>
  <nav>
    <div><strong>Dashboard</strong></div>
    <div>
      <span class="role"><?= htmlspecialchars(get_user_role()) ?></span>
      <?php if (is_admin() || is_analyst()): ?>
        <a href="/charts.php">Charts</a>
        <a href="/report.php">Reports</a>
      <?php endif; ?>
      <?php if (is_admin()): ?>
        <a href="/users.php">Manage Users</a>
      <?php endif; ?>
      <a href="/logout.php">Logout</a>
    </div>
  </nav>

  <h1>Welcome, <?= htmlspecialchars($_SESSION['username']) ?>!</h1>
  <p>Role: <span class="role"><?= htmlspecialchars(get_user_role()) ?></span></p>

  <?php if (is_viewer()): ?>
    <p>You have read-only access to saved reports.</p>
  <?php elseif (is_analyst()): ?>
    <p>You have access to analytics.</p>
    <?php if (empty(get_allowed_sections())): ?>
      <p><strong>No sections assigned yet.</strong></p>
    <?php else: ?>
      <p>Assigned sections: <?= htmlspecialchars(implode(', ', get_allowed_sections())) ?></p>
    <?php endif; ?>
  <?php else: ?>
    <p>You are an administrator.</p>
  <?php endif; ?>
</body>
</html>
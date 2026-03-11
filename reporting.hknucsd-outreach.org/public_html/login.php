<?php
require_once 'auth.php';
require_once 'db.php';

if (is_logged_in()) {
    header('Location: /index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare('SELECT id, username, password_hash, role, allowed_sections, display_name FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['logged_in'] = true;
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['display_name'] = $user['display_name'] ?? $user['username'];
        $_SESSION['role'] = $user['role'] ?? 'viewer';
        
        // Decode allowed_sections JSON for analysts
        if ($user['allowed_sections']) {
            $_SESSION['allowed_sections'] = json_decode($user['allowed_sections'], true) ?? [];
        } else {
            $_SESSION['allowed_sections'] = [];
        }

        header('Location: /index.php');
        exit;
    } else {
        $error = 'Invalid username or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Login</title>
  <style>
    body { font-family: Arial, sans-serif; max-width: 400px; margin: 100px auto; padding: 20px; }
    input { width: 100%; padding: 8px; margin: 10px 0; }
    button { width: 100%; padding: 10px; background: #2563eb; color: white; border: none; cursor: pointer; }
    .error { color: red; }
  </style>
</head>
<body>
  <h1>Login</h1>

  <?php if ($error): ?>
    <p class="error"><?= htmlspecialchars($error) ?></p>
  <?php endif; ?>

  <form method="POST" action="/login.php">
    <label>Username</label>
    <input type="text" name="username" required>

    <label>Password</label>
    <input type="password" name="password" required>

    <button type="submit">Login</button>
  </form>
</body>
</html>
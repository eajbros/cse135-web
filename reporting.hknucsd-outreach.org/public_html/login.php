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
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Analytics Login</title>
  <style>
    :root {
      --bg: #f6f8fb;
      --card: #ffffff;
      --text: #1f2937;
      --muted: #6b7280;
      --border: #e5e7eb;
      --accent: #2563eb;
      --error: #dc2626;
    }

    * { box-sizing: border-box; }

    body {
      margin: 0;
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
      background: linear-gradient(135deg, var(--accent) 0%, #1e40af 100%);
      color: var(--text);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 20px;
    }

    .login-container {
      background: var(--card);
      border-radius: 12px;
      box-shadow: 0 20px 60px rgba(0,0,0,0.15);
      padding: 40px;
      max-width: 400px;
      width: 100%;
    }

    .logo {
      text-align: center;
      margin-bottom: 32px;
    }

    .logo h1 {
      margin: 0;
      font-size: 1.8rem;
      background: linear-gradient(135deg, var(--accent) 0%, #1e40af 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }

    .logo p {
      margin: 8px 0 0;
      color: var(--muted);
      font-size: 0.95rem;
    }

    .alert {
      background: #fef2f2;
      border: 1px solid #fcdddd;
      border-radius: 8px;
      padding: 12px 16px;
      margin-bottom: 20px;
      color: var(--error);
      font-size: 0.95rem;
    }

    .form-group {
      margin-bottom: 20px;
    }

    label {
      display: block;
      margin-bottom: 8px;
      font-weight: 600;
      font-size: 0.95rem;
    }

    input[type="text"],
    input[type="password"] {
      width: 100%;
      padding: 12px 14px;
      border: 1px solid var(--border);
      border-radius: 8px;
      font-size: 0.95rem;
      transition: all 0.2s;
    }

    input[type="text"]:focus,
    input[type="password"]:focus {
      outline: none;
      border-color: var(--accent);
      box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
    }

    button {
      width: 100%;
      padding: 12px 16px;
      background: linear-gradient(135deg, var(--accent) 0%, #1e40af 100%);
      color: white;
      border: none;
      border-radius: 8px;
      font-size: 0.95rem;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.2s;
      margin-top: 8px;
    }

    button:hover {
      transform: translateY(-2px);
      box-shadow: 0 10px 20px rgba(37, 99, 235, 0.3);
    }

    button:active {
      transform: translateY(0);
    }

    .demo-creds {
      margin-top: 24px;
      padding-top: 24px;
      border-top: 1px solid var(--border);
      font-size: 0.85rem;
      color: var(--muted);
    }

    .demo-creds strong {
      display: block;
      margin-bottom: 8px;
      color: var(--text);
    }

    .cred-item {
      background: #f9fafb;
      padding: 8px 12px;
      border-radius: 6px;
      margin-bottom: 6px;
      font-family: 'Courier New', monospace;
    }
  </style>
</head>
<body>
  <div class="login-container">
    <div class="logo">
      <h1>Analytics</h1>
      <p>Role-Based Reporting Dashboard</p>
    </div>

    <?php if ($error): ?>
      <div class="alert"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="/login.php">
      <div class="form-group">
        <label for="username">Username</label>
        <input type="text" id="username" name="username" required autofocus>
      </div>

      <div class="form-group">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required>
      </div>

      <button type="submit">Login</button>
    </form>

    <div class="demo-creds">
      <strong>Demo Credentials:</strong>
      <div class="cred-item">admin / password</div>
      <div class="cred-item">analyst / password</div>
      <div class="cred-item">viewer / password</div>
    </div>
  </div>
</body>
</html>
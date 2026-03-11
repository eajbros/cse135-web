<?php
require_once 'auth.php';
require_login();
require_role('admin');
require_once 'db.php';

$message = '';
$error = '';
$action = $_GET['action'] ?? 'list';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($_POST['action'] === 'create') {
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $role = $_POST['role'] ?? 'viewer';
            $display_name = trim($_POST['display_name'] ?? '') ?: $username;
            $allowed_sections = [];

            if (empty($username) || empty($password)) {
                throw new Exception('Username and password are required.');
            }

            if ($role === 'analyst' && !empty($_POST['allowed_sections'])) {
                $allowed_sections = array_filter($_POST['allowed_sections'] ?? []);
            }

            // Check if username already exists
            $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                throw new Exception('Username already exists.');
            }

            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $allowed_sections_json = json_encode($allowed_sections);

            $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, role, allowed_sections, display_name) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$username, $password_hash, $role, $allowed_sections_json, $display_name]);

            $message = 'User created successfully!';
            $action = 'list';
        }

        elseif ($_POST['action'] === 'edit') {
            $user_id = $_POST['user_id'] ?? null;
            $display_name = trim($_POST['display_name'] ?? '');
            $role = $_POST['role'] ?? 'viewer';
            $allowed_sections = [];

            if (empty($user_id) || empty($display_name)) {
                throw new Exception('Invalid input.');
            }

            if ($role === 'analyst' && !empty($_POST['allowed_sections'])) {
                $allowed_sections = array_filter($_POST['allowed_sections'] ?? []);
            }

            $allowed_sections_json = json_encode($allowed_sections);

            $stmt = $pdo->prepare('UPDATE users SET display_name = ?, role = ?, allowed_sections = ? WHERE id = ?');
            $stmt->execute([$display_name, $role, $allowed_sections_json, $user_id]);

            $message = 'User updated successfully!';
            $action = 'list';
        }

        elseif ($_POST['action'] === 'reset_password') {
            $user_id = $_POST['user_id'] ?? null;
            $new_password = $_POST['new_password'] ?? '';

            if (empty($user_id) || empty($new_password)) {
                throw new Exception('Invalid input.');
            }

            $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $stmt->execute([$password_hash, $user_id]);

            $message = 'Password reset successfully!';
            $action = 'list';
        }

        elseif ($_POST['action'] === 'delete') {
            $user_id = $_POST['user_id'] ?? null;
            
            if (empty($user_id) || $user_id == $_SESSION['user_id']) {
                throw new Exception('Cannot delete your own account.');
            }

            $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
            $stmt->execute([$user_id]);

            $message = 'User deleted successfully!';
            $action = 'list';
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Get all users
$stmt = $pdo->query('SELECT id, username, display_name, role, allowed_sections FROM users ORDER BY username ASC');
$users = $stmt->fetchAll();

// Get user being edited
$edit_user = null;
if ($action === 'edit' && !empty($_GET['user_id'])) {
    $stmt = $pdo->prepare('SELECT id, username, display_name, role, allowed_sections FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$_GET['user_id']]);
    $edit_user = $stmt->fetch();
    if (!$edit_user) {
        $error = 'User not found.';
        $action = 'list';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>User Management - Analytics</title>
  <style>
    :root {
      --bg: #f6f8fb;
      --card: #ffffff;
      --text: #1f2937;
      --muted: #6b7280;
      --border: #e5e7eb;
      --accent: #2563eb;
      --accent-soft: #eff6ff;
      --success: #10b981;
      --warning: #f59e0b;
      --danger: #ef4444;
    }

    * { box-sizing: border-box; }

    html, body {
      margin: 0;
      padding: 0;
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
      background: var(--bg);
      color: var(--text);
    }

    .navbar {
      background: var(--card);
      border-bottom: 1px solid var(--border);
      padding: 16px 24px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 20px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    }

    .navbar-brand {
      font-size: 1.3rem;
      font-weight: 700;
      background: linear-gradient(135deg, var(--accent) 0%, #1e40af 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }

    .navbar-nav {
      display: flex;
      gap: 20px;
    }

    .navbar-nav a {
      text-decoration: none;
      color: var(--text);
      font-weight: 500;
      font-size: 0.95rem;
      transition: color 0.2s;
    }

    .navbar-nav a:hover {
      color: var(--accent);
    }

    .container {
      max-width: 1200px;
      margin: 32px auto;
      padding: 0 20px;
    }

    h1 {
      margin: 0 0 8px;
      font-size: 2rem;
    }

    .subtitle {
      margin: 0 0 24px;
      color: var(--muted);
    }

    .alert {
      padding: 16px;
      border-radius: 8px;
      margin-bottom: 20px;
      border-left: 4px solid;
    }

    .alert.success {
      background: #ecfdf3;
      border-color: var(--success);
      color: #047857;
    }

    .alert.error {
      background: #fef2f2;
      border-color: var(--danger);
      color: #991b1b;
    }

    .card {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 24px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.04);
      margin-bottom: 24px;
    }

    .button-group {
      display: flex;
      gap: 12px;
      margin-bottom: 24px;
      flex-wrap: wrap;
    }

    button, .btn {
      padding: 10px 20px;
      border-radius: 8px;
      border: none;
      font-weight: 600;
      font-size: 0.95rem;
      cursor: pointer;
      text-decoration: none;
      display: inline-block;
      transition: all 0.2s;
    }

    .btn-primary {
      background: linear-gradient(135deg, var(--accent) 0%, #1e40af 100%);
      color: white;
    }

    .btn-primary:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 16px rgba(37, 99, 235, 0.3);
    }

    .btn-secondary {
      background: var(--border);
      color: var(--text);
    }

    .btn-secondary:hover {
      background: #d1d5db;
    }

    .btn-danger {
      background: var(--danger);
      color: white;
    }

    .btn-danger:hover {
      background: #dc2626;
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
    input[type="password"],
    select,
    textarea {
      width: 100%;
      padding: 12px;
      border: 1px solid var(--border);
      border-radius: 8px;
      font-size: 0.95rem;
      font-family: inherit;
      transition: all 0.2s;
    }

    input[type="text"]:focus,
    input[type="password"]:focus,
    select:focus,
    textarea:focus {
      outline: none;
      border-color: var(--accent);
      box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
    }

    .checkbox-group {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
      gap: 12px;
      margin-top: 12px;
    }

    .checkbox-item {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    input[type="checkbox"] {
      width: 18px;
      height: 18px;
      cursor: pointer;
    }

    .checkbox-item label {
      margin: 0;
      font-weight: 500;
      cursor: pointer;
    }

    table {
      width: 100%;
      border-collapse: collapse;
    }

    th, td {
      padding: 14px;
      text-align: left;
      border-bottom: 1px solid var(--border);
    }

    th {
      background: #f9fafb;
      font-weight: 600;
    }

    tr:hover {
      background: #f9fafb;
    }

    .role-badge {
      display: inline-block;
      padding: 6px 12px;
      border-radius: 999px;
      font-size: 0.8rem;
      font-weight: 600;
    }

    .role-badge.admin {
      background: #fef2f2;
      color: #991b1b;
    }

    .role-badge.analyst {
      background: #fef3c7;
      color: #92400e;
    }

    .role-badge.viewer {
      background: #e0e7ff;
      color: #3730a3;
    }

    .actions {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
    }

    .action-btn {
      padding: 6px 12px;
      font-size: 0.85rem;
      border-radius: 6px;
      border: none;
      font-weight: 600;
      cursor: pointer;
      text-decoration: none;
      display: inline-block;
    }

    .action-btn.edit {
      background: var(--accent-soft);
      color: var(--accent);
    }

    .action-btn.edit:hover {
      background: #dbeafe;
    }

    .action-btn.delete {
      background: #fef2f2;
      color: var(--danger);
    }

    .action-btn.delete:hover {
      background: #fee2e2;
    }

    .form-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 20px;
    }

    @media (max-width: 768px) {
      .form-row {
        grid-template-columns: 1fr;
      }

      .actions {
        flex-direction: column;
      }

      .action-btn {
        width: 100%;
        text-align: center;
      }
    }
  </style>
</head>
<body>
  <nav class="navbar">
    <div class="navbar-brand">👥 User Management</div>
    <div class="navbar-nav">
      <a href="/index.php">← Back to Dashboard</a>
    </div>
  </nav>

  <div class="container">
    <h1><?= $action === 'edit' ? 'Edit User' : 'Manage Users' ?></h1>
    <p class="subtitle">Manage system users, roles, and permissions</p>

    <?php if ($message): ?>
      <div class="alert success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
      <div class="alert error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($action === 'create' || $action === 'edit'): ?>
      <!-- Form to create/edit user -->
      <div class="card">
        <h2><?= $action === 'edit' ? 'Edit User' : 'Create New User' ?></h2>

        <form method="POST" action="/users.php">
          <input type="hidden" name="action" value="<?= htmlspecialchars($action) ?>">
          <?php if ($action === 'edit' && $edit_user): ?>
            <input type="hidden" name="user_id" value="<?= htmlspecialchars($edit_user['id']) ?>">
          <?php endif; ?>

          <div class="form-row">
            <?php if ($action === 'create'): ?>
              <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required autofocus placeholder="e.g., sam">
              </div>
            <?php else: ?>
              <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" value="<?= htmlspecialchars($edit_user['username']) ?>" disabled>
              </div>
            <?php endif; ?>

            <div class="form-group">
              <label for="display_name">Display Name</label>
              <input type="text" id="display_name" name="display_name" placeholder="e.g., Sam Anderson" value="<?= $action === 'edit' ? htmlspecialchars($edit_user['display_name']) : '' ?>" required>
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label for="role">Role</label>
              <select id="role" name="role" required onchange="updateSectionDisplay()">
                <option value="viewer" <?= ($action === 'edit' && $edit_user['role'] === 'viewer') || ($action === 'create') ? 'selected' : '' ?>>Viewer (Read-only reports)</option>
                <option value="analyst" <?= ($action === 'edit' && $edit_user['role'] === 'analyst') ? 'selected' : '' ?>>Analyst (Full access to sections)</option>
                <option value="admin" <?= ($action === 'edit' && $edit_user['role'] === 'admin') ? 'selected' : '' ?>>Admin (Full system access)</option>
              </select>
            </div>

            <?php if ($action === 'create'): ?>
              <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required placeholder="Enter a strong password">
              </div>
            <?php endif; ?>
          </div>

          <div class="form-group" id="sections-group" style="display: <?= ($action === 'edit' && $edit_user['role'] === 'analyst') || ($action === 'create' && isset($_POST['role']) && $_POST['role'] === 'analyst') ? 'block' : 'none' ?>;">
            <label>Allowed Sections (for Analysts)</label>
            <p style="margin: 8px 0; color: var(--muted); font-size: 0.9rem;">Select which sections this analyst can access</p>
            <div class="checkbox-group">
              <div class="checkbox-item">
                <input type="checkbox" id="section_performance" name="allowed_sections[]" value="performance"
                  <?php if ($action === 'edit' && $edit_user['role'] === 'analyst') {
                    $sections = json_decode($edit_user['allowed_sections'], true) ?? [];
                    if (in_array('performance', $sections)) echo 'checked';
                  } ?>>
                <label for="section_performance">Performance</label>
              </div>
              <div class="checkbox-item">
                <input type="checkbox" id="section_behavioral" name="allowed_sections[]" value="behavioral"
                  <?php if ($action === 'edit' && $edit_user['role'] === 'analyst') {
                    $sections = json_decode($edit_user['allowed_sections'], true) ?? [];
                    if (in_array('behavioral', $sections)) echo 'checked';
                  } ?>>
                <label for="section_behavioral">Behavioral</label>
              </div>
              <div class="checkbox-item">
                <input type="checkbox" id="section_engagement" name="allowed_sections[]" value="engagement"
                  <?php if ($action === 'edit' && $edit_user['role'] === 'analyst') {
                    $sections = json_decode($edit_user['allowed_sections'], true) ?? [];
                    if (in_array('engagement', $sections)) echo 'checked';
                  } ?>>
                <label for="section_engagement">Engagement</label>
              </div>
            </div>
          </div>

          <div class="button-group">
            <button type="submit" class="btn-primary">
              <?= $action === 'edit' ? 'Update User' : 'Create User' ?>
            </button>
            <a href="/users.php" class="btn btn-secondary">Cancel</a>
          </div>
        </form>
      </div>

    <?php else: ?>
      <!-- List all users -->
      <div class="button-group">
        <a href="/users.php?action=create" class="btn btn-primary">+ Create New User</a>
      </div>

      <div class="card">
        <table>
          <thead>
            <tr>
              <th>Username</th>
              <th>Display Name</th>
              <th>Role</th>
              <th>Access</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($users)): ?>
              <tr>
                <td colspan="5" style="text-align: center; padding: 32px; color: var(--muted);">
                  No users found.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($users as $user): ?>
                <tr>
                  <td style="font-weight: 500;"><?= htmlspecialchars($user['username']) ?></td>
                  <td><?= htmlspecialchars($user['display_name']) ?></td>
                  <td>
                    <span class="role-badge <?= str_replace('_', '-', $user['role']) ?>">
                      <?= htmlspecialchars(str_replace('_', ' ', $user['role'])) ?>
                    </span>
                  </td>
                  <td>
                    <?php if ($user['role'] === 'analyst' && $user['allowed_sections']): ?>
                      <?php
                        $sections = json_decode($user['allowed_sections'], true) ?? [];
                        echo htmlspecialchars(implode(', ', $sections));
                      ?>
                    <?php elseif ($user['role'] === 'admin'): ?>
                      <span style="color: var(--muted);">Full access</span>
                    <?php else: ?>
                      <span style="color: var(--muted);">Reports only</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <div class="actions">
                      <a href="/users.php?action=edit&user_id=<?= htmlspecialchars($user['id']) ?>" class="action-btn edit">Edit</a>
                      <form method="POST" action="/users.php" style="display: inline; margin: 0;">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="user_id" value="<?= htmlspecialchars($user['id']) ?>">
                        <button type="submit" class="action-btn delete" onclick="return confirm('Are you sure you want to delete this user?'); ">Delete</button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <script>
    function updateSectionDisplay() {
      const role = document.getElementById('role').value;
      const sectionsGroup = document.getElementById('sections-group');
      if (role === 'analyst') {
        sectionsGroup.style.display = 'block';
      } else {
        sectionsGroup.style.display = 'none';
      }
    }
  </script>
</body>
</html>

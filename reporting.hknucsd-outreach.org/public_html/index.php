<?php
require_once 'auth.php';
require_login();
require_once 'db.php';

// Get user info
$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$display_name = $_SESSION['display_name'];
$role = get_user_role();
$allowed_sections = get_allowed_sections();

// Get quick stats
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM beacons_raw");
    $total_beacons = $stmt->fetch()['total'] ?? 0;
    
    $stmt = $pdo->query("SELECT COUNT(DISTINCT sid) as total FROM beacons_raw");
    $total_sessions = $stmt->fetch()['total'] ?? 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM users");
    $total_users = $stmt->fetch()['total'] ?? 0;
} catch (Exception $e) {
    $total_beacons = 0;
    $total_sessions = 0;
    $total_users = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Analytics Dashboard</title>
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

    body {
      overflow-x: hidden;
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

    .navbar-content {
      display: flex;
      align-items: center;
      gap: 30px;
    }

    .navbar-nav {
      display: flex;
      gap: 24px;
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

    .user-info {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .user-avatar {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--accent) 0%, #1e40af 100%);
      color: white;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 600;
      font-size: 0.9rem;
    }

    .role-badge {
      display: inline-block;
      padding: 4px 12px;
      border-radius: 999px;
      font-size: 0.8rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.5px;
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

    .logout-btn {
      background: var(--danger);
      color: white;
      border: none;
      padding: 8px 16px;
      border-radius: 6px;
      font-weight: 600;
      cursor: pointer;
      text-decoration: none;
      display: inline-block;
      font-size: 0.9rem;
      transition: all 0.2s;
    }

    .logout-btn:hover {
      background: #dc2626;
      transform: translateY(-1px);
    }

    .container {
      max-width: 1400px;
      margin: 32px auto;
      padding: 0 20px;
    }

    .header-section {
      margin-bottom: 32px;
    }

    .header-greeting {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 20px;
      flex-wrap: wrap;
    }

    h1 {
      margin: 0;
      font-size: 2rem;
      font-weight: 700;
    }

    .header-meta {
      display: flex;
      gap: 12px;
      align-items: center;
      flex-wrap: wrap;
    }

    .grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
      gap: 20px;
      margin-bottom: 32px;
    }

    .card {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 24px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.04);
      transition: all 0.2s;
    }

    .card:hover {
      box-shadow: 0 8px 24px rgba(0,0,0,0.08);
      transform: translateY(-2px);
    }

    .stat-card {
      text-align: center;
    }

    .stat-number {
      font-size: 2.5rem;
      font-weight: 700;
      margin: 12px 0;
      background: linear-gradient(135deg, var(--accent) 0%, #1e40af 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }

    .stat-label {
      color: var(--muted);
      font-weight: 500;
    }

    .action-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
      gap: 16px;
      margin-bottom: 32px;
    }

    .action-card {
      background: var(--card);
      border: 2px solid var(--border);
      border-radius: 12px;
      padding: 20px;
      text-decoration: none;
      color: var(--text);
      transition: all 0.2s;
      display: flex;
      flex-direction: column;
      gap: 12px;
    }

    .action-card:hover {
      border-color: var(--accent);
      box-shadow: 0 8px 24px rgba(37, 99, 235, 0.15);
      transform: translateY(-4px);
    }

    .action-card-icon {
      font-size: 2rem;
      line-height: 1;
    }

    .action-card-title {
      font-weight: 600;
      font-size: 1.1rem;
    }

    .action-card-desc {
      color: var(--muted);
      font-size: 0.9rem;
    }

    .section {
      margin-bottom: 32px;
    }

    .section-title {
      font-size: 1.4rem;
      font-weight: 700;
      margin: 0 0 16px;
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .section-title-icon {
      font-size: 1.6rem;
    }

    .empty-state {
      text-align: center;
      padding: 48px 32px;
      background: var(--card);
      border: 1px dashed var(--border);
      border-radius: 12px;
      color: var(--muted);
    }

    .empty-state-icon {
      font-size: 3rem;
      margin-bottom: 16px;
      opacity: 0.5;
    }

    .alert {
      background: var(--accent-soft);
      border-left: 4px solid var(--accent);
      border-radius: 8px;
      padding: 16px;
      margin-bottom: 20px;
      color: var(--accent);
    }

    @media (max-width: 768px) {
      .navbar {
        flex-direction: column;
        align-items: flex-start;
      }

      .navbar-content {
        flex-direction: column;
        width: 100%;
        gap: 16px;
      }

      .navbar-nav {
        flex-direction: column;
        gap: 12px;
        width: 100%;
      }

      h1 {
        font-size: 1.5rem;
      }

      .grid {
        grid-template-columns: 1fr;
      }

      .action-grid {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>
<body>
  <nav class="navbar">
    <div class="navbar-brand">📊 Analytics Dashboard</div>
    <div class="navbar-content">
      <div class="navbar-nav">
        <?php if (is_admin() || is_analyst()): ?>
          <a href="/charts.php">📈 Charts</a>
          <a href="/report.php">📋 Data Table</a>
        <?php endif; ?>
        <?php if (is_admin()): ?>
          <a href="/users.php">👥 Manage Users</a>
        <?php endif; ?>
      </div>
      <div class="user-info">
        <div class="user-avatar"><?= strtoupper(substr($display_name, 0, 1)) ?></div>
        <div>
          <div style="font-weight: 600; font-size: 0.95rem;"><?= htmlspecialchars($display_name) ?></div>
          <span class="role-badge <?= str_replace('_', '-', $role) ?>"><?= str_replace('_', ' ', $role) ?></span>
        </div>
        <a href="/logout.php" class="logout-btn">Logout</a>
      </div>
    </div>
  </nav>

  <div class="container">
    <div class="header-section">
      <div class="header-greeting">
        <div>
          <h1>Welcome, <?= htmlspecialchars($display_name) ?>! 👋</h1>
          <p style="margin: 8px 0 0; color: var(--muted);">Here's an overview of your analytics</p>
        </div>
      </div>
    </div>

    <?php if (is_viewer()): ?>
      <!-- VIEWER VIEW: Read-only saved reports -->
      <div class="alert">
        <strong>Viewer Access:</strong> You have read-only access to saved reports. Contact an analyst or administrator to request specific data views.
      </div>

      <div class="section">
        <div class="section-title">
          <span class="section-title-icon">📑</span>
          Saved Reports
        </div>
        <div class="empty-state">
          <div class="empty-state-icon">📄</div>
          <p>No reports have been created yet. Please contact an analyst to create custom reports for you.</p>
        </div>
      </div>

    <?php elseif (is_analyst()): ?>
      <!-- ANALYST VIEW: Access assigned sections -->
      <div class="grid">
        <div class="stat-card card">
          <div class="stat-label">Total Beacons</div>
          <div class="stat-number"><?= number_format($total_beacons) ?></div>
        </div>
        <div class="stat-card card">
          <div class="stat-label">Active Sessions</div>
          <div class="stat-number"><?= number_format($total_sessions) ?></div>
        </div>
        <div class="stat-card card">
          <div class="stat-label">Total Users</div>
          <div class="stat-number"><?= number_format($total_users) ?></div>
        </div>
      </div>

      <?php if (empty($allowed_sections)): ?>
        <div class="alert">
          <strong>No Sections Assigned:</strong> You don't have access to any data sections yet. Contact a super admin to assign you specific sections.
        </div>
      <?php else: ?>
        <div class="section">
          <div class="section-title">
            <span class="section-title-icon">🔓</span>
            Your Access
          </div>
          <div class="action-grid">
            <?php if (in_array('performance', $allowed_sections)): ?>
              <a href="/charts.php?section=performance" class="action-card">
                <div class="action-card-icon">📊</div>
                <div class="action-card-title">Performance Analytics</div>
                <div class="action-card-desc">View performance metrics and charts</div>
              </a>
            <?php endif; ?>

            <?php if (in_array('behavioral', $allowed_sections)): ?>
              <a href="/charts.php?section=behavioral" class="action-card">
                <div class="action-card-icon">👤</div>
                <div class="action-card-title">Behavioral Data</div>
                <div class="action-card-desc">Analyze user behavior patterns</div>
              </a>
            <?php endif; ?>

            <?php if (in_array('engagement', $allowed_sections)): ?>
              <a href="/report.php?section=engagement" class="action-card">
                <div class="action-card-icon">💬</div>
                <div class="action-card-title">Engagement Metrics</div>
                <div class="action-card-desc">Track user engagement data</div>
              </a>
            <?php endif; ?>
          </div>
        </div>

        <div class="section">
          <div class="section-title">
            <span class="section-title-icon">📈</span>
            Quick Links
          </div>
          <div class="action-grid">
            <a href="/charts.php" class="action-card">
              <div class="action-card-icon">📊</div>
              <div class="action-card-title">View Charts</div>
              <div class="action-card-desc">Visualize your data with interactive charts</div>
            </a>
            <a href="/report.php" class="action-card">
              <div class="action-card-icon">📋</div>
              <div class="action-card-title">View Raw Data</div>
              <div class="action-card-desc">Browse detailed beacon data table</div>
            </a>
          </div>
        </div>
      <?php endif; ?>

    <?php else: ?>
      <!-- ADMIN VIEW: Full control -->
      <div class="grid">
        <div class="stat-card card">
          <div class="stat-label">Total Beacons</div>
          <div class="stat-number"><?= number_format($total_beacons) ?></div>
        </div>
        <div class="stat-card card">
          <div class="stat-label">Active Sessions</div>
          <div class="stat-number"><?= number_format($total_sessions) ?></div>
        </div>
        <div class="stat-card card">
          <div class="stat-label">System Users</div>
          <div class="stat-number"><?= number_format($total_users) ?></div>
        </div>
      </div>

      <div class="section">
        <div class="section-title">
          <span class="section-title-icon">🔐</span>
          Administration
        </div>
        <div class="action-grid">
          <a href="/users.php" class="action-card">
            <div class="action-card-icon">👥</div>
            <div class="action-card-title">Manage Users</div>
            <div class="action-card-desc">Create, edit, and delete users • Assign roles and permissions</div>
          </a>
          <a href="/charts.php" class="action-card">
            <div class="action-card-icon">📊</div>
            <div class="action-card-title">View Charts</div>
            <div class="action-card-desc">Full access to all analytics and visualizations</div>
          </a>
          <a href="/report.php" class="action-card">
            <div class="action-card-icon">📋</div>
            <div class="action-card-title">View Raw Data</div>
            <div class="action-card-desc">Complete beacon data and event logs</div>
          </a>
        </div>
      </div>

      <div class="section">
        <div class="section-title">
          <span class="section-title-icon">ℹ️</span>
          System Information
        </div>
        <div class="card">
          <table style="width: 100%; border-collapse: collapse;">
            <tr style="border-bottom: 1px solid var(--border);">
              <td style="padding: 12px; font-weight: 600;">API Endpoint</td>
              <td style="padding: 12px; color: var(--muted);"><?= htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'localhost') ?></td>
            </tr>
            <tr style="border-bottom: 1px solid var(--border);">
              <td style="padding: 12px; font-weight: 600;">Database</td>
              <td style="padding: 12px; color: var(--muted);">collector_db</td>
            </tr>
            <tr>
              <td style="padding: 12px; font-weight: 600;">Session ID</td>
              <td style="padding: 12px; color: var(--muted);"><?= htmlspecialchars(session_id()) ?></td>
            </tr>
          </table>
        </div>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>
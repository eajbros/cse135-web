<?php
require_once 'auth.php';
require_login();

require_once 'db.php';

$display_name = $_SESSION['display_name'] ?? $_SESSION['username'];
$role = get_user_role();
$avatar_char = strtoupper(substr($display_name, 0, 1));
$user_id = $_SESSION['user_id'] ?? null;

// Fetch saved reports and merge with legacy exports
$all_downloads = [];

// Fetch saved reports from database
try {
  $stmt = $pdo->prepare("
    SELECT id, report_name, category, created_at, report_data, pdf_path, analyst_id, u.display_name
    FROM saved_reports
    LEFT JOIN users u ON saved_reports.analyst_id = u.id
    WHERE pdf_path IS NOT NULL
    ORDER BY created_at DESC
  ");
  $stmt->execute();
  $saved_reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
  
  foreach ($saved_reports as $report) {
    $all_downloads[] = [
      'type' => 'saved_report',
      'name' => $report['report_name'],
      'category' => $report['category'],
      'creator' => $report['display_name'] ?? 'Unknown',
      'date' => strtotime($report['created_at']),
      'date_formatted' => date('M d, Y', strtotime($report['created_at'])),
      'url' => '/exports/' . htmlspecialchars($report['pdf_path']),
      'id' => $report['id'],
      'analyst_id' => $report['analyst_id']
    ];
  }
} catch (Exception $e) {
  // Silently fail if table doesn't exist
  $saved_reports = [];
}

// Fetch legacy exports from filesystem
$exports_dir = __DIR__ . '/exports';
if (is_dir($exports_dir)) {
  $files = glob($exports_dir . '/chart-export-*.pdf') ?: [];
  
  foreach ($files as $file) {
    $basename = basename($file);
    $modified = filemtime($file);
    
    $all_downloads[] = [
      'type' => 'legacy_export',
      'name' => $basename,
      'category' => 'Export',
      'creator' => 'System',
      'date' => $modified ?: 0,
      'date_formatted' => $modified ? date('M d, Y', $modified) : 'Unknown',
      'url' => '/exports/' . rawurlencode($basename),
      'id' => null,
      'analyst_id' => null
    ];
  }
}

// Sort all downloads by date descending
usort($all_downloads, function($a, $b) {
  return $b['date'] <=> $a['date'];
});

// Keep only the 10 most recent
$all_downloads = array_slice($all_downloads, 0, 10);

// Handle delete from dashboard - only allow deletion by report owner or admin
$success_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_report') {
  try {
    $report_id = (int)($_POST['report_id'] ?? 0);
    if ($report_id > 0 && $user_id) {
      // Check if user owns the report or is admin
      $stmt = $pdo->prepare("SELECT analyst_id FROM saved_reports WHERE id = ?");
      $stmt->execute([$report_id]);
      $report = $stmt->fetch(PDO::FETCH_ASSOC);
      
      if ($report && ((int)$report['analyst_id'] === (int)$user_id || is_admin())) {
        $stmt = $pdo->prepare("DELETE FROM saved_reports WHERE id = ?");
        $stmt->execute([$report_id]);
        $success_msg = 'Report deleted.';
        // Rebuild all_downloads list
        $all_downloads = [];
        $stmt = $pdo->prepare("
          SELECT id, report_name, category, created_at, report_data, pdf_path, analyst_id, u.display_name
          FROM saved_reports
          LEFT JOIN users u ON saved_reports.analyst_id = u.id
          WHERE pdf_path IS NOT NULL
          ORDER BY created_at DESC
        ");
        $stmt->execute();
        $saved_reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($saved_reports as $r) {
          $all_downloads[] = [
            'type' => 'saved_report',
            'name' => $r['report_name'],
            'category' => $r['category'],
            'creator' => $r['display_name'] ?? 'Unknown',
            'date' => strtotime($r['created_at']),
            'date_formatted' => date('M d, Y', strtotime($r['created_at'])),
            'url' => '/exports/' . htmlspecialchars($r['pdf_path']),
            'id' => $r['id'],
            'analyst_id' => $r['analyst_id']
          ];
        }
        
        // Re-add legacy exports
        $exports_dir = __DIR__ . '/exports';
        if (is_dir($exports_dir)) {
          $files = glob($exports_dir . '/chart-export-*.pdf') ?: [];
          foreach ($files as $file) {
            $basename = basename($file);
            $modified = filemtime($file);
            $all_downloads[] = [
              'type' => 'legacy_export',
              'name' => $basename,
              'category' => 'Export',
              'creator' => 'System',
              'date' => $modified ?: 0,
              'date_formatted' => $modified ? date('M d, Y', $modified) : 'Unknown',
              'url' => '/exports/' . rawurlencode($basename),
              'id' => null,
              'analyst_id' => null
            ];
          }
        }
        
        usort($all_downloads, function($a, $b) {
          return $b['date'] <=> $a['date'];
        });
        $all_downloads = array_slice($all_downloads, 0, 10);
      }
    }
  } catch (Exception $e) {
    // Silently fail
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard</title>
  <style>
    :root {
      --bg: #f6f8fb;
      --card: #ffffff;
      --text: #1f2937;
      --muted: #6b7280;
      --border: #e5e7eb;
      --accent: #2563eb;
    }

    * { box-sizing: border-box; }

    body {
      margin: 0;
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

    .navbar-content {
      display: flex;
      align-items: center;
      gap: 30px;
    }

    .navbar-nav {
      display: flex;
      gap: 24px;
      align-items: center;
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
      background: #ef4444;
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
      max-width: 1200px;
      margin: 32px auto;
      padding: 0 20px;
    }

    .page-header {
      margin-bottom: 20px;
    }

    h1 {
      margin: 0;
      font-size: 2rem;
    }

    .subtitle {
      margin: 6px 0 0;
      color: var(--muted);
    }

    .panel {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 16px;
      box-shadow: 0 8px 24px rgba(0,0,0,0.05);
      padding: 20px;
    }

    .panel p {
      margin: 0 0 12px;
    }

    .panel p:last-child {
      margin-bottom: 0;
    }

    .section-title {
      margin: 24px 0 12px;
      font-size: 1.1rem;
      font-weight: 700;
      color: var(--text);
    }

    .reports-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
      gap: 12px;
    }

    .report-card {
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 12px;
      background: #f9fafb;
      display: flex;
      flex-direction: column;
      gap: 8px;
    }

    .report-title {
      font-weight: 600;
      color: var(--text);
      word-break: break-word;
      font-size: 0.95rem;
    }

    .report-badge {
      display: inline-block;
      padding: 3px 8px;
      background: var(--accent);
      color: white;
      border-radius: 4px;
      font-size: 0.75rem;
      font-weight: 600;
      text-transform: capitalize;
      width: fit-content;
    }

    .report-meta {
      color: var(--muted);
      font-size: 0.8rem;
    }

    .report-actions {
      display: flex;
      gap: 6px;
      margin-top: 6px;
    }

    .report-actions a,
    .report-actions button {
      flex: 1;
      padding: 6px 8px;
      border: none;
      border-radius: 4px;
      font-size: 0.8rem;
      font-weight: 600;
      cursor: pointer;
      text-decoration: none;
      text-align: center;
      transition: all 0.2s;
    }

    .report-actions a {
      background: var(--accent);
      color: white;
    }

    .report-actions a:hover {
      background: #1d4ed8;
    }

    .report-actions button {
      background: #f3f4f6;
      color: var(--text);
    }

    .report-actions button:hover {
      background: #e5e7eb;
    }

    .success-msg {
      padding: 10px 12px;
      margin-bottom: 16px;
      background: #ecfdf3;
      border: 1px solid #a7f3d0;
      border-radius: 6px;
      color: #047857;
      font-size: 0.9rem;
      font-weight: 500;
    }
  </style>
</head>
<body>
  <nav class="navbar">
    <div class="navbar-brand">Dashboard</div>
    <div class="navbar-content">
      <div class="navbar-nav">
        <?php if (is_admin() || is_analyst()): ?>
          <a href="/charts.php">Charts</a>
          <a href="/report.php">Data Table</a>
        <?php endif; ?>
        <?php if (is_admin()): ?>
          <a href="/users.php">Manage Users</a>
        <?php endif; ?>
      </div>
      <div class="user-info">
        <div class="user-avatar"><?= htmlspecialchars($avatar_char) ?></div>
        <div>
          <div style="font-weight: 600; font-size: 0.95rem;"><?= htmlspecialchars($display_name) ?></div>
          <span class="role-badge <?= str_replace('_', '-', $role) ?>"><?= str_replace('_', ' ', $role) ?></span>
        </div>
        <a href="/logout.php" class="logout-btn">Logout</a>
      </div>
    </div>
  </nav>

  <div class="container">
    <div class="page-header">
      <h1>Welcome, <?= htmlspecialchars($_SESSION['username']) ?>!</h1>
      <p class="subtitle">Your reporting portal home</p>
    </div>

    <?php if ($success_msg): ?>
      <div class="success-msg"><?= htmlspecialchars($success_msg) ?></div>
    <?php endif; ?>

    <div class="panel">
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

      <?php if (is_analyst() || is_admin()): ?>
        <h2 class="section-title">Saved Reports & Downloads</h2>
        <?php if (empty($all_downloads)): ?>
          <p>No saved reports yet. <a href="/charts.php" style="color: var(--accent); font-weight: 600;">Create one from Charts</a></p>
        <?php else: ?>
          <div class="reports-grid">
            <?php foreach ($all_downloads as $item): ?>
              <div class="report-card">
                <div class="report-title"><?= htmlspecialchars($item['name']) ?></div>
                <span class="report-badge"><?= htmlspecialchars($item['category']) ?></span>
                <div class="report-meta">
                  By: <?= htmlspecialchars($item['creator']) ?><br>
                  Date: <?= htmlspecialchars($item['date_formatted']) ?>
                </div>
                <div class="report-actions">
                  <a href="<?= htmlspecialchars($item['url']) ?>" target="_blank">Download</a>
                  <?php if ($item['type'] === 'saved_report' && ((int)$user_id === (int)$item['analyst_id'] || is_admin())): ?>
                    <form method="POST" style="flex: 1; margin: 0;">
                      <input type="hidden" name="action" value="delete_report">
                      <input type="hidden" name="report_id" value="<?= htmlspecialchars((string)$item['id']) ?>">
                      <button type="submit" onclick="return confirm('Delete?');">Delete</button>
                    </form>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      <?php else: ?>
        <?php if (!empty($all_downloads)): ?>
          <h2 class="section-title">Saved Reports & Downloads</h2>
          <div class="reports-grid">
            <?php foreach ($all_downloads as $item): ?>
              <div class="report-card">
                <div class="report-title"><?= htmlspecialchars($item['name']) ?></div>
                <span class="report-badge"><?= htmlspecialchars($item['category']) ?></span>
                <div class="report-meta">
                  By: <?= htmlspecialchars($item['creator']) ?><br>
                  Date: <?= htmlspecialchars($item['date_formatted']) ?>
                </div>
                <div class="report-actions">
                  <a href="<?= htmlspecialchars($item['url']) ?>" target="_blank" style="flex: 1;">Download</a>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
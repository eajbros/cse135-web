<?php
require_once __DIR__ . '/auth.php';
require_login();

// Only super admin and analysts can view raw data
if (!is_admin() && !is_analyst()) {
    http_response_code(403);
    die('Access denied. You do not have permission to view this data.');
}

require_once __DIR__ . '/db.php';

$stmt = $pdo->query("
    SELECT id, received_at, sid, page, sent_at, payload
    FROM beacons_raw
    ORDER BY received_at DESC
    LIMIT 100
");

$rows = $stmt->fetchAll();

function pretty_json($json) {
    $decoded = json_decode($json, true);
    if ($decoded === null) {
        return $json;
    }
    return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

function event_count($json) {
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) return 0;
    return isset($decoded['events']) && is_array($decoded['events']) ? count($decoded['events']) : 0;
}

// Get current user info
$display_name = $_SESSION['display_name'] ?? $_SESSION['username'];
$role = get_user_role();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Collected Beacon Data</title>
  <style>
    :root {
      --bg: #f6f8fb;
      --card: #ffffff;
      --text: #1f2937;
      --muted: #6b7280;
      --border: #e5e7eb;
      --accent: #2563eb;
      --accent-soft: #eff6ff;
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
      max-width: 1400px;
      margin: 32px auto;
      padding: 0 20px;
    }

    .page-header {
      margin-bottom: 24px;
    }

    h1 {
      margin: 0;
      font-size: 2rem;
    }

    .subtitle {
      margin: 6px 0 0;
      color: var(--muted);
    }

    .card {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 16px;
      box-shadow: 0 8px 24px rgba(0,0,0,0.05);
      overflow: hidden;
    }

    .card-header {
      padding: 18px 20px;
      border-bottom: 1px solid var(--border);
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;
    }

    .card-header p {
      margin: 0;
      color: var(--muted);
    }

    .table-wrap {
      overflow-x: auto;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      min-width: 1100px;
    }

    th, td {
      padding: 14px 16px;
      border-bottom: 1px solid var(--border);
      text-align: left;
      vertical-align: top;
    }

    th {
      background: #f9fafb;
      font-size: 0.95rem;
    }

    td {
      font-size: 0.94rem;
    }

    tr:hover {
      background: #fafcff;
    }

    .mono {
      font-family: "Courier New", monospace;
      font-size: 0.9rem;
      word-break: break-word;
    }

    .truncate {
      max-width: 260px;
      word-break: break-word;
    }

    .badge {
      display: inline-block;
      padding: 6px 10px;
      border-radius: 999px;
      background: var(--accent-soft);
      color: var(--accent);
      font-size: 0.85rem;
      font-weight: 700;
    }

    details {
      max-width: 420px;
    }

    details summary {
      cursor: pointer;
      color: var(--accent);
      font-weight: 600;
      margin-bottom: 8px;
    }

    pre {
      margin: 0;
      padding: 12px;
      background: #111827;
      color: #f9fafb;
      border-radius: 10px;
      overflow-x: auto;
      white-space: pre-wrap;
      word-break: break-word;
      font-size: 12px;
      line-height: 1.45;
    }

    .empty {
      padding: 28px 20px;
      color: var(--muted);
    }
  </style>
</head>
<body>
  <nav class="navbar">
    <div class="navbar-brand">📋 Collected Beacon Data</div>
    <div class="navbar-content">
      <div class="navbar-nav">
        <a href="/index.php">← Dashboard</a>
        <a href="/charts.php">📊 Charts</a>
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
    <div class="page-header">
      <h1>Collected Beacon Data</h1>
      <p class="subtitle">Raw event data and analytics from user interactions</p>
    </div>

    <div class="card">
      <div class="card-header">
        <strong>Recent Beacon Reports</strong>
        <p>Showing the 100 most recently received records</p>
      </div>

      <?php if (empty($rows)): ?>
        <div class="empty">No beacon data found.</div>
      <?php else: ?>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>ID</th>
                <th>Received</th>
                <th>Session ID</th>
                <th>Page</th>
                <th>Sent At</th>
                <th>Events</th>
                <th>Payload</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $r): ?>
                <tr>
                  <td class="mono"><?= htmlspecialchars((string)$r['id']) ?></td>
                  <td><?= htmlspecialchars((string)$r['received_at']) ?></td>
                  <td class="mono truncate"><?= htmlspecialchars((string)$r['sid']) ?></td>
                  <td class="truncate"><?= htmlspecialchars((string)($r['page'] ?? '')) ?></td>
                  <td class="mono"><?= htmlspecialchars((string)($r['sent_at'] ?? '')) ?></td>
                  <td>
                    <span class="badge"><?= event_count($r['payload']) ?> event<?= event_count($r['payload']) === 1 ? '' : 's' ?></span>
                  </td>
                  <td>
                    <details>
                      <summary>View JSON</summary>
                      <pre><?= htmlspecialchars(pretty_json($r['payload'])) ?></pre>
                    </details>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
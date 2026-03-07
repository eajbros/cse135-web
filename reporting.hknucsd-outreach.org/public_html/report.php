<?php
require_once __DIR__ . '/auth.php';
require_login();
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
      font-family: Arial, sans-serif;
      background: var(--bg);
      color: var(--text);
    }

    .container {
      max-width: 1400px;
      margin: 32px auto;
      padding: 0 20px;
    }

    .topbar {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 16px;
      margin-bottom: 20px;
    }

    h1 {
      margin: 0;
      font-size: 2rem;
    }

    .nav {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
    }

    .nav a {
      text-decoration: none;
      color: var(--accent);
      font-weight: 600;
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
  <div class="container">
    <div class="topbar">
      <div>
        <h1>Collected Beacon Data</h1>
      </div>
      <div class="nav">
        <a href="/index.php">Dashboard</a>
        <a href="/charts.php">Charts</a>
        <a href="/logout.php">Logout</a>
      </div>
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
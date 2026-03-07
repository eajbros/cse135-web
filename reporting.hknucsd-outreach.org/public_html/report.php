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
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Beacon Reports</title>
  <style>
    body {
      font-family: Arial, sans-serif;
      margin: 24px;
    }
    table {
      border-collapse: collapse;
      width: 100%;
      table-layout: fixed;
    }
    th, td {
      border: 1px solid #ccc;
      padding: 8px;
      vertical-align: top;
      text-align: left;
    }
    th {
      background: #f4f4f4;
    }
    td pre {
      margin: 0;
      white-space: pre-wrap;
      word-break: break-word;
      font-size: 12px;
    }
    a {
      text-decoration: none;
    }
  </style>
</head>
<body>
  <h1>Collected Beacon Data</h1>
  <p><a href="/index.php">Dashboard</a> | <a href="/logout.php">Logout</a></p>

  <table>
    <thead>
      <tr>
        <th>ID</th>
        <th>Received At</th>
        <th>Session ID</th>
        <th>Page</th>
        <th>Sent At</th>
        <th>Payload</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= htmlspecialchars((string)$r['id']) ?></td>
          <td><?= htmlspecialchars((string)$r['received_at']) ?></td>
          <td><?= htmlspecialchars((string)$r['sid']) ?></td>
          <td><?= htmlspecialchars((string)($r['page'] ?? '')) ?></td>
          <td><?= htmlspecialchars((string)($r['sent_at'] ?? '')) ?></td>
          <td><pre><?= htmlspecialchars(json_encode(json_decode($r['payload'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</body>
</html>
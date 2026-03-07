<?php
require_once 'auth.php';
require_login();
require_once 'db.php';

$stmt = $pdo->query("
    SELECT
        b.sid,
        b.sent_at,
        e.type,
        e.ts,
        e.page
    FROM events e
    JOIN beacons b ON e.beacon_id = b.id
    ORDER BY e.ts DESC
    LIMIT 100
");

$rows = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html>
<head>
<title>Analytics Events</title>
<style>
table {
  border-collapse: collapse;
  width: 100%;
}
th, td {
  border: 1px solid #ccc;
  padding: 8px;
}
th {
  background: #f5f5f5;
}
</style>
</head>
<body>

<h1>Collected Events</h1>

<a href="/logout.php">Logout</a>

<table>
<thead>
<tr>
<th>Session</th>
<th>Sent At</th>
<th>Event Type</th>
<th>Timestamp</th>
<th>Page</th>
</tr>
</thead>

<tbody>

<?php foreach ($rows as $r): ?>
<tr>
<td><?= htmlspecialchars($r['sid']) ?></td>
<td><?= htmlspecialchars($r['sent_at']) ?></td>
<td><?= htmlspecialchars($r['type']) ?></td>
<td><?= htmlspecialchars($r['ts']) ?></td>
<td><?= htmlspecialchars($r['page']) ?></td>
</tr>
<?php endforeach; ?>

</tbody>
</table>

</body>
</html>
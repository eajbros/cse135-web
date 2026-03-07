<?php
require_once __DIR__ . '/auth.php';
require_login();
require_once __DIR__ . '/db.php';

function path_only($url) {
    if (!$url) {
        return '(no page)';
    }

    $path = parse_url($url, PHP_URL_PATH);

    if ($path === null || $path === false || $path === '') {
        return '/';
    }

    return $path;
}

function display_event_type($type) {
    $map = [
        'mousemove' => 'mouse move',
        'idle_start' => 'idle start',
        'idle_end' => 'idle end',
        'performance_required' => 'performance required',
        'mouseleave' => 'mouse leave',
        'mouseenter' => 'mouse enter'
    ];

    return $map[$type] ?? $type;
}

/*
 * Chart 1: Beacon records by page path
 */
$stmt1 = $pdo->query("
    SELECT
        page,
        COUNT(*) AS beacon_count
    FROM beacons_raw
    GROUP BY page
    ORDER BY beacon_count DESC
    LIMIT 10
");
$pageRows = $stmt1->fetchAll();

/*
 * Chart source data
 */
$stmt2 = $pdo->query("
    SELECT
        payload
    FROM beacons_raw
");
$payloadRows = $stmt2->fetchAll();

$pageCountsByPath = [];

foreach ($pageRows as $row) {
    $path = path_only($row['page'] ?? '');

    if (!isset($pageCountsByPath[$path])) {
        $pageCountsByPath[$path] = 0;
    }

    $pageCountsByPath[$path] += (int)$row['beacon_count'];
}

arsort($pageCountsByPath);
$pageCountsByPath = array_slice($pageCountsByPath, 0, 10, true);

$pageLabels = array_keys($pageCountsByPath);
$pageCounts = array_values($pageCountsByPath);

/*
 * Build:
 * - event count buckets
 * - filtered user interaction event type counts
 */
$eventBuckets = [
    '0' => 0,
    '1' => 0,
    '2-3' => 0,
    '4-5' => 0,
    '6+' => 0
];

$eventTypeCounts = [];

foreach ($payloadRows as $row) {
    $decoded = json_decode($row['payload'], true);

    if (!is_array($decoded)) {
        $eventBuckets['0']++;
        continue;
    }

    $events = $decoded['events'] ?? [];
    if (!is_array($events)) {
        $events = [];
    }

    $count = count($events);

    if ($count === 0) {
        $eventBuckets['0']++;
    } elseif ($count === 1) {
        $eventBuckets['1']++;
    } elseif ($count <= 3) {
        $eventBuckets['2-3']++;
    } elseif ($count <= 5) {
        $eventBuckets['4-5']++;
    } else {
        $eventBuckets['6+']++;
    }

    foreach ($events as $event) {
        $type = $event['type'] ?? '(unknown)';

        // Remove performance telemetry from the user interaction chart
        if ($type === 'perf') {
            continue;
        }

        $type = display_event_type($type);

        if (!isset($eventTypeCounts[$type])) {
            $eventTypeCounts[$type] = 0;
        }
        $eventTypeCounts[$type]++;
    }
}

arsort($eventTypeCounts);
$eventTypeCounts = array_slice($eventTypeCounts, 0, 10, true);

$bucketLabels = array_keys($eventBuckets);
$bucketCounts = array_values($eventBuckets);

$eventTypeLabels = array_keys($eventTypeCounts);
$eventTypeValues = array_values($eventTypeCounts);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Analytics Charts</title>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
      flex-wrap: wrap;
    }

    h1 {
      margin: 0;
      font-size: 2rem;
    }

    .subtitle {
      margin: 6px 0 0;
      color: var(--muted);
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

    .stats {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 16px;
      margin-bottom: 20px;
    }

    .stat-card,
    .chart-card {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 16px;
      box-shadow: 0 8px 24px rgba(0,0,0,0.05);
    }

    .stat-card {
      padding: 18px 20px;
    }

    .stat-label {
      font-size: 0.95rem;
      color: var(--muted);
      margin-bottom: 8px;
    }

    .stat-value {
      font-size: 1.8rem;
      font-weight: 700;
    }

    .charts-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(420px, 1fr));
      gap: 20px;
    }

    .chart-card {
      padding: 20px;
    }

    .chart-card h2 {
      margin: 0 0 8px;
      font-size: 1.2rem;
    }

    .chart-card p {
      margin: 0 0 16px;
      color: var(--muted);
      font-size: 0.95rem;
    }

    .chart-wrap {
      position: relative;
      height: 360px;
    }

    @media (max-width: 640px) {
      .chart-wrap {
        height: 300px;
      }
    }
  </style>
</head>
<body>
  <div class="container">
    <div class="topbar">
      <div>
        <h1>Analytics Charts</h1>
        <p class="subtitle">Visual summaries of collected beacon and event data</p>
      </div>
      <div class="nav">
        <a href="/index.php">Dashboard</a>
        <a href="/report.php">Reports</a>
        <a href="/logout.php">Logout</a>
      </div>
    </div>

    <div class="stats">
      <div class="stat-card">
        <div class="stat-label">Tracked Page Paths</div>
        <div class="stat-value"><?= htmlspecialchars((string)count($pageLabels)) ?></div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Beacon Records</div>
        <div class="stat-value"><?= htmlspecialchars((string)count($payloadRows)) ?></div>
      </div>
      <div class="stat-card">
        <div class="stat-label">User Interaction Event Types</div>
        <div class="stat-value"><?= htmlspecialchars((string)count($eventTypeLabels)) ?></div>
      </div>
    </div>

    <div class="charts-grid">
      <div class="chart-card">
        <h2>Beacon Records by Page</h2>
        <p>Counts how many stored analytics payloads were submitted from each page path.</p>
        <div class="chart-wrap">
          <canvas id="pagesChart"></canvas>
        </div>
      </div>

      <div class="chart-card">
        <h2>Events Per Payload Distribution</h2>
        <p>Shows how many events were included in each stored payload.</p>
        <div class="chart-wrap">
          <canvas id="eventBucketChart"></canvas>
        </div>
      </div>

      <div class="chart-card">
        <h2>User Interaction Events</h2>
        <p>Counts the most common non-performance interaction events across all stored payloads.</p>
        <div class="chart-wrap">
          <canvas id="eventTypeChart"></canvas>
        </div>
      </div>
    </div>
  </div>

  <script>
    const pageLabels = <?= json_encode($pageLabels) ?>;
    const pageCounts = <?= json_encode($pageCounts) ?>;

    const bucketLabels = <?= json_encode($bucketLabels) ?>;
    const bucketCounts = <?= json_encode($bucketCounts) ?>;

    const eventTypeLabels = <?= json_encode($eventTypeLabels) ?>;
    const eventTypeValues = <?= json_encode($eventTypeValues) ?>;

    new Chart(document.getElementById('pagesChart'), {
      type: 'bar',
      data: {
        labels: pageLabels,
        datasets: [{
          label: 'Beacon Records',
          data: pageCounts,
          borderWidth: 1
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            display: true
          }
        },
        scales: {
          x: {
            ticks: {
              autoSkip: false,
              maxRotation: 35,
              minRotation: 20
            }
          },
          y: {
            beginAtZero: true,
            title: {
              display: true,
              text: 'Records'
            }
          }
        }
      }
    });

    new Chart(document.getElementById('eventBucketChart'), {
      type: 'doughnut',
      data: {
        labels: bucketLabels,
        datasets: [{
          label: 'Payload Count',
          data: bucketCounts,
          borderWidth: 1
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            position: 'top'
          }
        }
      }
    });

    new Chart(document.getElementById('eventTypeChart'), {
      type: 'bar',
      data: {
        labels: eventTypeLabels,
        datasets: [{
          label: 'Interaction Event Count',
          data: eventTypeValues,
          borderWidth: 1
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            display: true
          }
        },
        scales: {
          x: {
            ticks: {
              autoSkip: false,
              maxRotation: 35,
              minRotation: 20
            }
          },
          y: {
            beginAtZero: true,
            title: {
              display: true,
              text: 'Events'
            }
          }
        }
      }
    });
  </script>
</body>
</html>
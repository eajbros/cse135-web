<?php
require_once __DIR__ . '/auth.php';
require_login();
require_once __DIR__ . '/db.php';

function display_event_type(string $type): string {
    $labels = [
        'mousemove' => 'mouse move',
        'mouseenter' => 'mouse enter',
        'mouseleave' => 'mouse leave',
        'idle_start' => 'idle start',
        'idle_end' => 'idle end',
        'keydown' => 'key down',
    ];

    return $labels[$type] ?? $type;
}

function normalize_metric_name(?string $name): ?string {
    if ($name === null) {
        return null;
    }

    $key = strtolower(trim($name));

    $map = [
        'fcp' => 'FCP',
        'fid' => 'FID',
        'lcp' => 'LCP',
        'lcpfinal' => 'LCP',
        'cls' => 'CLS',
        'tbt' => 'TBT',
        'navigationtiming' => 'navigationTiming',
    ];

    return $map[$key] ?? null;
}

function metric_rating(string $metric, ?float $value): string {
    if ($value === null) {
        return 'no data';
    }

    switch ($metric) {
        case 'FCP':
            if ($value <= 1.8) return 'good';
            if ($value <= 3.0) return 'needs improvement';
            return 'poor';

        case 'LCP':
            if ($value <= 2.5) return 'good';
            if ($value <= 4.0) return 'needs improvement';
            return 'poor';

        case 'CLS':
            if ($value <= 0.1) return 'good';
            if ($value <= 0.25) return 'needs improvement';
            return 'poor';

        case 'TBT':
            if ($value <= 0.2) return 'good';
            if ($value <= 0.6) return 'needs improvement';
            return 'poor';

        case 'FID':
            if ($value <= 0.1) return 'good';
            if ($value <= 0.3) return 'needs improvement';
            return 'poor';

        default:
            return 'no data';
    }
}

function format_metric(string $metric, ?float $value): string {
    if ($value === null) {
        return '—';
    }

    if ($metric === 'CLS') {
        return number_format($value, 3);
    }

    return number_format($value, 2) . ' s';
}

$stmt = $pdo->query("
    SELECT payload
    FROM beacons_raw
    ORDER BY received_at DESC
");

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$interactionCounts = [];
$metricValues = [
    'FCP' => [],
    'LCP' => [],
    'CLS' => [],
    'TBT' => [],
    'FID' => [],
];
$metricScores = [
    'FCP' => [],
    'LCP' => [],
    'CLS' => [],
    'TBT' => [],
    'FID' => [],
];
$navigationTimings = [];

$ignoredInteractionTypes = ['perf', 'static', 'performance_required'];

foreach ($rows as $row) {
    $payload = json_decode($row['payload'], true);
    if (!is_array($payload)) {
        continue;
    }

    $events = $payload['events'] ?? [];
    if (!is_array($events)) {
        continue;
    }

    foreach ($events as $event) {
        $type = $event['type'] ?? '(unknown)';

        if ($type !== 'perf') {
            if (in_array($type, $ignoredInteractionTypes, true)) {
                continue;
            }

            $label = display_event_type($type);
            $interactionCounts[$label] = ($interactionCounts[$label] ?? 0) + 1;
            continue;
        }

        $perf = $event['data'] ?? [];
        if (!is_array($perf)) {
            continue;
        }

        $metricName = normalize_metric_name($perf['metricName'] ?? null);
        $rawValue = $perf['data'] ?? null;
        $score = $perf['vitalsScore'] ?? null;

        $value = is_numeric($rawValue) ? (float)$rawValue : null;

        if ($metricName === 'navigationTiming' && $value !== null) {
            $navigationTimings[] = $value;
        }

        if ($metricName !== null && array_key_exists($metricName, $metricValues) && $value !== null) {
            $metricValues[$metricName][] = $value;

            if (is_string($score) && $score !== '') {
                $metricScores[$metricName][] = $score;
            }
        }
    }
}

arsort($interactionCounts);
$interactionCounts = array_slice($interactionCounts, 0, 10, true);

$interactionLabels = array_keys($interactionCounts);
$interactionData = array_values($interactionCounts);

$metricSummary = [];
foreach ($metricValues as $metric => $values) {
    $average = count($values) ? array_sum($values) / count($values) : null;

    $scoreCounts = [];
    foreach ($metricScores[$metric] as $score) {
        $scoreCounts[$score] = ($scoreCounts[$score] ?? 0) + 1;
    }

    arsort($scoreCounts);
    $dominantScore = count($scoreCounts) ? array_key_first($scoreCounts) : metric_rating($metric, $average);

    $metricSummary[$metric] = [
        'formatted' => format_metric($metric, $average),
        'rating' => $dominantScore,
        'samples' => count($values),
    ];
}

$navHistogram = [
    '0.00–0.10 s' => 0,
    '0.10–0.20 s' => 0,
    '0.20–0.30 s' => 0,
    '0.30–0.40 s' => 0,
    '0.40+ s' => 0,
];

foreach ($navigationTimings as $value) {
    if ($value < 0.10) {
        $navHistogram['0.00–0.10 s']++;
    } elseif ($value < 0.20) {
        $navHistogram['0.10–0.20 s']++;
    } elseif ($value < 0.30) {
        $navHistogram['0.20–0.30 s']++;
    } elseif ($value < 0.40) {
        $navHistogram['0.30–0.40 s']++;
    } else {
        $navHistogram['0.40+ s']++;
    }
}

$navLabels = array_keys($navHistogram);
$navData = array_values($navHistogram);
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
      --good-bg: #ecfdf3;
      --good-text: #047857;
      --warn-bg: #fff7ed;
      --warn-text: #c2410c;
      --bad-bg: #fef2f2;
      --bad-text: #b91c1c;
      --nodata-bg: #f3f4f6;
      --nodata-text: #4b5563;
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
      margin-bottom: 24px;
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

    .section {
      margin-bottom: 24px;
    }

    .section-title {
      margin: 0 0 8px;
      font-size: 1.35rem;
    }

    .section-subtitle {
      margin: 0 0 16px;
      color: var(--muted);
    }

    .scorecards {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 16px;
    }

    .scorecard,
    .chart-card {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 16px;
      box-shadow: 0 8px 24px rgba(0,0,0,0.05);
    }

    .scorecard {
      padding: 18px 20px;
    }

    .scorecard .label {
      font-size: 0.95rem;
      color: var(--muted);
      margin-bottom: 8px;
    }

    .scorecard .value {
      font-size: 1.8rem;
      font-weight: 700;
      margin-bottom: 10px;
    }

    .badge {
      display: inline-block;
      padding: 6px 10px;
      border-radius: 999px;
      font-size: 0.85rem;
      font-weight: 700;
    }

    .badge.good {
      background: var(--good-bg);
      color: var(--good-text);
    }

    .badge.needs-improvement {
      background: var(--warn-bg);
      color: var(--warn-text);
    }

    .badge.poor {
      background: var(--bad-bg);
      color: var(--bad-text);
    }

    .badge.no-data {
      background: var(--nodata-bg);
      color: var(--nodata-text);
    }

    .samples {
      margin-top: 10px;
      font-size: 0.9rem;
      color: var(--muted);
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

    .chart-meta {
      margin-bottom: 14px;
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
        <p class="subtitle">Visual summaries of collected interaction and performance data</p>
      </div>
      <div class="nav">
        <a href="/index.php">Dashboard</a>
        <a href="/report.php">Reports</a>
        <a href="/logout.php">Logout</a>
      </div>
    </div>

    <section class="section">
      <h2 class="section-title">Performance Health Overview</h2>
      <p class="section-subtitle">Average values pulled directly from logged performance events such as FCP, LCP, CLS, and FID.</p>

      <div class="scorecards">
        <?php foreach ($metricSummary as $metric => $info): ?>
          <?php $badgeClass = str_replace(' ', '-', $info['rating']); ?>
          <div class="scorecard">
            <div class="label"><?= htmlspecialchars($metric) ?></div>
            <div class="value"><?= htmlspecialchars($info['formatted']) ?></div>
            <span class="badge <?= htmlspecialchars($badgeClass) ?>">
              <?= htmlspecialchars($info['rating']) ?>
            </span>
            <div class="samples"><?= htmlspecialchars((string)$info['samples']) ?> sample(s)</div>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

    <div class="charts-grid">
      <div class="chart-card">
        <h2>User Interaction Events</h2>
        <p>Counts the most common non-performance interaction events across all stored payloads.</p>
        <div class="chart-wrap">
          <canvas id="interactionChart"></canvas>
        </div>
      </div>

      <div class="chart-card">
        <h2>Navigation Timing Distribution</h2>
        <p>Shows how all recorded <code>navigationTiming</code> values are distributed across timing ranges.</p>
        <div class="chart-meta">
          <strong>Samples:</strong> <?= htmlspecialchars((string)count($navigationTimings)) ?>
        </div>
        <div class="chart-wrap">
          <canvas id="navHistogramChart"></canvas>
        </div>
      </div>
    </div>
  </div>

  <script>
    const interactionLabels = <?= json_encode($interactionLabels) ?>;
    const interactionData = <?= json_encode($interactionData) ?>;

    const navLabels = <?= json_encode($navLabels) ?>;
    const navData = <?= json_encode($navData) ?>;

    new Chart(document.getElementById('interactionChart'), {
      type: 'bar',
      data: {
        labels: interactionLabels,
        datasets: [{
          label: 'Interaction Event Count',
          data: interactionData,
          borderWidth: 1
        }]
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            display: true
          }
        },
        scales: {
          x: {
            beginAtZero: true,
            title: {
              display: true,
              text: 'Events'
            }
          }
        }
      }
    });

    new Chart(document.getElementById('navHistogramChart'), {
      type: 'bar',
      data: {
        labels: navLabels,
        datasets: [{
          label: 'Navigation Timing Samples',
          data: navData,
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
            title: {
              display: true,
              text: 'Timing Range'
            }
          },
          y: {
            beginAtZero: true,
            title: {
              display: true,
              text: 'Sample Count'
            }
          }
        }
      }
    });
  </script>
</body>
</html>
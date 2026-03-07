<?php
require_once __DIR__ . '/auth.php';
require_login();
require_once __DIR__ . '/db.php';

function display_event_type($type) {
    $map = [
        'mousemove' => 'mouse move',
        'mouseleave' => 'mouse leave',
        'mouseenter' => 'mouse enter',
        'idle_start' => 'idle start',
        'idle_end' => 'idle end',
        'scroll' => 'scroll',
        'click' => 'click',
        'enter' => 'enter',
        'leave' => 'leave',
        'keydown' => 'key down',
    ];

    return $map[$type] ?? $type;
}

function normalize_metric_name($name) {
    $name = strtolower(trim((string)$name));

    $map = [
        'fcp' => 'FCP',
        'fp' => 'FP',
        'fid' => 'FID',
        'lcp' => 'LCP',
        'lcpfinal' => 'LCP',
        'cls' => 'CLS',
        'tbt' => 'TBT',
        'navigationtiming' => 'navigationTiming',
        'networkinformation' => 'networkInformation',
        'storageestimate' => 'storageEstimate',
        'initialbrowserdata' => 'initialBrowserData',
    ];

    return $map[$name] ?? $name;
}

function metric_rating(string $metric, $value): string {
    if ($value === null || !is_numeric($value)) {
        return 'no data';
    }

    $value = (float)$value;

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

function format_metric(string $metric, $value): string {
    if ($value === null || !is_numeric($value)) {
        return '—';
    }

    $value = (float)$value;

    if ($metric === 'CLS') {
        return number_format($value, 3);
    }

    return number_format($value, 2) . ' s';
}

$stmt = $pdo->query("
    SELECT
        id,
        received_at,
        sid,
        page,
        payload
    FROM beacons_raw
    ORDER BY received_at DESC
");

$rows = $stmt->fetchAll();

/*
 * 1) User interaction events
 */
$userInteractionCounts = [];

/*
 * 2) Performance overview
 */
$metricBuckets = [
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

/*
 * 3) Page timing overview
 * We use the most recent navigationTiming perf event.
 */
$pageTimingValue = null;
$pageTimingPage = null;
$pageTimingSid = null;

foreach ($rows as $row) {
    $decoded = json_decode($row['payload'], true);
    if (!is_array($decoded)) {
        continue;
    }

    $events = $decoded['events'] ?? [];
    if (!is_array($events)) {
        continue;
    }

    foreach ($events as $event) {
        $type = $event['type'] ?? '(unknown)';

        if ($type !== 'perf') {
            $ignore = ['static', 'performance_required'];
            if (in_array($type, $ignore, true)) {
                continue;
            }

            $label = display_event_type($type);
            if (!isset($userInteractionCounts[$label])) {
                $userInteractionCounts[$label] = 0;
            }
            $userInteractionCounts[$label]++;
            continue;
        }

        $perf = $event['data'] ?? [];
        $metricNameRaw = $perf['metricName'] ?? null;
        $metricName = normalize_metric_name($metricNameRaw);
        $metricValueRaw = $perf['data'] ?? null;
        $vitalsScore = $perf['vitalsScore'] ?? null;

        $metricValue = is_numeric($metricValueRaw) ? (float)$metricValueRaw : null;

        if (isset($metricBuckets[$metricName]) && $metricValue !== null) {
            $metricBuckets[$metricName][] = $metricValue;
            if (is_string($vitalsScore) && $vitalsScore !== '') {
                $metricScores[$metricName][] = $vitalsScore;
            }
        }

        if (
            $metricName === 'navigationTiming' &&
            $pageTimingValue === null &&
            $metricValue !== null
        ) {
            $pageTimingValue = $metricValue;
            $pageTimingPage = $decoded['page'] ?? $row['page'] ?? '(no page)';
            $pageTimingSid = $row['sid'] ?? null;
        }
    }
}

arsort($userInteractionCounts);
$userInteractionCounts = array_slice($userInteractionCounts, 0, 10, true);

$interactionLabels = array_keys($userInteractionCounts);
$interactionValues = array_values($userInteractionCounts);

$metricSummary = [];
foreach ($metricBuckets as $metricName => $values) {
    $avg = count($values) ? array_sum($values) / count($values) : null;

    $scoreCounts = [];
    foreach ($metricScores[$metricName] as $score) {
        if (!isset($scoreCounts[$score])) {
            $scoreCounts[$score] = 0;
        }
        $scoreCounts[$score]++;
    }

    arsort($scoreCounts);
    $dominantScore = count($scoreCounts) ? array_key_first($scoreCounts) : metric_rating($metricName, $avg);

    $metricSummary[$metricName] = [
        'average' => $avg,
        'rating' => $dominantScore,
        'formatted' => format_metric($metricName, $avg),
        'samples' => count($values),
    ];
}

if ($pageTimingValue === null) {
    $pageTimingValue = 0;
    $pageTimingPage = '(no timing data)';
    $pageTimingSid = null;
}

$pageTimingLabels = ['navigation timing'];
$pageTimingValues = [$pageTimingValue];
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

    .section {
      margin-bottom: 22px;
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
      margin-bottom: 20px;
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

    .chart-wrap {
      position: relative;
      height: 360px;
    }

    .chart-meta {
      margin-bottom: 14px;
      color: var(--muted);
      font-size: 0.95rem;
      word-break: break-word;
    }

    .chart-meta strong {
      color: var(--text);
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
      <p class="section-subtitle">Average values pulled directly from logged perf events like <code>fcp</code>, <code>lcp</code>, <code>cls</code>, and <code>fid</code>.</p>

      <div class="scorecards">
        <?php foreach ($metricSummary as $metricName => $info): ?>
          <?php $badgeClass = str_replace(' ', '-', $info['rating']); ?>
          <div class="scorecard">
            <div class="label"><?= htmlspecialchars($metricName) ?></div>
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
        <h2>Page Timing Overview</h2>
        <p>Shows one recent <code>navigationTiming</code> metric from the stored perf logs.</p>

        <div class="chart-meta">
          <strong>Page:</strong> <?= htmlspecialchars((string)$pageTimingPage) ?>
          <?php if ($pageTimingSid): ?>
            &nbsp;|&nbsp;
            <strong>Session:</strong> <?= htmlspecialchars((string)$pageTimingSid) ?>
          <?php endif; ?>
        </div>

        <div class="chart-wrap">
          <canvas id="pageTimingChart"></canvas>
        </div>
      </div>
    </div>
  </div>

  <script>
    const interactionLabels = <?= json_encode($interactionLabels) ?>;
    const interactionValues = <?= json_encode($interactionValues) ?>;

    const pageTimingLabels = <?= json_encode($pageTimingLabels) ?>;
    const pageTimingValues = <?= json_encode($pageTimingValues) ?>;

    new Chart(document.getElementById('interactionChart'), {
      type: 'bar',
      data: {
        labels: interactionLabels,
        datasets: [{
          label: 'Interaction Event Count',
          data: interactionValues,
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

    new Chart(document.getElementById('pageTimingChart'), {
      type: 'bar',
      data: {
        labels: pageTimingLabels,
        datasets: [{
          label: 'Seconds',
          data: pageTimingValues,
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
              text: 'seconds'
            }
          }
        }
      }
    });
  </script>
</body>
</html>
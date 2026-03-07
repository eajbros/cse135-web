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
        'mouseleave' => 'mouse leave',
        'mouseenter' => 'mouse enter',
        'idle_start' => 'idle start',
        'idle_end' => 'idle end',
        'scroll' => 'scroll',
        'click' => 'click',
        'enter' => 'enter',
        'leave' => 'leave',
    ];

    return $map[$type] ?? $type;
}

function metric_rating(string $metric, $value): string {
    if ($value === null || !is_numeric($value)) {
        return 'no data';
    }

    $value = (float)$value;

    switch ($metric) {
        case 'FCP':
            if ($value <= 1800) return 'good';
            if ($value <= 3000) return 'needs improvement';
            return 'poor';

        case 'LCP':
            if ($value <= 2500) return 'good';
            if ($value <= 4000) return 'needs improvement';
            return 'poor';

        case 'CLS':
            if ($value <= 0.1) return 'good';
            if ($value <= 0.25) return 'needs improvement';
            return 'poor';

        case 'TBT':
            if ($value <= 200) return 'good';
            if ($value <= 600) return 'needs improvement';
            return 'poor';

        case 'FID':
            if ($value <= 100) return 'good';
            if ($value <= 300) return 'needs improvement';
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

    if ($metric === 'FCP' || $metric === 'LCP') {
        return number_format($value / 1000, 2) . ' s';
    }

    return number_format($value, 0) . ' ms';
}

function first_numeric_value(array $sources, array $keys) {
    foreach ($sources as $source) {
        if (!is_array($source)) {
            continue;
        }
        foreach ($keys as $key) {
            if (array_key_exists($key, $source) && is_numeric($source[$key])) {
                return (float)$source[$key];
            }
        }
    }
    return null;
}

function extract_perf_metrics(array $event): array {
    $data = $event['data'] ?? [];
    $props = $data['eventProperties'] ?? [];
    $nav = $data['navigatorInformation'] ?? [];
    $rawData = $data['data'] ?? [];

    $measureName = $data['metricName'] ?? null;
    $value = null;

    if (is_numeric($rawData)) {
        $value = (float)$rawData;
    } elseif (is_array($rawData)) {
        $value = first_numeric_value([$rawData], ['value', 'metricValue', 'delta']);
    }

    $metrics = [
        'FCP' => null,
        'LCP' => null,
        'CLS' => null,
        'TBT' => null,
        'FID' => null,
    ];

    if (is_string($measureName) && array_key_exists($measureName, $metrics) && $value !== null) {
        $metrics[$measureName] = $value;
    }

    $metrics['FCP'] = $metrics['FCP'] ?? first_numeric_value(
        [$data, $props, $rawData, $nav],
        ['FCP', 'fcp', 'firstContentfulPaint', 'first_contentful_paint']
    );

    $metrics['LCP'] = $metrics['LCP'] ?? first_numeric_value(
        [$data, $props, $rawData, $nav],
        ['LCP', 'lcp', 'largestContentfulPaint', 'largest_contentful_paint']
    );

    $metrics['CLS'] = $metrics['CLS'] ?? first_numeric_value(
        [$data, $props, $rawData, $nav],
        ['CLS', 'cls', 'cumulativeLayoutShift', 'cumulative_layout_shift']
    );

    $metrics['TBT'] = $metrics['TBT'] ?? first_numeric_value(
        [$data, $props, $rawData, $nav],
        ['TBT', 'tbt', 'totalBlockingTime', 'total_blocking_time']
    );

    $metrics['FID'] = $metrics['FID'] ?? first_numeric_value(
        [$data, $props, $rawData, $nav],
        ['FID', 'fid', 'firstInputDelay', 'first_input_delay']
    );

    return $metrics;
}

function extract_waterfall_parts(array $event): array {
    $data = $event['data'] ?? [];
    $props = $data['eventProperties'] ?? [];
    $nav = $data['navigatorInformation'] ?? [];
    $rawData = $data['data'] ?? [];

    $sources = [$props, $rawData, $nav, $data];

    $dns = first_numeric_value($sources, ['dns', 'dnsLookup', 'dns_lookup']);
    $tcp = first_numeric_value($sources, ['tcp', 'connect', 'tcpConnect', 'tcp_connect']);
    $ssl = first_numeric_value($sources, ['ssl', 'tls', 'sslHandshake', 'ssl_handshake']);
    $ttfb = first_numeric_value($sources, ['ttfb', 'TTFB', 'timeToFirstByte', 'time_to_first_byte']);
    $dom = first_numeric_value($sources, ['domLoad', 'domInteractive', 'domContentLoaded', 'dom_content_loaded']);
    $load = first_numeric_value($sources, ['loadEvent', 'loadComplete', 'load', 'load_complete']);

    return [
        'DNS' => $dns,
        'TCP' => $tcp,
        'SSL' => $ssl,
        'TTFB' => $ttfb,
        'DOM' => $dom,
        'Load' => $load,
    ];
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
 * 1) User Interaction Events
 */
$userInteractionCounts = [];

/*
 * 2) Performance Health Overview
 */
$metricBuckets = [
    'FCP' => [],
    'LCP' => [],
    'CLS' => [],
    'TBT' => [],
    'FID' => [],
];

/*
 * 3) Page Load Waterfall
 * Use the most recent perf event that contains at least one useful timing component.
 */
$waterfallSource = null;
$waterfallPage = null;
$waterfallSid = null;

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
            $label = display_event_type($type);
            if (!isset($userInteractionCounts[$label])) {
                $userInteractionCounts[$label] = 0;
            }
            $userInteractionCounts[$label]++;
            continue;
        }

        $perfMetrics = extract_perf_metrics($event);
        foreach ($perfMetrics as $metricName => $metricValue) {
            if ($metricValue !== null) {
                $metricBuckets[$metricName][] = $metricValue;
            }
        }

        if ($waterfallSource === null) {
            $parts = extract_waterfall_parts($event);
            $hasUsefulPart = false;
            foreach ($parts as $value) {
                if ($value !== null && is_numeric($value) && $value >= 0) {
                    $hasUsefulPart = true;
                    break;
                }
            }

            if ($hasUsefulPart) {
                $waterfallSource = $parts;
                $waterfallPage = path_only($decoded['page'] ?? $row['page'] ?? '');
                $waterfallSid = $row['sid'] ?? null;
            }
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
    $metricSummary[$metricName] = [
        'average' => $avg,
        'rating' => metric_rating($metricName, $avg),
        'formatted' => format_metric($metricName, $avg),
        'samples' => count($values),
    ];
}

if ($waterfallSource === null) {
    $waterfallSource = [
        'DNS' => 0,
        'TCP' => 0,
        'SSL' => 0,
        'TTFB' => 0,
        'DOM' => 0,
        'Load' => 0,
    ];
    $waterfallPage = '(no timing data)';
    $waterfallSid = null;
}

$waterfallLabels = array_keys($waterfallSource);
$waterfallValues = array_map(function ($v) {
    return $v === null ? 0 : (float)$v;
}, array_values($waterfallSource));
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

    .waterfall-meta {
      margin-bottom: 14px;
      color: var(--muted);
      font-size: 0.95rem;
    }

    .waterfall-meta strong {
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
      <p class="section-subtitle">Average Core Web Vitals and performance measurements across stored performance events.</p>

      <div class="scorecards">
        <?php foreach ($metricSummary as $metricName => $info): ?>
          <?php
            $badgeClass = str_replace(' ', '-', $info['rating']);
          ?>
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
        <h2>Page Load Waterfall</h2>
        <p>Breaks down one recent performance event into major load phases to make bottlenecks easier to spot.</p>

        <div class="waterfall-meta">
          <strong>Page:</strong> <?= htmlspecialchars((string)$waterfallPage) ?>
          <?php if ($waterfallSid): ?>
            &nbsp;|&nbsp;
            <strong>Session:</strong> <?= htmlspecialchars((string)$waterfallSid) ?>
          <?php endif; ?>
        </div>

        <div class="chart-wrap">
          <canvas id="waterfallChart"></canvas>
        </div>
      </div>
    </div>
  </div>

  <script>
    const interactionLabels = <?= json_encode($interactionLabels) ?>;
    const interactionValues = <?= json_encode($interactionValues) ?>;

    const waterfallLabels = <?= json_encode($waterfallLabels) ?>;
    const waterfallValues = <?= json_encode($waterfallValues) ?>;

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

    new Chart(document.getElementById('waterfallChart'), {
      type: 'bar',
      data: {
        labels: waterfallLabels,
        datasets: [{
          label: 'Milliseconds',
          data: waterfallValues,
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
              text: 'ms'
            }
          }
        }
      }
    });
  </script>
</body>
</html>
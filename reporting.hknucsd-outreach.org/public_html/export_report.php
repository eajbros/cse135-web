<?php
require_once __DIR__ . '/auth.php';
require_login();

if (!is_admin() && !is_analyst()) {
    http_response_code(403);
  die('Access denied. You do not have permission to export charts.');
}

require_once __DIR__ . '/db.php';

$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    http_response_code(500);
    die('PDF export package is not installed. Run "composer install" in reporting.hknucsd-outreach.org first.');
}

require_once $autoloadPath;

use Dompdf\Dompdf;
use Dompdf\Options;

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

function cleanup_old_exports(string $exportsDir, int $maxAgeDays = 7): void {
  if (!is_dir($exportsDir)) {
    return;
  }

  $threshold = time() - ($maxAgeDays * 86400);
  foreach (glob($exportsDir . '/chart-export-*.pdf') ?: [] as $filePath) {
    if (!is_file($filePath)) {
      continue;
    }

    $modifiedAt = filemtime($filePath);
    if ($modifiedAt !== false && $modifiedAt < $threshold) {
      @unlink($filePath);
    }
  }
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

$generatedAt = date('Y-m-d H:i:s');
$generatedBy = $_SESSION['display_name'] ?? $_SESSION['username'];

$html = '<!doctype html>';
$html .= '<html><head><meta charset="UTF-8"><style>';
$html .= 'body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111827; }';
$html .= 'h1 { margin: 0 0 8px; font-size: 22px; }';
$html .= 'h2 { margin: 20px 0 8px; font-size: 16px; }';
$html .= '.meta { margin-bottom: 14px; color: #374151; }';
$html .= 'table { width: 100%; border-collapse: collapse; table-layout: fixed; margin-bottom: 12px; }';
$html .= 'th, td { border: 1px solid #d1d5db; padding: 8px; vertical-align: top; word-wrap: break-word; }';
$html .= 'th { background: #f3f4f6; text-align: left; }';
$html .= '.bar-wrap { width: 100%; height: 10px; border-radius: 10px; background: #e5e7eb; overflow: hidden; }';
$html .= '.bar-fill { height: 10px; background: #2563eb; }';
$html .= '.small { font-size: 10px; color: #4b5563; }';
$html .= '</style></head><body>';
$html .= '<h1>Analytics Charts Export</h1>';
$html .= '<div class="meta">Generated at: ' . htmlspecialchars($generatedAt) . ' | Generated by: ' . htmlspecialchars($generatedBy) . ' | Payloads processed: ' . count($rows) . '</div>';

$html .= '<h2>Performance Health Overview</h2>';
$html .= '<table><thead><tr><th style="width:18%">Metric</th><th style="width:25%">Average</th><th style="width:27%">Rating</th><th style="width:30%">Samples</th></tr></thead><tbody>';
foreach ($metricSummary as $metric => $info) {
  $html .= '<tr>';
  $html .= '<td>' . htmlspecialchars($metric) . '</td>';
  $html .= '<td>' . htmlspecialchars($info['formatted']) . '</td>';
  $html .= '<td>' . htmlspecialchars($info['rating']) . '</td>';
  $html .= '<td>' . htmlspecialchars((string)$info['samples']) . '</td>';
  $html .= '</tr>';
}
$html .= '</tbody></table>';

$html .= '<h2>User Interaction Events (Top 10)</h2>';
$html .= '<table><thead><tr><th style="width:35%">Event</th><th style="width:15%">Count</th><th style="width:50%">Distribution</th></tr></thead><tbody>';
if (empty($interactionCounts)) {
  $html .= '<tr><td colspan="3">No interaction events found.</td></tr>';
} else {
  $maxInteractions = max($interactionCounts);
  foreach ($interactionCounts as $label => $count) {
    $width = $maxInteractions > 0 ? (($count / $maxInteractions) * 100.0) : 0.0;
    $html .= '<tr>';
    $html .= '<td>' . htmlspecialchars((string)$label) . '</td>';
    $html .= '<td>' . htmlspecialchars((string)$count) . '</td>';
    $html .= '<td><div class="bar-wrap"><div class="bar-fill" style="width:' . number_format($width, 2, '.', '') . '%"></div></div></td>';
    $html .= '</tr>';
    }
}
$html .= '</tbody></table>';

$html .= '<h2>Navigation Timing Distribution</h2>';
$html .= '<table><thead><tr><th style="width:35%">Timing Range</th><th style="width:15%">Samples</th><th style="width:50%">Distribution</th></tr></thead><tbody>';

$maxNavSamples = max($navHistogram) ?: 0;
foreach ($navHistogram as $range => $count) {
  $width = $maxNavSamples > 0 ? (($count / $maxNavSamples) * 100.0) : 0.0;
  $html .= '<tr>';
  $html .= '<td>' . htmlspecialchars((string)$range) . '</td>';
  $html .= '<td>' . htmlspecialchars((string)$count) . '</td>';
  $html .= '<td><div class="bar-wrap"><div class="bar-fill" style="width:' . number_format($width, 2, '.', '') . '%"></div></div></td>';
  $html .= '</tr>';
}

$html .= '</tbody></table>';
$html .= '<p class="small">This export summarizes the same analytics shown on the charts page.</p>';
$html .= '</body></html>';

$options = new Options();
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'DejaVu Sans');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();

$pdfBinary = $dompdf->output();

$exportsDir = __DIR__ . '/exports';
if (!is_dir($exportsDir) && !mkdir($exportsDir, 0775, true) && !is_dir($exportsDir)) {
    http_response_code(500);
    die('Failed to create export directory.');
}

if (!is_writable($exportsDir)) {
  @chmod($exportsDir, 0775);
}

if (!is_writable($exportsDir)) {
  http_response_code(500);
  die('Export directory is not writable: ' . htmlspecialchars($exportsDir));
}

cleanup_old_exports($exportsDir, 7);

$filename = 'chart-export-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.pdf';
$filePath = $exportsDir . '/' . $filename;

if (file_put_contents($filePath, $pdfBinary) === false) {
  $lastError = error_get_last();
  $detail = is_array($lastError) && isset($lastError['message']) ? $lastError['message'] : 'Unknown filesystem error';
    http_response_code(500);
  die('Failed to save exported PDF. ' . htmlspecialchars($detail));
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$publicUrl = $scheme . '://' . $host . '/exports/' . rawurlencode($filename);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Charts Export Complete</title>
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

    .container {
      max-width: 900px;
      margin: 40px auto;
      padding: 0 20px;
    }

    .card {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 16px;
      box-shadow: 0 8px 24px rgba(0,0,0,0.05);
      padding: 24px;
    }

    h1 {
      margin: 0 0 8px;
      font-size: 1.6rem;
    }

    p { color: var(--muted); }

    .url-box {
      margin: 16px 0;
      padding: 12px;
      border: 1px solid var(--border);
      border-radius: 8px;
      background: #f9fafb;
      word-break: break-all;
      font-family: "Courier New", monospace;
      color: #111827;
    }

    .actions {
      margin-top: 20px;
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
    }

    .btn {
      text-decoration: none;
      display: inline-block;
      padding: 10px 16px;
      border-radius: 8px;
      font-weight: 600;
      font-size: 0.95rem;
    }

    .btn.primary {
      background: var(--accent);
      color: white;
    }

    .btn.secondary {
      background: #e5e7eb;
      color: #111827;
    }
  </style>
</head>
<body>
  <div class="container">
    <div class="card">
      <h1>Charts PDF Export Created</h1>
      <p>Your charts analytics have been exported and saved to a public URL.</p>
      <div class="url-box"><?= htmlspecialchars($publicUrl) ?></div>
      <div class="actions">
        <a class="btn primary" href="<?= htmlspecialchars('/exports/' . $filename) ?>" target="_blank" rel="noopener">Open PDF</a>
        <a class="btn secondary" href="/charts.php">Back to Charts</a>
      </div>
    </div>
  </div>
</body>
</html>

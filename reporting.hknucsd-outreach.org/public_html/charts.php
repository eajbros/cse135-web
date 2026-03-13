<?php
require_once __DIR__ . '/auth.php';
require_login();

use Dompdf\Dompdf;

// Only super admin and analysts can view reports
if (!is_admin() && !is_analyst()) {
    http_response_code(403);
    die('Access denied. You do not have permission to view this data.');
}

require_once __DIR__ . '/db.php';

// Initialize CSRF token for form submission
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'] ?? '';

// Initialize error tracking
$page_error = '';
$page_warning = '';

// Ensure report_comments table exists with correct schema
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS report_comments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            category VARCHAR(50) NOT NULL,
            analyst_id INT NOT NULL,
            content LONGTEXT NOT NULL,
            is_markdown BOOLEAN DEFAULT FALSE,
            is_published BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (analyst_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_category (category),
            INDEX idx_analyst_id (analyst_id),
            INDEX idx_created_at (created_at)
        )
    ");
} catch (Exception $e) {
    $page_error = 'Database error: Unable to access report comments. Please try again later.';
}

// Ensure saved_reports table exists for PDF exports
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS saved_reports (
            id INT AUTO_INCREMENT PRIMARY KEY,
            category VARCHAR(50) NOT NULL,
            analyst_id INT NOT NULL,
            report_name VARCHAR(255) NOT NULL,
            report_data LONGTEXT NOT NULL,
            pdf_path VARCHAR(500),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (analyst_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_category (category),
            INDEX idx_analyst_id (analyst_id),
            INDEX idx_created_at (created_at)
        )
    ");
    
    // Add pdf_path column if it doesn't exist (for existing tables)
    $checkColumn = $pdo->query("SHOW COLUMNS FROM saved_reports LIKE 'pdf_path'");
    if ($checkColumn->rowCount() === 0) {
        $pdo->exec("ALTER TABLE saved_reports ADD COLUMN pdf_path VARCHAR(500) AFTER report_data");
    }
} catch (Exception $e) {
    // Table might already exist
}

// Verify required tables exist
try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
    if ($stmt->rowCount() === 0) {
        http_response_code(500);
        die('Server error: Required tables not found. Contact administrator.');
    }
} catch (Exception $e) {
    http_response_code(500);
    die('Server error: Database connection failed. Contact administrator.');
}

// Get current user info with validation (initialize early for POST handlers)
$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    $page_error = 'Session error: User ID not found. Please log in again.';
}
$display_name = $_SESSION['display_name'] ?? ($_SESSION['username'] ?? 'User');
$role = get_user_role();

$allowed_categories = ['performance', 'behavioral', 'engagement'];

// Determine what reports the user can access
$accessible_categories = $allowed_categories;
if (is_analyst()) {
    $allowed_sections = $_SESSION['allowed_sections'] ?? [];
    $accessible_categories = array_intersect($allowed_categories, $allowed_sections);
    if (empty($accessible_categories)) {
        http_response_code(403);
        die('Access denied. You do not have permission to view any reports.');
    }
}

// Determine which report to display
$report_category = $_GET['report'] ?? null;

// If not specified or not accessible, use first accessible category
if (!$report_category || !in_array($report_category, $accessible_categories)) {
    $report_category = reset($accessible_categories);
}

// Validate report category is in accessible list
if (!in_array($report_category, $accessible_categories)) {
    http_response_code(403);
    die('Access denied. You do not have permission to view the ' . htmlspecialchars($report_category) . ' report.');
}

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


// Extract beacon data for analysis
$stmt = $pdo->query("
    SELECT payload, received_at
    FROM beacons_raw
    ORDER BY received_at DESC
");

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Data aggregation for all reports
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
$eventTimeline = [];
$metricsTimeline = [];

$ignoredInteractionTypes = ['perf', 'static', 'performance_required'];
$malformed_beacon_count = 0;

foreach ($rows as $row) {
    $payload = json_decode($row['payload'], true);
    if (!is_array($payload)) {
        $malformed_beacon_count++;
        continue;
    }
    
    $events = $payload['events'] ?? [];
    if (!is_array($events)) continue;

    foreach ($events as $event) {
        $type = $event['type'] ?? '(unknown)';

        if ($type !== 'perf') {
            if (in_array($type, $ignoredInteractionTypes, true)) continue;
            
            $label = display_event_type($type);
            $interactionCounts[$label] = ($interactionCounts[$label] ?? 0) + 1;
            $eventTimeline[] = [
                'type' => $label,
                'timestamp' => $row['received_at'],
                'time' => strtotime($row['received_at'])
            ];
            continue;
        }

        $perf = $event['data'] ?? [];
        if (!is_array($perf)) continue;

        $metricName = normalize_metric_name($perf['metricName'] ?? null);
        $rawValue = $perf['data'] ?? null;
        $score = $perf['vitalsScore'] ?? null;
        $value = is_numeric($rawValue) ? (float)$rawValue : null;

        if ($metricName === 'navigationTiming' && $value !== null) {
            $navigationTimings[] = [
                'value' => $value,
                'timestamp' => $row['received_at']
            ];
        }

        if ($metricName !== null && array_key_exists($metricName, $metricValues) && $value !== null) {
            $metricValues[$metricName][] = [
                'value' => $value,
                'score' => $score,
                'timestamp' => $row['received_at']
            ];
            
            $metricsTimeline[] = [
                'metric' => $metricName,
                'value' => $value,
                'timestamp' => $row['received_at'],
                'time' => strtotime($row['received_at'])
            ];
            
            if (is_string($score) && $score !== '') {
                $metricScores[$metricName][] = $score;
            }
        }
    }
}

// Process interaction data
arsort($interactionCounts);
$topInteractions = array_slice($interactionCounts, 0, 15, true);
$interactionLabels = array_keys($topInteractions);
$interactionData = array_values($topInteractions);

// Process performance metrics
$metricSummary = [];
$metricTableData = [];
foreach ($metricValues as $metric => $values) {
    if (empty($values)) {
        $metricSummary[$metric] = [
            'formatted' => '—',
            'rating' => 'no data',
            'samples' => 0,
            'average' => null,
            'min' => null,
            'max' => null,
            'p95' => null
        ];
        continue;
    }
    
    $valueList = array_column($values, 'value');
    $average = array_sum($valueList) / count($valueList);
    
    sort($valueList);
    $min = $valueList[0];
    $max = $valueList[count($valueList) - 1];
    $p95Index = (int)ceil(count($valueList) * 0.95) - 1;
    $p95 = $valueList[$p95Index] ?? $max;
    
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
        'average' => $average,
        'min' => $min,
        'max' => $max,
        'p95' => $p95
    ];
    
    $metricTableData[$metric] = [
        'average' => format_metric($metric, $average),
        'min' => format_metric($metric, $min),
        'max' => format_metric($metric, $max),
        'p95' => format_metric($metric, $p95),
        'samples' => count($values)
    ];
}

// Process navigation timing histogram
$navHistogram = [
    '0.00–0.10 s' => 0,
    '0.10–0.20 s' => 0,
    '0.20–0.30 s' => 0,
    '0.30–0.40 s' => 0,
    '0.40–0.50 s' => 0,
    '0.50+ s' => 0,
];

$navTableData = [];
foreach ($navigationTimings as $item) {
    $value = $item['value'];
    if ($value < 0.10) {
        $navHistogram['0.00–0.10 s']++;
        $bucket = '0.00–0.10 s';
    } elseif ($value < 0.20) {
        $navHistogram['0.10–0.20 s']++;
        $bucket = '0.10–0.20 s';
    } elseif ($value < 0.30) {
        $navHistogram['0.20–0.30 s']++;
        $bucket = '0.20–0.30 s';
    } elseif ($value < 0.40) {
        $navHistogram['0.30–0.40 s']++;
        $bucket = '0.30–0.40 s';
    } elseif ($value < 0.50) {
        $navHistogram['0.40–0.50 s']++;
        $bucket = '0.40–0.50 s';
    } else {
        $navHistogram['0.50+ s']++;
        $bucket = '0.50+ s';
    }
    
    $navTableData[] = [
        'value' => number_format($value, 3),
        'bucket' => $bucket,
        'timestamp' => $item['timestamp']
    ];
}

$navLabels = array_keys($navHistogram);
$navData = array_values($navHistogram);

// Fetch comments for current report
$stmt = $pdo->prepare("
    SELECT rc.id, rc.content, rc.is_markdown, rc.is_published, rc.created_at, rc.updated_at, 
           u.display_name, u.username
    FROM report_comments rc
    LEFT JOIN users u ON rc.analyst_id = u.id
    WHERE rc.category = ? AND rc.is_published = 1
    ORDER BY rc.created_at DESC
");
$stmt->execute([$report_category]);
$comments = $stmt->fetchAll();

// Handle comment submission
$comment_message = '';
$comment_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_comment') {
    try {
        // Verify CSRF token
        $submitted_token = $_POST['csrf_token'] ?? '';
        if (empty($submitted_token) || !hash_equals($csrf_token, $submitted_token)) {
            throw new Exception('Security validation failed. Please try again.');
        }
        
        // Re-verify permission (defense-in-depth)
        if (!(is_analyst() || is_admin())) {
            throw new Exception('You do not have permission to add comments.');
        }
        
        // Validate user_id exists
        if (!$user_id) {
            throw new Exception('Session error: Unable to identify user. Please log in again.');
        }
        
        $content = trim($_POST['content'] ?? '');
        $is_markdown = isset($_POST['is_markdown']) ? 1 : 0;
        
        if (empty($content)) {
            throw new Exception('Comment cannot be empty.');
        }
        
        if (strlen($content) > 10000) {
            throw new Exception('Comment is too long (max 10,000 characters).');
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO report_comments (category, analyst_id, content, is_markdown, is_published)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $report_category,
            $user_id,
            $content,
            $is_markdown,
            1
        ]);
        
        $comment_message = 'Comment added successfully!';
        
        // Reload comments
        $stmt = $pdo->prepare("
            SELECT rc.id, rc.content, rc.is_markdown, rc.is_published, rc.created_at, rc.updated_at, 
                   u.display_name, u.username
            FROM report_comments rc
            LEFT JOIN users u ON rc.analyst_id = u.id
            WHERE rc.category = ? AND rc.is_published = 1
            ORDER BY rc.created_at DESC
        ");
        $stmt->execute([$report_category]);
        $comments = $stmt->fetchAll();
    } catch (Exception $e) {
        $comment_error = $e->getMessage();
    }
}

// Display warning if beacons had corruption
if ($malformed_beacon_count > 0) {
    $page_warning = "Note: {$malformed_beacon_count} beacon(s) had invalid data and were skipped from analysis.";
}

// Handle report save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_report') {
    try {
        // Verify CSRF token
        $submitted_token = $_POST['csrf_token'] ?? '';
        if (empty($submitted_token) || !hash_equals($csrf_token, $submitted_token)) {
            throw new Exception('Security validation failed. Please try again.');
        }
        
        // Verify permission
        if (!(is_analyst() || is_admin())) {
            throw new Exception('You do not have permission to save reports.');
        }
        
        if (!$user_id) {
            throw new Exception('Session error: Unable to identify user. Please log in again.');
        }
        
        $report_name = trim($_POST['report_name'] ?? '');
        if (empty($report_name)) {
            throw new Exception('Report name cannot be empty.');
        }
        
        if (strlen($report_name) > 255) {
            throw new Exception('Report name is too long (max 255 characters).');
        }
        
        // Package report data as JSON snapshot - category-specific
        $report_snapshot = [
            'category' => $report_category,
            'timestamp' => date('Y-m-d H:i:s'),
            'analyst' => $display_name,
            'comments_count' => count($comments ?? [])
        ];
        
        // Add category-specific data
        if ($report_category === 'performance') {
            $report_snapshot['metrics'] = $metricSummary ?? [];
            $report_snapshot['data_type'] = 'metrics';
        } elseif ($report_category === 'behavioral') {
            $report_snapshot['interactions'] = array_combine($interactionLabels ?? [], $interactionData ?? []);
            $report_snapshot['data_type'] = 'interactions';
        } elseif ($report_category === 'engagement') {
            $report_snapshot['navigation'] = array_combine($navLabels ?? [], $navData ?? []);
            $report_snapshot['data_type'] = 'navigation';
        }
        
        // Generate PDF using dompdf
        $pdf_filename = null;
        try {
            require_once __DIR__ . '/../vendor/autoload.php';
            
            $dompdf = new Dompdf();
            // Enable remote image loading for QuickChart
            $options = $dompdf->getOptions();
            $options->setIsRemoteEnabled(true);
            
            // Create HTML content for PDF
            $html = "<html><head><meta charset='UTF-8'><style>
                body { font-family: Arial, sans-serif; margin: 20px; color: #333; }
                h1 { color: #2563eb; border-bottom: 2px solid #2563eb; padding-bottom: 10px; }
                h2 { color: #4b5563; margin-top: 20px; }
                table { width: 100%; border-collapse: collapse; margin: 15px 0; }
                th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
                th { background: #f5f5f5; font-weight: bold; }
                .metadata { color: #666; font-size: 0.9em; margin-bottom: 20px; }
                .metric-value { font-size: 1.2em; font-weight: bold; color: #2563eb; }
                .chart-img { margin: 20px 0; width: 100%; max-width: 600px; page-break-inside: avoid; }
            </style></head><body>";
            
            $html .= "<h1>Report: " . htmlspecialchars($report_name) . "</h1>";
            $html .= "<div class='metadata'>";
            $html .= "<p><strong>Category:</strong> " . htmlspecialchars(ucfirst($report_category)) . "</p>";
            $html .= "<p><strong>Created by:</strong> " . htmlspecialchars($display_name) . "</p>";
            $html .= "<p><strong>Date:</strong> " . htmlspecialchars($report_snapshot['timestamp']) . "</p>";
            $html .= "</div>";
            
            // Display category-specific content
            if ($report_snapshot['data_type'] === 'metrics' && !empty($report_snapshot['metrics'])) {
                // Performance metrics chart
                $metricChartConfig = [
                    'type' => 'bar',
                    'data' => [
                        'labels' => array_keys($report_snapshot['metrics']),
                        'datasets' => [[
                            'label' => 'Average Value',
                            'data' => array_map(fn($d) => is_array($d) ? ($d['average'] ?? 0) : 0, $report_snapshot['metrics']),
                            'backgroundColor' => '#3b82f6',
                            'borderColor' => '#1e40af',
                            'borderWidth' => 1
                        ]]
                    ],
                    'options' => ['responsive' => true, 'maintainAspectRatio' => true]
                ];
                $chartUrl = 'https://quickchart.io/chart?c=' . urlencode(json_encode($metricChartConfig)) . '&w=800&h=400';
                
                $html .= "<h2>Performance Metrics Chart</h2>";
                $html .= "<img src='" . htmlspecialchars($chartUrl) . "' class='chart-img' alt='Performance Metrics Chart'>";
                
                $html .= "<h2>Detailed Metrics Table</h2><table><tr><th>Metric</th><th>Value</th><th>Rating</th><th>Samples</th></tr>";
                foreach ($report_snapshot['metrics'] as $metric => $data) {
                    if (is_array($data)) {
                        $val_str = $data['formatted'] ?? '—';
                        $rating = $data['rating'] ?? 'no data';
                        $samples = $data['samples'] ?? 0;
                    } else {
                        $val_str = (string)$data;
                        $rating = 'n/a';
                        $samples = 0;
                    }
                    $html .= "<tr><td>" . htmlspecialchars($metric) . "</td><td>" . htmlspecialchars($val_str) . "</td><td>" . htmlspecialchars($rating) . "</td><td>" . htmlspecialchars((string)$samples) . "</td></tr>";
                }
                $html .= "</table>";
            } elseif ($report_snapshot['data_type'] === 'interactions' && !empty($report_snapshot['interactions'])) {
                // Behavioral interactions chart
                $interactionChartConfig = [
                    'type' => 'doughnut',
                    'data' => [
                        'labels' => array_keys($report_snapshot['interactions']),
                        'datasets' => [[
                            'data' => array_values($report_snapshot['interactions']),
                            'backgroundColor' => ['#3b82f6', '#ef4444', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899', '#06b6d4', '#14b8a6', '#f97316', '#6366f1', '#d946ef', '#84cc16', '#0891b2', '#a855f7', '#15803d'],
                            'borderColor' => '#fff',
                            'borderWidth' => 2
                        ]]
                    ],
                    'options' => ['responsive' => true, 'maintainAspectRatio' => true]
                ];
                $chartUrl = 'https://quickchart.io/chart?c=' . urlencode(json_encode($interactionChartConfig)) . '&w=700&h=400';
                
                $html .= "<h2>User Interactions Distribution</h2>";
                $html .= "<img src='" . htmlspecialchars($chartUrl) . "' class='chart-img' alt='Interactions Chart'>";
                
                $html .= "<h2>Interaction Types Table</h2><table><tr><th>Interaction Type</th><th>Count</th></tr>";
                foreach ($report_snapshot['interactions'] as $type => $count) {
                    if ($count > 0) {
                        $html .= "<tr><td>" . htmlspecialchars($type) . "</td><td>" . htmlspecialchars((string)$count) . "</td></tr>";
                    }
                }
                $html .= "</table>";
            } elseif ($report_snapshot['data_type'] === 'navigation' && !empty($report_snapshot['navigation'])) {
                // Engagement navigation timing chart
                $navChartConfig = [
                    'type' => 'bar',
                    'data' => [
                        'labels' => array_keys($report_snapshot['navigation']),
                        'datasets' => [[
                            'label' => 'Sample Count',
                            'data' => array_values($report_snapshot['navigation']),
                            'backgroundColor' => '#3b82f6',
                            'borderColor' => '#1e40af',
                            'borderWidth' => 1
                        ]]
                    ],
                    'options' => ['responsive' => true, 'maintainAspectRatio' => true]
                ];
                $chartUrl = 'https://quickchart.io/chart?c=' . urlencode(json_encode($navChartConfig)) . '&w=800&h=400';
                
                $html .= "<h2>Navigation Timing Distribution</h2>";
                $html .= "<img src='" . htmlspecialchars($chartUrl) . "' class='chart-img' alt='Navigation Timing Chart'>";
                
                $html .= "<h2>Timing Buckets Table</h2><table><tr><th>Time Bucket</th><th>Occurrences</th></tr>";
                foreach ($report_snapshot['navigation'] as $bucket => $count) {
                    if ($count > 0) {
                        $html .= "<tr><td>" . htmlspecialchars($bucket) . "</td><td>" . htmlspecialchars((string)$count) . "</td></tr>";
                    }
                }
                $html .= "</table>";
            }
            
            $html .= "</body></html>";
            
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();
            
            // Create exports directory if it doesn't exist
            $exports_dir = __DIR__ . '/exports';
            if (!is_dir($exports_dir)) {
                mkdir($exports_dir, 0755, true);
            }
            
            // Generate PDF filename using report name
            // Sanitize name: keep alphanumeric, spaces, hyphens, and underscores
            $safe_name = preg_replace('/[^a-zA-Z0-9\s\-_]/', '', $report_name);
            $safe_name = preg_replace('/\s+/', '-', trim($safe_name));
            $safe_name = substr($safe_name, 0, 100); // Limit length
            if (empty($safe_name)) {
                $safe_name = 'report';
            }
            $pdf_filename = 'report-' . $safe_name . '-' . uniqid() . '.pdf';
            $pdf_file = $exports_dir . '/' . $pdf_filename;
            
            // Save PDF
            file_put_contents($pdf_file, $dompdf->output());
        } catch (Exception $pdf_err) {
            // If PDF generation fails, log it but continue
            error_log('PDF generation failed: ' . $pdf_err->getMessage());
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO saved_reports (category, analyst_id, report_name, report_data, pdf_path)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $report_category,
            $user_id,
            $report_name,
            json_encode($report_snapshot),
            $pdf_filename
        ]);
        
        $comment_message = "Report '{$report_name}' saved successfully! Download it from the dashboard.";
    } catch (Exception $e) {
        $comment_error = $e->getMessage();
    }
}

// Prepare metric timeline data (last 20 entries per metric, sorted by timestamp)
$metricTimelineByType = [];
foreach ($metricsTimeline as $entry) {
    if (!isset($metricTimelineByType[$entry['metric']])) {
        $metricTimelineByType[$entry['metric']] = [];
    }
    $metricTimelineByType[$entry['metric']][] = $entry;
}

// Get last 20 for chart (limit data points)
foreach ($metricTimelineByType as $metric => $entries) {
    usort($entries, fn($a, $b) => strtotime($a['timestamp']) <=> strtotime($b['timestamp']));
    $metricTimelineByType[$metric] = array_slice($entries, -20);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="X-UA-Compatible" content="ie=edge">
  <meta http-equiv="X-Frame-Options" content="SAMEORIGIN">
  <meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self';">
  <title><?= htmlspecialchars($report_category === 'performance' ? 'Performance Metrics' : ($report_category === 'behavioral' ? 'User Behavioral Patterns' : 'Engagement Performance')) ?></title>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    :root {
      --bg: #f6f8fb;
      --card: #ffffff;
      --text: #1f2937;
      --muted: #6b7280;
      --border: #e5e7eb;
      --accent: #2563eb;
      --accent-soft: #eff6ff;
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
      margin-bottom: 32px;
    }

    h1 {
      margin: 0;
      font-size: 2.2rem;
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .subtitle {
      margin: 8px 0 0;
      color: var(--muted);
      font-size: 1.05rem;
    }

    .page-header-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      flex-wrap: wrap;
    }

    .nav-tabs {
      display: flex;
      gap: 12px;
      margin-bottom: 24px;
      border-bottom: 1px solid var(--border);
    }

    .nav-tab {
      padding: 12px 24px;
      background: none;
      border: none;
      cursor: pointer;
      font-size: 1rem;
      font-weight: 500;
      color: var(--muted);
      transition: all 0.2s;
      border-bottom: 2px solid transparent;
      margin-bottom: -1px;
    }

    .nav-tab.active {
      color: var(--accent);
      border-bottom-color: var(--accent);
    }

    .nav-tab:hover {
      color: var(--text);
    }

    .export-btn {
      display: inline-block;
      text-decoration: none;
      font-size: 0.9rem;
      font-weight: 600;
      padding: 8px 12px;
      border-radius: 8px;
      background: var(--accent-soft);
      color: var(--accent);
      border: 1px solid #bfdbfe;
      transition: all 0.2s;
    }

    .export-btn:hover {
      background: #dbeafe;
      border-color: #93c5fd;
    }

    .section {
      margin-bottom: 32px;
    }

    .section-title {
      margin: 0 0 8px;
      font-size: 1.35rem;
      font-weight: 600;
    }

    .section-subtitle {
      margin: 0 0 20px;
      color: var(--muted);
      font-size: 0.95rem;
    }

    .scorecards {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
      gap: 16px;
      margin-bottom: 28px;
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
      font-size: 0.85rem;
      color: var(--muted);
      margin-bottom: 8px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .scorecard .value {
      font-size: 1.8rem;
      font-weight: 700;
      margin-bottom: 10px;
      font-variant-numeric: tabular-nums;
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
      font-size: 0.85rem;
      color: var(--muted);
    }

    .charts-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
      gap: 20px;
      margin-bottom: 28px;
    }

    .chart-card {
      padding: 24px;
    }

    .chart-card h2 {
      margin: 0 0 8px;
      font-size: 1.2rem;
      font-weight: 600;
    }

    .chart-card > p {
      margin: 0 0 16px;
      color: var(--muted);
      font-size: 0.95rem;
    }

    .chart-meta {
      margin-bottom: 16px;
      padding: 12px;
      background: #f9fafb;
      border-radius: 8px;
      font-size: 0.9rem;
      color: var(--muted);
    }

    .chart-meta strong {
      color: var(--text);
    }

    .chart-wrap {
      position: relative;
      height: 360px;
      margin-bottom: 16px;
    }

    .table-responsive {
      overflow-x: auto;
      margin-top: 16px;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.9rem;
    }

    table thead {
      background: #f9fafb;
      font-weight: 600;
    }

    table th {
      padding: 12px;
      text-align: left;
      border-bottom: 1px solid var(--border);
    }

    table td {
      padding: 12px;
      border-bottom: 1px solid var(--border);
    }

    table tbody tr:hover {
      background: #f9fafb;
    }

    .comments-section {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 16px;
      padding: 24px;
      box-shadow: 0 8px 24px rgba(0,0,0,0.05);
    }

    .comment-form {
      margin-bottom: 24px;
      padding-bottom: 24px;
      border-bottom: 1px solid var(--border);
    }

    .form-group {
      margin-bottom: 12px;
    }

    .form-group label {
      display: block;
      font-weight: 600;
      margin-bottom: 6px;
      font-size: 0.95rem;
    }

    .form-group textarea {
      width: 100%;
      padding: 12px;
      border: 1px solid var(--border);
      border-radius: 8px;
      font-family: inherit;
      font-size: 0.95rem;
      resize: vertical;
      min-height: 100px;
    }

    .form-group textarea:focus {
      outline: none;
      border-color: var(--accent);
      box-shadow: 0 0 0 3px var(--accent-soft);
    }

    .form-check {
      display: flex;
      align-items: center;
      gap: 8px;
      margin-bottom: 12px;
    }

    .form-check input[type="checkbox"] {
      cursor: pointer;
      width: 18px;
      height: 18px;
    }

    .form-check label {
      margin: 0;
      cursor: pointer;
    }

    .submit-btn {
      background: var(--accent);
      color: white;
      border: none;
      padding: 10px 20px;
      border-radius: 8px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.2s;
    }

    .submit-btn:hover {
      background: #1d4ed8;
      transform: translateY(-1px);
    }

    .comment {
      padding: 16px;
      margin-bottom: 12px;
      background: #f9fafb;
      border-radius: 8px;
      border-left: 4px solid var(--accent);
    }

    .comment-header {
      display: flex;
      justify-content: space-between;
      align-items: start;
      margin-bottom: 8px;
    }

    .comment-author {
      font-weight: 600;
      color: var(--text);
    }

    .comment-time {
      font-size: 0.85rem;
      color: var(--muted);
    }

    .comment-content {
      color: var(--text);
      line-height: 1.6;
    }

    .comment-content h1, .comment-content h2, .comment-content h3 {
      margin: 12px 0 8px;
      font-size: inherit;
    }

    .comment-content ul, .comment-content ol {
      margin: 8px 0;
      padding-left: 24px;
    }

    .comment-content code {
      background: #f3f4f6;
      padding: 2px 6px;
      border-radius: 4px;
      font-family: 'Courier New', monospace;
      font-size: 0.9em;
    }

    .comment-content pre {
      background: #f3f4f6;
      padding: 12px;
      border-radius: 8px;
      overflow-x: auto;
      margin: 8px 0;
    }

    .message {
      padding: 12px 16px;
      border-radius: 8px;
      margin-bottom: 16px;
      font-size: 0.95rem;
    }

    .message.success {
      background: var(--good-bg);
      color: var(--good-text);
      border: 1px solid #a7f3d0;
    }

    .message.error {
      background: var(--bad-bg);
      color: var(--bad-text);
      border: 1px solid #fecaca;
    }

    @media (max-width: 768px) {
      .charts-grid {
        grid-template-columns: 1fr;
      }

      .chart-wrap {
        height: 300px;
      }

      h1 {
        font-size: 1.7rem;
      }

      .scorecards {
        grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
      }
    }
  </style>
</head>
<body>
  <noscript>
    <div style="background: #fef2f2; color: #b91c1c; padding: 16px 24px; text-align: center; font-weight: 600;">
      This reporting dashboard requires JavaScript to display charts and interactive features. Please enable JavaScript in your browser.
    </div>
  </noscript>

  <nav class="navbar">
    <div class="navbar-brand">Reporting Dashboard</div>
    <div class="navbar-content">
      <div class="navbar-nav">
        <a href="/index.php">Dashboard</a>
        <a href="/charts.php">Charts</a>
        <a href="/report.php">Data Table</a>
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
    <?php if ($page_error): ?>
      <div style="padding: 16px; margin-bottom: 24px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; color: #b91c1c; font-weight: 600;">
        <?= htmlspecialchars($page_error) ?>
      </div>
    <?php endif; ?>

    <?php if ($page_warning): ?>
      <div style="padding: 16px; margin-bottom: 24px; background: #fff7ed; border: 1px solid #fed7aa; border-radius: 8px; color: #92400e; font-weight: 500;">
        <?= htmlspecialchars($page_warning) ?>
      </div>
    <?php endif; ?>

    <div class="page-header">
      <h1>
        <?php
        $titles = [
          'performance' => 'Performance Metrics Summary',
          'behavioral' => 'User Behavioral Analysis',
          'engagement' => 'Engagement Performance'
        ];
        echo '';
        ?> 
        <?= htmlspecialchars($titles[$report_category] ?? 'Report') ?>
      </h1>
      <p class="subtitle">
        <?php
        $descs = [
          'performance' => 'Core Web Vitals (FCP, LCP, CLS) and performance metrics analysis',
          'behavioral' => 'User behavior patterns and engagement metrics',
          'engagement' => 'Page load timing and navigation performance distribution'
        ];
        echo htmlspecialchars($descs[$report_category] ?? 'Report data');
        ?>
      </p>
      <?php if (is_analyst() || is_admin()): ?>
        <div style="margin-top: 16px;">
          <button onclick="document.getElementById('saveReportModal').style.display='block'" style="background: #10b981; color: white; border: none; padding: 8px 16px; border-radius: 6px; font-weight: 600; cursor: pointer; font-size: 0.9rem;">Save Report</button>
        </div>
      <?php endif; ?>
    </div>

    <!-- Report Navigation Tabs -->
    <div class="nav-tabs">
      <?php
      $tab_labels = [
        'performance' => 'Performance',
        'behavioral' => 'Behavioral',
        'engagement' => 'Engagement'
      ];
      foreach ($accessible_categories as $cat): ?>
        <button class="nav-tab <?= $report_category === $cat ? 'active' : '' ?>" onclick="location.href='?report=<?= htmlspecialchars($cat) ?>'">
          <?= htmlspecialchars($tab_labels[$cat]) ?>
        </button>
      <?php endforeach; ?>
    </div>

    <?php if ($report_category === 'performance'): ?>
      <!-- PERFORMANCE REPORT -->
      <section class="section">
        <h2 class="section-title">Web Vitals Overview</h2>
        <p class="section-subtitle">Average values and health status for Core Web Vitals metrics</p>

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
          <h2>Metrics Trend Over Time</h2>
          <p>Performance metrics plotted chronologically for trend analysis</p>
          <div class="chart-meta">
            <strong>Data points:</strong> <?= array_sum(array_map('count', $metricTimelineByType)) ?>
          </div>
          <div class="chart-wrap">
            <canvas id="performanceChart"></canvas>
          </div>
          <div class="table-responsive">
            <table>
              <thead>
                <tr>
                  <th>Metric</th>
                  <th>Average</th>
                  <th>Min</th>
                  <th>Max</th>
                  <th>P95</th>
                  <th>Samples</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($metricTableData as $metric => $data): ?>
                  <tr>
                    <td><strong><?= htmlspecialchars($metric) ?></strong></td>
                    <td><?= htmlspecialchars($data['average']) ?></td>
                    <td><?= htmlspecialchars($data['min']) ?></td>
                    <td><?= htmlspecialchars($data['max']) ?></td>
                    <td><?= htmlspecialchars($data['p95']) ?></td>
                    <td><?= htmlspecialchars((string)$data['samples']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

    <?php elseif ($report_category === 'behavioral'): ?>
      <!-- BEHAVIORAL REPORT -->
      <div class="charts-grid">
        <div class="chart-card">
          <h2>User Behavioral Distribution</h2>
          <p>Top 15 interaction event types by frequency</p>
          <div class="chart-meta">
            <strong>Total interactions:</strong> <?= array_sum($interactionData) ?>
          </div>
          <div class="chart-wrap">
            <canvas id="interactionChart"></canvas>
          </div>
        </div>

        <div class="chart-card">
          <h2>Behavioral Event Breakdown</h2>
          <p>Detailed count of each interaction type recorded</p>
          <div class="table-responsive">
            <table>
              <thead>
                <tr>
                  <th>Event Type</th>
                  <th>Count</th>
                  <th>Percentage</th>
                </tr>
              </thead>
              <tbody>
                <?php 
                $total = array_sum($interactionData);
                foreach ($interactionLabels as $i => $label): 
                  $count = $interactionData[$i];
                  $percentage = $total > 0 ? number_format(($count / $total) * 100, 1) : 0;
                ?>
                  <tr>
                    <td><?= htmlspecialchars($label) ?></td>
                    <td><strong><?= htmlspecialchars((string)$count) ?></strong></td>
                    <td><?= htmlspecialchars($percentage) ?>%</td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

    <?php elseif ($report_category === 'engagement'): ?>
      <!-- ENGAGEMENT REPORT -->
      <div class="charts-grid">
        <div class="chart-card">
          <h2>Navigation Timing Distribution</h2>
          <p>Histogram of page load times across frequency buckets</p>
          <div class="chart-meta">
            <strong>Total measurements:</strong> <?= count($navigationTimings) ?>
          </div>
          <div class="chart-wrap">
            <canvas id="navHistogramChart"></canvas>
          </div>
        </div>

        <div class="chart-card">
          <h2>Timing Buckets Analysis</h2>
          <p>Breakdown of navigation times by performance tier</p>
          <div class="table-responsive">
            <table>
              <thead>
                <tr>
                  <th>Timing Range</th>
                  <th>Sample Count</th>
                  <th>Percentage</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                <?php 
                $navTotal = array_sum($navData);
                foreach ($navLabels as $i => $bucket): 
                  $count = $navData[$i];
                  $percentage = $navTotal > 0 ? number_format(($count / $navTotal) * 100, 1) : 0;
                  $status = (strpos($bucket, '0.00') !== false || strpos($bucket, '0.10') !== false) ? 'Excellent' : 
                            (strpos($bucket, '0.20') !== false || strpos($bucket, '0.30') !== false ? 'Good' : 
                            (strpos($bucket, '0.40') !== false ? 'Fair' : 'Needs Improvement'));
                ?>
                  <tr>
                    <td><?= htmlspecialchars($bucket) ?></td>
                    <td><strong><?= htmlspecialchars((string)$count) ?></strong></td>
                    <td><?= htmlspecialchars($percentage) ?>%</td>
                    <td><span class="badge <?= $status === 'Excellent' ? 'good' : ($status === 'Good' ? 'good' : 'poor') ?>"><?= htmlspecialchars($status) ?></span></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <!-- ANALYST COMMENTS SECTION -->
    <section class="comments-section">
      <h2 class="section-title">Analyst Comments & Insights</h2>
      <p class="section-subtitle">Expert observations and interpretations of the data</p>

      <?php if ($comment_message): ?>
        <div class="message success"><?= htmlspecialchars($comment_message) ?></div>
      <?php endif; ?>

      <?php if ($comment_error): ?>
        <div class="message error"><?= htmlspecialchars($comment_error) ?></div>
      <?php endif; ?>

      <?php if (is_analyst() || is_admin()): ?>
        <div class="comment-form">
          <form method="POST">
            <input type="hidden" name="action" value="add_comment">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            
            <div class="form-group">
              <label for="content">Add Your Analysis</label>
              <textarea id="content" name="content" required placeholder="Share your insights and observations about this report..."></textarea>
            </div>

            <div class="form-check">
              <input type="checkbox" id="is_markdown" name="is_markdown" value="1">
              <label for="is_markdown">Enable Markdown formatting (for emphasis, links, lists, etc.)</label>
            </div>

            <button type="submit" class="submit-btn">Post Comment</button>
          </form>
        </div>
      <?php endif; ?>

      <?php if (!empty($comments)): ?>
        <div style="margin-top: 24px;">
          <h3 style="margin: 0 0 16px; font-size: 1.1rem;">Recent Comments</h3>
          <?php foreach ($comments as $comment): ?>
            <div class="comment">
              <div class="comment-header">
                <div>
                  <div class="comment-author"><?= htmlspecialchars($comment['display_name'] ?: $comment['username'] ?: 'Anonymous') ?></div>
                </div>
                <div class="comment-time"><?= date('M d, Y \a\t H:i', strtotime($comment['created_at'])) ?></div>
              </div>
              <div class="comment-content">
                <?php
                if ($comment['is_markdown']) {
                  // Simple markdown rendering (bold, italic, links, lists)
                  $text = htmlspecialchars($comment['content']);
                  // Bold: **text** -> <strong>text</strong>
                  $text = preg_replace('/\*\*(.*?)\*\*/s', '<strong>$1</strong>', $text);
                  // Italic: *text* -> <em>text</em>
                  $text = preg_replace('/\*(.*?)\*/s', '<em>$1</em>', $text);
                  // Links: [text](url) -> <a href="url">text</a>
                  $text = preg_replace('/\[(.*?)\]\((.*?)\)/s', '<a href="$2" style="color: var(--accent);">$1</a>', $text);
                  // Line breaks
                  $text = nl2br($text);
                  // Lists - convert lines starting with - or * to <li>
                  $text = preg_replace('/^\s*[-*]\s+(.*?)$/m', '<li>$1</li>', $text);
                  $text = preg_replace('/(<li>.*?<\/li>)/s', '<ul>$1</ul>', $text);
                  $text = str_replace('</ul>\n<ul>', '', $text);
                  
                  echo $text;
                } else {
                  echo nl2br(htmlspecialchars($comment['content']));
                }
                ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p style="color: var(--muted); margin-top: 24px; text-align: center;">No comments yet. Be the first to share your insights!</p>
      <?php endif; ?>
    </section>

    <!-- SAVE REPORT MODAL -->
    <?php if (is_analyst() || is_admin()): ?>
    <div id="saveReportModal" style="display:none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.4);">
      <div style="background-color: var(--card); margin: 10% auto; padding: 24px; border: 1px solid var(--border); border-radius: 8px; width: 90%; max-width: 400px; box-shadow: 0 8px 24px rgba(0,0,0,0.15);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
          <h2 style="margin: 0; font-size: 1.3rem;">Save Report</h2>
          <button onclick="document.getElementById('saveReportModal').style.display='none'" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--muted);">&times;</button>
        </div>
        
        <form id="saveReportForm" method="POST" style="display: flex; flex-direction: column; gap: 12px;">
          <input type="hidden" name="action" value="save_report">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
          
          <div id="formMessage" style="display: none; padding: 12px; border-radius: 6px; margin-bottom: 12px; font-size: 0.9rem;">
          </div>
          
          <div>
            <label for="report_name" style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.95rem;">Report Name</label>
            <input type="text" id="report_name" name="report_name" required placeholder="e.g., Q1 Performance Analysis" style="width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 6px; font-size: 0.95rem;">
          </div>
          
          <div style="display: flex; gap: 8px; margin-top: 12px;">
            <button type="submit" id="saveBtn" style="flex: 1; background: #10b981; color: white; border: none; padding: 10px; border-radius: 6px; font-weight: 600; cursor: pointer;">Save</button>
            <button type="button" onclick="document.getElementById('saveReportModal').style.display='none'" style="flex: 1; background: var(--border); color: var(--text); border: none; padding: 10px; border-radius: 6px; font-weight: 600; cursor: pointer;">Cancel</button>
          </div>
        </form>
        
        <script>
          document.getElementById('saveReportForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const btn = document.getElementById('saveBtn');
            const msgDiv = document.getElementById('formMessage');
            btn.disabled = true;
            btn.textContent = 'Saving...';
            
            fetch(window.location.href, {
              method: 'POST',
              body: new FormData(this)
            })
            .then(response => response.text())
            .then(html => {
              msgDiv.style.display = 'block';
              if (html.includes("saved successfully")) {
                msgDiv.style.background = '#ecfdf3';
                msgDiv.style.color = '#047857';
                msgDiv.style.border = '1px solid #a7f3d0';
                msgDiv.textContent = 'Report saved successfully! Redirecting to dashboard...';
                setTimeout(() => {
                  window.location.href = '/index.php';
                }, 1500);
              } else if (html.includes("Session error") || html.includes("Access denied") || html.includes("Security")) {
                msgDiv.style.background = '#fef2f2';
                msgDiv.style.color = '#b91c1c';
                msgDiv.style.border = '1px solid #fecaca';
                msgDiv.textContent = 'Error: ' + (html.match(/(?:Session error|Access denied|Security)[^<]*/)?.[0] || 'Unknown error');
                btn.disabled = false;
                btn.textContent = 'Save';
              } else {
                msgDiv.style.background = '#fef2f2';
                msgDiv.style.color = '#b91c1c';
                msgDiv.style.border = '1px solid #fecaca';
                msgDiv.textContent = 'Error: Could not save report. Please try again.';
                btn.disabled = false;
                btn.textContent = 'Save';
              }
            })
            .catch(err => {
              msgDiv.style.display = 'block';
              msgDiv.style.background = '#fef2f2';
              msgDiv.style.color = '#b91c1c';
              msgDiv.style.border = '1px solid #fecaca';
              msgDiv.textContent = 'Network error: ' + err.message;
              btn.disabled = false;
              btn.textContent = 'Save';
            });
          });
        </script>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <script>
    // Performance Metrics Timeline Chart (only for performance report)
    <?php if ($report_category === 'performance'): ?>
      const metricTimelineByType = <?= json_encode($metricTimelineByType) ?>;
      
      // Prepare data for line chart
      const chartDatasets = [];
      const colors = {
        'FCP': '#3b82f6',
        'LCP': '#ef4444',
        'CLS': '#8b5cf6',
        'TBT': '#eab308',
        'FID': '#06b6d4'
      };

      for (const [metric, data] of Object.entries(metricTimelineByType)) {
        if (data.length > 0) {
          chartDatasets.push({
            label: metric,
            data: data.map(d => d.value),
            borderColor: colors[metric] || '#6b7280',
            backgroundColor: colors[metric] ? colors[metric] + '20' : '#6b728020',
            tension: 0.3,
            fill: false,
            borderWidth: 2,
            pointRadius: 4,
            pointBackgroundColor: colors[metric] || '#6b7280',
            pointBorderColor: '#fff',
            pointBorderWidth: 2,
          });
        }
      }

      const allTimestamps = [];
      for (const data of Object.values(metricTimelineByType)) {
        for (const point of data) {
          if (!allTimestamps.includes(point.timestamp)) {
            allTimestamps.push(point.timestamp);
          }
        }
      }

      let performanceChart = new Chart(document.getElementById('performanceChart'), {
        type: 'line',
        data: {
          labels: allTimestamps.map(t => new Date(t).toLocaleTimeString()),
          datasets: chartDatasets
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          interaction: {
            mode: 'index',
            intersect: false,
          },
          plugins: {
            legend: {
              display: true,
              position: 'bottom',
              onClick: false
            }
          },
          scales: {
            y: {
              beginAtZero: true,
              title: {
                display: true,
                text: 'Metric Value (seconds)'
              }
            }
          }
        }
      });
    <?php endif; ?>

    // Behavioral Distribution Chart (only for behavioral report)
    <?php if ($report_category === 'behavioral'): ?>
      const interactionLabels = <?= json_encode($interactionLabels) ?>;
      const interactionData = <?= json_encode($interactionData) ?>;

      new Chart(document.getElementById('interactionChart'), {
        type: 'doughnut',
        data: {
          labels: interactionLabels,
          datasets: [{
            data: interactionData,
            backgroundColor: [
              '#3b82f6', '#ef4444', '#10b981', '#f59e0b', '#8b5cf6',
              '#ec4899', '#06b6d4', '#14b8a6', '#f97316', '#6366f1',
              '#d946ef', '#84cc16', '#0891b2', '#a855f7', '#15803d'
            ],
            borderWidth: 2,
            borderColor: '#fff'
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              position: 'right',
              onClick: false
            }
          }
        }
      });
    <?php endif; ?>

    // Navigation Histogram Chart (only for engagement report)
    <?php if ($report_category === 'engagement'): ?>
      const navLabels = <?= json_encode($navLabels) ?>;
      const navData = <?= json_encode($navData) ?>;

      new Chart(document.getElementById('navHistogramChart'), {
        type: 'bar',
        data: {
          labels: navLabels,
          datasets: [{
            label: 'Sample Count',
            data: navData,
            backgroundColor: '#3b82f6',
            borderColor: '#1e40af',
            borderWidth: 1
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              display: true,
              onClick: false
            }
          },
          scales: {
            y: {
              beginAtZero: true,
              title: {
                display: true,
                text: 'Number of Samples'
              }
            }
          }
        }
      });
    <?php endif; ?>
  </script>
</body>
</html>
<?php
// ---- CORS (allow main site -> collector) ----
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allow = [
  'https://hknucsd-outreach.org',
  'https://www.hknucsd-outreach.org',
];

if (in_array($origin, $allow, true)) {
  header("Access-Control-Allow-Origin: $origin");
  header("Vary: Origin");
}

// Needed for preflight
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

// Only allow POST for real data
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit;
}

// ---- existing code (your logging) ----
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
  http_response_code(400);
  exit;
}

file_put_contents(
  __DIR__ . '/../beacons.jsonl',
  json_encode($data) . "\n",
  FILE_APPEND
);

http_response_code(204);

?>
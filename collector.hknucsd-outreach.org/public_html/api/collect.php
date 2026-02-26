<?php
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit;
}

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
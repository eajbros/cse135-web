<?php
declare(strict_types=1);

$JSONL_PATH = "/var/www/collector.hknucsd-outreach.org/public_html/beacons.jsonl";

// DB info
$DB_HOST = "localhost";
$DB_NAME = "collector_db";
$DB_USER = "collector_user";     // <-- change
$DB_PASS = "SUPER_Collector_2026!&67";     // <-- change
$dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4";
// ----------------------------------------

if (!file_exists($JSONL_PATH)) {
  fwrite(STDERR, "No file at: {$JSONL_PATH}\n");
  exit(1);
}

$pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$insert = $pdo->prepare("
  INSERT INTO beacons_raw (sid, page, sent_at, payload)
  VALUES (:sid, :page, :sent_at, CAST(:payload AS JSON))
");

$fh = fopen($JSONL_PATH, "rb");
if (!$fh) {
  fwrite(STDERR, "Failed to open {$JSONL_PATH}\n");
  exit(1);
}

$lines = 0;
$inserted = 0;
$bad = 0;

while (($line = fgets($fh)) !== false) {
  $lines++;
  $line = trim($line);
  if ($line === "") continue;

  $data = json_decode($line, true);
  if (!is_array($data)) { $bad++; continue; }

  $sid = isset($data["sid"]) ? (string)$data["sid"] : "";
  if ($sid === "") { $bad++; continue; }

  $page = isset($data["page"]) ? (string)$data["page"] : null;
  $sent_at = isset($data["sent_at"]) ? (int)$data["sent_at"] : null;

  $insert->execute([
    ":sid" => $sid,
    ":page" => $page,
    ":sent_at" => $sent_at,
    ":payload" => $line,
  ]);

  $inserted++;
}

fclose($fh);

echo "Done. lines_read={$lines} inserted={$inserted} bad={$bad}\n";
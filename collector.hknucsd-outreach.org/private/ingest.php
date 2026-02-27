<?php
declare(strict_types=1);

$BASE = "/var/www/collector.hknucsd-outreach.org/public_html";
$JSONL = $BASE . "/beacons.jsonl";

// DB info
$DB_HOST = "localhost";
$DB_NAME = "collector_db";
$DB_USER = "collector_user";   
$DB_PASS = "SUPER_Collector_2026!&67"; 
$dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4";
// ----------------------------------------

$pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Rotate the file atomically so new beacons keep flowing into a fresh file
if (!file_exists($JSONL)) {
  // ensure file exists
  file_put_contents($JSONL, "");
  @chgrp($JSONL, "www-data");
  @chmod($JSONL, 0664);
  echo "No beacons.jsonl existed; created empty. Ingested=0\n";
  exit(0);
}

$rot = $JSONL . ".ingesting." . date("Ymd_His");

if (!@rename($JSONL, $rot)) {
  // If we can't rotate, do NOT truncate (avoid data loss)
  fwrite(STDERR, "ERROR: failed to rotate {$JSONL}\n");
  exit(1);
}

// Immediately create fresh empty file for the collector to append to
file_put_contents($JSONL, "");
@chgrp($JSONL, "www-data");
@chmod($JSONL, 0664);

// Ingest rotated file
$insert = $pdo->prepare("
  INSERT INTO beacons_raw (sid, page, sent_at, payload)
  VALUES (:sid, :page, :sent_at, CAST(:payload AS JSON))
");

$fh = fopen($rot, "rb");
if (!$fh) {
  fwrite(STDERR, "ERROR: cannot open rotated file {$rot}\n");
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

// delete rotated file after successful ingest
@unlink($rot);

echo "Done. lines_read={$lines} inserted={$inserted} bad={$bad} wiped=1\n";
<?php
header("Content-Type: application/json");

$DB_HOST = "localhost";
$DB_NAME = "collector_db";
$DB_USER = "collector_user";
$DB_PASS = "SUPER_Collector_2026!&67";

$pdo = new PDO(
    "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
    $DB_USER,
    $DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$method = $_SERVER['REQUEST_METHOD']; // GET, POST, PUT, DELETE
$request = $_SERVER['REQUEST_URI'];

// Split URL into segments
$path = parse_url($request, PHP_URL_PATH);
$segments = explode('/', trim($path, '/'));

// Example: /api/beacons/5 → ["api", "beacons", "5"]
$id = $segments[2] ?? null;

if (!isset($segments[1]) || $segments[1] !== 'beacons') {
    http_response_code(404);
    echo json_encode(["error" => "Not found"]);
    exit;
}

if ($method === "GET" && $id === null) {
    $stmt = $pdo->query("SELECT * FROM beacons_raw ORDER BY id DESC LIMIT 100");
    echo json_encode($stmt->fetchAll());
    exit;
}

if ($method === "GET" && $id !== null) {
    $stmt = $pdo->prepare("SELECT * FROM beacons_raw WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        http_response_code(404);
        echo json_encode(["error" => "Not found"]);
        exit;
    }
    echo json_encode($row);
    exit;
}

if ($method === "POST" && $id === null) {
    $input = json_decode(file_get_contents("php://input"), true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(["error" => "Invalid JSON"]);
        exit;
    }

    $stmt = $pdo->prepare("
        INSERT INTO beacons_raw (sid, page, sent_at, payload)
        VALUES (?, ?, ?, CAST(? AS JSON))
    ");
    $stmt->execute([
        $input["sid"] ?? "",
        $input["page"] ?? null,
        $input["sent_at"] ?? null,
        json_encode($input)
    ]);

    echo json_encode(["id" => $pdo->lastInsertId()]);
    exit;
}

if ($method === "PUT" && $id !== null) {
    $input = json_decode(file_get_contents("php://input"), true);
    $stmt = $pdo->prepare("
        UPDATE beacons_raw
        SET page = ?, sent_at = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $input["page"] ?? null,
        $input["sent_at"] ?? null,
        $id
    ]);
    echo json_encode(["updated" => $stmt->rowCount()]);
    exit;
}

if ($method === "DELETE" && $id !== null) {
    $stmt = $pdo->prepare("DELETE FROM beacons_raw WHERE id = ?");
    $stmt->execute([$id]);
    echo json_encode(["deleted" => $stmt->rowCount()]);
    exit;
}

http_response_code(405);
echo json_encode(["error" => "Method not allowed"]);
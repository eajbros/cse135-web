<?php
header("Cache-Control: no-cache");
header("Content-Type: application/json");

$message = [
    "title"   => "Hello, PHP!",
    "heading" => "Hello, PHP!",
    "message" => "This page was generated with the PHP programming language",
    "time"    => date("Y-m-d H:i:s"),
    "IP"      => $_SERVER["REMOTE_ADDR"] ?? "unknown"
];

echo json_encode($message);
?>

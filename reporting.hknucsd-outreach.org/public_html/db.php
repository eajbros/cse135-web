<?php
$host = 'localhost';
$dbname = 'collector_db';
$dbuser = 'collector_user';
$dbpass = 'SUPER_Collector_2026!&67';

$dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";

$pdo = new PDO($dsn, $dbuser, $dbpass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
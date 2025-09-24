<?php
$DB_HOST = 'localhost';
$DB_USER = 'root';   // Default XAMPP username
$DB_PASS = '';       // Default XAMPP password is empty
$DB_NAME = 'isiqueue';

$mysqli = @new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($mysqli->connect_errno) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['ok'=>false, 'error'=>'Database connection failed: '.$mysqli->connect_error]);
    exit;
}
$mysqli->set_charset('utf8mb4');

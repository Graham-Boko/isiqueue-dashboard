<?php
header('Content-Type: application/json');
require_once __DIR__ . '/db.php'; // defines $mysqli

if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'message'=>'DB connection not available']); exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$id  = (int)($input['customer_id'] ?? 0);
$new = trim($input['new_password'] ?? '');

if ($id <= 0 || $new === '') {
  echo json_encode(['ok'=>false,'message'=>'Invalid data']); exit;
}

$stmt = $mysqli->prepare(
  "UPDATE customers
   SET password = ?, must_change_password = 0, updated_at = NOW()
   WHERE id = ?"
);
$stmt->bind_param('si', $new, $id);
$stmt->execute();

echo json_encode(['ok'=>true,'message'=>'Password updated']);

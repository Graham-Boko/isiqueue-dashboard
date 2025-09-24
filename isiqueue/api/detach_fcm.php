<?php
// detach_fcm.php
// Expects POST: customer_id (int), token (string)

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST');

require __DIR__ . '/db.php'; // must define $mysqli (mysqli)

if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
    echo json_encode(['ok' => false, 'error' => 'Database connection not available']); exit;
}

$customerId = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
$token      = isset($_POST['token']) ? trim($_POST['token']) : '';

if ($customerId <= 0 || $token === '') {
    echo json_encode(['ok' => false, 'error' => 'Missing customer_id or token']); exit;
}

$stmt = $mysqli->prepare("DELETE FROM customer_tokens WHERE customer_id=? AND fcm_token=?");
if (!$stmt) {
    echo json_encode(['ok' => false, 'error' => 'Prepare failed: ' . $mysqli->error]); exit;
}
$stmt->bind_param('is', $customerId, $token);

if (!$stmt->execute()) {
    echo json_encode(['ok' => false, 'error' => 'Execute failed: ' . $stmt->error]);
    $stmt->close();
    $mysqli->close();
    exit;
}

$stmt->close();
$mysqli->close();

echo json_encode(['ok' => true, 'message' => 'Token detached']);

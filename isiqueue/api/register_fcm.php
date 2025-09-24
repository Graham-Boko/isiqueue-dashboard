<?php
// register_fcm.php
// Expects POST: customer_id (int), token (string), device (string, optional)

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
$device     = isset($_POST['device']) ? trim($_POST['device']) : 'android';

if ($customerId <= 0 || $token === '') {
    echo json_encode(['ok' => false, 'error' => 'Missing customer_id or token']); exit;
}

/*
 * NOTE:
 * customer_tokens table must exist with a UNIQUE KEY on fcm_token
 *   CREATE TABLE IF NOT EXISTS customer_tokens (
 *     id INT AUTO_INCREMENT PRIMARY KEY,
 *     customer_id INT NOT NULL,
 *     fcm_token VARCHAR(255) NOT NULL,
 *     device_id VARCHAR(191) DEFAULT NULL,
 *     last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE KEY uq_token (fcm_token),
 *     KEY idx_customer (customer_id)
 *   );
 *
 * This upserts the token so exactly one owner exists per token.
 */

$sql = "INSERT INTO customer_tokens (customer_id, fcm_token, device_id, last_seen_at)
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
          customer_id = VALUES(customer_id),
          device_id   = VALUES(device_id),
          last_seen_at= NOW()";

$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    echo json_encode(['ok' => false, 'error' => 'Prepare failed: ' . $mysqli->error]); exit;
}

$stmt->bind_param('iss', $customerId, $token, $device);

if (!$stmt->execute()) {
    echo json_encode(['ok' => false, 'error' => 'Execute failed: ' . $stmt->error]);
    $stmt->close();
    $mysqli->close();
    exit;
}

$stmt->close();

// Optional: if you still have old columns in `customers`, you can keep them NULL to avoid confusion.
// $stmt2 = $mysqli->prepare("UPDATE customers SET fcm_token = NULL, fcm_device = NULL WHERE id = ?");
// if ($stmt2) { $stmt2->bind_param('i', $customerId); $stmt2->execute(); $stmt2->close(); }

echo json_encode([
    'ok' => true,
    'message' => 'Token bound to customer',
    'customer_id' => $customerId,
    'device' => $device
]);

$mysqli->close();

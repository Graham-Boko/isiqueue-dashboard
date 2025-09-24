<?php
// C:\xampp\htdocs\isiqueue\api\my_ticket.php
header('Content-Type: application/json');
require __DIR__ . '/db.php'; // mysqli -> $mysqli

$conn = $mysqli;
if (!($conn instanceof mysqli)) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'DB connection not available']); exit;
}

// read JSON or form
$input = [];
$ctype = $_SERVER['CONTENT_TYPE'] ?? '';
if ($ctype && stripos($ctype, 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($json)) $input = $json;
}
if (!$input) $input = $_POST;

$customer_id = isset($input['customer_id']) ? (int)$input['customer_id'] : 0;
if ($customer_id <= 0) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'customer_id required']); exit;
}

/*
   Handles both text and numeric status storage.
   If numeric: 1=waiting, 2=serving (not now_serving), 3=served
*/
$sql = "
  SELECT
    t.ticket_no,
    t.status,
    s.code   AS service_code,
    s.service,
    t.called_at,
    t.completed_at
  FROM tickets t
  JOIN services s ON s.id = t.service_id
  WHERE t.customer_id = ?
  ORDER BY t.created_at DESC, t.id DESC
  LIMIT 1
";
$stmt = $conn->prepare($sql);
if (!$stmt) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Prepare failed: '.$conn->error]); exit; }
$stmt->bind_param("i", $customer_id);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();
$stmt->close();

if (!$row) { echo json_encode(['ok'=>true, 'ticket_no'=>null]); exit; }

// derive canonical status from timestamps first
$status = !empty($row['completed_at']) ? 'served' : (!empty($row['called_at']) ? 'serving' : null);

// map DB status if needed
if ($status === null) {
    $raw = $row['status'];
    if (is_numeric($raw)) {
        $map = [1=>'waiting', 2=>'serving', 3=>'served'];
        $status = $map[(int)$raw] ?? 'waiting';
    } else {
        $v = strtolower(trim((string)$raw));
        if ($v === 'now_serving') $v = 'serving';
        if (!in_array($v, ['waiting','serving','served'], true)) $v = 'waiting';
        $status = $v;
    }
}

echo json_encode([
  'ok'           => true,
  'ticket_no'    => $row['ticket_no'],
  'status'       => $status,
  'service_code' => $row['service_code'],
  'service'      => $row['service']
]);

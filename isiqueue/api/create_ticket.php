<?php
// C:\xampp\htdocs\isiqueue\api\create_ticket_seq.php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php'; // provides $mysqli
function fail($m){ echo json_encode(['ok'=>false,'message'=>$m]); exit; }

$in = json_decode(file_get_contents('php://input'), true) ?: [];

$customer_id  = (int)($in['customer_id'] ?? 0);
/* Accept either the short code ("L") or the service name ("Loan") */
$service_code_or_name = trim((string)($in['service_code'] ?? $in['service'] ?? ''));

if ($customer_id <= 0) fail('Invalid customer');
if ($service_code_or_name === '') fail('Missing service');

try {
  /* 1) Find service by code OR by name (must be active) */
  $svc = strtoupper($service_code_or_name);
  $stmt = $mysqli->prepare("
      SELECT id, UPPER(code) AS code, service
      FROM services
      WHERE (UPPER(code) = ? OR UPPER(service) = ?)
        AND is_active = 1
      LIMIT 1
  ");
  $stmt->bind_param('ss', $svc, $svc);
  $stmt->execute();
  $res = $stmt->get_result();
  if ($res->num_rows === 0) fail('Invalid or inactive service');
  $row = $res->fetch_assoc();
  $service_id   = (int)$row['id'];
  $service_code = (string)$row['code'];   // e.g., W, D, C, E, L
  $stmt->close();

  /* 2) Prevent duplicate active ticket (waiting/serving only) */
  $stmt = $mysqli->prepare("
      SELECT ticket_no FROM tickets
      WHERE customer_id=? AND TRIM(LOWER(status)) IN ('waiting','serving')
      ORDER BY id DESC LIMIT 1
  ");
  $stmt->bind_param('i', $customer_id);
  $stmt->execute();
  $res = $stmt->get_result();
  if ($res->num_rows > 0) {
    echo json_encode([
      'ok'=>true,
      'message'=>'Active ticket exists',
      'ticket_no'=>$res->fetch_assoc()['ticket_no']
    ]);
    exit;
  }
  $stmt->close();

  /* 3) Generate sequence (per day, global) and create ticket
        — keeps your existing ticket_sequence design */
  $mysqli->begin_transaction();
  $today = date('Y-m-d');

  $stmt = $mysqli->prepare("
    INSERT INTO ticket_sequence (seq_date, last_seq) VALUES (?,0)
    ON DUPLICATE KEY UPDATE last_seq = last_seq + 1
  ");
  $stmt->bind_param('s', $today);
  $stmt->execute();
  $stmt->close();

  $stmt = $mysqli->prepare("SELECT last_seq FROM ticket_sequence WHERE seq_date=?");
  $stmt->bind_param('s', $today);
  $stmt->execute();
  $seq = (int)$stmt->get_result()->fetch_assoc()['last_seq'];
  $stmt->close();

  // Your ISO-style ticket (frontend compresses this to W#, D# etc. for display)
  $ticket_no = sprintf('%s-%s-%04d', $service_code, date('Ymd'), $seq);

  $statusWaiting = 'waiting';
  $stmt = $mysqli->prepare("
    INSERT INTO tickets (ticket_no, customer_id, service_id, status, created_at)
    VALUES (?,?,?,?, NOW())
  ");
  $stmt->bind_param('siis', $ticket_no, $customer_id, $service_id, $statusWaiting);
  $stmt->execute();
  $stmt->close();

  $mysqli->commit();

  echo json_encode([
    'ok'        => true,
    'message'   => 'Ticket created',
    'ticket_no' => $ticket_no,
    'service'   => ['id'=>$service_id, 'code'=>$service_code]
  ]);

} catch (Throwable $e) {
  try { $mysqli->rollback(); } catch (Throwable $ignored) {}
  fail('Server error creating ticket');
}

<?php
// C:\xampp\htdocs\isiqueue\api\skip_ticket.php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    require __DIR__ . '/db.php';
    if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
        throw new Exception('DB not initialized ($mysqli is null)');
    }

    $raw    = file_get_contents('php://input');
    $ctype  = $_SERVER['CONTENT_TYPE'] ?? '';
    $isJson = stripos($ctype, 'application/json') !== false;
    $in     = $isJson ? (json_decode($raw, true) ?: []) : $_POST;

    $ticketNo = isset($in['ticket_no']) ? trim((string)$in['ticket_no']) : '';
    $ticketId = isset($in['ticket_id']) ? (int)$in['ticket_id'] : 0;

    $mysqli->begin_transaction();

    if ($ticketId > 0) {
        $st = $mysqli->prepare("SELECT id, status, ticket_no FROM tickets WHERE id=? FOR UPDATE");
        $st->bind_param('i', $ticketId);
    } elseif ($ticketNo !== '') {
        $st = $mysqli->prepare("SELECT id, status, ticket_no FROM tickets WHERE ticket_no=? ORDER BY id DESC LIMIT 1 FOR UPDATE");
        $st->bind_param('s', $ticketNo);
    } else {
        // default to the latest serving ticket
        $st = $mysqli->prepare("
            SELECT id, status, ticket_no
              FROM tickets
             WHERE TRIM(LOWER(status)) IN ('serving','now_serving')
          ORDER BY called_at DESC, id DESC
             LIMIT 1
        ");
    }
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$row) { $mysqli->rollback(); http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Ticket not found']); exit; }

    $id = (int)$row['id'];
    $status = strtolower(trim($row['status'] ?? ''));

    if (in_array($status, ['served','cancelled'], true)) {
        $mysqli->rollback();
        http_response_code(409);
        echo json_encode(['ok'=>false,'error'=>"Ticket already $status"]); exit;
    }

    $u = $mysqli->prepare("
        UPDATE tickets
           SET status='cancelled',
               cancelled_at=NOW(),
               counter_id=NULL
         WHERE id=?
    ");
    $u->bind_param('i', $id);
    $u->execute(); $u->close();

    $mysqli->commit();
    echo json_encode([
        'ok'=>true,
        'message'=>'Ticket cancelled (skipped)',
        'ticket'=>['id'=>$id, 'ticket_no'=>$row['ticket_no']]
    ]);

} catch (Throwable $e) {
    try { $mysqli->rollback(); } catch(Throwable $ignored) {}
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Server error','detail'=>$e->getMessage()]);
}

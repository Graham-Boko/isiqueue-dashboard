<?php
// C:\xampp\htdocs\isiqueue\api\complete_ticket.php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

ini_set('display_errors','0');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
while (ob_get_level() > 0) { ob_end_clean(); }

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    require __DIR__ . '/db.php';
    if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
        throw new Exception('DB not initialized ($mysqli is null)');
    }

    // input
    $raw    = file_get_contents('php://input');
    $ctype  = $_SERVER['CONTENT_TYPE'] ?? '';
    $isJson = stripos($ctype, 'application/json') !== false;
    $in     = $isJson ? (json_decode($raw, true) ?: []) : $_POST;

    $ticketNo = isset($in['ticket_no']) ? trim((string)$in['ticket_no']) : '';
    $ticketId = isset($in['ticket_id']) ? (int)$in['ticket_id'] : 0;

    if ($ticketId <= 0 && $ticketNo === '') {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'ticket_no or ticket_id is required']); exit;
    }

    $mysqli->begin_transaction();

    // locate FOR UPDATE
    if ($ticketId > 0) {
        $st = $mysqli->prepare("
            SELECT id, ticket_no, customer_id, service_id, status, counter_id, called_at, completed_at
              FROM tickets
             WHERE id=?
             LIMIT 1
             FOR UPDATE
        ");
        $st->bind_param('i',$ticketId);
    } else {
        $st = $mysqli->prepare("
            SELECT id, ticket_no, customer_id, service_id, status, counter_id, called_at, completed_at
              FROM tickets
             WHERE ticket_no=?
          ORDER BY id DESC
             LIMIT 1
             FOR UPDATE
        ");
        $st->bind_param('s',$ticketNo);
    }
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$row) { $mysqli->rollback(); echo json_encode(['ok'=>false,'error'=>'Ticket not found']); exit; }

    $cur = strtolower(trim((string)$row['status']));
    if ($cur === 'served' || $cur === 'completed') {
        $mysqli->commit();
        echo json_encode(['ok'=>true,'idempotent'=>true,'item'=>[
            'id'=>(int)$row['id'],
            'ticket_no'=>$row['ticket_no'],
            'ticket_short'=>$row['ticket_no'],
            'status'=>'served',
            'counter'=>$row['counter_id'] ? (string)$row['counter_id'] : '',
            'called_at'=>$row['called_at'],
            'completed_at'=>$row['completed_at']
        ]]); exit;
    }

    // ✅ allow finishing even if teller forgot to click "Serving"
    $u = $mysqli->prepare("
        UPDATE tickets
           SET status='served',
               completed_at=NOW(),
               counter_id=NULL
         WHERE id=? AND TRIM(LOWER(status)) IN ('serving','now_serving','on_call','waiting')
    ");
    $u->bind_param('i', $row['id']);
    $u->execute();
    if ($u->affected_rows !== 1) { 
        $u->close(); 
        $mysqli->rollback(); 
        echo json_encode(['ok'=>false,'error'=>'Could not update ticket status (maybe already changed)']); 
        exit; 
    }
    $u->close();

    // re-fetch the served ticket for response
    $q = $mysqli->prepare("
        SELECT id, ticket_no, customer_id, service_id, status, counter_id, called_at, completed_at
          FROM tickets WHERE id=? LIMIT 1
    ");
    $q->bind_param('i', $row['id']); $q->execute();
    $ticket = $q->get_result()->fetch_assoc(); $q->close();

    $mysqli->commit();

    // ---- Push "SERVED" ----
    $notified = false; $pushInfo = ['sent'=>0,'errors'=>[]];
    try {
        $tokens = [];
        $tokStmt = $mysqli->prepare("SELECT fcm_token FROM customer_tokens WHERE customer_id=?");
        if ($tokStmt) {
            $cid = (int)$ticket['customer_id'];
            $tokStmt->bind_param('i', $cid);
            $tokStmt->execute();
            $res = $tokStmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $t = trim((string)$r['fcm_token']); if ($t !== '') $tokens[] = $t;
            }
            $tokStmt->close();
        }

        if ($tokens) {
            $autoload = __DIR__ . '/../firebase/vendor/autoload.php';
            if (file_exists($autoload)) {
                require $autoload;
                $factory   = (new \Kreait\Firebase\Factory)->withServiceAccount(__DIR__ . '/../firebase/serviceAccountKey.json');
                $messaging = $factory->createMessaging();

                // 🔔 Clearer notification
                $title = "Ticket {$ticket['ticket_no']} Completed";
                $body  = "Your service is now finished. Thank you for choosing BSP.";

                $data = [
                    'action'       => 'SERVED',
                    'status'       => 'served',
                    'ticket_id'    => (string)$ticket['id'],
                    'ticket_no'    => (string)$ticket['ticket_no'],
                    'ticket_short' => (string)$ticket['ticket_no'],
                    'service_id'   => (string)$ticket['service_id'],
                    'counter'      => (string)($ticket['counter_id'] ?? ''),
                    'completed_at' => (string)($ticket['completed_at'] ?? ''),
                    'timestamp'    => (string)time(),
                ];

                foreach ($tokens as $tk) {
                    $message = [
                        'token' => $tk,
                        'notification' => ['title' => $title, 'body' => $body],
                        'data' => $data,
                        'android' => ['priority' => 'high','notification' => ['sound' => 'default','channel_id' => 'isiqueue_calls']],
                        'apns'    => ['headers' => ['apns-priority' => '10'], 'payload' => ['aps' => ['sound' => 'default']]],
                    ];

                    try {
                        $messaging->send($message);
                        $pushInfo['sent']++;
                        $notified = true;
                    } catch (\Throwable $e) {
                        $pushInfo['errors'][] = ['token'=>$tk, 'error'=>$e->getMessage()];
                    }
                }
            }
        }
    } catch (\Throwable $e) {
        $pushInfo['errors'][] = ['token'=>null, 'error'=>$e->getMessage()];
    }

    echo json_encode([
        'ok'=>true,
        'item'=>[
            'id'=>(int)$ticket['id'],
            'ticket_no'=>$ticket['ticket_no'],
            'ticket_short'=>$ticket['ticket_no'],
            'status'=>'served',
            'counter'=>$ticket['counter_id'] ? (string)$ticket['counter_id'] : '',
            'called_at'=>$ticket['called_at'],
            'completed_at'=>$ticket['completed_at']
        ],
        'notified'=>$notified,
        'push'=>$pushInfo
    ]);
    exit;

} catch (Throwable $e) {
    try { $mysqli->rollback(); } catch(Throwable $ignored) {}
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Server error','detail'=>$e->getMessage()]);
    exit;
}

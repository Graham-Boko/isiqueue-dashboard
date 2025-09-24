<?php
// C:\xampp\htdocs\isiqueue\api\cancel_ticket.php
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

    // Parse input (JSON or form)
    $raw    = file_get_contents('php://input');
    $ctype  = $_SERVER['CONTENT_TYPE'] ?? '';
    $isJson = stripos($ctype, 'application/json') !== false;
    $in     = $isJson ? (json_decode($raw, true) ?: []) : $_POST;

    $ticketNo = isset($in['ticket_no']) ? trim((string)$in['ticket_no']) : '';
    $ticketId = isset($in['ticket_id']) ? (int)$in['ticket_id'] : 0;
    $reason   = isset($in['reason'])    ? trim((string)$in['reason'])    : 'no_show';

    if ($ticketId <= 0 && $ticketNo === '') {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'ticket_no or ticket_id is required']); exit;
    }

    $mysqli->begin_transaction();

    // Lock the ticket row
    if ($ticketId > 0) {
        $st = $mysqli->prepare("
            SELECT id, ticket_no, customer_id, service_id, status, counter_id, hold_count, called_at
              FROM tickets
             WHERE id=? FOR UPDATE
        ");
        $st->bind_param('i', $ticketId);
    } else {
        $st = $mysqli->prepare("
            SELECT id, ticket_no, customer_id, service_id, status, counter_id, hold_count, called_at
              FROM tickets
             WHERE ticket_no=? ORDER BY id DESC LIMIT 1 FOR UPDATE
        ");
        $st->bind_param('s', $ticketNo);
    }
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$row) {
        $mysqli->rollback();
        http_response_code(404);
        echo json_encode(['ok'=>false,'error'=>'Ticket not found']); exit;
    }

    $id         = (int)$row['id'];
    $status     = strtolower(trim((string)$row['status']));
    $customerId = (int)$row['customer_id'];
    $serviceId  = (int)$row['service_id'];
    $holdCount  = isset($row['hold_count']) ? (int)$row['hold_count'] : 0;

    // Idempotency / guards
    if ($status === 'cancelled') {
        $mysqli->commit();
        echo json_encode([
            'ok'=>true,
            'idempotent'=>true,
            'action'=>'cancelled',
            'item'=>[
                'id'=>$id,
                'ticket_no'=>$row['ticket_no'],
                'status'=>'cancelled',
                'hold_count'=>$holdCount,
                'counter_id'=>null,
            ]
        ]);
        exit;
    }
    if ($status === 'served' || $status === 'completed') {
        $mysqli->rollback();
        http_response_code(409);
        echo json_encode(['ok'=>false,'error'=>'Ticket already served/completed']); exit;
    }

    // Business rule: cancel is allowed when the ticket is currently ON_CALL
    // (You can also allow cancellation from HOLD by adding it to the list below)
    if (!in_array($status, ['on_call'], true)) {
        $mysqli->rollback();
        http_response_code(409);
        echo json_encode(['ok'=>false,'error'=>'Ticket not cancellable in its current status','status'=>$status]); exit;
    }

    // Update to cancelled
    $u = $mysqli->prepare("
        UPDATE tickets
           SET status='cancelled',
               cancelled_at=NOW(),
               counter_id=NULL
         WHERE id=? AND TRIM(LOWER(status)) IN ('on_call')
    ");
    $u->bind_param('i', $id);
    $u->execute();
    if ($u->affected_rows !== 1) {
        $u->close();
        $mysqli->rollback();
        http_response_code(409);
        echo json_encode(['ok'=>false,'error'=>'Race: ticket already changed']); exit;
    }
    $u->close();

    // Re-fetch for response
    $q = $mysqli->prepare("
        SELECT id, ticket_no, customer_id, service_id, status, counter_id, hold_count, called_at, cancelled_at
          FROM tickets WHERE id=? LIMIT 1
    ");
    $q->bind_param('i', $id);
    $q->execute();
    $ticket = $q->get_result()->fetch_assoc();
    $q->close();

    $mysqli->commit();

    // -------- Optional push to all customer devices via customer_tokens --------
    $notified = false;
    $pushInfo = ['sent'=>0, 'errors'=>[]];

    try {
        // Collect tokens
        $tokens = [];
        $tokStmt = $mysqli->prepare("SELECT fcm_token FROM customer_tokens WHERE customer_id=?");
        if ($tokStmt) {
            $tokStmt->bind_param('i', $customerId);
            $tokStmt->execute();
            $resT = $tokStmt->get_result();
            while ($r = $resT->fetch_assoc()) {
                $t = trim((string)$r['fcm_token']);
                if ($t !== '') $tokens[] = $t;
            }
            $tokStmt->close();
        }

        // Resolve a human-friendly service label (optional)
        $svcLabel = ''; $svcCode = '';
        if ($serviceId > 0) {
            $colSql = "
                SELECT COLUMN_NAME
                  FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = 'services'
                   AND COLUMN_NAME IN ('name','service','code')
              ORDER BY FIELD(COLUMN_NAME,'name','service','code')
                 LIMIT 1
            ";
            $colRes = $mysqli->query($colSql);
            $rowCol = $colRes ? $colRes->fetch_assoc() : null;
            $serviceCol = $rowCol ? $rowCol['COLUMN_NAME'] : 'name';
            $serviceColEsc = '`'.$mysqli->real_escape_string($serviceCol).'`';

            $svcR = $mysqli->query("SELECT $serviceColEsc AS label, UPPER(code) AS code FROM services WHERE id=".$serviceId." LIMIT 1");
            if ($svcR && ($rr = $svcR->fetch_assoc())) { $svcLabel = (string)($rr['label'] ?? ''); $svcCode = (string)($rr['code'] ?? ''); }
        }

        if ($tokens) {
            $autoload = __DIR__ . '/../firebase/vendor/autoload.php';
            if (file_exists($autoload)) {
                require $autoload;
                $factory   = (new \Kreait\Firebase\Factory)->withServiceAccount(__DIR__ . '/../firebase/serviceAccountKey.json');
                $messaging = $factory->createMessaging();

                $title = ($svcLabel ? "{$svcLabel} " : '') . ($ticket['ticket_no'] ?? 'Ticket') . ' — Cancelled';
                $body  = 'Your ticket has been cancelled. Reason: '.($reason ?: 'unspecified');

                foreach ($tokens as $tk) {
                    $message = [
                        'token' => $tk,
                        'notification' => ['title'=>$title, 'body'=>$body],
                        'data' => [
                            'action'      => 'CANCELLED',
                            'ticket_id'   => (string)$ticket['id'],
                            'ticket_no'   => (string)$ticket['ticket_no'],
                            'service_id'  => (string)$ticket['service_id'],
                            'service'     => (string)$svcCode,
                            'counter'     => '', // cleared
                            'called_at'   => (string)($ticket['called_at'] ?? ''),
                            'cancelled_at'=> (string)($ticket['cancelled_at'] ?? ''),
                            'hold_count'  => (string)($ticket['hold_count'] ?? ''),
                            'reason'      => (string)$reason,
                            'timestamp'   => (string)time(),
                        ],
                        'android'=>['priority'=>'high','notification'=>['sound'=>'default','channel_id'=>'isiqueue_calls']],
                        'apns'   =>['headers'=>['apns-priority'=>'10'],'payload'=>['aps'=>['sound'=>'default']]],
                    ];

                    try {
                        $messaging->send($message);
                        $pushInfo['sent']++;
                        $notified = true;
                    } catch (\Throwable $e) {
                        $pushInfo['errors'][] = ['token'=>$tk, 'error'=>$e->getMessage()];
                        if (preg_match('/NotRegistered|InvalidRegistration/i', $e->getMessage())) {
                            $del = $mysqli->prepare("DELETE FROM customer_tokens WHERE fcm_token=?");
                            if ($del) { $del->bind_param('s', $tk); $del->execute(); $del->close(); }
                        }
                    }
                }
            } else {
                $pushInfo['errors'][] = ['token'=>null, 'error'=>'FCM SDK not installed'];
            }
        } else {
            $pushInfo['errors'][] = ['token'=>null, 'error'=>'No FCM tokens for this customer'];
        }
    } catch (\Throwable $e) {
        $pushInfo['errors'][] = ['token'=>null, 'error'=>$e->getMessage()];
    }
    // --------------------------------------------------------------------------

    echo json_encode([
        'ok'      => true,
        'action'  => 'cancelled',
        'item'    => [
            'id'           => (int)$ticket['id'],
            'ticket_no'    => $ticket['ticket_no'],
            'status'       => 'cancelled',
            'hold_count'   => (int)($ticket['hold_count'] ?? 0),
            'counter_id'   => null,
            'cancelled_at' => $ticket['cancelled_at'] ?? null
        ],
        'notified'=> $notified,
        'push'    => $pushInfo
    ]);
    exit;

} catch (Throwable $e) {
    try { $mysqli->rollback(); } catch(Throwable $ignored) {}
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Server error','detail'=>$e->getMessage()]);
    exit;
}

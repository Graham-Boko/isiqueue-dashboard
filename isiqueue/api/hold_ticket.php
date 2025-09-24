<?php
// C:\xampp\htdocs\isiqueue\api\hold_ticket.php
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

    // Parse input
    $raw    = file_get_contents('php://input');
    $ctype  = $_SERVER['CONTENT_TYPE'] ?? '';
    $isJson = stripos($ctype, 'application/json') !== false;
    $in     = $isJson ? (json_decode($raw, true) ?: []) : $_POST;

    $ticketNo = isset($in['ticket_no']) ? trim((string)$in['ticket_no']) : '';
    $ticketId = isset($in['ticket_id']) ? (int)$in['ticket_id'] : 0;

    if ($ticketNo === '' && $ticketId <= 0) {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'ticket_no or ticket_id is required']); exit;
    }

    $mysqli->begin_transaction();

    // Lock the ticket row (must be on_call)
    if ($ticketId > 0) {
        $st = $mysqli->prepare("
            SELECT id, ticket_no, status, hold_count, customer_id, service_id, counter_id, called_at
              FROM tickets
             WHERE id=? FOR UPDATE
        ");
        $st->bind_param('i', $ticketId);
    } else {
        $st = $mysqli->prepare("
            SELECT id, ticket_no, status, hold_count, customer_id, service_id, counter_id, called_at
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
    $hold_count = (int)($row['hold_count'] ?? 0);
    $customerId = (int)$row['customer_id'];
    $serviceId  = (int)$row['service_id'];

    // Guards
    if (in_array($status, ['served','completed','cancelled'], true)) {
        $mysqli->rollback();
        http_response_code(409);
        echo json_encode(['ok'=>false,'error'=>"Ticket already $status"]); exit;
    }
    if ($status !== 'on_call') {
        $mysqli->rollback();
        http_response_code(409);
        echo json_encode(['ok'=>false,'error'=>'Hold allowed only when ticket is On call','status'=>$status]); exit;
    }

    $newHold = $hold_count + 1;
    if ($newHold > 3) {
        $mysqli->rollback();
        http_response_code(409);
        echo json_encode([
            'ok'=>false,'error'=>'hold_limit_reached',
            'message'=>'This ticket has reached the maximum number of holds (3). Please cancel the ticket.',
            'hold_count'=>$hold_count, 'ticket'=>['id'=>$id, 'ticket_no'=>$row['ticket_no']]
        ]);
        exit;
    }

    // A -> HOLD (no position column; use timestamps)
    $updA = $mysqli->prepare("
        UPDATE tickets
           SET status='hold',
               counter_id=NULL,
               hold_count=?,
               last_hold_at=NOW(),
               last_recall_at=NULL
         WHERE id=? AND TRIM(LOWER(status))='on_call'
    ");
    $updA->bind_param('ii', $newHold, $id);
    $updA->execute();
    if ($updA->affected_rows !== 1) {
        $updA->close();
        $mysqli->rollback();
        http_response_code(409);
        echo json_encode(['ok'=>false,'error'=>'Race: ticket already changed']); exit;
    }
    $updA->close();

    // Re-fetch minimal
    $q = $mysqli->prepare("
        SELECT id, ticket_no, customer_id, service_id, status, counter_id, called_at, hold_count, last_hold_at
          FROM tickets WHERE id=? LIMIT 1
    ");
    $q->bind_param('i', $id);
    $q->execute();
    $ticket = $q->get_result()->fetch_assoc();
    $q->close();

    $mysqli->commit();

    // ---------- Push (same style as your codebase) ----------
    $notified = false;
    $pushInfo = ['sent'=>0, 'errors'=>[]];

    try {
        $tokens = [];
        $tokStmt = $mysqli->prepare("SELECT fcm_token FROM customer_tokens WHERE customer_id=?");
        if ($tokStmt) {
            $tokStmt->bind_param('i', $customerId);
            $tokStmt->execute();
            $res = $tokStmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $t = trim((string)$r['fcm_token']);
                if ($t !== '') $tokens[] = $t;
            }
            $tokStmt->close();
        }

        if ($tokens) {
            $autoload = __DIR__ . '/../firebase/vendor/autoload.php';
            if (file_exists($autoload)) {
                require $autoload;
                $factory   = (new \Kreait\Firebase\Factory)->withServiceAccount(__DIR__ . '/../firebase/serviceAccountKey.json');
                $messaging = $factory->createMessaging();

                // Optional service label/code for notification text
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
                    if ($colRes = $mysqli->query($colSql)) {
                        $rowCol = $colRes->fetch_assoc();
                        $serviceCol = $rowCol ? $rowCol['COLUMN_NAME'] : 'name';
                        $serviceColEsc = '`'.$mysqli->real_escape_string($serviceCol).'`';
                        $colRes->close();

                        $svcR = $mysqli->query("SELECT $serviceColEsc AS label, UPPER(code) AS code FROM services WHERE id=".$serviceId." LIMIT 1");
                        if ($svcR && ($rr = $svcR->fetch_assoc())) { $svcLabel = (string)($rr['label'] ?? ''); $svcCode = (string)($rr['code'] ?? ''); }
                    }
                }

                $title = ($svcLabel ? "{$svcLabel} " : '') . ($ticket['ticket_no'] ?? 'On Hold');
                $body  = "We tried to call you. Your ticket is on hold. Please stay nearby for recall.";

                $data = [
                    'action'       => 'HOLD',
                    'ticket_id'    => (string)$ticket['id'],
                    'ticket_no'    => (string)$ticket['ticket_no'],
                    'service_id'   => (string)$ticket['service_id'],
                    'service'      => (string)$svcCode,
                    'counter'      => '',
                    'called_at'    => (string)($ticket['called_at'] ?? ''),
                    'hold_count'   => (string)$ticket['hold_count'],
                    'last_hold_at' => (string)($ticket['last_hold_at'] ?? ''),
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

    echo json_encode([
        'ok'         => true,
        'action'     => 'hold',
        'hold_count' => (int)$ticket['hold_count'],
        'message'    => 'Ticket put on hold',
        'ticket'     => ['id'=>(int)$ticket['id'],'ticket_no'=>$ticket['ticket_no']],
        'notified'   => $notified,
        'push'       => $pushInfo
    ]);
    exit;

} catch (Throwable $e) {
    try { $mysqli->rollback(); } catch(Throwable $ignored) {}
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Server error','detail'=>$e->getMessage()]);
    exit;
}

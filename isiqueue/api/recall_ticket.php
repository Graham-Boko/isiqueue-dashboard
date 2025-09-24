<?php
// C:\xampp\htdocs\isiqueue\api\recall_ticket.php
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

    $ticketNo  = isset($in['ticket_no']) ? trim((string)$in['ticket_no']) : '';
    $ticketId  = isset($in['ticket_id']) ? (int)$in['ticket_id'] : 0;
    $counterId = isset($in['counter_id']) ? (int)$in['counter_id'] : 0;

    $mysqli->begin_transaction();

    // Pick a held ticket: explicit first, else oldest held
    if ($ticketId > 0) {
        $st = $mysqli->prepare("
            SELECT id, ticket_no, customer_id, service_id
              FROM tickets
             WHERE id=? AND TRIM(LOWER(status))='hold'
             FOR UPDATE
        ");
        $st->bind_param('i', $ticketId);
    } elseif ($ticketNo !== '') {
        $st = $mysqli->prepare("
            SELECT id, ticket_no, customer_id, service_id
              FROM tickets
             WHERE ticket_no=? AND TRIM(LOWER(status))='hold'
          ORDER BY id DESC
             LIMIT 1
             FOR UPDATE
        ");
        $st->bind_param('s', $ticketNo);
    } else {
        $st = $mysqli->prepare("
            SELECT id, ticket_no, customer_id, service_id
              FROM tickets
             WHERE TRIM(LOWER(status))='hold'
          ORDER BY last_hold_at ASC, id ASC
             LIMIT 1
             FOR UPDATE
        ");
    }
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$row) { $mysqli->rollback(); echo json_encode(['ok'=>true,'item'=>null,'message'=>'No held tickets to recall']); exit; }

    $id         = (int)$row['id'];
    $customerId = (int)$row['customer_id'];
    $serviceId  = (int)$row['service_id'];

    if ($counterId > 0) {
        $u = $mysqli->prepare("
            UPDATE tickets
               SET status='serving',
                   called_at=NOW(),
                   last_recall_at=NOW(),
                   counter_id=?
             WHERE id=? AND TRIM(LOWER(status))='hold'
        ");
        $u->bind_param('ii', $counterId, $id);
    } else {
        $u = $mysqli->prepare("
            UPDATE tickets
               SET status='serving',
                   called_at=NOW(),
                   last_recall_at=NOW()
             WHERE id=? AND TRIM(LOWER(status))='hold'
        ");
        $u->bind_param('i', $id);
    }
    $u->execute();
    if ($u->affected_rows !== 1) { $u->close(); $mysqli->rollback(); echo json_encode(['ok'=>false,'error'=>'Race: ticket already changed']); exit; }
    $u->close();

    // Fetch updated row for response + push
    $q = $mysqli->prepare("
        SELECT id, ticket_no, customer_id, service_id, status, counter_id, called_at
          FROM tickets WHERE id=? LIMIT 1
    ");
    $q->bind_param('i', $id);
    $q->execute();
    $ticket = $q->get_result()->fetch_assoc();
    $q->close();

    $mysqli->commit();

    // ---------- Targeted FCM push (use customer_tokens; multi-device; prune invalid) ----------
    $notified = false;
    $pushInfo = ['sent'=>0, 'errors'=>[]];

    try {
        // Pick a human label column from services (optional)
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
            if ($colRes) $colRes->close();

            $svcR = $mysqli->query("SELECT $serviceColEsc AS label, UPPER(code) AS code FROM services WHERE id=".$serviceId." LIMIT 1");
            if ($svcR && ($r = $svcR->fetch_assoc())) { $svcLabel = (string)($r['label'] ?? ''); $svcCode = (string)($r['code'] ?? ''); }
        }

        // Collect ALL tokens for this customer
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
            require __DIR__ . '/../firebase/vendor/autoload.php';
            $serviceAccountPath = __DIR__ . '/../firebase/serviceAccountKey.json';
            $factory   = (new \Kreait\Firebase\Factory)->withServiceAccount($serviceAccountPath);
            $messaging = $factory->createMessaging();

            $title = ($svcLabel ? "{$svcLabel} " : '') . ($ticket['ticket_no'] ?: 'Your Turn');
            $body  = $ticket['counter_id'] ? "Please proceed to Counter {$ticket['counter_id']}." : "Please proceed to the counter.";

            $data = [
                'action'      => 'RECALL',
                'ticket_id'   => (string)$ticket['id'],
                'ticket_no'   => (string)$ticket['ticket_no'],
                'service_id'  => (string)$ticket['service_id'],
                'service'     => (string)$svcCode,
                'counter'     => (string)($ticket['counter_id'] ?? ''),
                'called_at'   => (string)$ticket['called_at'],
                'timestamp'   => (string)time(),
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
            $pushInfo['errors'][] = ['token'=>null, 'error'=>'No FCM tokens for this customer'];
        }
    } catch (\Throwable $e) {
        $pushInfo['errors'][] = ['token'=>null, 'error'=>$e->getMessage()];
    }
    // ------------------------------------------------------------------------------------------

    echo json_encode([
        'ok'=>true,
        'notified'=>$notified,
        'push'=>$pushInfo,
        'item'=>[
            'id'         => (int)$ticket['id'],
            'ticket_no'  => $ticket['ticket_no'],
            'service_id' => (int)$ticket['service_id'],
            'status'     => $ticket['status'],
            'counter_id' => $ticket['counter_id'] ?: null,
            'called_at'  => $ticket['called_at'],
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    try { $mysqli->rollback(); } catch(Throwable $ignored) {}
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Server error','detail'=>$e->getMessage()]);
}

<?php
// C:\xampp\htdocs\isiqueue\api\set_serving.php
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

    // ---- input (JSON or form) ----
    $raw    = file_get_contents('php://input');
    $ctype  = $_SERVER['CONTENT_TYPE'] ?? '';
    $isJson = stripos($ctype, 'application/json') !== false;
    $in     = $isJson ? (json_decode($raw, true) ?: []) : $_POST;

    $ticketNo  = isset($in['ticket_no']) ? trim((string)$in['ticket_no']) : '';
    $ticketId  = isset($in['ticket_id']) ? (int)$in['ticket_id'] : 0;
    $counterId = isset($in['counter_id']) ? (int)$in['counter_id'] : 0;

    $mysqli->begin_transaction();

    // Detect if the column serving_started_at exists
    $hasServingStarted = false;
    $colSql = "
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'tickets'
           AND COLUMN_NAME  = 'serving_started_at'
         LIMIT 1
    ";
    if ($res = $mysqli->query($colSql)) {
        $hasServingStarted = (bool)$res->fetch_row();
        $res->close();
    }

    // ---- fetch the target ticket (FOR UPDATE) ----
    if ($ticketId > 0) {
        $st = $mysqli->prepare("
            SELECT id, ticket_no, customer_id, service_id, status, counter_id, called_at
              FROM tickets
             WHERE id=?
             LIMIT 1
             FOR UPDATE
        ");
        $st->bind_param('i', $ticketId);
    } elseif ($ticketNo !== '') {
        $st = $mysqli->prepare("
            SELECT id, ticket_no, customer_id, service_id, status, counter_id, called_at
              FROM tickets
             WHERE ticket_no=?
             ORDER BY id DESC
             LIMIT 1
             FOR UPDATE
        ");
        $st->bind_param('s', $ticketNo);
    } elseif ($counterId > 0) {
        // Pick the most recently called on_call ticket for this counter
        $st = $mysqli->prepare("
            SELECT id, ticket_no, customer_id, service_id, status, counter_id, called_at
              FROM tickets
             WHERE TRIM(LOWER(status))='on_call' AND counter_id=?
             ORDER BY called_at DESC, id DESC
             LIMIT 1
             FOR UPDATE
        ");
        $st->bind_param('i', $counterId);
    } else {
        $mysqli->rollback();
        echo json_encode(['ok'=>false, 'error'=>'Missing ticket identifier (ticket_no/ticket_id) or counter_id'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$row) {
        $mysqli->rollback();
        echo json_encode(['ok'=>false, 'error'=>'Ticket not found or no on_call ticket for this counter'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $status = strtolower(trim((string)($row['status'] ?? '')));
    if ($status === 'serving') {
        // Idempotent: already serving
        $mysqli->commit();

        // Include serving_started_at if it exists
        $selectCols = "id, ticket_no, customer_id, service_id, status, counter_id, called_at";
        if ($hasServingStarted) $selectCols .= ", serving_started_at";

        $q = $mysqli->prepare("SELECT $selectCols FROM tickets WHERE id=? LIMIT 1");
        $q->bind_param('i', $row['id']);
        $q->execute();
        $ticket = $q->get_result()->fetch_assoc();
        $q->close();

        echo json_encode(['ok'=>true, 'item'=>$ticket, 'idempotent'=>true], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($status !== 'on_call') {
        $mysqli->rollback();
        echo json_encode(['ok'=>false, 'error'=>'Ticket is not on_call'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- update to serving ----
    if ($hasServingStarted && $counterId > 0) {
        $u = $mysqli->prepare("
            UPDATE tickets
               SET status='serving',
                   serving_started_at=NOW(),
                   counter_id=?
             WHERE id=? AND TRIM(LOWER(status))='on_call'
        ");
        $u->bind_param('ii', $counterId, $row['id']);
    } elseif ($hasServingStarted) {
        $u = $mysqli->prepare("
            UPDATE tickets
               SET status='serving',
                   serving_started_at=NOW()
             WHERE id=? AND TRIM(LOWER(status))='on_call'
        ");
        $u->bind_param('i', $row['id']);
    } elseif ($counterId > 0) {
        $u = $mysqli->prepare("
            UPDATE tickets
               SET status='serving',
                   counter_id=?
             WHERE id=? AND TRIM(LOWER(status))='on_call'
        ");
        $u->bind_param('ii', $counterId, $row['id']);
    } else {
        $u = $mysqli->prepare("
            UPDATE tickets
               SET status='serving'
             WHERE id=? AND TRIM(LOWER(status))='on_call'
        ");
        $u->bind_param('i', $row['id']);
    }
    $u->execute();
    if ($u->affected_rows !== 1) {
        $u->close();
        $mysqli->rollback();
        echo json_encode(['ok'=>false,'error'=>'Race: ticket already changed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $u->close();

    // ---- fetch updated ticket ----
    $selectCols = "id, ticket_no, customer_id, service_id, status, counter_id, called_at";
    if ($hasServingStarted) $selectCols .= ", serving_started_at";

    $q = $mysqli->prepare("SELECT $selectCols FROM tickets WHERE id=? LIMIT 1");
    $q->bind_param('i', $row['id']);
    $q->execute();
    $ticket = $q->get_result()->fetch_assoc();
    $q->close();

    $mysqli->commit();

    // ---- FCM push: "SERVING" (updated to use customer_tokens) ----
    $notified = false;
    $pushInfo = ['sent'=>0, 'errors'=>[]];

    try {
        // Pick a human label column from services
        $colSql2 = "
            SELECT COLUMN_NAME
              FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'services'
               AND COLUMN_NAME IN ('name','service','code')
          ORDER BY FIELD(COLUMN_NAME,'name','service','code')
             LIMIT 1
        ";
        $colRes = $mysqli->query($colSql2);
        $rowCol = $colRes ? $colRes->fetch_assoc() : null;
        $serviceCol = $rowCol ? $rowCol['COLUMN_NAME'] : 'name';
        $serviceColEsc = '`'.$mysqli->real_escape_string($serviceCol).'`';

        $svcLabel = ''; $svcCode = '';
        if ((int)$ticket['service_id'] > 0) {
            $svcR = $mysqli->query("SELECT $serviceColEsc AS label, UPPER(code) AS code FROM services WHERE id=".(int)$ticket['service_id']." LIMIT 1");
            if ($svcR && ($r = $svcR->fetch_assoc())) { $svcLabel = (string)($r['label'] ?? ''); $svcCode = (string)($r['code'] ?? ''); }
        }

        // Collect ALL tokens for this customer (multi-device support)
        $tokens = [];
        $tokStmt = $mysqli->prepare("SELECT fcm_token FROM customer_tokens WHERE customer_id=?");
        if ($tokStmt) {
            $cid = (int)$ticket['customer_id'];
            $tokStmt->bind_param('i', $cid);
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

            $title = ($svcLabel ? "{$svcLabel} " : '') . ($ticket['ticket_no'] ?: 'Now Serving');
            $counterTxt = $ticket['counter_id'] ? "You are now being served at Counter {$ticket['counter_id']}." : "You are now being served.";

            $baseData = [
                'action'      => 'SERVING',
                'ticket_id'   => (string)$ticket['id'],
                'ticket_no'   => (string)$ticket['ticket_no'],
                'service_id'  => (string)$ticket['service_id'],
                'service'     => (string)$svcCode,
                'counter'     => (string)($ticket['counter_id'] ?? ''),
                'called_at'   => (string)($ticket['called_at'] ?? ''),
                'timestamp'   => (string)time(),
            ];
            if ($hasServingStarted) {
                $baseData['serving_started_at'] = (string)($ticket['serving_started_at'] ?? '');
            }

            // Send to each token individually; prune invalid ones
            foreach ($tokens as $tk) {
                $message = [
                    'token' => $tk,
                    'notification' => ['title' => $title, 'body' => $counterTxt],
                    'data' => $baseData,
                    'android' => ['priority' => 'high','notification' => ['sound' => 'default','channel_id' => 'isiqueue_calls']],
                    'apns'    => ['headers' => ['apns-priority' => '10'], 'payload' => ['aps' => ['sound' => 'default']]],
                ];

                try {
                    $res = $messaging->send($message);
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

    echo json_encode([
        'ok'       => true,
        'notified' => $notified,
        'push'     => $pushInfo,
        'item'     => $ticket
    ], JSON_UNESCAPED_UNICODE);
    exit;

} catch (Throwable $e) {
    try { $mysqli->rollback(); } catch(Throwable $ignored) {}
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Server error','detail'=>$e->getMessage()]);
    exit;
}

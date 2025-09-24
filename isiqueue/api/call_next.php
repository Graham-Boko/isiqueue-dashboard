<?php
// C:\xampp\htdocs\isiqueue\api\call_next.php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

ini_set('display_errors','0');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
while (ob_get_level() > 0) { ob_end_clean(); }

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    require_once __DIR__ . '/db.php';
    if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
        throw new Exception('DB not initialized ($mysqli is null)');
    }

    // body
    $raw    = file_get_contents('php://input');
    $ctype  = $_SERVER['CONTENT_TYPE'] ?? '';
    $isJson = stripos($ctype ?? '', 'application/json') !== false;
    $in     = $isJson ? (json_decode($raw, true) ?: []) : $_POST;

    $counterId   = isset($in['counter_id']) ? (int)$in['counter_id'] : null;
    $ticketNoIn  = isset($in['ticket_no'])  ? trim((string)$in['ticket_no']) : '';
    $ticketIdIn  = isset($in['ticket_id'])  ? (int)$in['ticket_id'] : 0;

    $id = null;           // the ID we finally call
    $chosenFrom = null;   // 'waiting' or 'hold' or null

    $mysqli->begin_transaction();

    // Helper updates inside the transaction
    $promote_waiting_tx = function(int $id) use ($mysqli, $counterId) {
        if ($counterId !== null && $counterId > 0) {
            $upd = $mysqli->prepare("UPDATE tickets SET status='on_call', counter_id=?, called_at=NOW() WHERE id=? AND TRIM(LOWER(status))='waiting'");
            $upd->bind_param('ii', $counterId, $id);
        } else {
            $upd = $mysqli->prepare("UPDATE tickets SET status='on_call', called_at=NOW() WHERE id=? AND TRIM(LOWER(status))='waiting'");
            $upd->bind_param('i', $id);
        }
        $upd->execute();
        if ($upd->affected_rows !== 1) { $upd->close(); throw new Exception('Race: waiting ticket already changed'); }
        $upd->close();
    };
    $recall_hold_to_waiting_tx = function(int $id) use ($mysqli) {
        // Reinsert held ticket to waiting (so it will be next)
        $u = $mysqli->prepare("
            UPDATE tickets
               SET status='waiting',
                   last_recall_at=NOW(),
                   last_hold_at=NULL
             WHERE id=? AND TRIM(LOWER(status))='hold'
        ");
        $u->bind_param('i', $id);
        $u->execute();
        if ($u->affected_rows !== 1) { $u->close(); throw new Exception('Race: held ticket already changed'); }
        $u->close();
    };
    $call_hold_direct_tx = function(int $id) use ($mysqli, $counterId) {
        // No waiting available: call the held ticket directly
        if ($counterId !== null && $counterId > 0) {
            $u = $mysqli->prepare("
                UPDATE tickets
                   SET status='on_call',
                       counter_id=?,
                       called_at=NOW(),
                       last_recall_at=NOW(),
                       last_hold_at=NULL
                 WHERE id=? AND TRIM(LOWER(status))='hold'
            ");
            $u->bind_param('ii', $counterId, $id);
        } else {
            $u = $mysqli->prepare("
                UPDATE tickets
                   SET status='on_call',
                       called_at=NOW(),
                       last_recall_at=NOW(),
                       last_hold_at=NULL
                 WHERE id=? AND TRIM(LOWER(status))='hold'
            ");
            $u->bind_param('i', $id);
        }
        $u->execute();
        if ($u->affected_rows !== 1) { $u->close(); throw new Exception('Race: held ticket already changed'); }
        $u->close();
    };

    // If a specific waiting ticket was requested, call it (and still prep oldest hold to be next)
    $explicitWaitingId = null;
    if ($ticketIdIn > 0) {
        $st = $mysqli->prepare("SELECT id FROM tickets WHERE id=? AND TRIM(LOWER(status))='waiting' LIMIT 1 FOR UPDATE");
        $st->bind_param('i', $ticketIdIn); $st->execute();
        if ($r = $st->get_result()->fetch_assoc()) $explicitWaitingId = (int)$r['id'];
        $st->close();
    } elseif ($ticketNoIn !== '') {
        $st = $mysqli->prepare("SELECT id FROM tickets WHERE ticket_no=? AND TRIM(LOWER(status))='waiting' LIMIT 1 FOR UPDATE");
        $st->bind_param('s', $ticketNoIn); $st->execute();
        if ($r = $st->get_result()->fetch_assoc()) $explicitWaitingId = (int)$r['id'];
        $st->close();
    }

    if ($explicitWaitingId) {
        // Call that waiting ticket now
        $promote_waiting_tx($explicitWaitingId);
        $id = $explicitWaitingId; $chosenFrom = 'waiting';

        // Promote oldest HOLD to waiting (to be next)
        $qH = $mysqli->query("
            SELECT id
              FROM tickets
             WHERE TRIM(LOWER(status))='hold'
          ORDER BY last_hold_at ASC, id ASC
             LIMIT 1
             FOR UPDATE
        ");
        if ($qH && ($h = $qH->fetch_assoc())) {
            $recall_hold_to_waiting_tx((int)$h['id']);
        }

        $mysqli->commit();
    } else {
        // Normal path: choose next waiting using prioritization
        $qB = $mysqli->query("
            SELECT id
              FROM tickets
             WHERE TRIM(LOWER(status))='waiting'
          ORDER BY (last_recall_at IS NULL) ASC, last_recall_at ASC, created_at ASC, id ASC
             LIMIT 1
             FOR UPDATE
        ");
        $B = $qB ? $qB->fetch_assoc() : null;

        if ($B) {
            // Call B
            $promote_waiting_tx((int)$B['id']);
            $id = (int)$B['id']; $chosenFrom = 'waiting';

            // Also bring the oldest HOLD back into waiting so it becomes next
            $qH = $mysqli->query("
                SELECT id
                  FROM tickets
                 WHERE TRIM(LOWER(status))='hold'
              ORDER BY last_hold_at ASC, id ASC
                 LIMIT 1
                 FOR UPDATE
            ");
            if ($qH && ($h = $qH->fetch_assoc())) {
                $recall_hold_to_waiting_tx((int)$h['id']);
            }

            $mysqli->commit();
        } else {
            // No waiting — call oldest hold directly, if any
            $qH2 = $mysqli->query("
                SELECT id
                  FROM tickets
                 WHERE TRIM(LOWER(status))='hold'
              ORDER BY last_hold_at ASC, id ASC
                 LIMIT 1
                 FOR UPDATE
            ");
            $H = $qH2 ? $qH2->fetch_assoc() : null;

            if ($H) {
                $call_hold_direct_tx((int)$H['id']);
                $id = (int)$H['id']; $chosenFrom = 'hold';
                $mysqli->commit();
            } else {
                $mysqli->commit();
                echo json_encode(['ok'=>true,'status'=>null,'ticket'=>null,'item'=>null,'message'=>'No tickets to call']); exit;
            }
        }
    }

    // ---- fetch the called ticket for response/push ----
    $fetch = $mysqli->prepare("
        SELECT id, ticket_no, customer_id, service_id, status, counter_id, called_at, hold_count
          FROM tickets WHERE id=? LIMIT 1
    ");
    $fetch->bind_param('i', $id);
    $fetch->execute();
    $ticket = $fetch->get_result()->fetch_assoc();
    $fetch->close();

    // service code
    $svcCode = 'Q';
    if ((int)$ticket['service_id'] > 0) {
        $svcStmt = $mysqli->prepare("SELECT UPPER(code) AS code FROM services WHERE id=? LIMIT 1");
        $svcStmt->bind_param('i', $ticket['service_id']);
        $svcStmt->execute();
        if ($svcRes = $svcStmt->get_result()->fetch_assoc()) { if (!empty($svcRes['code'])) $svcCode = $svcRes['code']; }
        $svcStmt->close();
    }

    // Compact display form
    $ticketShort = $ticket['ticket_no'];
    if (preg_match('/^([A-Za-z]+)-\d{8}-(\d+)$/', $ticketShort, $m)) {
        $ticketShort = strtoupper($m[1]) . (int)$m[2];
    }

    // Push to all devices (customer_tokens)
    $notified = false;
    $pushInfo = ['sent'=>0, 'errors'=>[]];

    try {
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
            $factory   = (new \Kreait\Firebase\Factory)->withServiceAccount(__DIR__ . '/../firebase/serviceAccountKey.json');
            $messaging = $factory->createMessaging();

            // Force title to only show "Ticket {number}"
            $title = "Ticket " . ($ticket['ticket_no'] ?: 'On call');
            $counterTxt = $ticket['counter_id']
                ? "Please proceed to Counter {$ticket['counter_id']}."
                : "Please proceed to the counter.";

            foreach ($tokens as $tk) {
                $message = [
                    'token'=>$tk,
                    'notification'=>['title'=>$title,'body'=>$counterTxt],
                    'data'=>[
                        'action'=>'ON_CALL',
                        'ticket_id'=>(string)$ticket['id'],
                        'ticket_no'=>(string)$ticket['ticket_no'],
                        'ticket_short'=>(string)$ticketShort,
                        'service_id'=>(string)$ticket['service_id'],
                        'service'=>(string)$svcCode,
                        'counter'=>(string)($ticket['counter_id'] ?? ''),
                        'called_at'=>(string)$ticket['called_at'],
                        'timestamp'=>(string)time(),
                    ],
                    'android'=>['priority'=>'high','notification'=>['sound'=>'default','channel_id'=>'isiqueue_calls']],
                    'apns'=>['headers'=>['apns-priority'=>'10'],'payload'=>['aps'=>['sound'=>'default']]],
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

    $payloadTicket = [
        'id'           => (int)$ticket['id'],
        'ticket_no'    => $ticket['ticket_no'],
        'ticket_short' => $ticketShort,
        'customer_id'  => (int)$ticket['customer_id'],
        'service_id'   => (int)$ticket['service_id'],
        'service'      => $svcCode,
        'status'       => 'on_call',
        'counter'      => $ticket['counter_id'] ? (string)$ticket['counter_id'] : '',
        'called_at'    => $ticket['called_at'],
        'hold_count'   => isset($ticket['hold_count']) ? (int)$ticket['hold_count'] : 0,
    ];

    echo json_encode([
        'ok'             => true,
        'status'         => 'on_call',
        'ticket'         => $payloadTicket,
        'item'           => $payloadTicket,
        'chosen_from'    => $chosenFrom,
        'notified'       => $notified,
        'push'           => $pushInfo
    ]);
    exit;

} catch (Throwable $e) {
    try { $mysqli->rollback(); } catch(Throwable $ignored) {}
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Server error','detail'=>$e->getMessage()]);
    exit;
}

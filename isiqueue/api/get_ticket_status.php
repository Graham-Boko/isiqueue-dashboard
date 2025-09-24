<?php
// C:\xampp\htdocs\isiqueue\api\get_ticket_status.php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    require __DIR__ . '/db.php';
    if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
        throw new Exception('DB not initialized ($mysqli is null)');
    }

    // Accept GET/POST/JSON
    $raw    = file_get_contents('php://input');
    $ctype  = $_SERVER['CONTENT_TYPE'] ?? '';
    $isJson = stripos($ctype, 'application/json') !== false;
    $body   = $isJson ? (json_decode($raw, true) ?: []) : [];
    $in     = array_merge($_GET ?? [], $_POST ?? [], $body);

    $customerId = isset($in['customer_id']) ? (int)$in['customer_id'] : 0;
    $ticketNo   = isset($in['ticket_no'])   ? trim((string)$in['ticket_no']) : '';

    if ($customerId <= 0 && $ticketNo === '') {
        http_response_code(400);
        echo json_encode(['ok'=>false, 'error'=>'Provide customer_id or ticket_no']); exit;
    }

    $normalize = function(string $s): string {
        $v = strtolower(trim($s));
        if ($v === 'now_serving') return 'serving';
        if ($v === 'completed')   return 'served';
        if (in_array($v, ['waiting','on_call','hold','serving','served','cancelled'], true)) return $v;
        return $v ?: 'waiting';
    };

    // ---- lookup ticket
    $row = null;
    if ($ticketNo !== '') {
        $st = $mysqli->prepare("
            SELECT id, ticket_no, customer_id, service_id, status, counter_id, created_at, called_at, completed_at,
                   IFNULL(notified_pos5,0) AS notified_pos5, IFNULL(notified_pos3,0) AS notified_pos3
            FROM tickets
            WHERE ticket_no=?
            ORDER BY id DESC
            LIMIT 1
        ");
        $st->bind_param('s', $ticketNo);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
    }

    if (!$row && $customerId > 0) {
        $st = $mysqli->prepare("
            SELECT id, ticket_no, customer_id, service_id, status, counter_id, created_at, called_at, completed_at,
                   IFNULL(notified_pos5,0) AS notified_pos5, IFNULL(notified_pos3,0) AS notified_pos3
            FROM tickets
            WHERE customer_id = ?
              AND (completed_at IS NULL OR completed_at >= CURDATE())
              AND TRIM(LOWER(status)) IN ('waiting','on_call','hold','serving','now_serving')
            ORDER BY id DESC
            LIMIT 1
        ");
        $st->bind_param('i', $customerId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
    }

    if (!$row && $customerId > 0) {
        $st = $mysqli->prepare("
            SELECT id, ticket_no, customer_id, service_id, status, counter_id, created_at, called_at, completed_at,
                   IFNULL(notified_pos5,0) AS notified_pos5, IFNULL(notified_pos3,0) AS notified_pos3
            FROM tickets
            WHERE customer_id = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $st->bind_param('i', $customerId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
    }

    if (!$row) { echo json_encode(['ok'=>true,'status'=>null,'ticket'=>null]); exit; }

    // derive status
    $rawStatus = (string)$row['status'];
    if ($rawStatus === '' || $rawStatus === null) {
        if (!empty($row['completed_at']))  $rawStatus = 'served';
        elseif (!empty($row['called_at'])) $rawStatus = 'on_call';
        else                                $rawStatus = 'waiting';
    }
    $status = $normalize($rawStatus);

    // people ahead & ordinal (only when waiting)
    $people_ahead = 0;
    $position_ordinal = 0;
    if ($status === 'waiting') {
        $rs = $mysqli->prepare("
            SELECT COUNT(*) AS ahead
            FROM tickets
            WHERE TRIM(LOWER(status))='waiting'
              AND ( created_at < ? OR (created_at = ? AND id < ?) )
        ");
        $rs->bind_param('ssi', $row['created_at'], $row['created_at'], $row['id']);
        $rs->execute();
        $aheadRow = $rs->get_result()->fetch_assoc();
        $rs->close();

        $people_ahead     = max(0, (int)($aheadRow['ahead'] ?? 0));
        $position_ordinal = $people_ahead + 1;
    }

    // ✅ ETA (5 minutes per person in line, hold = fixed 5)
    $eta_minutes = 0;
    if ($status === 'waiting')       { $eta_minutes = $position_ordinal * 5; }
    elseif ($status === 'hold')      { $eta_minutes = 5; }

    $position_display = ($status === 'waiting') ? (string)$position_ordinal : '-';

    // ---- service code & name (unchanged)
    $svcCode = null;
    $svcName = null;

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
    $serviceColEsc = '`' . $mysqli->real_escape_string($serviceCol) . '`';

    if ((int)$row['service_id'] > 0) {
        $svcStmt = $mysqli->prepare("SELECT UPPER(code) AS code, $serviceColEsc AS label FROM services WHERE id=? LIMIT 1");
        $svcStmt->bind_param('i', $row['service_id']);
        $svcStmt->execute();
        $svcR = $svcStmt->get_result()->fetch_assoc();
        $svcStmt->close();
        if ($svcR) {
            $svcCode = !empty($svcR['code']) ? $svcR['code'] : null;
            $svcName = isset($svcR['label']) ? (string)$svcR['label'] : null;
        }
    }

    if ($svcCode === null || $svcCode === '') {
        if (preg_match('/^([A-Za-z]+)/', (string)$row['ticket_no'], $m)) {
            $svcCode = strtoupper($m[1][0]); // first letter
        } else {
            $svcCode = 'Q';
        }
    }

    // ---------- NEW: position 5 / 3 notifications (send once) ----------
    if ($status === 'waiting' && $position_ordinal > 0) {
        $needPos5 = ($position_ordinal === 5 && (int)$row['notified_pos5'] === 0);
        $needPos3 = ($position_ordinal === 3 && (int)$row['notified_pos3'] === 0);

        if ($needPos5 || $needPos3) {
            try {
                // gather tokens
                $tokens = [];
                $tokStmt = $mysqli->prepare("SELECT fcm_token FROM customer_tokens WHERE customer_id=?");
                if ($tokStmt) {
                    $cid = (int)$row['customer_id'];
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
                    $autoload = __DIR__ . '/../firebase/vendor/autoload.php';
                    if (file_exists($autoload)) {
                        require $autoload;
                        $factory   = (new \Kreait\Firebase\Factory)->withServiceAccount(__DIR__ . '/../firebase/serviceAccountKey.json');
                        $messaging = $factory->createMessaging();

                        $posTxt = $position_ordinal; // 5 or 3
                        $title  = "Heads up";
                        $body   = ($posTxt === 5)
                            ? "You're now at position 5 for Ticket {$row['ticket_no']}. Please prepare to be served soon."
                            : "You're now at position 3 for Ticket {$row['ticket_no']}. Please be ready to proceed when called.";

                        $data   = [
                            'action'        => 'POSITION_ALERT',
                            'ticket_id'     => (string)$row['id'],
                            'ticket_no'     => (string)$row['ticket_no'],
                            'position'      => (string)$posTxt,
                            'eta_minutes'   => (string)$eta_minutes,
                            'timestamp'     => (string)time(),
                        ];

                        foreach ($tokens as $tk) {
                            $message = [
                                'token' => $tk,
                                'notification' => ['title' => $title, 'body' => $body],
                                'data' => $data,
                                'android' => ['priority' => 'high','notification' => ['sound' => 'default','channel_id' => 'isiqueue_calls']],
                                'apns'    => ['headers' => ['apns-priority' => '10'], 'payload' => ['aps' => ['sound' => 'default']]],
                            ];
                            try { $messaging->send($message); } catch (\Throwable $e) { /* swallow per-token error */ }
                        }

                        // set one-time flags
                        if ($needPos5) {
                            $u5 = $mysqli->prepare("UPDATE tickets SET notified_pos5=1 WHERE id=? AND notified_pos5=0");
                            $u5->bind_param('i', $row['id']); $u5->execute(); $u5->close();
                            $row['notified_pos5'] = 1;
                        }
                        if ($needPos3) {
                            $u3 = $mysqli->prepare("UPDATE tickets SET notified_pos3=1 WHERE id=? AND notified_pos3=0");
                            $u3->bind_param('i', $row['id']); $u3->execute(); $u3->close();
                            $row['notified_pos3'] = 1;
                        }
                    }
                }
            } catch (\Throwable $e) {
                // don't break API; just log if needed
                error_log("position alert failed: ".$e->getMessage());
            }
        }
    }
    // --------------------------------------------------------------------

    $server_now_ms = (int) round(microtime(true) * 1000);

    echo json_encode([
        'ok'               => true,
        'status'           => $status,
        'people_ahead'     => $people_ahead,
        'position_ordinal' => $position_ordinal,
        'eta_minutes'      => $eta_minutes,
        'position'         => $position_ordinal,
        'position_display' => $position_display,
        'is_waiting'       => ($status === 'waiting'),
        'is_serving'       => ($status === 'serving'),
        'is_served'        => ($status === 'served'),
        'server_now'       => $server_now_ms,
        'ticket' => [
            'id'               => (int)$row['id'],
            'ticket_no'        => $row['ticket_no'],
            'ticket_short'     => $row['ticket_no'],
            'customer_id'      => (int)$row['customer_id'],
            'service_id'       => (int)$row['service_id'],
            'service'          => $svcCode,
            'service_name'     => $svcName ?: '',
            'status'           => $status,
            'people_ahead'     => $people_ahead,
            'position_ordinal' => $position_ordinal,
            'eta_minutes'      => $eta_minutes,
            'position'         => $position_ordinal,
            'position_display' => $position_display,
            'counter'          => $row['counter_id'] ? (string)$row['counter_id'] : '',
            'created_at'       => $row['created_at'],
            'called_at'        => $row['called_at'],
            'completed_at'     => $row['completed_at']
        ]
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Server error','detail'=>$e->getMessage()]);
}

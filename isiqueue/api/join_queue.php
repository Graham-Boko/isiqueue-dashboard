<?php
// C:\xampp\htdocs\isiqueue\api\join_queue.php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

mysqli_report(MYSQLI_REPORT_OFF);

require_once __DIR__ . '/db.php';
if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'DB not initialized']);
    exit;
}

$raw   = file_get_contents('php://input');
$ctype = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
$in    = (strpos($ctype, 'application/json') !== false)
          ? (json_decode($raw, true) ?: [])
          : (is_array($_POST) ? $_POST : []);

$customerId = (int)($in['customer_id'] ?? $in['customerId'] ?? 0);
$serviceId  = (int)($in['service_id']  ?? $in['serviceId']  ?? 0);

if ($customerId <= 0 || $serviceId <= 0) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Missing or invalid customer_id/service_id']);
    exit;
}

/* -------- Validate service & get prefix -------- */
$svcCode = 'Q';
if ($stmt = $mysqli->prepare("SELECT code FROM services WHERE id=? AND is_active=1 LIMIT 1")) {
    $stmt->bind_param('i', $serviceId);
    if ($stmt->execute()) {
        $stmt->bind_result($code);
        if ($stmt->fetch()) {
            $code = (string)$code;
            if (preg_match('/[A-Za-z0-9]+/', $code, $m)) {
                $svcCode = strtoupper($m[0]);
            }
        } else {
            http_response_code(400);
            echo json_encode(['ok'=>false,'error'=>"Service id {$serviceId} not found or inactive"]);
            $stmt->close();
            exit;
        }
    }
    $stmt->close();
}

/* -------- Allocate unique short ticket -------- */
$todayYmd = date('Y-m-d');
$maxForPrefix = 0;
if ($stmt = $mysqli->prepare("
    SELECT COALESCE(MAX(CAST(SUBSTRING(ticket_no, CHAR_LENGTH(?) + 1) AS UNSIGNED)), 0)
    FROM tickets
    WHERE ticket_no REGEXP CONCAT('^', ?, '[0-9]+$')
      AND DATE(created_at) = ?
")) {
    $stmt->bind_param('sss', $svcCode, $svcCode, $todayYmd);
    if ($stmt->execute()) { $stmt->bind_result($mx); if ($stmt->fetch()) $maxForPrefix = (int)$mx; }
    $stmt->close();
}
$nextN = $maxForPrefix + 1;

$ticketNo = '';
$inserted = false;
$newId    = 0;

for ($attempt = 0; $attempt < 6 && !$inserted; $attempt++) {
    $ticketNo = $svcCode . $nextN;

    if ($stmt = $mysqli->prepare("
        INSERT INTO tickets (ticket_no, customer_id, service_id, status, priority, counter_id, created_at)
        VALUES (?, ?, ?, 'waiting', 0, NULL, NOW())
    ")) {
        $stmt->bind_param('sii', $ticketNo, $customerId, $serviceId);
        if ($stmt->execute()) {
            $newId    = $stmt->insert_id;
            $inserted = true;
            $stmt->close();
            break;
        } else {
            $errno = $stmt->errno;
            $stmt->close();

            if ($errno == 1062) { $nextN++; continue; }
            if ($errno == 1452) {
                http_response_code(400);
                echo json_encode(['ok'=>false,'error'=>'Foreign key failed (invalid customer_id or service_id)']);
                exit;
            }
            http_response_code(400);
            echo json_encode(['ok'=>false,'error'=>'Insert failed']);
            exit;
        }
    }
}

if (!$inserted) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Could not allocate a unique ticket number']);
    exit;
}

/* -------- Fetch the inserted ticket -------- */
$ticket = null;
if ($stmt = $mysqli->prepare("
    SELECT id, ticket_no, service_id, status, counter_id, created_at
    FROM tickets WHERE id=? LIMIT 1
")) {
    $stmt->bind_param('i', $newId);
    if ($stmt->execute()) {
        $stmt->bind_result($id, $tno, $sid, $status, $counterId, $createdAt);
        if ($stmt->fetch()) {
            $ticket = [
                'id'         => (int)$id,
                'ticket_no'  => $tno,
                'service_id' => (int)$sid,
                'status'     => $status,
                'counter_id' => $counterId,
                'created_at' => $createdAt
            ];
        }
    }
    $stmt->close();
}
if (!$ticket) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Inserted ticket not found']);
    exit;
}

/* -------- Compute queue ahead/position + ETA -------- */
$ahead = 0;
if ($stmt = $mysqli->prepare("
    SELECT COUNT(*) AS ahead
    FROM tickets
    WHERE TRIM(LOWER(status))='waiting'
      AND ( created_at < ? OR (created_at = ? AND id < ?) )
")) {
    $stmt->bind_param('ssi', $ticket['created_at'], $ticket['created_at'], $ticket['id']);
    if ($stmt->execute()) { $stmt->bind_result($aheadCnt); $stmt->fetch(); $ahead = (int)$aheadCnt; }
    $stmt->close();
}
$peopleAhead     = ($ticket['status'] === 'waiting') ? max(0, $ahead) : 0;
$positionOrdinal = ($ticket['status'] === 'waiting') ? ($peopleAhead + 1) : 0;

// ✅ ETA calculation = (peopleAhead + 1) × avg service time (5 minutes each)
$avgServiceTime  = 5; // minutes per ticket
$etaMinutes      = ($ticket['status'] === 'waiting')
    ? (($peopleAhead + 1) * $avgServiceTime)
    : 0;

/* -------- Push notification: Ticket Generated -------- */
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
        require __DIR__ . '/../firebase/vendor/autoload.php';
        $factory   = (new \Kreait\Firebase\Factory)->withServiceAccount(__DIR__ . '/../firebase/serviceAccountKey.json');
        $messaging = $factory->createMessaging();

        $title = "Ticket Generated";
        $body  = "Your ticket is {$ticket['ticket_no']}";

        foreach ($tokens as $tk) {
            $message = [
                'token'=>$tk,
                'notification'=>['title'=>$title,'body'=>$body],
                'data'=>[
                    'action'=>'NEW_TICKET',
                    'ticket_id'=>(string)$ticket['id'],
                    'ticket_no'=>(string)$ticket['ticket_no'],
                    'ticket_short'=>(string)$ticket['ticket_no'],
                    'service_id'=>(string)$ticket['service_id'],
                    'status'=>$ticket['status'],
                    'eta_minutes'=>(string)$etaMinutes,
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
            }
        }
    }
} catch (\Throwable $e) {
    $pushInfo['errors'][] = ['error'=>$e->getMessage()];
}

/* -------- Response -------- */
echo json_encode([
    'ok'               => true,
    'status'           => $ticket['status'],
    'people_ahead'     => $peopleAhead,
    'position_ordinal' => $positionOrdinal,
    'eta_minutes'      => $etaMinutes,
    'notified'         => $notified,
    'push'             => $pushInfo,
    'ticket' => [
        'id'               => $ticket['id'],
        'ticket_no'        => $ticket['ticket_no'],
        'ticket_short'     => $ticket['ticket_no'],
        'service_id'       => $ticket['service_id'],
        'status'           => $ticket['status'],
        'people_ahead'     => $peopleAhead,
        'position_ordinal' => $positionOrdinal,
        'eta_minutes'      => $etaMinutes,
        'position'         => ($ticket['status'] === 'waiting') ? (string)$positionOrdinal : '-',
        'counter'          => $ticket['counter_id'] ? (string)$ticket['counter_id'] : '',
        'created_at'       => $ticket['created_at']
    ],
    'peopleAhead'       => $peopleAhead,
    'position'          => (string)$positionOrdinal
]);

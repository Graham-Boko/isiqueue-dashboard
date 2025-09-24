<?php
// C:\xampp\htdocs\isiqueue\api\waiting_list.php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

ini_set('display_errors','0');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
// Ensure nothing else is echoed before JSON
while (ob_get_level() > 0) { ob_end_clean(); }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    require __DIR__ . '/db.php'; // defines $mysqli
    if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
        throw new Exception('DB connection not available');
    }

    // Detect a display column for services - defaults to `name`
    $serviceCol = 'name';
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
        if ($rowCol = $colRes->fetch_assoc()) {
            $serviceCol = $rowCol['COLUMN_NAME'] ?: 'name';
        }
        $colRes->close();
    }
    $serviceColEsc = '`' . $mysqli->real_escape_string($serviceCol) . '`';

    // Get all WAITING (ordered oldest first)
    $sqlWaiting = "
        SELECT
            t.id, t.ticket_no, t.service_id, t.status, t.created_at,
            CONCAT(TRIM(c.first_name), ' ', TRIM(c.last_name)) AS customer_name,
            s.$serviceColEsc AS service_name,
            UPPER(s.code) AS service_code
        FROM tickets AS t
        JOIN customers AS c ON c.id = t.customer_id
   LEFT JOIN services  AS s ON s.id = t.service_id
       WHERE TRIM(LOWER(t.status))='waiting'
    ORDER BY t.created_at ASC, t.id ASC
    ";
    $waitingRes = $mysqli->query($sqlWaiting);
    $waiting = [];
    while ($r = $waitingRes->fetch_assoc()) { $waiting[] = $r; }
    $waitingRes->close();

    // Get all HOLD (order by last_hold_at, then id). NULLs sort first in ASC; that’s acceptable.
    $sqlHold = "
        SELECT
            t.id, t.ticket_no, t.service_id, t.status, t.created_at, t.last_hold_at,
            CONCAT(TRIM(c.first_name), ' ', TRIM(c.last_name)) AS customer_name,
            s.$serviceColEsc AS service_name,
            UPPER(s.code) AS service_code
        FROM tickets AS t
        JOIN customers AS c ON c.id = t.customer_id
   LEFT JOIN services  AS s ON s.id = t.service_id
       WHERE TRIM(LOWER(t.status))='hold'
    ORDER BY t.last_hold_at ASC, t.id ASC
    ";
    $holdRes = $mysqli->query($sqlHold);
    $holds = [];
    while ($r = $holdRes->fetch_assoc()) { $holds[] = $r; }
    $holdRes->close();

    // Compose list: first waiting (if any), then holds, then the rest waiting
    $ordered = [];
    if (count($waiting) > 0) {
        $ordered[] = $waiting[0];               // position 1
        foreach ($holds as $h) $ordered[] = $h; // positions 2..(h+1)
        for ($i = 1; $i < count($waiting); $i++) {
            $ordered[] = $waiting[$i];          // remaining positions
        }
    } else {
        // No waiting at all -> just show holds in order
        foreach ($holds as $h) $ordered[] = $h;
    }

    // Build response + compute position & ETA
    $items = [];
    $pos = 0;
    foreach ($ordered as $r) {
        $pos++;
        $code   = !empty($r['service_code']) ? $r['service_code'] : 'Q';
        $status = strtolower(trim($r['status'] ?? 'waiting'));

        // ETA rule: waiting -> pos * 3 minutes; hold -> fixed 3 minutes
        $eta = ($status === 'hold') ? 3 : ($pos * 3);

        $items[] = [
            'id'               => (int)$r['id'],
            'name'             => (string)($r['customer_name'] ?? ''),
            'ticket_no'        => (string)($r['ticket_no'] ?? ''),
            'service'          => (string)($r['service_name'] ?? ''),
            'service_id'       => (int)($r['service_id'] ?? 0),
            'service_code'     => (string)$code,
            'status'           => (string)$status,          // 'waiting' or 'hold'
            'arrived'          => $r['created_at'],
            'position_ordinal' => $pos,
            'eta_minutes'      => $eta,
        ];
    }

    // Include server time (ms) for accurate client countdown
    $server_now_ms = (int) round(microtime(true) * 1000);

    echo json_encode(['ok' => true, 'items' => $items, 'server_now' => $server_now_ms], JSON_UNESCAPED_UNICODE);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}

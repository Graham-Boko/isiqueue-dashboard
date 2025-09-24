<?php
// C:\xampp\htdocs\isiqueue\api\current_serving.php
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
while (ob_get_level() > 0) { ob_end_clean(); }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    require __DIR__ . '/db.php';
    if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
        throw new Exception('DB not initialized');
    }

    $counterId = isset($_GET['counter_id']) ? (int)$_GET['counter_id'] : 0;

    // Detect service label column
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

    $mapRow = function(array $row) {
        $status = strtolower(trim((string)($row['status'] ?? '')));
        if ($status === 'now_serving') $status = 'serving';
        return [
            'id'            => (int)$row['id'],
            'ticket_no'     => (string)$row['ticket_no'],
            'customer_name' => (string)($row['customer_name'] ?? ''),
            'service_name'  => (string)($row['service_name'] ?? ''),
            'service_id'    => (int)($row['service_id'] ?? 0),
            'service_code'  => (string)(!empty($row['service_code']) ? $row['service_code'] : 'Q'),
            'status'        => $status,
            'counter_id'    => $row['counter_id'] ? (int)$row['counter_id'] : null,
            'called_at'     => $row['called_at'],
            'hold_count'    => isset($row['hold_count']) ? (int)$row['hold_count'] : 0,
        ];
    };

    $selectBase = "
        SELECT t.id,
               t.ticket_no,
               t.service_id,
               t.status,
               t.counter_id,
               t.called_at,
               t.hold_count,
               CONCAT(TRIM(c.first_name), ' ', TRIM(c.last_name)) AS customer_name,
               s.$serviceColEsc AS service_name,
               UPPER(s.code) AS service_code
          FROM tickets t
          JOIN customers c ON c.id = t.customer_id
     LEFT JOIN services  s ON s.id = t.service_id
    ";

    $row = null;

    // On-call (priority)
    if ($counterId > 0) {
        $sql = $selectBase . " WHERE TRIM(LOWER(t.status))='on_call' AND t.counter_id=? ORDER BY t.called_at DESC, t.id DESC LIMIT 1";
        $st = $mysqli->prepare($sql); $st->bind_param('i', $counterId);
        $st->execute(); $res = $st->get_result(); $row = $res->fetch_assoc(); $st->close();
    }

    // Serving
    if (!$row && $counterId > 0) {
        $sql = $selectBase . " WHERE TRIM(LOWER(t.status)) IN ('serving','now_serving') AND t.counter_id=? ORDER BY t.called_at DESC, t.id DESC LIMIT 1";
        $st = $mysqli->prepare($sql); $st->bind_param('i', $counterId);
        $st->execute(); $res = $st->get_result(); $row = $res->fetch_assoc(); $st->close();
    }

    // Global fallback (only active states, NOT last served)
    if (!$row) {
        $sql = $selectBase . " WHERE TRIM(LOWER(t.status)) IN ('on_call','serving','now_serving') ORDER BY t.called_at DESC, t.id DESC LIMIT 1";
        if ($res = $mysqli->query($sql)) {
            $row = $res->fetch_assoc(); $res->close();
        }
    }

    if (!$row) {
        echo json_encode(['ok'=>true, 'item'=>null], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['ok'=>true, 'item'=>$mapRow($row)], JSON_UNESCAPED_UNICODE);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
    exit;
}

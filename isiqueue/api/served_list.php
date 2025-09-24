<?php
// api/served_list.php
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

require __DIR__ . '/db.php';

try {
    if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
        throw new Exception('DB connection not available');
    }

    // Detect service label column to display (name/service/code)
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
    if (!$colRes) throw new Exception('Column check failed: '.$mysqli->error);
    $rowCol = $colRes->fetch_assoc();
    $serviceCol = $rowCol ? $rowCol['COLUMN_NAME'] : 'name';
    $serviceColEsc = '`' . $mysqli->real_escape_string($serviceCol) . '`';

    // Fetch recently served tickets (limit last 50 for dashboard)
    $sql = "
        SELECT
            t.id,
            t.ticket_no,
            t.service_id,
            t.status,
            t.completed_at,
            CONCAT(TRIM(c.first_name), ' ', TRIM(c.last_name)) AS customer_name,
            s.$serviceColEsc AS service_name,
            UPPER(s.code) AS service_code
        FROM tickets t
        JOIN customers c ON c.id = t.customer_id
        LEFT JOIN services s ON s.id = t.service_id
        WHERE TRIM(LOWER(t.status)) = 'served'
        ORDER BY t.completed_at DESC, t.id DESC
        LIMIT 50
    ";
    $res = $mysqli->query($sql);
    if (!$res) throw new Exception('Query failed: '.$mysqli->error);

    $items = [];
    while ($row = $res->fetch_assoc()) {
        $items[] = [
            'id'            => (int)$row['id'],
            'ticket_no'     => $row['ticket_no'],
            'customer_name' => $row['customer_name'] ?: '',
            'service_name'  => $row['service_name'] ?: '',
            'service_id'    => (int)$row['service_id'],
            'service_code'  => (!empty($row['service_code']) ? $row['service_code'] : 'Q'),
            'status'        => $row['status'],
            'completed_at'  => $row['completed_at']
        ];
    }

    echo json_encode(['ok'=>true,'items'=>$items], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}

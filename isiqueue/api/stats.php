<?php
// C:\xampp\htdocs\isiqueue\api\stats.php
header('Content-Type: application/json');

$pdo = null;
$mysqli = null;

$dbFile = __DIR__ . '/db.php';
if (file_exists($dbFile)) {
    include $dbFile;
    foreach (get_defined_vars() as $name => $val) {
        if ($val instanceof PDO) { $pdo = $val; break; }
    }
    $candidates = ['mysqli','conn','con','db','connection'];
    foreach ($candidates as $n) {
        if (isset($$n) && $$n instanceof mysqli) { $mysqli = $$n; break; }
    }
}

function kpiWithPDO(PDO $pdo): array {
    $waiting = (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE TRIM(LOWER(status))='waiting'")->fetchColumn();
    $serving = (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE TRIM(LOWER(status))='serving'")->fetchColumn();
    $served  = (int)$pdo->query("
        SELECT COUNT(*)
        FROM tickets
        WHERE TRIM(LOWER(status))='served' AND DATE(completed_at)=CURDATE()
    ")->fetchColumn();
    return ['waiting_all'=>$waiting, 'serving_now'=>$serving, 'served_today'=>$served];
}

function kpiWithMySQLi(mysqli $m): array {
    $waiting = fetchCount($m, "SELECT COUNT(*) AS c FROM tickets WHERE TRIM(LOWER(status))='waiting'");
    $serving = fetchCount($m, "SELECT COUNT(*) AS c FROM tickets WHERE TRIM(LOWER(status))='serving'");
    $served  = fetchCount($m, "
        SELECT COUNT(*) AS c
        FROM tickets
        WHERE TRIM(LOWER(status))='served' AND DATE(completed_at)=CURDATE()
    ");
    return ['waiting_all'=>$waiting, 'serving_now'=>$serving, 'served_today'=>$served];
}

function fetchCount(mysqli $m, string $sql): int {
    $res = $m->query($sql);
    if (!$res) throw new Exception($m->error);
    $row = $res->fetch_array();
    return (int)$row[0];
}

try {
    if ($pdo instanceof PDO) {
        $kpi = kpiWithPDO($pdo);
    } elseif ($mysqli instanceof mysqli) {
        $kpi = kpiWithMySQLi($mysqli);
    } else {
        $pdo = new PDO("mysql:host=localhost;dbname=isiqueue;charset=utf8mb4", "root", "", [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_NUM,
        ]);
        $kpi = kpiWithPDO($pdo);
    }

    echo json_encode(['ok'=>true, 'kpi'=>$kpi]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}

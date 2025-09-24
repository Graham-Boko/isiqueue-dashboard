<?php
// C:\xampp\htdocs\isiqueue\api\staff_kpis.php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/db.php';

// Overall KPIs
// Use DB date (CURDATE()) to avoid PHP/Apache timezone mismatches
$kpi = [
  'issued_today' => 0,
  'waiting'      => 0,
  'in_service'   => 0,
  'served_today' => 0
];

$q1 = $mysqli->query("
  SELECT
    SUM(DATE(created_at) = CURDATE())            AS issued_today,
    SUM(status = 'waiting')                      AS waiting,
    SUM(status = 'in_service')                   AS in_service,
    SUM(status = 'served' AND DATE(completed_at) = CURDATE()) AS served_today
  FROM tickets
");
if ($q1) {
  $row = $q1->fetch_assoc();
  $kpi['issued_today'] = (int)($row['issued_today'] ?? 0);
  $kpi['waiting']      = (int)($row['waiting'] ?? 0);
  $kpi['in_service']   = (int)($row['in_service'] ?? 0);
  $kpi['served_today'] = (int)($row['served_today'] ?? 0);
}

// Per-service breakdown (shows 0s for services with no tickets)
$svc = [];
$q2 = $mysqli->query("
  SELECT s.id, s.code, s.name,
         COALESCE(SUM(DATE(t.created_at) = CURDATE()), 0) AS issued_today,
         COALESCE(SUM(t.status = 'waiting'), 0)           AS waiting,
         COALESCE(SUM(t.status = 'in_service'), 0)        AS in_service,
         COALESCE(SUM(t.status = 'served' AND DATE(t.completed_at)=CURDATE()), 0) AS served_today
  FROM services s
  LEFT JOIN tickets t ON t.service_id = s.id
  GROUP BY s.id, s.code, s.name
  ORDER BY s.code
");
if ($q2) {
  while ($r = $q2->fetch_assoc()) {
    $svc[] = [
      'code'          => $r['code'],
      'name'          => $r['name'],
      'issued_today'  => (int)$r['issued_today'],
      'waiting'       => (int)$r['waiting'],
      'in_service'    => (int)$r['in_service'],
      'served_today'  => (int)$r['served_today']
    ];
  }
}

echo json_encode(['ok'=>true, 'kpi'=>$kpi, 'services'=>$svc], JSON_UNESCAPED_UNICODE);

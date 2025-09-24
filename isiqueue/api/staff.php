<?php
// C:\xampp\htdocs\isiqueue\api\staff.php
// Actions:
//   create          -> add a staff (first_name, last_name, email, password)
//   login           -> email + plain-text password
//   reset_password  -> sets a temporary password and returns it
//   list            -> list staff (no passwords), supports q/limit/offset
//   get             -> get one staff by id (no password)
//
// All responses are JSON. CORS enabled for local dev.

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok'=>false,'error'=>'Method not allowed']);
  exit;
}

require __DIR__ . '/db.php';
if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'DB connection not available']);
  exit;
}
$mysqli->set_charset('utf8mb4');

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input) || !$input) { $input = $_POST; }

$action = strtolower(trim($input['action'] ?? ''));

function respond($code, $arr){ http_response_code($code); echo json_encode($arr); exit; }

/* ============================================================
   ACTION: CREATE (plain-text password as requested)
   ============================================================ */
if ($action === 'create') {
  $first = trim($input['first_name'] ?? '');
  $last  = trim($input['last_name'] ?? '');
  $email = trim($input['email'] ?? '');
  $pass  = (string)($input['password'] ?? '');

  $errs = [];
  if ($first==='') $errs[]='First name required';
  if ($last==='')  $errs[]='Last name required';
  if ($email==='') $errs[]='Email required';
  if ($pass==='')  $errs[]='Password required';
  if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errs[]='Invalid email';
  if ($errs) respond(422, ['ok'=>false, 'error'=>'Validation failed', 'details'=>$errs]);

  $stmt = $mysqli->prepare("INSERT INTO staff (first_name, last_name, email, password) VALUES (?, ?, ?, ?)");
  if (!$stmt) respond(500, ['ok'=>false,'error'=>'Prepare failed: '.$mysqli->error]);
  $stmt->bind_param('ssss', $first, $last, $email, $pass);

  if (!$stmt->execute()) {
    if ($stmt->errno === 1062) { $stmt->close(); respond(409, ['ok'=>false,'error'=>'Email already exists']); }
    $err = $stmt->error; $stmt->close(); respond(500, ['ok'=>false,'error'=>'Insert failed: '.$err]);
  }

  $id = $stmt->insert_id;
  $stmt->close();
  respond(201, ['ok'=>true,'id'=>$id,'message'=>'Staff created']);
}

/* ============================================================
   ACTION: LOGIN (email + plain-text password)
   ============================================================ */
if ($action === 'login') {
  $email = trim($input['email'] ?? '');
  $pass  = (string)($input['password'] ?? '');
  if ($email==='' || $pass==='') respond(422, ['ok'=>false,'error'=>'Email and password required']);

  $stmt = $mysqli->prepare("SELECT id, first_name, last_name, email, password FROM staff WHERE email=? LIMIT 1");
  if (!$stmt) respond(500, ['ok'=>false,'error'=>'Prepare failed: '.$mysqli->error]);
  $stmt->bind_param('s', $email);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res->fetch_assoc();
  $stmt->close();

  if (!$row || $row['password'] !== $pass) { respond(401, ['ok'=>false,'error'=>'Invalid credentials']); }

  respond(200, ['ok'=>true, 'staff'=>[
    'id' => (int)$row['id'],
    'first_name' => $row['first_name'],
    'last_name'  => $row['last_name'],
    'email'      => $row['email']
  ]]);
}

/* ============================================================
   ACTION: RESET PASSWORD (generate & return a temp password)
   ============================================================ */
if ($action === 'reset_password') {
  $email = trim($input['email'] ?? '');
  if ($email === '') { respond(422, ['ok'=>false,'error'=>'Email required']); }

  $stmt = $mysqli->prepare("SELECT id FROM staff WHERE email=? LIMIT 1");
  if (!$stmt) respond(500, ['ok'=>false,'error'=>'Prepare failed: '.$mysqli->error]);
  $stmt->bind_param('s', $email);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res->fetch_assoc();
  $stmt->close();

  if (!$row) { respond(404, ['ok'=>false,'error'=>'Staff not found']); }

  // 8-char temp password (no ambiguous chars)
  $pool = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
  $temp = '';
  for ($i=0; $i<8; $i++) { $temp .= $pool[random_int(0, strlen($pool)-1)]; }

  $up = $mysqli->prepare("UPDATE staff SET password=? WHERE id=?");
  if (!$up) respond(500, ['ok'=>false,'error'=>'Prepare failed: '.$mysqli->error]);
  $up->bind_param('si', $temp, $row['id']);
  if (!$up->execute()) { $err = $up->error; $up->close(); respond(500, ['ok'=>false,'error'=>'Update failed: '.$err]); }
  $up->close();

  respond(200, ['ok'=>true, 'temp_password'=>$temp, 'message'=>'Temporary password set']);
}

/* ============================================================
   ACTION: LIST (admin) — returns staff WITHOUT passwords
   Supports optional: q (search), limit (default 100), offset (default 0)
   ============================================================ */
if ($action === 'list') {
  $q = trim($input['q'] ?? '');
  $limit  = isset($input['limit'])  ? max(1, (int)$input['limit'])  : 100;
  $offset = isset($input['offset']) ? max(0, (int)$input['offset']) : 0;

  if ($q !== '') {
    $like = '%'.$q.'%';
    $stmt = $mysqli->prepare("
      SELECT id, first_name, last_name, email
      FROM staff
      WHERE first_name LIKE ? OR last_name LIKE ? OR email LIKE ?
      ORDER BY last_name, first_name
      LIMIT ? OFFSET ?
    ");
    if (!$stmt) respond(500, ['ok'=>false,'error'=>'Prepare failed: '.$mysqli->error]);
    $stmt->bind_param('sssii', $like, $like, $like, $limit, $offset);
  } else {
    $stmt = $mysqli->prepare("
      SELECT id, first_name, last_name, email
      FROM staff
      ORDER BY last_name, first_name
      LIMIT ? OFFSET ?
    ");
    if (!$stmt) respond(500, ['ok'=>false,'error'=>'Prepare failed: '.$mysqli->error]);
    $stmt->bind_param('ii', $limit, $offset);
  }

  $stmt->execute();
  $res = $stmt->get_result();
  $items = [];
  while ($row = $res->fetch_assoc()) {
    $items[] = [
      'id' => (int)$row['id'],
      'first_name' => $row['first_name'],
      'last_name' => $row['last_name'],
      'email' => $row['email']
    ];
  }
  $stmt->close();

  respond(200, ['ok'=>true, 'items'=>$items, 'limit'=>$limit, 'offset'=>$offset, 'count'=>count($items)]);
}

/* ============================================================
   ACTION: GET (one staff by id, no password)
   ============================================================ */
if ($action === 'get') {
  $id = (int)($input['id'] ?? 0);
  if ($id <= 0) respond(422, ['ok'=>false,'error'=>'Valid id required']);

  $stmt = $mysqli->prepare("SELECT id, first_name, last_name, email FROM staff WHERE id=? LIMIT 1");
  if (!$stmt) respond(500, ['ok'=>false,'error'=>'Prepare failed: '.$mysqli->error]);
  $stmt->bind_param('i', $id);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res->fetch_assoc();
  $stmt->close();

  if (!$row) respond(404, ['ok'=>false,'error'=>'Not found']);

  respond(200, ['ok'=>true, 'staff'=>[
    'id' => (int)$row['id'],
    'first_name' => $row['first_name'],
    'last_name'  => $row['last_name'],
    'email'      => $row['email']
  ]]);
}

/* ============================================================
   Fallback
   ============================================================ */
respond(400, ['ok'=>false,'error'=>'Invalid action. Use one of: create, login, reset_password, list, get']);

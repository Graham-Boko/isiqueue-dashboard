<?php
// C:\xampp\htdocs\isiqueue\api\register.php
// Minimal, surgical adjustments only. Original logic kept.

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }
header('Content-Type: application/json');

// Only POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'Method not allowed']);
    exit;
}

// DB
require __DIR__ . '/db.php';      // expects $mysqli
if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'DB connection not available']);
    exit;
}
$mysqli->set_charset('utf8mb4');

// Read JSON or form-data (kept)
$input = [];
$ct = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
if (stripos($ct, 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    $j = json_decode($raw, true);
    if (is_array($j)) $input = $j;
}
if (!$input) { $input = $_POST; }

// Collect
$first_name = trim($input['first_name'] ?? '');
$last_name  = trim($input['last_name'] ?? '');
$dob        = trim($input['dob'] ?? '');
$email      = trim($input['email'] ?? '');
$address    = trim($input['address'] ?? '');

/**
 * Accept dd/mm/yyyy and normalize to yyyy-mm-dd
 */
if ($dob !== '' && preg_match('#^\d{2}/\d{2}/\d{4}$#', $dob)) {
    $d = DateTime::createFromFormat('d/m/Y', $dob);
    if ($d) { $dob = $d->format('Y-m-d'); }
}

// Validation (kept)
$errors = [];
if ($first_name === '') $errors[] = 'First name is required';
if ($last_name  === '') $errors[] = 'Last name is required';
if ($dob        === '') $errors[] = 'Date of birth is required';
if ($email      === '') $errors[] = 'Email is required';
if ($address    === '') $errors[] = 'Address is required';

if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Email format is invalid';
}
if ($dob && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
    $errors[] = 'DOB must be in YYYY-MM-DD format';
}

if ($errors) {
    http_response_code(422);
    echo json_encode(['ok'=>false,'error'=>'Validation failed','details'=>$errors]);
    exit;
}

// Insert (kept)
$sql = "INSERT INTO customers (first_name, last_name, dob, email, address)
        VALUES (?, ?, ?, ?, ?)";
$stmt = $mysqli->prepare($sql);

if (!$stmt) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Database prepare failed: '.$mysqli->error]);
    exit;
}

$stmt->bind_param('sssss', $first_name, $last_name, $dob, $email, $address);

if (!$stmt->execute()) {
    // ***** Adjustment: check the statement errno first *****
    if ($stmt->errno === 1062) {
        http_response_code(409);
        echo json_encode(['ok'=>false,'error'=>'Email already exists']);
    } else {
        http_response_code(500);
        echo json_encode(['ok'=>false,'error'=>'Database insert failed: '.$stmt->error]);
    }
    $stmt->close();
    exit;
}

// Only succeed if one row added
if ($stmt->affected_rows !== 1) {
    $stmt->close();
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Insert failed (no rows added)']);
    exit;
}

$new_id = $stmt->insert_id;
$stmt->close();

echo json_encode([
    'ok'      => true,
    'id'      => $new_id,
    'message' => 'Customer registered successfully'
]);

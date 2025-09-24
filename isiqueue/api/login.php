<?php
// C:\xampp\htdocs\isiqueue\api\login.php

header('Content-Type: application/json');

require __DIR__ . '/db.php'; // provides $mysqli and exits on failure

// --- POST only ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
  exit;
}

// Read JSON or form data
$input = [];
if (!empty($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
  $raw = file_get_contents('php://input');
  $json = json_decode($raw, true);
  if (is_array($json)) $input = $json;
}
if (!$input) $input = $_POST;

$email    = isset($input['email']) ? trim($input['email']) : '';
$password = isset($input['password']) ? (string)$input['password'] : '';

if ($email === '' || $password === '') {
  http_response_code(400);
  echo json_encode(['ok' => false, 'message' => 'Email and password are required']);
  exit;
}

if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'message' => 'DB connection not available']);
  exit;
}

// --- Query customers (ONLY existing columns) ---
$sql = "SELECT 
          id,
          first_name,
          last_name,
          email,
          password,
          must_change_password
        FROM customers
        WHERE email = ?
        LIMIT 1";

$stmt = $mysqli->prepare($sql);
if (!$stmt) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'message' => 'Query prepare failed']);
  exit;
}
$stmt->bind_param('s', $email);
$stmt->execute();
$res = $stmt->get_result();

if ($res && ($row = $res->fetch_assoc())) {
  $dbPass = (string)($row['password'] ?? '');

  // Plain-text compare (matches your current setup)
  if ($dbPass === $password) {
    $userId   = (int)$row['id'];
    $emailOut = (string)($row['email'] ?? $email);

    // Names from DB (trim + collapse spaces)
    $firstName = preg_replace('/\s+/', ' ', trim((string)($row['first_name'] ?? '')));
    $lastName  = preg_replace('/\s+/', ' ', trim((string)($row['last_name'] ?? '')));

    // Build display name (never empty)
    $displayName = trim($firstName . ' ' . $lastName);
    if ($displayName === '') {
      // final fallback: email prefix
      $displayName = strtok($emailOut, '@');
    }

    // If DB had a combined name scenario (not your case), keep first/last as-is.
    // Here we already got them from columns; no need to split.

    // must_change_password (0/1)
    $mustChange = 0;
    if (array_key_exists('must_change_password', $row) && $row['must_change_password'] !== null) {
      $mustChange = (int)!empty($row['must_change_password']);
    }

    // Response: keep 'user' for backward compatibility and add 'customer'
    $response = [
      'ok' => true,
      'user' => [
        'id'    => $userId,
        'name'  => $displayName, // <-- guaranteed non-empty now
        'email' => $emailOut,
        // you previously included default_password_changed; not selected here, so return null
        'default_password_changed' => null
      ],
      'customer' => [
        'id' => $userId,
        'first_name' => $firstName,
        'last_name'  => $lastName,
        'email' => $emailOut,
        'must_change_password' => $mustChange
      ]
    ];

    echo json_encode($response);
    exit;
  }
}

// Invalid credentials
http_response_code(401);
echo json_encode(['ok' => false, 'message' => 'Invalid email or password']);

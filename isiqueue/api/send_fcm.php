<?php
// C:\xampp\htdocs\isiqueue\api\send_fcm.php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require __DIR__ . '/db.php'; // must set $mysqli

// Get params (from Thunder Client POST body or query)
$customerId = intval($_POST['customer_id'] ?? $_GET['customer_id'] ?? 0);
$title      = trim($_POST['title'] ?? "IsiQueue Update");
$body       = trim($_POST['body'] ?? "It's your turn! Please proceed to the counter.");

if ($customerId <= 0) {
    echo json_encode(['ok'=>false, 'error'=>'Missing customer_id']);
    exit;
}

// Look up customer’s FCM token
$sql = "SELECT fcm_token FROM customers WHERE id=? LIMIT 1";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $customerId);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();
$stmt->close();

if (!$row || empty($row['fcm_token'])) {
    echo json_encode(['ok'=>false, 'error'=>'No FCM token for this customer']);
    exit;
}
$token = $row['fcm_token'];

// Load Firebase Admin SDK
require __DIR__ . '/../firebase/vendor/autoload.php';   // path to composer autoload.php
use Kreait\Firebase\Factory;

$serviceAccountPath = __DIR__ . '/../firebase/serviceAccountKey.json';
$factory = (new Factory)->withServiceAccount($serviceAccountPath);
$messaging = $factory->createMessaging();

// Build the message
$message = [
    'token' => $token,
    'notification' => [
        'title' => $title,
        'body'  => $body,
    ],
    'data' => [ // optional extra payload
        'customer_id' => (string)$customerId
    ]
];

try {
    $result = $messaging->send($message);
    echo json_encode(['ok'=>true, 'message'=>'Notification sent', 'id'=>$result]);
} catch (Exception $e) {
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}

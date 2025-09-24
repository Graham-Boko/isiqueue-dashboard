<?php
// C:\xampp\htdocs\isiqueue\api\firebase_push.php
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

function send_push_token(string $token, string $title, string $body, array $data = []): array {
    $autoload = __DIR__ . '/../firebase/vendor/autoload.php';
    $keyFile  = __DIR__ . '/../firebase/serviceAccountKey.json';

    if (!file_exists($autoload))  { error_log('FCM autoload missing');  return ['ok'=>false,'error'=>'firebase autoload not found']; }
    if (!file_exists($keyFile))   { error_log('FCM key missing');        return ['ok'=>false,'error'=>'firebase serviceAccountKey.json missing']; }

    require_once $autoload;

    $factory   = (new Factory())->withServiceAccount($keyFile);
    $messaging = $factory->createMessaging();

    $msg = CloudMessage::withTarget('token', $token)
        ->withNotification(Notification::create($title, $body))
        ->withData(array_map('strval', $data))
        ->withAndroid(['priority'=>'high','notification'=>['sound'=>'default','channel_id'=>'isiqueue_calls']])
        ->withApns(['headers'=>['apns-priority'=>'10'],'payload'=>['aps'=>['sound'=>'default']]]);

    try {
        $res = $messaging->send($msg);
        return ['ok'=>true,'result'=>(string)$res];
    } catch (\Throwable $e) {
        error_log('FCM send error: '.$e->getMessage());
        return ['ok'=>false,'error'=>$e->getMessage()];
    }
}

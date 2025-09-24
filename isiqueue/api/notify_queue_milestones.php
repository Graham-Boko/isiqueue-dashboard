<?php
// C:\xampp\htdocs\isiqueue\api\notify_queue_milestones.php
require_once __DIR__ . '/firebase_push.php';

/**
 * Sends one-time notifications when a waiting customer is exactly at positions 5 or 3.
 * Ordering mirrors waiting_list.php:
 *   1) first WAITING[0]
 *   2) then all HOLD (by last_hold_at, id)
 *   3) then remaining WAITING (oldest -> newest)
 *
 * We notify ONLY tickets with status='waiting' (holds still count toward positions).
 *
 * @param mysqli $mysqli
 * @return array debug info (optional to log/inspect)
 */
function notify_milestones(mysqli $mysqli): array
{
    $debug = ['ordered'=>[], 'sent'=>[], 'skipped'=>[]];

    // Helper: fetch all tokens for a given customer (multi-device)
    $fetchTokens = function(mysqli $db, int $customerId): array {
        $tokens = [];
        $stmt = $db->prepare("SELECT fcm_token FROM customer_tokens WHERE customer_id=?");
        if ($stmt) {
            $stmt->bind_param('i', $customerId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $t = trim((string)$r['fcm_token']);
                if ($t !== '') $tokens[$t] = true; // de-dupe
            }
            $stmt->close();
        }
        return array_keys($tokens);
    };

    // 1) WAITING (oldest first) — use ticket fields only (no join to customers)
    $sqlWaiting = "
        SELECT t.id, t.ticket_no, t.customer_id, t.service_id, t.status, t.created_at,
               COALESCE(t.notified_pos5,0) AS n5,
               COALESCE(t.notified_pos3,0) AS n3
          FROM tickets t
         WHERE TRIM(LOWER(t.status))='waiting'
      ORDER BY t.created_at ASC, t.id ASC
    ";
    $resW = $mysqli->query($sqlWaiting);
    if (!$resW) { error_log('notify_milestones: waiting query failed: '.$mysqli->error); return $debug; }
    $waiting = [];
    while ($r = $resW->fetch_assoc()) $waiting[] = $r;

    // 2) HOLD (oldest hold first)
    $sqlHold = "
        SELECT t.id, t.ticket_no, t.customer_id, t.service_id, t.status, t.last_hold_at
          FROM tickets t
         WHERE TRIM(LOWER(t.status))='hold'
      ORDER BY t.last_hold_at ASC, t.id ASC
    ";
    $resH = $mysqli->query($sqlHold);
    if (!$resH) { error_log('notify_milestones: hold query failed: '.$mysqli->error); return $debug; }
    $holds = [];
    while ($r = $resH->fetch_assoc()) $holds[] = $r;

    // 3) Combined order
    $ordered = [];
    if (count($waiting) > 0) {
        $ordered[] = $waiting[0];               // pos 1 (waiting)
        foreach ($holds as $h) $ordered[] = $h; // pos 2..(H+1) (hold)
        for ($i=1; $i<count($waiting); $i++) {  // remaining waiting
            $ordered[] = $waiting[$i];
        }
    } else {
        foreach ($holds as $h) $ordered[] = $h; // only holds (no notifications)
    }

    // 4) Notify waiting tickets at exactly 5 and 3
    $pos = 0;
    foreach ($ordered as $row) {
        $pos++;
        $debug['ordered'][] = ['pos'=>$pos, 'id'=>(int)$row['id'], 'ticket_no'=>$row['ticket_no'], 'status'=>$row['status']];

        $status = strtolower(trim((string)($row['status'] ?? '')));
        if ($status !== 'waiting') continue; // notify waiting only

        $need5 = ($pos === 5 && (int)($row['n5'] ?? 0) === 0);
        $need3 = ($pos === 3 && (int)($row['n3'] ?? 0) === 0);
        if (!$need5 && !$need3) continue;

        // Fetch all tokens for this customer
        $tokens = $fetchTokens($mysqli, (int)$row['customer_id']);
        if (!$tokens) {
            $debug['skipped'][] = ['pos'=>$pos, 'id'=>(int)$row['id'], 'reason'=>'no_tokens'];
            continue;
        }

        $title = 'Queue update';
        $body  = $need5 ? "You're #5 in line. Please start heading in."
                        : "You're #3 in line. You're almost up — please be ready.";

        $sentAny = false; $errors = 0;
        foreach ($tokens as $tk) {
            $out = send_push_token($tk, $title, $body, [
                'action'   => 'QUEUE_POSITION',
                'position' => (string)$pos,
            ]);
            if (!empty($out['ok'])) {
                $sentAny = true;
            } else {
                $errors++;
                $debug['skipped'][] = ['pos'=>$pos, 'id'=>(int)$row['id'], 'reason'=>'fcm_error', 'error'=>$out['error'] ?? 'unknown', 'token'=>substr($tk,0,12).'...'];
                // Optional: prune NotRegistered tokens if your send_push_token exposes that info
                if (!empty($out['error']) && preg_match('/NotRegistered|InvalidRegistration/i', $out['error'])) {
                    $del = $mysqli->prepare("DELETE FROM customer_tokens WHERE fcm_token=?");
                    if ($del) { $del->bind_param('s', $tk); $del->execute(); $del->close(); }
                }
            }
        }

        if ($sentAny) {
            $debug['sent'][] = ['pos'=>$pos, 'id'=>(int)$row['id'], 'threshold'=>$need5 ? 5 : 3];
            $upd = $mysqli->prepare(
                $need5 ? "UPDATE tickets SET notified_pos5=1 WHERE id=?"
                       : "UPDATE tickets SET notified_pos3=1 WHERE id=?"
            );
            $id = (int)$row['id']; $upd->bind_param('i', $id); $upd->execute(); $upd->close();
        }
    }

    return $debug; // returned to caller; failures never break the endpoint
}

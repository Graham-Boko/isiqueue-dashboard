<?php
// C:\xampp\htdocs\isiqueue\api\fix_status_enum.php
header('Content-Type: text/plain; charset=utf-8');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/db.php';

try {
    if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
        throw new Exception('DB not initialized ($mysqli is null)');
    }

    // Show current definition
    $res = $mysqli->query("SHOW COLUMNS FROM tickets LIKE 'status'");
    $def = $res->fetch_assoc();
    echo "Current status column:\n";
    print_r($def);

    // Alter ENUM
    $sqlAlter = "
        ALTER TABLE tickets
          MODIFY status ENUM('waiting','now_serving','served','hold')
          NOT NULL DEFAULT 'waiting'
    ";
    $mysqli->query($sqlAlter);
    echo "\nALTER TABLE done.\n";

    // Repair bad rows
    $sqlFix = "
        UPDATE tickets
        SET status = CASE
            WHEN completed_at IS NOT NULL THEN 'served'
            WHEN called_at    IS NOT NULL THEN 'now_serving'
            ELSE 'waiting'
        END
        WHERE status IS NULL OR status = ''
    ";
    $mysqli->query($sqlFix);
    echo "Repaired rows (status ''/NULL): " . $mysqli->affected_rows . "\n";

    // Sample distinct statuses
    $res2 = $mysqli->query("SELECT DISTINCT status FROM tickets ORDER BY status");
    echo "\nDistinct statuses now:\n";
    while ($r = $res2->fetch_row()) { echo "- " . ($r[0] === null ? 'NULL' : $r[0]) . "\n"; }

    echo "\nOK\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage();
}

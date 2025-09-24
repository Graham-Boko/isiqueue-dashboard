<?php
$DB_HOST = 'YOUR_DB_HOST';
$DB_USER = 'YOUR_DB_USER';
$DB_PASS = 'YOUR_DB_PASSWORD';
$DB_NAME = 'YOUR_DB_NAME';
$mysqli = @new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
$mysqli->set_charset('utf8mb4');

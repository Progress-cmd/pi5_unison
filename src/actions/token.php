<?php
session_start();
$_SESSION['token'] = bin2hex(random_bytes(32));
session_write_close();
header('Content-Type: application/json');
echo json_encode(['token' => $_SESSION['token']]);
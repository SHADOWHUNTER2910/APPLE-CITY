<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json');
$_SESSION = [];
session_destroy();
echo json_encode(['ok' => true]);
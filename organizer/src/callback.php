<?php
require_once __DIR__ . '/auth.php';

if (!isset($_GET['code'])) {
    http_response_code(400);
    header('Content-Type: text/plain');
    die('No authorization code provided');
}

handleCallback($_GET['code']);

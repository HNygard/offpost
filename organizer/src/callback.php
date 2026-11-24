<?php
require_once __DIR__ . '/auth.php';

if (!isset($_GET['code'])) {
    http_response_code(400);
    header('Content-Type: text/plain');
    die('No authorization code provided');
}

$expectedState = $_SESSION['oauth_login_state'] ?? null;
unset($_SESSION['oauth_login_state']);
$givenState = $_GET['state'] ?? '';
if (!is_string($expectedState) || !is_string($givenState) || !hash_equals($expectedState, $givenState)) {
    http_response_code(400);
    header('Content-Type: text/plain');
    die('Invalid login state');
}

handleCallback($_GET['code']);

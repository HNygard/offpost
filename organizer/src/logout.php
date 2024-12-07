<?php
session_start();
session_destroy();

$auth_url = getenv('AUTH_URL');
if (!$auth_url) {
    die('AUTH_URL environment variable not set');
}

// Redirect to OIDC end session endpoint
header('Location: ' . $auth_url . '/oidc/session/end');
exit;

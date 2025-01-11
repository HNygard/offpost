<?php
session_start();
session_destroy();

require __DIR__ . '/username-password.php';

// Redirect to OIDC end session endpoint
header('Location: ' . $oidc_auth_url . '/oidc/session/end');
exit;

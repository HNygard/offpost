<?php
session_start();
session_destroy();

require __DIR__ . '/username-password.php';

// Redirect to OIDC end session endpoint
header('Location: ' . $oidc_end_session_endpoint);
exit;

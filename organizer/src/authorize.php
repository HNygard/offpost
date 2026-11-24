<?php
require_once __DIR__ . '/auth.php';

// Restart login using browser-bound state, even if the PHP session was lost.
requireAuth(false);

require __DIR__ . '/username-password.php';
$state = $_COOKIE['offpost_login_recovery'] ?? null;
$redirect = getLoginRedirect($state, $oidc_client_secret) ?? '/';
if (getLoginRedirect($state, $oidc_client_secret) !== null) {
    clearLoginState($state, parse_url($oidc_callback_url, PHP_URL_SCHEME) === 'https');
}
header('Location: ' . $redirect);
exit;

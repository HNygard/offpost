<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function isAuthenticated() {
    return isset($_SESSION['user']);
}

function requireAuth() {
    if (!isAuthenticated()) {
        // Store the current URL to redirect back after login
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
        
        require __DIR__ . '/username-password.php';

        // Redirect to auth service using frontend URL
        header('Location: ' . $oidc_auth_url . '?' . http_build_query([
            'client_id' => $oidc_client_id,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'redirect_uri' => $oidc_callback_url,
            'state' => bin2hex(random_bytes(16))
        ]));
        exit;
    }
}

function handleCallback($code) {
    require __DIR__ . '/username-password.php';

    // Exchange code for tokens using server-to-server URL
    $response = file_get_contents($oidc_token_endpoint, false, stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/x-www-form-urlencoded',
            'content' => http_build_query([
                'grant_type' => 'authorization_code',
                'code' => $code,
                'client_id' => $oidc_client_id,
                'client_secret' => $oidc_client_secret,
                'redirect_uri' => $oidc_callback_url,
            ])
        ]
    ]));

    if ($response === false) {
        die('Failed to exchange code for tokens');
    }

    $tokens = json_decode($response, true);
    
    // Get user info using server-to-server URL
    $userinfo = file_get_contents($oidc_userinfo_endpoint, false, stream_context_create([
        'http' => [
            'header' => 'Authorization: Bearer ' . $tokens['access_token']
        ]
    ]));

    if ($userinfo === false) {
        die('Failed to get user info');
    }

    $user = json_decode($userinfo, true);
    $_SESSION['user'] = $user;

    // Redirect back to original page
    $redirect = $_SESSION['redirect_after_login'] ?? '/';
    unset($_SESSION['redirect_after_login']);
    header('Location: ' . $redirect);
    exit;
}

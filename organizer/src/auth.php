<?php
session_start();

function isAuthenticated() {
    return isset($_SESSION['user']);
}

function requireAuth() {
    if (!isAuthenticated()) {
        // Store the current URL to redirect back after login
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
        
        require __DIR__ . '/username-password.php';

        // Redirect to auth service using frontend URL
        header('Location: ' . $oidc_auth_url . '/oidc/auth?' . http_build_query([
            'client_id' => $oidc_client_id,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'redirect_uri' => 'http://localhost:25081/callback.php',
            'state' => bin2hex(random_bytes(16))
        ]));
        exit;
    }
}

function handleCallback($code) {
    require __DIR__ . '/username-password.php';

    // Exchange code for tokens using server-to-server URL
    $response = file_get_contents($oidc_server_auth_url . '/oidc/token', false, stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/x-www-form-urlencoded',
            'content' => http_build_query([
                'grant_type' => 'authorization_code',
                'code' => $code,
                'client_id' => $oidc_client_id,
                'client_secret' => $oidc_client_secret,
                'redirect_uri' => 'http://localhost:25081/callback.php'
            ])
        ]
    ]));

    if ($response === false) {
        die('Failed to exchange code for tokens');
    }

    $tokens = json_decode($response, true);
    
    // Get user info using server-to-server URL
    $userinfo = file_get_contents($oidc_server_auth_url . '/oidc/me', false, stream_context_create([
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

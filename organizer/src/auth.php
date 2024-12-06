<?php
session_start();

function isAuthenticated() {
    return isset($_SESSION['user']);
}

function requireAuth() {
    if (!isAuthenticated()) {
        $auth_url = getenv('AUTH_URL');
        if (!$auth_url) {
            die('AUTH_URL environment variable not set');
        }
        
        // Store the current URL to redirect back after login
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
        
        // Redirect to auth service using frontend URL
        header('Location: ' . $auth_url . '/oidc/auth?' . http_build_query([
            'client_id' => 'organizer',
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'redirect_uri' => 'http://localhost:25081/callback.php',
            'state' => bin2hex(random_bytes(16))
        ]));
        exit;
    }
}

function handleCallback($code) {
    $frontend_auth_url = getenv('AUTH_URL');
    $server_auth_url = 'http://auth:3000';  // Server-to-server URL using Docker service name

    if (!$frontend_auth_url) {
        die('AUTH_URL environment variable not set');
    }

    // Exchange code for tokens using server-to-server URL
    $response = file_get_contents($server_auth_url . '/oidc/token', false, stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/x-www-form-urlencoded',
            'content' => http_build_query([
                'grant_type' => 'authorization_code',
                'code' => $code,
                'client_id' => 'organizer',
                'client_secret' => 'secret',
                'redirect_uri' => 'http://localhost:25081/callback.php'
            ])
        ]
    ]));

    if ($response === false) {
        die('Failed to exchange code for tokens');
    }

    $tokens = json_decode($response, true);
    
    // Get user info using server-to-server URL
    $userinfo = file_get_contents($server_auth_url . '/oidc/me', false, stream_context_create([
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

<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function isAuthenticated() {
    return isset($_SESSION['user']);
}

function isLocalLoginRedirect($redirect): bool {
    return is_string($redirect)
        && str_starts_with($redirect, '/')
        && !str_starts_with(rawurldecode($redirect), '//')
        && !preg_match('/[\x00-\x20\x7f\\\\]/', $redirect)
        && !preg_match('/[\x00-\x1f\x7f\\\\]/', rawurldecode($redirect));
}

function loginCookieOptions(bool $secure): array {
    return [
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function createLoginState(string $redirect, string $secret, bool $secure): string {
    if (!isLocalLoginRedirect($redirect)) {
        throw new InvalidArgumentException('Invalid login destination');
    }

    $nonce = bin2hex(random_bytes(16));
    $payload = $nonce . '.' . rtrim(strtr(base64_encode($redirect), '+/', '-_'), '=');
    $state = $payload . '.' . hash_hmac('sha256', 'offpost-login-state:' . $payload, $secret);
    $options = loginCookieOptions($secure);

    // These browser-session cookies survive PHP session garbage collection.
    setcookie('offpost_login_' . $nonce, hash('sha256', $state), $options);
    setcookie('offpost_login_recovery', $state, $options);
    return $state;
}

function getLoginRedirect($state, string $secret): ?string {
    if (!is_string($state)) {
        return null;
    }
    $parts = explode('.', $state);
    if (count($parts) !== 3 || !preg_match('/^[a-f0-9]{32}$/D', $parts[0])) {
        return null;
    }
    [$nonce, $encodedRedirect, $signature] = $parts;
    $expectedSignature = hash_hmac('sha256', 'offpost-login-state:' . $nonce . '.' . $encodedRedirect, $secret);
    $cookie = $_COOKIE['offpost_login_' . $nonce] ?? null;
    if (!hash_equals($expectedSignature, $signature)
        || !is_string($cookie)
        || !hash_equals(hash('sha256', $state), $cookie)) {
        return null;
    }

    $redirect = base64_decode(strtr($encodedRedirect, '-_', '+/'), true);
    return isLocalLoginRedirect($redirect) ? $redirect : null;
}

function clearLoginState(string $state, bool $secure): void {
    $options = loginCookieOptions($secure) + ['expires' => 1];
    setcookie('offpost_login_' . explode('.', $state)[0], '', $options);
    if (($_COOKIE['offpost_login_recovery'] ?? null) === $state) {
        setcookie('offpost_login_recovery', '', $options);
    }
}

function requireAuth(bool $rememberCurrentUrl = true) {
    if (!isAuthenticated()) {
        require __DIR__ . '/username-password.php';
        $secure = parse_url($oidc_callback_url, PHP_URL_SCHEME) === 'https';
        $recoveryState = $_COOKIE['offpost_login_recovery'] ?? null;
        $redirect = $rememberCurrentUrl
            ? $_SERVER['REQUEST_URI']
            : (getLoginRedirect($recoveryState, $oidc_client_secret) ?? '/');
        if (!$rememberCurrentUrl && getLoginRedirect($recoveryState, $oidc_client_secret) !== null) {
            clearLoginState($recoveryState, $secure);
        }

        // Redirect to auth service using frontend URL
        header('Location: ' . $oidc_auth_url . '?' . http_build_query([
            'client_id' => $oidc_client_id,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'redirect_uri' => $oidc_callback_url,
            'state' => createLoginState($redirect, $oidc_client_secret, $secure)
        ]));
        exit;
    }
}

function handleCallback($code) {
    require __DIR__ . '/username-password.php';

    $state = $_GET['state'] ?? null;
    $redirect = getLoginRedirect($state, $oidc_client_secret);
    if ($redirect === null) {
        http_response_code(400);
        header('Content-Type: text/plain');
        exit('Invalid login state');
    }

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

    clearLoginState($state, parse_url($oidc_callback_url, PHP_URL_SCHEME) === 'https');
    header('Location: ' . $redirect);
    exit;
}

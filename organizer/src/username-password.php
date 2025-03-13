<?php

// This is also the user name and password for entering Roundcube
$imap_username = "greenmail-user";
$imap_password = "EzUVrHxLVrF2";

$admins = array();


if (defined('PHPUNIT_RUNNING')) {
    require __DIR__ . '/username-password-override-dev.php';
}
else {
    require '/username-password-override.php';
}

if ($environment == 'development') {
    $admins[] = 'dev-user-id';
    $imapServer = '{greenmail:3993/imap/ssl}';
    $imapSentFolder = 'Sent';
    $smtpSecure = null;

    $smtpUsername = 'greenmail-user';
    $smtpServer = 'greenmail';
    $smtpPort = '3025';  // GreenMail SMTP port
    $smtpSecure = '';    // No encryption for GreenMail on port 3025

    // Server-to-server URL using Docker service name, the others are for the browser on host.
    $oidc_auth_url = 'http://localhost:25083/oidc/auth';
    $oidc_token_endpoint = 'http://auth:3000/oidc/token';
    $oidc_end_session_endpoint = 'http://localhost:25083/oidc/session/end';
    $oidc_userinfo_endpoint = 'http://auth:3000/oidc/me';
    $oidc_callback_url = 'http://localhost:25081/callback';

    $oidc_client_id = 'organizer';
    $oidc_client_secret = 'secret';


    if (defined('PHPUNIT_RUNNING')) {
        $imapServer = '{localhost:25993/imap/ssl}';
        $smtpServer = 'localhost';
        $smtpPort = '25025';  // GreenMail SMTP port
    }
}
else if ($environment == 'production') {
    $imapServer = '{ssl://imap.one.com:993/imap/ssl}';
    $imapSentFolder = 'INBOX.Sent';
    
    $smtpServer = 'smtp.sendgrid.net';
    $smtpPort = '587';
    $smtpSecure = 'tls';  // Use TLS for SendGrid

    // Auth0 - https://auth.offpost.no/.well-known/openid-configuration
    $oidc_auth_url = 'https://auth.offpost.no/authorize';
    $oidc_token_endpoint = 'https://auth.offpost.no/oauth/token';
    $oidc_end_session_endpoint = 'https://auth.offpost.no/oidc/logout';
    $oidc_userinfo_endpoint = 'https://auth.offpost.no/userinfo';
    $oidc_callback_url = 'https://offpost.no/callback';

    // Client id and secret is set in the override file
}
else {
    throw new Exception('Unknown environment: ' . $environment);
}

<?php

// This is also the user name and password for entering Roundcube
$yourEmail = "greenmail-user";
$yourEmailPassword = "EzUVrHxLVrF2";


require '/username-password-override.php';

if ($environment == 'development') {
    $imapServer = '{greenmail:3993/imap/ssl}';
    $imapSentFolder = 'Sent';
    $smtpSecure = null;

    $smtpUsername = 'greenmail-user';
    $smtpServer = 'greenmail';
    $smtpPort = '3025';  // GreenMail SMTP port
    $smtpSecure = '';    // No encryption for GreenMail on port 3025

    $oidc_auth_url = 'http://localhost:25083';
    $oidc_server_auth_url = 'http://auth:3000';  // Server-to-server URL using Docker service name
    $oidc_client_id = 'organizer';
    $oidc_client_secret = 'secret';
}
else if ($environment == 'production') {
    $imapServer = '{ssl://imap.one.com:993/imap/ssl}';
    $imapSentFolder = 'INBOX.Sent';
    
    $smtpServer = 'smtp.sendgrid.net';
    $smtpPort = '587';
    $smtpSecure = 'tls';  // Use TLS for SendGrid

    $oidc_auth_url = 'https://auth.offpost.no/';
    $oidc_server_auth_url = $oidc_auth_url;
    // Client id and secret is set in the override file
}
else {
    throw new Exception('Unknown environment: ' . $environment);
}

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
}
else if ($environment == 'production') {
    $imapServer = '{ssl://imap.one.com:993/imap/ssl}';
    $imapSentFolder = 'INBOX.Sent';
    
    $smtpServer = 'smtp.sendgrid.net';
    $smtpPort = '587';
    $smtpSecure = 'tls';  // Use TLS for SendGrid
}

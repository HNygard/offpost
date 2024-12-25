<?php

require '/username-password-smtp.php';

$config['db_dsnw'] = 'mysql://mail:mail@mysql/roundcubemail';
$config['db_dsnr'] = '';
if ($environment == 'development') {
    $config['default_host'] = 'ssl://greenmail:3993';
    $config['default_port'] = '993';
    $config['smtp_server'] = 'greenmail';
    $config['smtp_port'] = '25';
    $config['smtp_user'] = 'user@localhost';
    $config['smtp_pass'] = 'password';
    $config['ssl_verify_peer'] = false;
    $config['ssl_verify_host'] = false;
    $config['imap_conn_options'] = array(
        'ssl' => array(
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        )
    );
    $config['smtp_conn_options'] = array(
        'ssl' => array(
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        )
    );
}
elseif ($environment == 'production') {
    $config['default_host'] = 'ssl://imap.one.com:993/imap/ssl';
    $config['default_port'] = '993';
    $config['smtp_server'] = 'tls://smtp.sendgrid.net';
    $config['smtp_port'] = '587';
    $config['smtp_user'] = $sendgridUsername;
    $config['smtp_pass'] = $sendgridPassword;
}
else {
    echo 'Unknown environment: ' . $environment;
    exit;
}
$config['temp_dir'] = '/tmp/roundcube-temp';
$config['plugins'] = ['archive', 'zipdownload'];
$config['zipdownload_selection'] = true;
$config['log_driver'] = 'stdout';

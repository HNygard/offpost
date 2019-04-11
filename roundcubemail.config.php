<?php

require '/username-password-smtp.php';

$config['db_dsnw'] = 'mysql://mail:mail@mysql/roundcubemail';
$config['db_dsnr'] = '';
$config['default_host'] = 'ssl://imap.one.com:993/imap/ssl';
$config['default_port'] = '993';
$config['smtp_server'] = 'tls://smtp.sendgrid.net';
$config['smtp_port'] = '587';
$config['smtp_user'] = $sendgridUsername;
$config['smtp_pass'] = $sendgridPassword;
$config['temp_dir'] = '/tmp/roundcube-temp';
$config['plugins'] = ['archive', 'zipdownload'];
$config['zipdownload_selection'] = true;
$config['log_driver'] = 'stdout';

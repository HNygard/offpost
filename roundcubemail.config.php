<?php

require '/username-password-smtp.php';

$config['db_dsnw'] = 'mysql://mail:mail@mysql/roundcubemail';
$config['db_dsnr'] = '';
$config['default_host'] = 'ssl://greenmail:993';
$config['default_port'] = '993';
$config['smtp_server'] = 'greenmail';
$config['smtp_port'] = '25';
$config['smtp_user'] = 'user@localhost';
$config['smtp_pass'] = 'password';
$config['temp_dir'] = '/tmp/roundcube-temp';
$config['plugins'] = ['archive', 'zipdownload'];
$config['zipdownload_selection'] = true;
$config['log_driver'] = 'stdout';

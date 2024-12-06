<?php
require_once __DIR__ . '/auth.php';

if (!isset($_GET['code'])) {
    die('No authorization code provided');
}

handleCallback($_GET['code']);

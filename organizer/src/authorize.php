<?php
require_once __DIR__ . '/auth.php';

// Restart login without replacing the original page with this recovery URL.
requireAuth(false);

header('Location: /');
exit;

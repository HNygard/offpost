<?php

require_once __DIR__ . '/../class/common.php';
require_once __DIR__ . '/../error.php';

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

try {
    ob_start();

    require __DIR__ . '/../username-password.php';

    // Define scheduled system pages that can be accessed in development or by cron in production
    $scheduledSystemPages = [
        '/scheduled-email-sending' => '/../system-pages/scheduled-email-sending.php',
        '/scheduled-email-receiver' => '/../system-pages/scheduled-email-receiver.php',
        '/scheduled-email-extraction' => '/../system-pages/scheduled-email-extraction.php',
    ];

    // Define admin-only pages that require authentication
    $adminPages = [
        '/update-identities' => '/../update-identities.php',
        '/email-sending-overview' => '/../system-pages/email-sending-overview.php',
        '/extraction-overview' => '/../system-pages/extraction-overview.php',
        '/update-imap' => '/../update-imap.php', // Temporary while we wait for new imap integration
    ];

    // Check if the path is a scheduled system page
    if (array_key_exists($path, $scheduledSystemPages) && 
        (($environment == 'development') || 
         ($environment == 'production' && $_SERVER['HTTP_HOST'] == 'organizer'))) {
        require __DIR__ . $scheduledSystemPages[$path];
    }
    // Check if the path is an admin page
    elseif (array_key_exists($path, $adminPages)) {
        require_once __DIR__ . '/../auth.php';
        requireAuth();
        if (in_array($_SESSION['user']['sub'], $admins)) {
            require __DIR__ . $adminPages[$path];
        } else {
            throw new Exception("404 Not Found", 404);
        }
    }

    // :: Rest of the pages
    else {
        // Define regular pages
        $regularPages = [
            '/' => '/../index.php',
            '/thread-view' => '/../view-thread.php',
            '/thread-start' => '/../start-thread.php',
            '/thread-classify' => '/../classify-email.php',
            '/api/threads' => '/../api.php',
            '/callback' => '/../callback.php',
            '/logout' => '/../logout.php',
            '/file' => '/../file.php',
            '/entities' => '/../entities.php',
            '/thread-bulk-actions' => '/../thread-bulk-actions.php',
        ];

        if (array_key_exists($path, $regularPages)) {
            require __DIR__ . $regularPages[$path];
        } else {
            throw new Exception("404 Not Found", 404);
        }
    }
} 
catch (Throwable $e) {
    displayErrorPage($e);
}

ob_end_flush();

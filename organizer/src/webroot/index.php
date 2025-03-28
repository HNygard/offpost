<?php

require_once __DIR__ . '/../class/common.php';
require_once __DIR__ . '/../error.php';

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

try {
    ob_start();


    require __DIR__ . '/../username-password.php';

    // :: Development only
    if ($environment == 'development' && $path == '/update-identities') {
        require __DIR__ . '/../update-identities.php';
    }

    // :: System pages (dev or cron in prod)
    elseif ($path == '/scheduled-email-sending'
        && (
            ($environment == 'development')
            || (
                $environment == 'production'
                // Internal name - used by container 'cron'
                && $_SERVER['HTTP_HOST'] == 'organizer'
            )
        )
    ) {
        require __DIR__ . '/../system-pages/scheduled-email-sending.php';
    }
    elseif ($path == '/email-sending-overview') {
        require_once __DIR__ . '/../auth.php';
        requireAuth();
        if (in_array($_SESSION['user']['sub'], $admins)) {
            require __DIR__ . '/../system-pages/email-sending-overview.php';
        }
        else {
            throw new Exception("404 Not Found", 404);
        }
    }
    elseif ($path == '/extraction-overview') {
        require_once __DIR__ . '/../auth.php';
        requireAuth();
        if (in_array($_SESSION['user']['sub'], $admins)) {
            require __DIR__ . '/../system-pages/extraction-overview.php';
        }
        else {
            throw new Exception("404 Not Found", 404);
        }
    }
    // Temporary while we wait for new imap integration
    elseif ($path == '/update-imap') {
        require_once __DIR__ . '/../auth.php';
        requireAuth();
        if (in_array($_SESSION['user']['sub'], $admins)) {
            require __DIR__ . '/../update-imap.php';
        }
        else {
            throw new Exception("404 Not Found", 404);
        }
    }

    // :: Rest of the pages
    else {
        // Explicitly map URLs to PHP files
        switch ($path) {
            case '/':
                require __DIR__ . '/../index.php';
                break;
            case '/thread-view':
                require __DIR__ . '/../view-thread.php';
                break;
            case '/thread-start':
                require __DIR__ . '/../start-thread.php';
                break;
            case '/thread-classify':
                require __DIR__ . '/../classify-email.php';
                break;
            case '/api/threads':
                require __DIR__ . '/../api.php';
                break;
            case '/callback':
                require __DIR__ . '/../callback.php';
                break;
            case '/logout':
                require __DIR__ . '/../logout.php';
                break;
            case '/file':
                require __DIR__ . '/../file.php';
                break;
            case '/entities':
                require __DIR__ . '/../entities.php';
                break;
            case '/thread-bulk-actions':
                require __DIR__ . '/../thread-bulk-actions.php';
                break;
            default:
                throw new Exception("404 Not Found", 404);
        }
    }
} 
catch (Throwable $e) {
    displayErrorPage($e);
}

ob_end_flush();

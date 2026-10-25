<?php
// Send an admin alert from outside the web app, e.g. from the deploy script.
//
// Usage (in the organizer container):
//   php bin/notify-admin.php <source> <message>

require_once __DIR__ . '/../class/AdminNotificationService.php';

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php bin/notify-admin.php <source> <message>\n");
    exit(2);
}

$service = new AdminNotificationService();
if (!$service->notifyAdminOfError($argv[1], $argv[2])) {
    fwrite(STDERR, "Failed to notify admin: " . $service->getLastError() . "\n");
    exit(1);
}
echo "Admin notified.\n";

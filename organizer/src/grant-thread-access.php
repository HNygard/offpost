<?php
require_once __DIR__ . '/class/Database.php';

if ($argc !== 2) {
    echo "Usage: php grant-thread-access.php <user_id>\n";
    exit(1);
}

$userId = $argv[1];
echo "Granting access to all threads for user: $userId\n";

$db = new Database();

// Get all thread IDs
$threads = $db->query("SELECT id FROM threads");
$granted = 0;
$errors = 0;

foreach ($threads as $thread) {
    try {
        // Grant access to each thread
        $db->execute(
            "INSERT INTO thread_authorizations (thread_id, user_id, is_owner) 
             VALUES (?, ?, true) 
             ON CONFLICT (thread_id, user_id) DO UPDATE SET is_owner = true",
            [$thread['id'], $userId]
        );
        $granted++;
    } catch (Exception $e) {
        echo "Error granting access to thread {$thread['id']}: " . $e->getMessage() . "\n";
        $errors++;
    }
}

echo "\nAccess granted to $granted threads for user $userId\n";

if ($errors > 0) {
    echo "Encountered $errors errors while granting access.\n";
    exit(1);
}

exit(0);

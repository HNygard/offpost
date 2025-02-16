<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/class/ThreadStorageManager.php';
require_once __DIR__ . '/class/ThreadLabelFilter.php';

// Require authentication
requireAuth();

if (!isset($_POST['label'])) {
    header('Location: /');
    exit;
}

$label = $_POST['label'];
$storageManager = ThreadStorageManager::getInstance();
$userId = $_SESSION['user']['sub'];

// Get all threads
$allThreads = $storageManager->getThreads($userId);

// Archive threads with matching label
$archivedCount = 0;
foreach ($allThreads as $file => $threads) {
    if (!isset($threads->threads)) {
        continue;
    }

    foreach ($threads->threads as $thread) {
        if (ThreadLabelFilter::matches($thread, $label)) {
            $thread->archived = true;
            $storageManager->updateThread($thread);
            $archivedCount++;
        }
    }
}

// Redirect back with success message
header('Location: /?archived&label_filter=' . urlencode($label));
exit;

<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/class/Threads.php';

// Require authentication
requireAuth();

$debug = true;
function logDebug($text) {
    global $debug;
    if ($debug) {
        echo $text . chr(10);
    }
}


$entityId = $_GET['entityId'];
$threadId = $_GET['threadId'];
$threads = getThreadsForEntity($entityId);

$thread = null;
foreach ($threads->threads as $thread1) {
    if (getThreadId($thread1) == $threadId) {
        $thread = $thread1;
    }
}

foreach($thread->emails as $email) {
    if (!isset($email->attachments)) {
        continue;
    }
    foreach($email->attachments as $att) {
        if (isset($_GET['attachment']) && $att->location == $_GET['attachment']) {
            $att->status_type = 'success';
            $att->status_text = 'Document OK';
        }
    }
}
$thread->archived = true;
echo $threads->entity_id .'<br>';
echo $thread->title .'<br>';


echo '<br>OK.';
saveEntityThreads($entityId, $threads);

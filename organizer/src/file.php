<?php

require_once __DIR__ . '/class/Threads.php';

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
        if ($att->location == $_GET['attachment']) {
            if ($att->filetype == 'pdf') {
                header("Content-type:application/pdf");
            }
            echo getThreadFile($entityId, $thread, $att->location);
            exit;
        }
    }
}

echo $threads->entity_id .'<br>';
echo $thread->title .'<br>';

throw new Exception('404 Not found.');
<?php

if (!isset($_GET['label'])) {
    throw new Exception('No "label" param given.');
}


require_once __DIR__ . '/class/Threads.php';

/* @var Threads[] $threads */
$allThreads = getThreads();

$threadsMatch = array();
foreach ($allThreads as $entityFile => $entityThreads) {
    if (!isset($entityThreads->threads)) {
        throw new Exception('Error in file: ' . $entityFile);
    }

    foreach ($entityThreads->threads as $thread) {
        foreach ($thread->labels as $label) {
            if ($label == $_GET['label']) {

                $thread->entity_id = $entityThreads->entity_id;

                foreach($thread->emails as $emails) {
                    $emails->link = 'http://localhost:25081/file.php?entityId=' . urlencode($entityThreads->entity_id)
                        . '&threadId='. urlencode(getThreadId($thread))
                        . '&body=' . urlencode($emails->id);
                    if (!isset($emails->attachments)) {
                        continue;
                    }
                    foreach($emails->attachments as $att) {
                        $att->link = 'http://localhost:25081/file.php?entityId=' . urlencode($entityThreads->entity_id)
                            . '&threadId='. urlencode(getThreadId($thread))
                            . '&attachment=' . urlencode($att->location);
                        $att->linkSetSuccess = 'http://localhost:25081/setSuccessForThreadAndDocument.php?entityId=' . urlencode($entityThreads->entity_id)
                            . '&threadId='. urlencode(getThreadId($thread))
                            . '&attachment=' . urlencode($att->location);
                    }
                }

                $threadsMatch[] = $thread;
            }
        }
    }
}

$obj = new stdClass();
$obj->matchingThreads = $threadsMatch;

header('Content-Type: application/json');
echo json_encode($obj, JSON_PRETTY_PRINT ^ JSON_UNESCAPED_UNICODE ^ JSON_UNESCAPED_SLASHES);
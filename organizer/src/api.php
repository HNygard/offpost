<?php

require 'vendor/autoload.php';

use Laminas\Mail\Storage\Message;

if (!isset($_GET['label'])) {
    throw new Exception('No "label" param given.');
}


require_once __DIR__ . '/class/Threads.php';
require_once __DIR__ . '/class/ThreadLabelFilter.php';

/* @var Threads[] $threads */
$allThreads = getThreads();

$threadsMatch = array();
foreach ($allThreads as $entityFile => $entityThreads) {
    if (!isset($entityThreads->threads)) {
        throw new Exception('Error in file: ' . $entityFile);
    }

    foreach ($entityThreads->threads as $thread) {
        # Copy object to stdClass
        $thread = json_decode(json_encode($thread));

        $thread->thread_id = $thread->id;
        if (ThreadLabelFilter::matches($thread, $_GET['label'])) {
            $thread->entity_id = $entityThreads->entity_id;

            foreach($thread->emails as $emails) {
                    $emails->link = 'http://localhost:25081/file?entityId=' . urlencode($entityThreads->entity_id)
                        . '&threadId='. urlencode($thread->id)
                        . '&body=' . urlencode($emails->id);


                    $eml = getThreadFile($thread->entity_id, $thread, $emails->id . '.eml');
                    $message = new Message(['raw' => $eml]);
                    $emails->subject = $message->getHeader('subject')->getFieldValue();

                    if (!isset($emails->attachments)) {
                        continue;
                    }
                    foreach($emails->attachments as $att) {
                        $att->link = 'http://localhost:25081/file?entityId=' . urlencode($entityThreads->entity_id)
                            . '&threadId='. urlencode($thread->id)
                            . '&attachment=' . urlencode($att->id);
                        $att->linkText = 'http://localhost:25081/file?entityId=' . urlencode($entityThreads->entity_id)
                            . '&threadId='. urlencode($thread->id)
                            . '&attachment=' . urlencode($att->id)
                            . '&text=true';
                    }
                }

            $threadsMatch[] = $thread;
        }
    }
}

$obj = new stdClass();
$obj->matchingThreads = $threadsMatch;

header('Content-Type: application/json');
echo json_encode($obj, JSON_PRETTY_PRINT ^ JSON_UNESCAPED_UNICODE ^ JSON_UNESCAPED_SLASHES);

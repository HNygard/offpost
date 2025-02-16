<?php

require_once __DIR__ . '/auth.php';
require 'vendor/autoload.php';
require_once __DIR__ . '/class/ThreadStorageManager.php';
require_once __DIR__ . '/class/ThreadAuthorization.php';

// Require authentication
requireAuth();

use Laminas\Mail\Storage\Message;

require_once __DIR__ . '/class/Threads.php';

if (!isset($_GET['entityId']) || !isset($_GET['threadId'])) {
    throw new Exception("Missing required parameters: entityId and threadId", 400);
}

$entityId = $_GET['entityId'];
$threadId = $_GET['threadId'];

if (!is_uuid($threadId)) {
    throw new Exception("Invalid threadId parameter", 400);
}

$threads = ThreadStorageManager::getInstance()->getThreadsForEntity($entityId);

$thread = null;
foreach ($threads->threads as $thread1) {
    if ($thread1->id == $threadId) {
        $thread = $thread1;
    }
}

if (!$thread) {
    throw new Exception("Thread not found: threadId={$threadId}, entityId={$entityId}", 404);
}

if (!ThreadAuthorizationManager::canUserAccessThread($threadId, $_SESSION['user']['sub'])) {
    throw new Exception("Unauthorized access to thread: {$threadId}", 403);
}

foreach ($thread->emails as $email) {
    if (isset($_GET['body']) && $_GET['body'] == $email->id) {
        $eml = ThreadStorageManager::getInstance()->getThreadFile($entityId, $thread, $email->id_old . '.eml'); 
        $message = new Message(['raw' => $eml]);

        switch ($message->getHeaders()->getEncoding()) {
            case 'ASCII':
                $htmlConvert = function ($html) {
                    return mb_convert_encoding($html, 'UTF-8', 'ISO-8859-1');
                };
                break;
            default:
                echo 'Unknown encoding: ' . $message->getHeaders()->getEncoding();
                exit;
        }
        //header('Content-Type: text/html; charset='. $message->getHeaders()->getEncoding());

        $message = new Message(['raw' => $eml]);

        $email_content = json_decode(ThreadStorageManager::getInstance()->getThreadFile($entityId, $thread, $email->id_old . '.json'));
        echo '<h1 id="email-subject">Subject: ' . htmlescape($message->getHeader('subject')->getFieldValue()) . '</h1>' . chr(10);
        echo '<b>Date: ' . $email_content->date . '</b><br>' . chr(10);
        //echo '<b>Sender: ' . $email_content->senderAddress . '</b><br>'.chr(10);

        // Access the plain text content
        if ($message->isMultipart()) {
            $plainTextPart = false;
            $htmlPart = false;

            foreach (new RecursiveIteratorIterator($message) as $part) {
                if (strtok($part->contentType, ';') == 'text/plain') {
                    $plainTextPart = $part;
                }
                if (strtok($part->contentType, ';') == 'text/html') {
                    $htmlPart = $part;
                }
            }

            $plainText = $plainTextPart ? $plainTextPart->getContent() : '';
            $html = $htmlPart ? $htmlPart->getContent() : '';

            echo '<b>Plain text version:</b><br>' . chr(10);
            echo '<pre>' . $htmlConvert(base64_decode($plainText)) . '</pre><br><br>' . chr(10) . chr(10);

            echo 'HTML version:<br>' . chr(10);
            echo $htmlConvert(base64_decode($html)) . '<br><br>' . chr(10) . chr(10);
            //unset($email_content->body);
        }
        else {
            // If the message is not multipart, simply echo the content
            echo '<pre>' . $htmlConvert(imap_qprint($message->getContent())) . '</pre>';
        }

        unset($email_content->subject);
        unset($email_content->date);
        unset($email_content->body);
        unset($email_content->attachments);
        unset($email_content->attachements);
        unset($email_content->timestamp);
        echo '<pre>';
        echo json_encode($email_content, JSON_PRETTY_PRINT ^ JSON_UNESCAPED_UNICODE ^ JSON_UNESCAPED_SLASHES);
        exit;
    }

    if (!isset($email->attachments)) {
        continue;
    }
    if (isset($_GET['attachment'])) {
        foreach ($email->attachments as $att) {
            if ($att->location == $_GET['attachment']) {
                if ($att->filetype == 'pdf') {
                    header("Content-type:application/pdf");
                }
                echo ThreadStorageManager::getInstance()->getThreadFile($entityId, $thread, $att->location);
                exit;
            }
        }
    }
}


// If we got here, neither body nor attachment was found
throw new Exception("Requested content not found in thread: threadId={$threadId}, entityId={$entityId}", 404);

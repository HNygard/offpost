<?php

require_once __DIR__ . '/auth.php';
require 'vendor/autoload.php';

// Require authentication
requireAuth();

use Laminas\Mail\Storage\Message;

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

foreach ($thread->emails as $email) {
    if (isset($_GET['body']) && $_GET['body'] == $email->id) {
        $eml = getThreadFile($entityId, $thread, $email->id . '.eml');
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

        $email_content = json_decode(getThreadFile($entityId, $thread, $email->id . '.json'));
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
            echo '<pre>' . $htmlConvert(imap_qprint($plainText)) . '</pre><br><br>' . chr(10) . chr(10);

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
                echo getThreadFile($entityId, $thread, $att->location);
                exit;
            }
        }
    }
}

echo $threads->entity_id . '<br>';
echo $thread->title . '<br>';

throw new Exception('404 Not found.');

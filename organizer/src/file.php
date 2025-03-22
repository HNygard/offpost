<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/class/ThreadStorageManager.php';
require_once __DIR__ . '/class/ThreadAuthorization.php';

// Require authentication
requireAuth();

use Laminas\Mail\Storage\Message;

require_once __DIR__ . '/class/Threads.php';

if (!isset($_GET['entityId']) || !isset($_GET['threadId'])) {
    http_response_code(400);
    header('Content-Type: text/plain');
    die("Missing required parameters: entityId and threadId");
}

$entityId = $_GET['entityId'];
$threadId = $_GET['threadId'];

if (!is_uuid($threadId)) {
    http_response_code(400);
    header('Content-Type: text/plain');
    die("Invalid threadId parameter");
}

$threads = ThreadStorageManager::getInstance()->getThreadsForEntity($entityId);

$thread = null;
foreach ($threads->threads as $thread1) {
    if ($thread1->id == $threadId) {
        $thread = $thread1;
    }
}

if (!$thread) {
    http_response_code(404);
    header('Content-Type: text/plain');
    die("Thread not found: threadId={$threadId}, entityId=" . htmlescape($entityId) ."");
}

if (!ThreadAuthorizationManager::canUserAccessThread($threadId, $_SESSION['user']['sub'])) {
    http_response_code(403);
    header('Content-Type: text/plain');
    throw new Exception("Unauthorized access to thread: {$threadId}");
}

foreach ($thread->emails as $email) {
    if (isset($_GET['body']) && $_GET['body'] == $email->id) {
        $eml =  ThreadStorageManager::getInstance()->getThreadEmailContent($entityId, $thread, $email->id); 
        $message = new Message(['raw' => $eml]);

        $htmlConvert = function ($html, $charset) {
            switch (strtoupper($charset)) {
                case 'UTF-8':
                    return $html;
                case 'ASCII':
                case 'ISO-8859-1':
                    return mb_convert_encoding($html, 'UTF-8', 'ISO-8859-1');
                default:
                    throw new Exception(message: 'Unknown encoding: ' . $charset);
            }
        };
        header('Content-Type: text/html; charset=UTF-8');

        $message = new Message(['raw' => $eml]);

        $email_content = $email;
        try {
            $subject = $message->getHeader('subject')->getFieldValue();
        }
        catch (Exception $e) {
            $subject = 'Error getting subject - ' . $e->getMessage();
        }
        echo '<h1 id="email-subject">Subject: ' . htmlescape($subject) . '</h1>' . chr(10);
        echo '<b>Date: ' . $email_content->datetime_received . '</b><br>' . chr(10);
        //echo '<b>Sender: ' . $email_content->senderAddress . '</b><br>'.chr(10);

        // Access the plain text content
        echo '<pre>';
        echo '-------------------' . chr(10);
        echo "EMAIL BODY CONTENT:\n";
        echo '</pre>';
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
            echo '<pre>' . $htmlConvert(base64_decode($plainText), $message->getHeaders()->getEncoding()) . '</pre><br><br>' . chr(10) . chr(10);

            echo 'HTML version:<br>' . chr(10);
            echo $htmlConvert(base64_decode($html), $message->getHeaders()->getEncoding()) . '<br><br>' . chr(10) . chr(10);
        }
        else {
            // If the message is not multipart, simply echo the content

            $charset = $message->getHeaders()->getEncoding();
            if ($message->getHeaders()->get('content-type') !== false) {
                // Example:
                // Content-Type: text/plain;
                //  charset="UTF-8";
                //  format="flowed"
                $content_type = $message->getHeaders()->get('content-type')->getFieldValue();
                preg_match('/charset=["\']?([\w-]+)["\']?/i', $content_type, $matches);
                if (isset($matches[1])) {
                    $charset = $matches[1];
                }
            }
            
            echo '<pre>' . $htmlConvert($message->getContent(), $charset) . '</pre>';
        }

        echo '<pre>';
        echo '-------------------' . chr(10);
        echo "EMAIL HEADERS (RAW):\n";
        echo $message->getHeaders()->toString();
        echo '</pre>';
        exit;
    }

    if (!isset($email->attachments)) {
        continue;
    }
    if (isset($_GET['attachment'])) {
        foreach ($email->attachments as $att) {
            /* @var $att ThreadEmailAttachment */
            if ($att->location == $_GET['attachment']) {
                if (str_contains($att->location, '/')) {
                    // Strip last part of location to get the filename
                    $filename = substr($att->location, strrpos($att->location, '/') + 1);
                }
                else {
                    // New format of location
                    $filename = $att->location;
                }
                $att = ThreadStorageManager::getInstance()->getThreadEmailAttachment($thread, $att->location);
                if (empty($att->content)) {
                    throw new Exception("Attachment content empty: threadId={$threadId}, entityId={$entityId}, attachment={$att->location}", 404);
                }

                if ($att->filetype == 'pdf') {
                    header("Content-type:application/pdf");
                }
                echo $att->content;
                exit;
            }
        }
    }
}


// If we got here, neither body nor attachment was found
throw new Exception("Requested content not found in thread: threadId={$threadId}, entityId={$entityId}", 404);

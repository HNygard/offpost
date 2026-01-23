<?php

use Laminas\Mime\Decode;
use Laminas\Mime\Mime;

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/class/ThreadStorageManager.php';
require_once __DIR__ . '/class/ThreadAuthorization.php';
require_once __DIR__ . '/class/Extraction/ThreadEmailExtractorEmailBody.php';
require_once __DIR__ . '/class/Imap/ImapEmail.php';

// Require authentication
requireAuth();

require_once __DIR__ . '/class/Threads.php';

if (!isset($_GET['threadId'])) {
    http_response_code(400);
    header('Content-Type: text/plain');
    die("Missing required parameter: threadId");
}

$threadId = $_GET['threadId'];

if (!is_uuid($threadId)) {
    http_response_code(400);
    header('Content-Type: text/plain');
    die("Invalid threadId parameter");
}

$thread = Thread::loadFromDatabaseOrNone($threadId);
if (!$thread) {
    http_response_code(404);
    header('Content-Type: text/plain');
    die("Thread not found: threadId={$threadId}");
}

if (!ThreadAuthorizationManager::canUserAccessThread($threadId, $_SESSION['user']['sub'])) {
    http_response_code(403);
    header('Content-Type: text/plain');
    throw new Exception("Unauthorized access to thread: {$threadId}");
}

foreach ($thread->emails as $email) {
    if (isset($_GET['body']) && $_GET['body'] == $email->id) {
        $emailData = ThreadStorageManager::getInstance()->getThreadEmailContentWithTimestamp($thread->id, $email->id);
        if (!$emailData) {
            http_response_code(404);
            header('Content-Type: text/plain');
            die("Email content not found");
        }
        
        $eml = $emailData['content'];
        $lastModified = strtotime($emailData['timestamp']);
        
        // Set Last-Modified header
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModified) . ' GMT');
        
        // Set Cache-Control header
        header('Cache-Control: private, max-age=3600');
        
        // Check If-Modified-Since header
        if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
            $ifModifiedSince = strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']);
            if ($ifModifiedSince >= $lastModified) {
                http_response_code(304);
                exit;
            }
        }

        header('Content-Type: text/html; charset=UTF-8');

        // Set Content-Security-Policy header to prevent XSS somewhat
        header("Content-Security-Policy: default-src 'none';   script-src 'self';   style-src 'self';   img-src 'self' data:;   frame-src 'none';   object-src 'none';   base-uri 'none';   form-action 'none';");

        $email_content = ThreadEmailExtractorEmailBody::extractContentFromEmail($eml);

        $subject = Imap\ImapEmail::getEmailSubject($eml);
        echo '<h1 id="email-subject">Subject: ' . htmlescape($subject) . '</h1>' . chr(10);
        // Convert datetime to local timezone (Europe/Oslo)
        $utcDateTime = new DateTime($email->datetime_received);
        $utcDateTime->setTimezone(new DateTimeZone('Europe/Oslo'));
        $localDateTime = $utcDateTime->format('Y-m-d H:i:s');
        
        echo '<b>Date: ' . $localDateTime . '</b><br>' . chr(10);
        //echo '<b>Sender: ' . $email->senderAddress . '</b><br>'.chr(10);

        // Access the plain text content
        echo '<pre>';
        echo '-------------------' . chr(10);
        echo "EMAIL BODY CONTENT:\n";
        echo '</pre>';

        if (!empty($email_content->plain_text) && !empty($email_content->html)) {

            echo '<b>Plain text version:</b><br>' . chr(10);
            echo '<pre>' . $email_content->plain_text . '</pre><br><br>' . chr(10) . chr(10);

            echo 'HTML version:<br>' . chr(10);
            echo $email_content->html . '<br><br>' . chr(10) . chr(10);
        }
        elseif(!empty($email_content->plain_text)) {
            echo '<pre>' . $email_content->plain_text . '</pre>';
        }
        elseif(!empty($email_content->html)) {
            echo '<pre>' . $email_content->html . '</pre>';
        }
        
        echo '<pre>';
        echo '-------------------' . chr(10);
        echo "EMAIL HEADERS (RAW):\n";
        $message = ThreadEmailExtractorEmailBody::readLaminasMessage_withErrorHandling($eml);
        echo htmlescape($message->getHeaders()->toString());
        echo '</pre>';
        exit;
    }

    if (!isset($email->attachments)) {
        continue;
    }
    if (isset($_GET['attachmentId'])) {
        foreach ($email->attachments as $att) {
            /* @var $att ThreadEmailAttachment */
            if ($att->id == $_GET['attachmentId']) {
                if (str_contains($att->location, '/')) {
                    // Strip last part of location to get the filename
                    $filename = substr($att->location, strrpos($att->location, '/') + 1);
                }
                else {
                    // New format of location
                    $filename = $att->location;
                }
                $attachmentData = ThreadStorageManager::getInstance()->getThreadEmailAttachmentWithTimestamp($thread, $att->location);
                $att = $attachmentData['attachment'];
                $lastModified = strtotime($attachmentData['timestamp']);
                
                if (empty($att->content)) {
                    throw new Exception("Attachment content empty: threadId={$threadId}, attachmentId={$att->attachment_id}", 404);
                }

                // Set Last-Modified header
                header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModified) . ' GMT');
                
                // Set Cache-Control header
                header('Cache-Control: private, max-age=31536000'); // 1 year for attachments
                
                // Check If-Modified-Since header
                if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
                    $ifModifiedSince = strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']);
                    if ($ifModifiedSince >= $lastModified) {
                        http_response_code(304);
                        exit;
                    }
                }

                if ($att->filetype == 'pdf') {
                    header("Content-type:application/pdf");
                }
                elseif($att->filetype == 'png') {
                    header("Content-type:image/png");
                }
                elseif($att->filetype == 'jpg' || $att->filetype == 'jpeg') {
                    header("Content-type:image/jpeg");
                }
                elseif($att->filetype == 'gif') {
                    header("Content-type:image/gif");
                }
                elseif($att->filetype == 'txt') {
                    header("Content-type:text/plain");
                }
                else {
                    header("Content-type:application/octet-stream");
                }
                echo $att->content;
                exit;
            }
        }
    }
}


// If we got here, neither body nor attachment was found
throw new Exception("Requested content not found in thread: threadId={$threadId}", 404);

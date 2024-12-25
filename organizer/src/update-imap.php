<?php

require_once __DIR__ . '/auth.php';

set_time_limit(0);
ini_set('memory_limit', '-1');

// Require authentication
requireAuth();

require_once __DIR__ . '/class/Imap/ImapWrapper.php';
require_once __DIR__ . '/class/Imap/ImapConnection.php';
require_once __DIR__ . '/class/Imap/ImapFolderManager.php';
require_once __DIR__ . '/class/Imap/ImapEmailProcessor.php';
require_once __DIR__ . '/class/Imap/ImapAttachmentHandler.php';
require_once __DIR__ . '/class/Threads.php';

use Imap\ImapConnection;
use Imap\ImapFolderManager;
use Imap\ImapEmailProcessor;
use Imap\ImapAttachmentHandler;

// Load IMAP credentials
require_once __DIR__ . '/username-password-imap.php';

echo '<pre>';

// Initialize IMAP connection
$server = '{imap.one.com:993/imap/ssl}';
$connection = new ImapConnection($server, $yourEmail, $yourEmailPassword, true);
$connection->openConnection();

// Initialize managers
$folderManager = new ImapFolderManager($connection);
$emailProcessor = new ImapEmailProcessor($connection);
$attachmentHandler = new ImapAttachmentHandler($connection);

// Initialize folders
$connection->logDebug('---- EXISTING FOLDERS ----');
$folderManager->initialize();

// Get required folders from threads
$connection->logDebug('---- CREATING FOLDERS ----');
$threads = getThreads();
$requiredFolders = ['INBOX.Archive'];

foreach ($threads as $entityThreads) {
    foreach ($entityThreads->threads as $thread) {
        $requiredFolders[] = getThreadEmailFolder($entityThreads, $thread);
    }
}

// Create and subscribe to required folders
$folderManager->createThreadFolders($requiredFolders);

// Process email moving
$connection->logDebug('---- ARCHIVING FOLDERS ----');
$emailToFolder = [];

foreach ($threads as $entityThreads) {
    foreach ($entityThreads->threads as $thread) {
        $title = str_replace('INBOX.Archive.', '', getThreadEmailFolder($entityThreads, $thread));
        
        if (!$thread->archived && $thread->my_email != 'dmarc@offpost.no') {
            $emailToFolder[$thread->my_email] = getThreadEmailFolder($entityThreads, $thread);
        }
        
        if ($thread->archived) {
            $inboxFolder = 'INBOX.' . str_replace('INBOX.Archive.', '', $title);
            if (in_array($inboxFolder, $folderManager->getExistingFolders())) {
                $connection->logDebug("Archiving folder: $title");
                $folderManager->archiveFolder($inboxFolder);
            }
        }
    }
}

// Process emails in INBOX and INBOX.Sent
$connection->logDebug('---- SEARCH EMAILS ----');

$connection->logDebug('-- INBOX');
processMailbox($connection, $folderManager, $emailProcessor, 'INBOX', $emailToFolder);
$connection->closeConnection(CL_EXPUNGE);

$connection->logDebug('-- INBOX.Sent');
$connection = new ImapConnection($server, $yourEmail, $yourEmailPassword, true);
$connection->openConnection('INBOX.Sent');
processMailbox($connection, $folderManager, $emailProcessor, 'INBOX.Sent', $emailToFolder);
$connection->closeConnection(CL_EXPUNGE);

// Process thread folders
$connection->logDebug('---- SAVE EMAILS ----');

foreach ($threads as $threadFile => $entityThreads) {
    foreach ($entityThreads->threads as $thread) {
        $folder = getThreadEmailFolder($entityThreads, $thread);
        $connection->logDebug("-- $folder");
        
        $folderJson = '/organizer-data/threads/' . $entityThreads->entity_id . '/' . getThreadId($thread);
        if (!file_exists($folderJson)) {
            mkdir($folderJson, 0777, true);
        }
        $connection->logDebug("   Folder ... : $folderJson");

        // Skip if already archived
        if (file_exists($folderJson . '/archiving_finished.json')) {
            if (!$thread->archived) {
                unlink($folderJson . '/archiving_finished.json');
            } else {
                continue;
            }
        }

        // Check if folder needs update
        if (isset($_GET['update-only-before']) && 
            !$emailProcessor->needsUpdate($folderJson, $_GET['update-only-before'])) {
            continue;
        }

        try {
            $connection = new ImapConnection($server, $yourEmail, $yourEmailPassword, true);
            $connection->openConnection($folder);
            
            saveThreadEmails(
                $connection,
                $emailProcessor,
                $attachmentHandler,
                $folderJson,
                $thread,
                $folder
            );

            if ($thread->archived) {
                $connection->logDebug('Archiving finished.');
                file_put_contents(
                    $folderJson . '/archiving_finished.json',
                    '{"date": "' . date('Y-m-d H:i:s') . '"}'
                );
            }

            $emailProcessor->updateFolderCache($folderJson);
            
        } catch(Exception $e) {
            $connection->logDebug('ERROR during saveThreadEmails().');
            $connection->logDebug($e->getMessage());
            $connection->logDebug($e->getTraceAsString());
            throw $e;
        }

        $connection->logDebug('');
    }
    saveEntityThreads($entityThreads->entity_id, $entityThreads);
}

/**
 * Process emails in a mailbox
 */
function processMailbox(
    ImapConnection $connection,
    ImapFolderManager $folderManager,
    ImapEmailProcessor $emailProcessor,
    string $mailbox,
    array $emailToFolder
): void {
    $emails = $emailProcessor->processEmails($mailbox);
    
    foreach ($emails as $email) {
        $addresses = $emailProcessor->getEmailAddresses($email->mailHeaders);
        $targetFolder = 'INBOX';
        
        foreach ($addresses as $address) {
            if (isset($emailToFolder[$address])) {
                $connection->logDebug('FOUND : ' . $emailToFolder[$address]);
                $targetFolder = $emailToFolder[$address];
            }
        }
        
        if ($targetFolder === 'INBOX') {
            foreach ($addresses as $address) {
                echo '- <a href="start-thread.php?my_email=' . urlencode($address) . 
                     '">Start thread with ' . htmlspecialchars($address) . '</a>' . PHP_EOL;
            }
        }
        
        $folderManager->moveEmail($email->uid, $targetFolder);
    }
}

/**
 * Save emails for a thread
 */
function saveThreadEmails(
    ImapConnection $connection,
    ImapEmailProcessor $emailProcessor,
    ImapAttachmentHandler $attachmentHandler,
    string $folderJson,
    object $thread,
    string $folder
): void {
    $emails = $emailProcessor->processEmails($folder);
    
    foreach ($emails as $email) {
        $direction = $emailProcessor->getEmailDirection($email->mailHeaders, $thread->my_email);
        $filename = $emailProcessor->generateEmailFilename($email->mailHeaders, $thread->my_email);
        
        // Save raw email
        $emailRawFile = $folderJson . '/' . $filename . '.eml';
        if (!file_exists($emailRawFile)) {
            file_put_contents($emailRawFile, imap_fetchbody($connection->getConnection(), $email->uid, "", FT_UID));
        }
        
        // Save email metadata
        $emailJsonFile = $folderJson . '/' . $filename . '.json';
        if (!file_exists($emailJsonFile)) {
            // Process attachments
            $attachments = $attachmentHandler->processAttachments($email->uid);
            foreach ($attachments as $i => $attachment) {
                $attachment->location = $filename . ' - att ' . $i . '-' . md5($attachment->name) . 
                                     '.' . $attachment->filetype;
                
                $attachmentPath = $folderJson . '/' . $attachment->location;
                if (!file_exists($attachmentPath)) {
                    $attachmentHandler->saveAttachment($email->uid, $i + 1, $attachment, $attachmentPath);
                }
            }
            
            $email->attachments = $attachments;
            file_put_contents($emailJsonFile, json_encode($email, 
                JSON_PRETTY_PRINT ^ JSON_UNESCAPED_SLASHES ^ JSON_UNESCAPED_UNICODE));
        }
        
        // Update thread email list
        if (!isset($thread->emails)) {
            $thread->emails = [];
        }
        
        if (!emailExistsInThread($thread, $filename)) {
            $newEmail = new \stdClass();
            $newEmail->timestamp_received = $email->timestamp;
            $newEmail->datetime_received = date('Y-m-d H:i:s', $newEmail->timestamp_received);
            $newEmail->datetime_first_seen = date('Y-m-d H:i:s');
            $newEmail->id = $filename;
            $newEmail->email_type = $direction;
            $newEmail->status_type = 'unknown';
            $newEmail->status_text = 'Uklassifisert';
            $newEmail->ignore = false;
            
            if (!empty($email->attachments)) {
                $newEmail->attachments = array_map(function($att) {
                    $att->status_type = 'unknown';
                    $att->status_text = 'uklassifisert-dok';
                    return $att;
                }, $email->attachments);
            }
            
            $thread->emails[] = $newEmail;
            usort($thread->emails, function($a, $b) {
                return strcmp($a->datetime_received, $b->datetime_received);
            });
            
            if (!in_array('uklassifisert-epost', $thread->labels)) {
                $thread->labels[] = 'uklassifisert-epost';
            }
        }
    }
}

/**
 * Check if email exists in thread
 */
function emailExistsInThread(object $thread, string $emailId): bool {
    foreach ($thread->emails as $email) {
        if ($email->id === $emailId) {
            return true;
        }
    }
    return false;
}

/**
 * Get thread email folder
 */
function getThreadEmailFolder($entityThreads, $thread): string {
    $title = $entityThreads->title_prefix . ' - ' . str_replace('/', '-', $thread->title);
    $title = str_replace(['Æ', 'Ø', 'Å', 'æ', 'ø', 'å'],
                        ['AE', 'OE', 'AA', 'ae', 'oe', 'aa'],
                        $title);
    
    return $thread->archived ? 'INBOX.Archive.' . $title : 'INBOX.' . $title;
}

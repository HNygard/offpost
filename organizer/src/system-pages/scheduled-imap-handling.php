<?php

use Imap\ImapConnection;
use Imap\ImapEmailProcessor;
use Imap\ImapFolderManager;

require_once __DIR__ . '/../class/Imap/ImapConnection.php';
require_once __DIR__ . '/../class/Imap/ImapFolderManager.php';
require_once __DIR__ . '/../class/Imap/ImapEmailProcessor.php';
require_once __DIR__ . '/../class/ThreadStorageManager.php';


// Set up error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set up time and memory limits
set_time_limit(0);
ini_set('memory_limit', '768M');

require_once __DIR__ . '/../username-password.php';
require_once __DIR__ . '/../update-imap-functions.php';

header('Content-Type: text/plain; charset=utf-8');

// Initialize IMAP connection and components
$connection = new ImapConnection($imapServer, $imap_username, $imap_password, true);
$connection->openConnection();

// Get all threads
$threads = ThreadStorageManager::getInstance()->getThreads();

$folderManager = new ImapFolderManager($connection);
$folderManager->initialize();

$emailProcessor = new ImapEmailProcessor($connection);

// Same as the task https://offpost.no/update-imap?task=create-folders:
createFolders($connection, $folderManager, $threads);

// Same as the task https://offpost.no/update-imap?task=process-sent:
processSentFolder($connection, $folderManager, $emailProcessor, $threads, $imapSentFolder);

// Same as the task https://offpost.no/update-imap?task=process-inbox:
processInbox($connection, $folderManager, $emailProcessor, $threads);

// Finally, expunge to remove any deleted emails
$connection->closeConnection(CL_EXPUNGE);

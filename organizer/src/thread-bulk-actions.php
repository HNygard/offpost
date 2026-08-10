<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/class/Database.php';
require_once __DIR__ . '/class/ThreadStorageManager.php';
require_once __DIR__ . '/class/ThreadHistory.php';
require_once __DIR__ . '/class/ThreadEmailSending.php';

/**
 * Read a thread ID out of a submitted thread reference.
 *
 * Threads are identified by ID alone. Pages used to submit "entityId:threadId", so an
 * entity prefix is still accepted to keep an already-loaded page working.
 */
function parseThreadReference($threadInfo) {
    $separator = strrpos($threadInfo, ':');
    if ($separator === false) {
        return $threadInfo;
    }
    return substr($threadInfo, $separator + 1);
}

/**
 * Explain why a thread ID could not be resolved to a thread the user may act on.
 *
 * getThreads() only returns threads the user can access, so an ID missing from that list
 * either does not exist or is one the user may not touch. Telling those apart is the
 * difference between an admin chasing a bad link and an admin requesting access.
 *
 * @return array{reason: string, title: ?string}
 */
function describeUnavailableThread($threadId) {
    // threads.id is a uuid column, so compare as text to tolerate malformed input
    // queryOneOrNone, not queryOne: a missing thread is the expected case here, not an error
    $row = Database::queryOneOrNone(
        "SELECT title FROM threads WHERE id::text = ?",
        [$threadId]
    );

    if (empty($row)) {
        return ['reason' => 'No thread exists with this ID', 'title' => null];
    }

    return [
        'reason' => 'You do not have access to this thread (not public, no authorization)',
        'title' => $row['title']
    ];
}

// Require authentication
requireAuth();

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /');
    exit;
}

// Validate required parameters
if (!isset($_POST['action']) || !isset($_POST['thread_ids']) || !is_array($_POST['thread_ids']) || empty($_POST['thread_ids'])) {
    $_SESSION['error_message'] = 'Invalid request: Missing required parameters';
    header('Location: /');
    exit;
}

$action = $_POST['action'];
$threadIds = $_POST['thread_ids'];
$userId = $_SESSION['user']['sub']; // OpenID Connect subject identifier
$storageManager = ThreadStorageManager::getInstance();
$allThreads = $storageManager->getThreads($userId);
$processedCount = 0;
$errors = array();

// Process each thread
foreach ($threadIds as $threadInfo) {
    $threadId = parseThreadReference($threadInfo);

    // Find the thread
    $thread = null;
    foreach ($allThreads as $file => $threads) {
        foreach ($threads->threads as $t) {
            if ($t->id === $threadId) {
                $thread = $t;
                break 2;
            }
        }
    }

    // Skip if thread not found or user not authorized, explaining which of the two it was
    if (!$thread || !$thread->canUserAccess($userId)) {
        $explanation = describeUnavailableThread($threadId);
        $errors[] = [
            'ref' => $threadId,
            'title' => $explanation['title'],
            'reason' => $explanation['reason']
        ];
        continue;
    }

    // Apply the selected action. A failing update must not abort the whole batch.
    try {
        switch ($action) {
            case 'archive':
                $thread->archived = true;
                $storageManager->updateThread($thread, $userId);
                $processedCount++;
                break;

            case 'unarchive':
                $thread->archived = false;
                $storageManager->updateThread($thread, $userId);
                $processedCount++;
                break;

            case 'ready_for_sending':
                if ($thread->sending_status === Thread::SENDING_STATUS_STAGING) {
                    $thread->sending_status = Thread::SENDING_STATUS_READY_FOR_SENDING;
                    $storageManager->updateThread($thread, $userId);

                    // Also update the corresponding ThreadEmailSending records
                    $emailSendings = ThreadEmailSending::getByThreadId($thread->id);
                    foreach ($emailSendings as $emailSending) {
                        if ($emailSending->status === ThreadEmailSending::STATUS_STAGING) {
                            ThreadEmailSending::updateStatus(
                                $emailSending->id,
                                ThreadEmailSending::STATUS_READY_FOR_SENDING
                            );
                        }
                    }
                    $processedCount++;
                } else {
                    $errors[] = [
                        'ref' => $threadId,
                        'title' => $thread->title,
                        'reason' => 'Cannot mark as ready for sending: status is '
                            . $thread->sending_status . ', expected ' . Thread::SENDING_STATUS_STAGING
                    ];
                }
                break;

            case 'make_private':
                if ($thread->public) {
                    $thread->public = false;
                    $storageManager->updateThread($thread, $userId);
                    $processedCount++;
                } else {
                    // Already private, count as processed
                    $processedCount++;
                }
                break;

            case 'make_public':
                if (!$thread->public) {
                    $thread->public = true;
                    $storageManager->updateThread($thread, $userId);
                    $processedCount++;
                } else {
                    // Already public, count as processed
                    $processedCount++;
                }
                break;

            default:
                $errors[] = [
                    'ref' => $threadId,
                    'title' => $thread->title,
                    'reason' => 'Unknown bulk action "' . $action . '"'
                ];
                break;
        }
    }
    catch (Exception $e) {
        $errors[] = [
            'ref' => $threadId,
            'title' => $thread->title,
            'reason' => 'Update failed: ' . $e->getMessage()
        ];
    }
}

// Set success/error messages
if ($processedCount > 0) {
    $_SESSION['success_message'] = "Successfully processed $processedCount thread(s)";
}

if (count($errors) > 0) {
    // One line per failure, so an admin can see which thread failed and why
    $errorLines = array();
    foreach ($errors as $error) {
        $label = $error['ref'];
        if (!empty($error['title'])) {
            $label .= ' (' . $error['title'] . ')';
        }
        $errorLines[] = "\u{2022} " . $label . " \u{2014} " . $error['reason'];
    }

    $_SESSION['error_message'] = 'Failed to process ' . count($errors) . " thread(s):\n"
        . implode("\n", $errorLines);
}

// Redirect back to the appropriate page
// If only one thread was processed, redirect back to thread view
if ($processedCount === 1 && count($threadIds) === 1) {
    $threadId = parseThreadReference($threadIds[0]);

    // Security: Validate that the thread actually exists and user has access
    // This prevents open redirect attacks by ensuring we only redirect to valid threads
    $thread = null;
    foreach ($allThreads as $file => $threads) {
        foreach ($threads->threads as $t) {
            if ($t->id === $threadId && $t->canUserAccess($userId)) {
                $thread = $t;
                break 2;
            }
        }
    }

    // Only redirect if thread exists and user has access
    if ($thread) {
        header("Location: /thread-view?threadId=" . urlencode($threadId));
        exit;
    }
}

// Otherwise redirect to the thread listing
header('Location: /');
exit;

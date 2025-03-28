<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/class/Threads.php';
require_once __DIR__ . '/class/ThreadEmailClassifier.php';
require_once __DIR__ . '/class/ThreadStorageManager.php';
require_once __DIR__ . '/class/ThreadHistory.php';
require_once __DIR__ . '/class/ThreadEmailSending.php';

// Require authentication
requireAuth();

// Get thread ID and entity ID from URL parameters
$threadId = isset($_GET['threadId']) ? $_GET['threadId'] : null;
$entityId = isset($_GET['entityId']) ? $_GET['entityId'] : null;
$userId = $_SESSION['user']['sub']; // OpenID Connect subject identifier

if (!$threadId || !$entityId) {
    http_response_code(400);
    header('Content-Type: text/plain');
    die('Thread ID and Entity ID are required');
}

$storageManager = ThreadStorageManager::getInstance();
$allThreads = $storageManager->getThreads();

$thread = null;
$threadEntity = null;

// Find the specific thread
/* @var Threads[] $threads */
foreach ($allThreads as $file => $threads) {
    if ($threads->entity_id === $entityId) {
        foreach ($threads->threads as $t) {
            if ($t->id === $threadId) {
                $thread = $t;
                $threadEntity = $threads;
                break 2;
            }
        }
    }
}

if (!$thread) {
    die('Thread not found');
}

// Handle public toggle
if (isset($_POST['toggle_public_to']) && isset($_POST['thread_id'])) {
    $toggleThread = null;
    foreach ($allThreads as $threads) {
        foreach ($threads->threads as $t) {
            if ($t->id === $_POST['thread_id']) {
                $toggleThread = $t;
                break 2;
            }
        }
    }
    
    if ($toggleThread && $toggleThread->isUserOwner($userId)) {
        $toggleThread->public = $_POST['toggle_public_to'] === '1';
        $storageManager->updateThread($toggleThread, $userId);
    }
    
    // Redirect to remove POST data
    header("Location: /thread-view?threadId=" . urlencode($toggleThread->id) . "&entityId=" . urlencode($entityId));
    exit;
}

// Handle sending status change from STAGING to READY_FOR_SENDING
if (isset($_POST['change_status_to_ready']) && isset($_POST['thread_id'])) {
    $statusThread = null;
    foreach ($allThreads as $threads) {
        foreach ($threads->threads as $t) {
            if ($t->id === $_POST['thread_id']) {
                $statusThread = $t;
                break 2;
            }
        }
    }
    
    if ($statusThread 
        && $statusThread->isUserOwner($userId) 
        && $statusThread->sending_status === Thread::SENDING_STATUS_STAGING) {
        // Update thread status
        $statusThread->sending_status = Thread::SENDING_STATUS_READY_FOR_SENDING;
        $storageManager->updateThread($statusThread, $userId);
        
        // Also update the corresponding ThreadEmailSending records
        $emailSendings = ThreadEmailSending::getByThreadId($statusThread->id);
        foreach ($emailSendings as $emailSending) {
            if ($emailSending->status === ThreadEmailSending::STATUS_STAGING) {
                ThreadEmailSending::updateStatus(
                    $emailSending->id,
                    ThreadEmailSending::STATUS_READY_FOR_SENDING
                );
            }
        }
    }
    
    // Redirect to remove POST data
    header("Location: /thread-view?threadId=" . urlencode($statusThread->id) . "&entityId=" . urlencode($entityId));
    exit;
}

// Handle user authorization
if (isset($_POST['add_user']) && $_POST['user_id'] && $_POST['thread_id']) {
    $authThread = null;
    foreach ($allThreads as $threads) {
        foreach ($threads->threads as $t) {
            if ($t->id === $_POST['thread_id']) {
                $authThread = $t;
                break 2;
            }
        }
    }
    
    if ($authThread && $authThread->isUserOwner($userId)) {
        $authThread->addUser($_POST['user_id']);
        $storageManager->updateThread($authThread, $userId);
    }
    
    header("Location: /thread-view?threadId=" . urlencode($authThread->id) . "&entityId=" . urlencode($entityId));
    exit;
}

if (isset($_POST['remove_user']) && $_POST['user_id'] && $_POST['thread_id']) {
    $authThread = null;
    foreach ($allThreads as $threads) {
        foreach ($threads->threads as $t) {
            if ($t->id === $_POST['thread_id']) {
                $authThread = $t;
                break 2;
            }
        }
    }
    
    if ($authThread && $authThread->isUserOwner($userId)) {
        $authThread->removeUser($_POST['user_id']);
        $storageManager->updateThread($authThread, $userId);
    }
    
    header("Location: /thread-view?threadId=" . urlencode($threadId) . "&entityId=" . urlencode($entityId));
    exit;
}

// Check authorization
if (!$thread->canUserAccess($userId)) {
    die('You do not have permission to view this thread (user ' . $userId . ')');
}

// Get authorized users if viewer is owner
$authorizedUsers = array();
if ($thread->isUserOwner($userId)) {
    $authorizedUsers = ThreadAuthorizationManager::getThreadUsers($thread->id);
}

// Get thread history
$history = new ThreadHistory();
$historyEntries = $history->getHistoryForThread($thread->id);

// Function to get icon class based on file type
function getIconClass($filetype) {
    switch ($filetype) {
        case 'image/jpeg':
        case 'image/png':
        case 'image/gif':
            return 'icon-image';
        case 'application/pdf':
            return 'icon-pdf';
        default:
            return '';
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <?php 
    $pageTitle = 'View Thread - ' . htmlescape($thread->title);
    include 'head.php';
    ?>
</head>
<body>
    <div class="container">
        <?php include 'header.php'; ?>

        <h1>Thread: <?= htmlescape($thread->title) ?></h1>

        <div class="thread-details">
            <p>
                <strong>Entity:</strong> <?= Entity::getNameHtml($thread->getEntity()) ?> (<?= htmlescape($threadEntity->entity_id) ?>)<br>
                <strong>Identity:</strong> <?= htmlescape($thread->my_name) ?> &lt;<?= htmlescape($thread->my_email) ?>&gt;<br>
                <strong>Law Basis:</strong> <?= $thread->request_law_basis === Thread::REQUEST_LAW_BASIS_OFFENTLEGLOVA ? 'Offentlegova' : ($thread->request_law_basis === Thread::REQUEST_LAW_BASIS_OTHER ? 'Other type of request' : 'Not specified') ?><br>
                <strong>Follow-up Plan:</strong> <?= $thread->request_follow_up_plan === Thread::REQUEST_FOLLOW_UP_PLAN_SPEEDY ? 'Simple request, expecting speedy follow up' : ($thread->request_follow_up_plan === Thread::REQUEST_FOLLOW_UP_PLAN_SLOW ? 'Complex request, expecting slow follow up' : 'Not specified') ?>
            </p>

            <p>
                <?php $status = $thread->getThreadStatus(); ?>
                <strong>Status:</strong> <?= $status->status_text; ?><br>
                <strong>Error?</strong> <?= isset($status->error) && $status->error ? 'Yes' : 'No'; ?><br>
            </p>

            <div class="status-labels">
                <?php 
                switch ($thread->sending_status) {
                    case Thread::SENDING_STATUS_STAGING:
                        echo '<span class="label label_info"><a href="/?label_filter=staging">'
                                        . ThreadHistory::sendingStatusToString($thread->sending_status) . '</a></span>';
                        break;
                    case Thread::SENDING_STATUS_READY_FOR_SENDING:
                        echo '<span class="label label_warn"><a href="/?label_filter=ready_for_sending">'
                                        . ThreadHistory::sendingStatusToString($thread->sending_status) . '</a></span>';
                        break;
                    case Thread::SENDING_STATUS_SENDING:
                        echo '<span class="label label_warn"><a href="/?label_filter=sending">'
                                        . ThreadHistory::sendingStatusToString($thread->sending_status) . '</a></span>';
                        break;
                    case Thread::SENDING_STATUS_SENT:
                        echo '<span class="label label_ok"><a href="/?label_filter=sent">'
                                        . ThreadHistory::sendingStatusToString($thread->sending_status) . '</a></span>';
                        break;
                    default:
                        throw new Exception('Unknown sending status: ' . $thread->sending_status);
                }
                ?>
                <?= $thread->archived ? '<span class="label label_ok"><a href="/?label_filter=archived">Archived</a></span>' : '<span class="label label_warn"><a href="/?label_filter=not_archived">Not archived</a></span>' ?>
            </div>

            <div class="labels">
                <?php foreach ($thread->labels as $label): 
                    if (empty($label)) {
                        continue;
                    }
                    ?>
                    <span class="label"><a href="/?label_filter=<?=urlencode($label)?>"><?= htmlescape($label) ?></a></span>
                <?php endforeach; ?>
            </div>

            <div class="action-links">
                <a href="/thread-classify?entityId=<?= htmlescape($entityId) ?>&threadId=<?= htmlescape($threadId) ?>">Classify</a>
                <a href="/toggle-thread-archive?entityId=<?= htmlescape($entityId) ?>&threadId=<?= htmlescape($threadId) ?>&archive=<?= $thread->archived ? '0' : '1' ?>">
                    <?= $thread->archived ? 'Unarchive thread' : 'Archive thread' ?>
                </a>
            </div>

            <?php if ($thread->isUserOwner($userId)): ?>
                <div class="thread-management">
                    <h3>Thread Management</h3>
                    
                    <!-- Public Toggle -->
                    <form method="POST" style="margin-bottom: 20px;">
                        <input type="hidden" name="thread_id" value="<?= htmlescape($thread->id) ?>">
                        <input type="hidden" name="toggle_public_to" value="<?= $thread->public ? '0' : '1' ?>">
                        <button type="submit" class="button">
                            <?= $thread->public ? 'Make Private' : 'Make Public' ?>
                        </button>
                        <span class="status">(Currently <?= $thread->public ? 'Public' : 'Private' ?>)</span>
                    </form>

                    <!-- Sending Status Change -->
                    <?php if ($thread->sending_status === Thread::SENDING_STATUS_STAGING): ?>
                    <form method="POST" style="margin-bottom: 20px;">
                        <input type="hidden" name="thread_id" value="<?= htmlescape($thread->id) ?>">
                        <input type="hidden" name="change_status_to_ready" value="1">
                        <button type="submit" class="button">
                            Mark as Ready for Sending
                        </button>
                        <span class="status">(Currently in <?=ThreadHistory::sendingStatusToString($thread->sending_status)?>)</span>
                    </form>
                    <?php endif; ?>

                    <!-- User Management -->
                    <div class="authorized-users">
                        <h4>Authorized Users</h4>
                        <?php foreach ($authorizedUsers as $auth): ?>
                            <div class="user-item">
                                <?= htmlescape($auth->getUserId()) ?>
                                <?= $auth->isOwner() ? ' (Owner)' : '' ?>
                                <?php if (!$auth->isOwner()): ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="thread_id" value="<?= htmlescape($thread->id) ?>">
                                        <input type="hidden" name="user_id" value="<?= htmlescape($auth->getUserId()) ?>">
                                        <button type="submit" name="remove_user" value="1" class="button small">Remove</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>

                        <!-- Add User Form -->
                        <form method="POST" class="add-user-form responsive-form">
                            <input type="hidden" name="thread_id" value="<?= htmlescape($thread->id) ?>">
                            <input type="text" name="user_id" placeholder="User ID" required class="responsive-input">
                            <button type="submit" name="add_user" value="1" class="button responsive-button">Add User</button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <h2>Thread History</h2>
        <div class="thread-history">
            <?php if (empty($historyEntries)): ?>
                <p>No history available</p>
            <?php else: ?>
                <?php foreach ($historyEntries as $entry): 
                    $formattedEntry = $history->formatHistoryEntry($entry);
                ?>
                    <div class="history-item">
                        <span class="history-action"><?= htmlescape($formattedEntry['action']) ?></span>
                        <span class="history-user">by <?= htmlescape($formattedEntry['user']) ?></span>
                        <span class="history-date"><?= htmlescape($formattedEntry['date']) ?></span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <h2>Emails in Thread</h2>
        <div class="emails-list">
            <?php
            if (!isset($thread->emails)) {
                $thread->emails = array();
            }
            foreach ($thread->emails as $email):
                $label_type = getLabelType('email', $email->status_type);
            ?>
                <div class="email-item<?= $email->ignore ? ' ignored' : '' ?>">
                    <div class="email-header">
                        <span class="datetime"><?= htmlescape($email->datetime_received) ?></span>
                        <span class="email-type"><?= htmlescape($email->email_type) ?></span>
                        <span class="<?= $label_type ?>"><?= htmlescape($email->status_text) ?></span>
                        <?php
                        if (ThreadEmailClassifier::getClassificationLabel($email) !== null) {
                            ?>
                            <span style="font-size: 0.8em">
                            [Classified by <?= ThreadEmailClassifier::getClassificationLabel($email) ?>]
                            </span>
                            <?php
                        }
                        ?>
                    </div>

                    <?php if (isset($email->description) && $email->description): ?>
                        <div class="email-description">
                            <?= htmlescape($email->description) ?>
                        </div>
                    <?php endif; ?>

                    <?php if (isset($email->id)): ?>
                        <div class="email-links">
                            <a href="/file?entityId=<?= htmlescape($entityId) ?>&threadId=<?= htmlescape($threadId) ?>&body=<?= htmlescape($email->id) ?>">View email</a> (text)
                        </div>
                    <?php endif; ?>

                    <?php if (isset($email->attachments) && count($email->attachments) > 0): ?>
                        <div class="attachments">
                            <span class="attachments-label">Attachments:</span>
                            <div class="attachments-list">
                                <?php foreach ($email->attachments as $att):
                                    $label_type = getLabelType('attachement', $att->status_type);
                                    $iconClass = getIconClass($att->filetype);
                                ?>
                                <div class="attachment-item">
                                    <span class="<?= $label_type ?>"><?= htmlescape($att->status_text) ?></span>
                                    <?= htmlescape($att->filetype) ?> - 
                                    <?php if (isset($att->location)): ?>
                                        <a href="/file?entityId=<?= htmlescape($entityId) ?>&threadId=<?= htmlescape($threadId) ?>&attachment=<?= urlencode($att->location) ?>">
                                            <?php if ($iconClass): ?>
                                                <i class="<?= $iconClass ?>"></i>
                                            <?php endif; ?>
                                            <?= htmlescape($att->name) ?>
                                        </a>
                                    <?php else: ?>
                                        <?php if ($iconClass): ?>
                                            <i class="<?= $iconClass ?>"></i>
                                        <?php endif; ?>
                                        <?= htmlescape($att->name) ?>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</body>
</html>

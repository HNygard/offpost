<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/class/Threads.php';
require_once __DIR__ . '/class/ThreadEmailClassifier.php';
require_once __DIR__ . '/class/ThreadStorageManager.php';
require_once __DIR__ . '/class/ThreadHistory.php';

// Require authentication
requireAuth();

// Get thread ID and entity ID from URL parameters
$threadId = isset($_GET['threadId']) ? $_GET['threadId'] : null;
$entityId = isset($_GET['entityId']) ? $_GET['entityId'] : null;
$userId = $_SESSION['user']['sub']; // OpenID Connect subject identifier

if (!$threadId || !$entityId) {
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
        saveEntityThreads($entityId, $threadEntity);
    }
    
    // Redirect to remove POST data
    header("Location: /thread-view?threadId=" . urlencode($threadId) . "&entityId=" . urlencode($entityId));
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
        saveEntityThreads($entityId, $threadEntity);
    }
    
    header("Location: /thread-view?threadId=" . urlencode($threadId) . "&entityId=" . urlencode($entityId));
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
        saveEntityThreads($entityId, $threadEntity);
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
                <strong>Entity:</strong> <?= htmlescape($threadEntity->title_prefix) ?> (<?= htmlescape($threadEntity->entity_id) ?>)<br>
                <strong>Identity:</strong> <?= htmlescape($thread->my_name) ?> &lt;<?= htmlescape($thread->my_email) ?>&gt;
            </p>

            <div class="status-labels">
                <?= $thread->sent ? '<span class="label label_ok"><a href="/?label_filter=sent">Sent</a></span>' : '<span class="label label_warn"><a href="/?label_filter=not_sent">Not sent</a></span>' ?>
                <?= $thread->archived ? '<span class="label label_ok"><a href="/?label_filter=archived">Archived</a></span>' : '<span class="label label_warn"><a href="/?label_filter=not_archived">Not archived</a></span>' ?>
            </div>

            <div class="labels">
                <?php foreach ($thread->labels as $label): ?>
                    <span class="label"><a href="/?label_filter=<?=urlencode($label)?>"><?= htmlescape($label) ?></a></span>
                <?php endforeach; ?>
            </div>

            <div class="action-links">
                <a href="/thread-classify?entityId=<?= htmlescape($entityId) ?>&threadId=<?= htmlescape($threadId) ?>">Classify</a>
                <a href="/thread-send-email?entityId=<?= htmlescape($entityId) ?>&threadId=<?= htmlescape($threadId) ?>">Send email</a>
                <a href="/setSuccessForThreadAndDocument?entityId=<?= htmlescape($entityId) ?>&threadId=<?= htmlescape($threadId) ?>">Archive thread</a>
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
                        <form method="POST" class="add-user-form">
                            <input type="hidden" name="thread_id" value="<?= htmlescape($thread->id) ?>">
                            <input type="text" name="user_id" placeholder="User ID" required>
                            <button type="submit" name="add_user" value="1" class="button">Add User</button>
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
                                ?>
                                <div class="attachment-item">
                                    <span class="<?= $label_type ?>"><?= htmlescape($att->status_text) ?></span>
                                    <?= htmlescape($att->filetype) ?> - 
                                    <?php if (isset($att->location)): ?>
                                        <a href="/file?entityId=<?= htmlescape($entityId) ?>&threadId=<?= htmlescape($threadId) ?>&attachment=<?= urlencode($att->location) ?>"><?= htmlescape($att->name) ?></a>
                                    <?php else: ?>
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

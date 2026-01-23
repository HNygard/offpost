<?php
require_once __DIR__ . '/class/Enums/ThreadEmailStatusType.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/class/ThreadUtils.php';
require_once __DIR__ . '/class/ThreadEmailService.php';
require_once __DIR__ . '/class/Threads.php';
require_once __DIR__ . '/class/ThreadAuthorization.php';
require_once __DIR__ . '/class/ThreadLabelFilter.php';
require_once __DIR__ . '/class/ThreadEmailClassifier.php';
require_once __DIR__ . '/class/Database.php';
require_once __DIR__ . '/class/ThreadStorageManager.php';
require_once __DIR__ . '/class/ThreadStatusRepository.php';

// Require authentication
requireAuth();

$userId = $_SESSION['user']['sub']; // OpenID Connect subject identifier

// Get recent incoming emails for the user
$recentEmails = ThreadStatusRepository::getRecentIncomingEmailsForUser($userId, 20);

// Get thread statuses for the emails we're displaying
$threadIds = array_unique(array_map(function($email) { return $email->thread_id; }, $recentEmails));
$threadStatuses = [];
if (!empty($threadIds)) {
    $threadStatuses = 
        ThreadStatusRepository::getAllThreadStatusesEfficient($threadIds, archived: false)
        + ThreadStatusRepository::getAllThreadStatusesEfficient($threadIds, archived: true);
}

// Helper function to convert thread status to human-readable string
function threadStatusToString($status) {
    switch ($status) {
        case ThreadStatusRepository::ERROR_THREAD_NOT_FOUND:
            return 'ERROR: Thread not found';
        case ThreadStatusRepository::ERROR_NO_FOLDER_FOUND:
            return 'ERROR: Email not synced';
        case ThreadStatusRepository::ERROR_MULTIPLE_FOLDERS:
            return 'ERROR: Multiple folders found';
        case ThreadStatusRepository::ERROR_NO_SYNC:
            return 'ERROR: No sync';
        case ThreadStatusRepository::ERROR_OLD_SYNC_REQUESTED_UPDATE:
            return 'ERROR: Update requested';
        case ThreadStatusRepository::ERROR_OLD_SYNC:
            return 'ERROR: Sync outdated';
        case ThreadStatusRepository::ERROR_INBOX_SYNC:
            return 'ERROR: Inbox sync needed';
        case ThreadStatusRepository::ERROR_SENT_SYNC:
            return 'ERROR: Sent folder sync needed';
        case ThreadStatusRepository::NOT_SENT:
            return 'Not sent';
        case ThreadStatusRepository::EMAIL_SENT_NOTHING_RECEIVED:
            return 'Sent, no response';
        case ThreadStatusRepository::STATUS_OK:
            return 'Email sync OK';
        default:
            return 'Unknown status';
    }
}

// Helper function to format timestamp
function formatTimestamp($timestamp) {
    if (!$timestamp) return 'N/A';
    // Convert datetime to local timezone (Europe/Oslo)
    $utcDateTime = new DateTime($timestamp);
    $utcDateTime->setTimezone(new DateTimeZone('Europe/Oslo'));
    return $utcDateTime->format('Y-m-d H:i');
}

// Helper function to get thread status from thread information
function getThreadStatusForEmail($email, $threadStatuses) {
    if (isset($threadStatuses[$email->thread_id])) {
        $statusData = $threadStatuses[$email->thread_id];
        return threadStatusToString($statusData->status);
    }
    return 'Unknown';
}

// Helper function to truncate text
function truncateText($text, $length = 100) {
    if (strlen($text) <= $length) {
        return $text;
    }
    return substr($text, 0, $length) . '...';
}

?>
<!DOCTYPE html>
<html>
<head>
    <?php 
    $pageTitle = 'Recent Activity - Offpost';
    include 'head.php';
    ?>
</head>
<body>
    <div class="container">
        <?php include 'header.php'; ?>

        <h1>Recent Activity</h1>

        <ul class="nav-links">
            <li><a href="/">Back to main page</a></li>
        </ul>

        <hr>

        <h2>Recent Incoming Emails (<?= count($recentEmails) ?>)</h2>
        
        <?php if (count($recentEmails) > 0): ?>
        <p>Recent incoming emails from threads you have access to, sorted by time received:</p>
        
        <table class="recent-activity-table">
            <thead>
                <tr>
                    <th>Thread Info</th>
                    <th>Email Info</th>
                    <th>Received</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentEmails as $email): ?>
                <tr>
                    <td>
                        <div class="thread-info">
                            <strong><?= htmlspecialchars($email->thread_title) ?></strong><br>
                            <small>Entity: <?= htmlspecialchars($email->entity_id) ?></small><br>
                            
                            <!-- Thread Status -->
                            <span class="label label_info">
                                <?= htmlspecialchars(getThreadStatusForEmail($email, $threadStatuses)) ?>
                            </span>
                            
                            <!-- Thread Labels -->
                            <?php if (!empty($email->thread_labels)): ?>
                                <br>
                                <?php foreach ($email->thread_labels as $label): ?>
                                    <?php if (!empty($label)): ?>
                                        <span class="label"><a href="/?label_filter=<?= urlencode($label) ?>"><?= htmlspecialchars($label) ?></a></span>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <div class="email-info">
                            <strong>From:</strong> <?= htmlspecialchars($email->from_name) ?> 
                            &lt;<?= htmlspecialchars($email->from_email) ?>&gt;<br>
                            <strong>Subject:</strong> <?= htmlspecialchars($email->subject) ?><br>
                            
                            <?php if (!empty($email->email_description)): ?>
                                <strong>Summary:</strong> <?= htmlspecialchars(truncateText($email->email_description, 150)) ?><br>
                            <?php endif; ?>
                            
                            <strong>Classification:</strong> 
                            <span class="label <?= getLabelType('email', $email->email_status_type) ?>">
                                <?= htmlspecialchars($email->email_status_text) ?>
                            </span>
                        </div>
                    </td>
                    <td>
                        <small><?= formatTimestamp($email->datetime_received) ?></small>
                    </td>
                    <td>
                        <div class="action-links">
                            <a href="/thread-view?entityId=<?= urlencode($email->entity_id) ?>&threadId=<?= urlencode($email->thread_id) ?>">View Thread</a><br>
                            <a href="/thread-classify?entityId=<?= urlencode($email->entity_id) ?>&threadId=<?= urlencode($email->thread_id) ?>">Classify</a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <p>No recent email activity found.</p>
        <?php endif; ?>
    </div>
</body>
</html>
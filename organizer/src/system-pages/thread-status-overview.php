<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../class/ThreadStatusRepository.php';
require_once __DIR__ . '/../class/Thread.php';
require_once __DIR__ . '/../class/ThreadEmail.php';
require_once __DIR__ . '/../class/Database.php';

// Require authentication
requireAuth();

// Get all non-archived thread statuses
$threadStatuses = ThreadStatusRepository::getAllThreadStatusesEfficient(null, null, false);

// Count threads by status
$statusCounts = [];
$totalThreads = count($threadStatuses);

// Initialize counts for all status types
$statusConstants = [
    ThreadStatusRepository::ERROR_NO_FOLDER_FOUND,
    ThreadStatusRepository::ERROR_MULTIPLE_FOLDERS,
    ThreadStatusRepository::ERROR_NO_SYNC,
    ThreadStatusRepository::ERROR_OLD_SYNC_REQUESTED_UPDATE,
    ThreadStatusRepository::ERROR_OLD_SYNC,
    ThreadStatusRepository::ERROR_THREAD_NOT_FOUND,
    ThreadStatusRepository::ERROR_INBOX_SYNC,
    ThreadStatusRepository::ERROR_SENT_SYNC,
    ThreadStatusRepository::NOT_SENT,
    ThreadStatusRepository::EMAIL_SENT_NOTHING_RECEIVED,
    ThreadStatusRepository::STATUS_OK
];

foreach ($statusConstants as $status) {
    $statusCounts[$status] = 0;
}

// Count threads by status
foreach ($threadStatuses as $threadStatus) {
    $status = $threadStatus->status;
    if (isset($statusCounts[$status])) {
        $statusCounts[$status]++;
    }
}

// Function to truncate text
function truncateText($text, $length = 50) {
    if (!$text) return '';
    if (mb_strlen($text, 'UTF-8') <= $length) {
        return $text;
    }
    return mb_substr($text, 0, $length, 'UTF-8') . '...';
}

// Function to format status with appropriate styling
function formatStatus($status) {
    $class = '';
    switch ($status) {
        case ThreadStatusRepository::STATUS_OK:
            $class = 'label_ok';
            break;
        case ThreadStatusRepository::NOT_SENT:
        case ThreadStatusRepository::EMAIL_SENT_NOTHING_RECEIVED:
            $class = 'label_info';
            break;
        case ThreadStatusRepository::ERROR_NO_FOLDER_FOUND:
        case ThreadStatusRepository::ERROR_MULTIPLE_FOLDERS:
        case ThreadStatusRepository::ERROR_NO_SYNC:
        case ThreadStatusRepository::ERROR_OLD_SYNC_REQUESTED_UPDATE:
        case ThreadStatusRepository::ERROR_OLD_SYNC:
        case ThreadStatusRepository::ERROR_THREAD_NOT_FOUND:
        case ThreadStatusRepository::ERROR_INBOX_SYNC:
        case ThreadStatusRepository::ERROR_SENT_SYNC:
            $class = 'label_danger';
            break;
        default:
            $class = 'label_warn';
    }
    return '<span class="label ' . $class . '"><a href="#" onclick="return false;">' . htmlspecialchars($status) . '</a></span>';
}

// Function to format timestamp
function formatTimestamp($timestamp) {
    if (!$timestamp) return 'N/A';
    return date('Y-m-d H:i', $timestamp);
}

// Get thread titles for display
$threadIds = array_keys($threadStatuses);
$threadTitles = [];

if (!empty($threadIds)) {
    $placeholders = implode(',', array_fill(0, count($threadIds), '?'));
    $query = "SELECT id, title FROM threads WHERE id IN ($placeholders)";
    $threads = Database::query($query, $threadIds);
    
    foreach ($threads as $thread) {
        $threadTitles[$thread['id']] = $thread['title'];
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <?php 
    $pageTitle = 'Thread Status Overview - Offpost';
    include __DIR__ . '/../head.php';
    ?>
    <style>
        dialog.thread-details {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 15px;
            font-family: monospace;
            max-width: 80%;
            max-height: 80vh;
            overflow-y: auto;
        }
        dialog.thread-details::backdrop {
            background-color: rgba(0, 0, 0, 0.5);
        }
        dialog.thread-details .dialog-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        dialog.thread-details .dialog-header h3 {
            margin: 0;
        }
        dialog.thread-details .dialog-header .close-button {
            background: none;
            border: none;
            font-size: 1.5em;
            cursor: pointer;
        }
        .summary-box {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 20px;
            display: flex;
            flex-wrap: wrap;
            justify-content: space-around;
        }
        .summary-item {
            text-align: center;
            margin: 10px;
            min-width: 150px;
        }
        .summary-count {
            font-size: 1.5em;
            font-weight: bold;
        }
        .summary-label {
            color: #666;
        }
        .toggle-details {
            cursor: pointer;
            color: #3498db;
            text-decoration: underline;
        }
        /* Table styling for better fit */
        table {
            table-layout: fixed;
            width: 100%;
        }
        th.id-col, td.id-col {
            width: 5%;
        }
        th.thread-col, td.thread-col {
            width: 20%;
        }
        th.status-col, td.status-col {
            width: 15%;
        }
        th.emails-col, td.emails-col {
            width: 10%;
        }
        th.activity-col, td.activity-col {
            width: 15%;
        }
        th.sync-col, td.sync-col {
            width: 15%;
        }
        th.actions-col, td.actions-col {
            width: 20%;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php include __DIR__ . '/../header.php'; ?>

        <h1>Thread Status Overview</h1>

        <div class="summary-box">
            <div class="summary-item">
                <div class="summary-count"><?= $totalThreads ?></div>
                <div class="summary-label">Total Threads</div>
            </div>
            <?php foreach ($statusCounts as $status => $count): ?>
                <?php if ($count > 0): ?>
                    <div class="summary-item">
                        <div class="summary-count"><?= $count ?></div>
                        <div class="summary-label"><?= $status ?></div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <table>
            <tr>
                <th class="thread-col">Thread</th>
                <th class="status-col">Status</th>
                <th class="emails-col">Emails (In/Out)</th>
                <th class="activity-col">Last email Activity</th>
                <th class="sync-col">Last IMAP Sync</th>
                <th class="actions-col">Actions</th>
            </tr>
            <?php foreach ($threadStatuses as $threadId => $threadStatus): ?>
                <tr>
                    <td class="thread-col">
                        <?php
                        if (!empty($threadStatus->request_law_basis)) {
                            echo '<span class="label label_ok"><a href="#" onclick="return false;">' . $threadStatus->request_law_basis . '</a></span>';
                        }
                        if (!empty($threadStatus->request_follow_up_plan)) {
                            echo '<span class="label label_info"><a href="#" onclick="return false;">' . $threadStatus->request_follow_up_plan . '</a></span>';
                        }
                        if (!empty($threadStatus->request_law_basis) || !empty($threadStatus->request_follow_up_plan)) {
                            echo '<br>';
                        }
                        ?>
                        <?= isset($threadTitles[$threadId]) ? htmlspecialchars(truncateText($threadTitles[$threadId], 50)) : 'Unknown Thread' ?>
                    </td>
                    <td class="status-col"><?= formatStatus($threadStatus->status) ?></td>
                    <td class="emails-col">
                        <?= isset($threadStatus->email_count_in) ? $threadStatus->email_count_in : 0 ?> / 
                        <?= isset($threadStatus->email_count_out) ? $threadStatus->email_count_out : 0 ?>
                    </td>
                    <td class="activity-col">
                        <?= isset($threadStatus->email_last_activity) ? formatTimestamp($threadStatus->email_last_activity) : 'N/A' ?>
                    </td>
                    <td class="sync-col">
                        <?= isset($threadStatus->email_server_last_checked_at) ? date('Y-m-d H:i', $threadStatus->email_server_last_checked_at) : 'N/A' ?>
                    </td>
                    <td class="actions-col">
                        <a href="/thread-view?threadId=<?= htmlspecialchars($threadId) ?>&entityId=<?= urlencode($threadStatus->entity_id) ?>">View Thread</a> | 
                        <a href="#" class="toggle-details" data-id="<?= $threadId ?>">Show Details</a>
                        
                        <dialog id="details-<?= $threadId ?>" class="thread-details">
                            <div class="dialog-header">
                                <h3>Thread Details - ID: <?= substr($threadId, 0, 8) ?>...</h3>
                                <button class="close-button" onclick="document.getElementById('details-<?= $threadId ?>').close()">&times;</button>
                            </div>
                            <div class="dialog-content">
                                <p><strong>Thread ID:</strong> <?= $threadId ?></p>
                                <p><strong>Title:</strong> <?= isset($threadTitles[$threadId]) ? htmlspecialchars($threadTitles[$threadId]) : 'Unknown Thread' ?></p>
                                <p><strong>Status:</strong> <?= $threadStatus->status ?></p>
                                
                                <p><strong>Email Count (In):</strong> <?= isset($threadStatus->email_count_in) ? $threadStatus->email_count_in : 0 ?></p>
                                <p><strong>Email Count (Out):</strong> <?= isset($threadStatus->email_count_out) ? $threadStatus->email_count_out : 0 ?></p>
                                
                                <p><strong>Last Email Received:</strong> 
                                    <?= isset($threadStatus->email_last_received) ? formatTimestamp($threadStatus->email_last_received) : 'N/A' ?>
                                </p>
                                <p><strong>Last Email Sent:</strong> 
                                    <?= isset($threadStatus->email_last_sent) ? formatTimestamp($threadStatus->email_last_sent) : 'N/A' ?>
                                </p>
                                <p><strong>Last Activity:</strong> 
                                    <?= isset($threadStatus->email_last_activity) ? formatTimestamp($threadStatus->email_last_activity) : 'N/A' ?>
                                </p>
                                
                                <p><strong>Last Sync:</strong> 
                                    <?= isset($threadStatus->email_server_last_checked_at) ? date('Y-m-d H:i:s', $threadStatus->email_server_last_checked_at) : 'N/A' ?>
                                </p>
                            </div>
                        </dialog>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($threadStatuses)): ?>
                <tr>
                    <td colspan="7" style="text-align: center;">No threads found</td>
                </tr>
            <?php endif; ?>
        </table>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Add click handlers for opening thread details dialogs
            const toggleButtons = document.querySelectorAll('.toggle-details');
            toggleButtons.forEach(button => {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    const id = this.getAttribute('data-id');
                    const detailsDialog = document.getElementById('details-' + id);
                    
                    if (detailsDialog) {
                        detailsDialog.showModal();
                    }
                });
            });
            
            // Close dialog when clicking on backdrop (outside the dialog)
            const dialogs = document.querySelectorAll('dialog');
            dialogs.forEach(dialog => {
                dialog.addEventListener('click', function(e) {
                    const dialogDimensions = dialog.getBoundingClientRect();
                    if (
                        e.clientX < dialogDimensions.left ||
                        e.clientX > dialogDimensions.right ||
                        e.clientY < dialogDimensions.top ||
                        e.clientY > dialogDimensions.bottom
                    ) {
                        dialog.close();
                    }
                });
            });
        });
    </script>
</body>
</html>

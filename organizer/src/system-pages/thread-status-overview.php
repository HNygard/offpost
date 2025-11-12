<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../class/ThreadStatusRepository.php';
require_once __DIR__ . '/../class/Thread.php';
require_once __DIR__ . '/../class/ThreadEmail.php';
require_once __DIR__ . '/../class/Database.php';
require_once __DIR__ . '/../class/ThreadEmailProcessingErrorManager.php';

// Require authentication
requireAuth();

// Handle form submissions for error resolution
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $userId = $_SESSION['user_id'] ?? 'system';
        
        if ($_POST['action'] === 'resolve_error' && isset($_POST['error_id']) && isset($_POST['thread_id'])) {
            $errorId = (int)$_POST['error_id'];
            $threadId = $_POST['thread_id'];
            $description = $_POST['description'] ?? '';
            
            ThreadEmailProcessingErrorManager::resolveError($errorId, $threadId, $description);
            $message = 'Email processing error resolved successfully.';
            $messageType = 'success';
        } elseif ($_POST['action'] === 'dismiss_error' && isset($_POST['error_id'])) {
            $errorId = (int)$_POST['error_id'];
            
            ThreadEmailProcessingErrorManager::dismissError($errorId);
            $message = 'Email processing error dismissed successfully.';
            $messageType = 'success';
        }
    }
}

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

// Get email processing errors
$emailProcessingErrors = ThreadEmailProcessingErrorManager::getUnresolvedErrors();
$emailProcessingErrorCount = count($emailProcessingErrors);

// Build suggestions for each unresolved error based on matching recipients
foreach ($emailProcessingErrors as &$error) {
    $error['suggested_threads'] = [];

    // Extract email addresses from the stored email_addresses string
    $matches = [];
    preg_match_all('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $error['email_addresses'] ?? '', $matches);
    $recipients = array_map('strtolower', array_unique($matches[0] ?? []));

    if (empty($recipients)) {
        continue;
    }

    // Query thread_emails using JSONB contains operator to find threads with matching email addresses
    // We'll look for matches in the from, to, cc, and reply_to fields within imap_headers
    $candidateThreads = [];
    
    foreach ($recipients as $recipient) {
        // Search for this recipient in imap_headers JSONB field
        // The imap_headers structure contains arrays like: {"from": [{"mailbox": "user", "host": "domain.com"}], ...}
        $recipientParts = explode('@', $recipient);
        if (count($recipientParts) !== 2) continue;
        
        $mailbox = $recipientParts[0];
        $host = $recipientParts[1];
        
        // Build a query that checks if the email appears in from, to, cc, or reply_to fields
        // We use jsonb_path_exists to check if any email object matches
        $query = "
            SELECT DISTINCT te.thread_id, te.created_at
            FROM thread_emails te
            WHERE te.imap_headers IS NOT NULL
            AND (
                te.imap_headers::text ILIKE ?
            )
            ORDER BY te.created_at DESC
            LIMIT 20
        ";
        
        $searchPattern = '%' . $mailbox . '@' . $host . '%';
        $results = Database::query($query, [$searchPattern]);
        
        foreach ($results as $row) {
            $threadId = $row['thread_id'];
            if (!isset($candidateThreads[$threadId])) {
                $candidateThreads[$threadId] = [
                    'thread_id' => $threadId,
                    'match_count' => 0,
                    'last_email' => $row['created_at']
                ];
            }
            $candidateThreads[$threadId]['match_count']++;
        }
    }

    // Sort candidates by match count (descending) and last email date (descending)
    usort($candidateThreads, function($a, $b) {
        if ($a['match_count'] !== $b['match_count']) {
            return $b['match_count'] - $a['match_count'];
        }
        return strcmp($b['last_email'], $a['last_email']);
    });

    // Limit to top 5 candidates
    $candidateThreads = array_slice($candidateThreads, 0, 5);

    if (!empty($candidateThreads)) {
        $threadIds = array_column($candidateThreads, 'thread_id');
        $placeholders2 = implode(',', array_fill(0, count($threadIds), '?'));
        $threadsQuery = "SELECT id, title FROM threads WHERE id IN ($placeholders2)";
        $threadRows = Database::query($threadsQuery, $threadIds);

        $titles = [];
        foreach ($threadRows as $t) {
            $titles[$t['id']] = $t['title'];
        }

        foreach ($candidateThreads as $cand) {
            $tid = $cand['thread_id'];
            $error['suggested_threads'][] = [
                'thread_id'   => $tid,
                'title'       => isset($titles[$tid]) ? $titles[$tid] : 'Unknown Thread',
                'match_count' => (int)$cand['match_count']
            ];
        }

        // Set backward-compatible fields from top suggestion
        $top = $error['suggested_threads'][0];
        $error['suggested_thread_id'] = $top['thread_id'];
        $error['suggested_thread_title'] = $top['title'];
    }
}
unset($error);

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
        
        /* Email processing errors styling */
        .error-section {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .error-section h2 {
            color: #856404;
            margin-top: 0;
        }
        .error-item {
            background-color: #ffffff;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 15px;
        }
        .error-form {
            display: flex;
            gap: 10px;
            align-items: flex-end;
            margin-top: 10px;
        }
        .error-form select, .error-form input {
            padding: 5px;
            border: 1px solid #ddd;
            border-radius: 3px;
        }
        .error-form button {
            padding: 5px 10px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
        }
        .btn-resolve {
            background-color: #28a745;
            color: white;
        }
        .btn-dismiss {
            background-color: #6c757d;
            color: white;
        }
        .message {
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 15px;
        }
        .message.success {
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        .message.error {
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
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
            <?php if ($emailProcessingErrorCount > 0): ?>
                <div class="summary-item">
                    <div class="summary-count" style="color: #856404;"><?= $emailProcessingErrorCount ?></div>
                    <div class="summary-label">Email Processing Errors</div>
                </div>
            <?php endif; ?>
        </div>
        
        <?php if ($message): ?>
            <div class="message <?= $messageType ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <?php if ($emailProcessingErrorCount > 0): ?>
            <div class="error-section">
                <h2>Email Processing Errors (<?= $emailProcessingErrorCount ?>)</h2>
                <p>The following emails could not be automatically assigned to threads and require manual intervention:</p>
                
                <?php foreach ($emailProcessingErrors as $error): ?>
                    <div class="error-item">
                        <div><strong>Email Subject:</strong> <?= htmlspecialchars($error['email_subject']) ?></div>
                        <div><strong>Email Addresses:</strong> <?= htmlspecialchars($error['email_addresses']) ?></div>
                        <div><strong>Error Type:</strong> <?= $error['error_type'] === 'no_matching_thread' ? 'No matching thread' : 'Multiple matching threads' ?></div>
                        <div><strong>Folder:</strong> <?= htmlspecialchars($error['folder_name']) ?></div>
                        <div><strong>Created:</strong> <?= date('Y-m-d H:i:s', strtotime($error['created_at'])) ?></div>
                        
                        <?php if (!empty($error['suggested_threads'])): ?>
                            <div><strong>Suggested Threads:</strong></div>
                            <ul style="margin: 5px 0 10px 18px;">
                                <?php foreach ($error['suggested_threads'] as $sugg): ?>
                                    <li>
                                        <a href="#" class="use-suggestion" data-error-id="<?= $error['id'] ?>" data-thread-id="<?= htmlspecialchars($sugg['thread_id']) ?>" onclick="return false;">
                                            <?= htmlspecialchars(truncateText($sugg['title'], 60)) ?> (matches: <?= $sugg['match_count'] ?>)
                                        </a>
                                        &nbsp; <small style="color:#666">(<?= substr($sugg['thread_id'], 0, 8) ?>...)</small>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php elseif (!empty($error['suggested_thread_title'])): ?>
                            <div><strong>Suggested Thread:</strong> <?= htmlspecialchars($error['suggested_thread_title']) ?> (<?= substr($error['suggested_thread_id'], 0, 8) ?>...)</div>
                        <?php endif; ?>
                        
                        <form class="error-form" method="post">
                            <input type="hidden" name="error_id" value="<?= $error['id'] ?>">
                            
                            <div>
                                <label for="thread_<?= $error['id'] ?>">Assign to Thread:</label>
                                <input type="text" name="thread_id" id="thread_<?= $error['id'] ?>" 
                                       value="<?= htmlspecialchars($error['suggested_thread_id'] ?? '') ?>" 
                                       placeholder="Enter thread ID..." style="width: 300px;">
                                <?php if ($error['suggested_thread_id'] && $error['suggested_thread_title']): ?>
                                    <small style="display: block; color: #666; margin-top: 2px;">
                                        Suggested: <?= htmlspecialchars($error['suggested_thread_title']) ?>
                                    </small>
                                <?php endif; ?>
                            </div>
                            
                            <div>
                                <label for="description_<?= $error['id'] ?>">Description:</label>
                                <input type="text" name="description" id="description_<?= $error['id'] ?>" placeholder="Optional description..." style="width: 200px;">
                            </div>
                            
                            <button type="submit" name="action" value="resolve_error" class="btn-resolve">Resolve</button>
                            <button type="submit" name="action" value="dismiss_error" class="btn-dismiss" 
                                    onclick="return confirm('Are you sure you want to dismiss this error without creating a mapping?')">Dismiss</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

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
            
            // Add click handlers for suggestion links
            const suggestionLinks = document.querySelectorAll('.use-suggestion');
            suggestionLinks.forEach(link => {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    const errorId = this.getAttribute('data-error-id');
                    const threadId = this.getAttribute('data-thread-id');
                    
                    // Find the corresponding thread input field
                    const threadInput = document.getElementById('thread_' + errorId);
                    if (threadInput) {
                        threadInput.value = threadId;
                        // Highlight the input briefly to show it was updated
                        threadInput.style.backgroundColor = '#ffffcc';
                        setTimeout(function() {
                            threadInput.style.backgroundColor = '';
                        }, 500);
                    }
                });
            });
        });
    </script>
</body>
</html>

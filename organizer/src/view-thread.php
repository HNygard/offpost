<?php
require_once __DIR__ . '/class/Enums/ThreadEmailStatusType.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/class/Threads.php';
require_once __DIR__ . '/class/ThreadEmailClassifier.php';
require_once __DIR__ . '/class/ThreadStorageManager.php';
require_once __DIR__ . '/class/ThreadHistory.php';
require_once __DIR__ . '/class/ThreadEmailSending.php';
require_once __DIR__ . '/class/Extraction/ThreadEmailExtractionService.php';
require_once __DIR__ . '/class/ThreadUtils.php';

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
    displayErrorPage(new Exception('Thread not found.'));
    exit;
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

// Get thread email extractions
$extraction_service = new ThreadEmailExtractionService();

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

function print_extraction ($extraction) {
    $text = '';
    $style = '';

    if (!empty($extraction->error_message)) {
        $style = 'background-color: #f8d7da; color: #721c24;';
        $text = 'Error extracting info.';
    }
    elseif ($extraction->prompt_service == 'code' && $extraction->prompt_text == 'email_body') {
        $text = 'Email body extracted.';
        $style = 'background-color: #d1ecf1; color: #0c5460;';
    }
    elseif ($extraction->prompt_service == 'code' && $extraction->prompt_text == 'attachment_pdf') {
        $text = 'PDF attachment text extracted.';
        $style = 'background-color: #d1ecf1; color: #0c5460;';
    }
    elseif ($extraction->prompt_service == 'openai' && $extraction->prompt_id == 'saksnummer') {
        if (empty($extraction->extracted_text)) {
            return;
        }
        $obj = json_decode($extraction->extracted_text);
        foreach($obj as $case_numer) {
            if (!empty($case_numer->document_number)) {
                $text .= "Document number: " . htmlescape($case_numer->document_number);
            }
            elseif (!empty($case_numer->case_number)) {
                $text .= " Case number: " . htmlescape($case_numer->case_number);
            }
            if (!empty($case_numer->entity_name)) {
                $text .= " (" . htmlescape($case_numer->entity_name) . ")";
            }
        }
        $style = 'background-color: #d4edda; color: #155724;';
    }
    elseif ($extraction->prompt_service == 'openai' && $extraction->prompt_id == 'email-latest-reply') {
        if (empty($extraction->extracted_text)) {
            return;
        }
        $text = 'Latest reply extracted.';
        $style = 'background-color: #86cff1; color: rgb(11, 96, 111);';
    }
    elseif ($extraction->prompt_service == 'openai' && $extraction->prompt_id == 'copy-asking-for') {
        if (empty($extraction->extracted_text)) {
            return;
        }
        $obj = json_decode($extraction->extracted_text);
        if (!$obj->is_requesting_copy) {
            // Not requesting a copy, so nothing to display
            return;
        }
        $text = 'Sender is requesting a copy of the email.';
        $style = 'background-color: #d4edda; color:rgb(21, 33, 87);';
    }
    else {
        global $admins;
        $text = 'Unknown extraction.';
        if (in_array($_SESSION['user']['sub'], $admins)) {
            $text .= "\nJSON: " . json_encode($extraction);
        }
        throw new Exception($text);
    }
    echo '<span class="email-extraction" style="border: 1px solid gray; padding: 5px; border-radius: 4px; margin-right: 6px; ' . $style . '">' . trim($text) . '</span>';
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
                <strong>Status error?</strong> <?= isset($status->error) && $status->error ? 'Yes' : 'No'; ?><br>
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
            if (isset($status->error) && $status->error) {
                echo '<div class="alert-error" style="margin: 1em; padding: 1em;"><b>Email sync error:</b><br>'
                    . htmlescape($status->status_text) . '<br><br>'
                    . '<i>This can affect the email list below.</i></div>';
            }

            if (!isset($thread->emails)) {
                $thread->emails = array();
            }
            foreach ($thread->emails as $email):
                $label_type = getLabelType('email', $email->status_type);

                $extractions = $extraction_service->getExtractionsForEmail($email->id);
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

                    <div class="email-extractions">
                        <?php
                        foreach ($extractions as $extraction) {
                            if (!empty($extraction->attachment_id)) {
                                continue;
                            }
                            print_extraction($extraction);

                        }
                        ?>
                    </div>

                    <?php if (isset($email->id)): ?>
                        <div class="email-links">
                            <a href="/file?entityId=<?= htmlescape($entityId) ?>&threadId=<?= htmlescape($threadId) ?>&body=<?= htmlescape($email->id) ?>">View email</a> (text)
                        </div>
                        <div class="email-links">
                            <a href="/thread-classify?entityId=<?= htmlescape($entityId) ?>&threadId=<?= htmlescape($threadId) ?>&emailId=<?= htmlescape($email->id) ?>">Classify</a>
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
                                        <a href="/file?entityId=<?= htmlescape($entityId) ?>&threadId=<?= htmlescape($threadId) ?>&attachmentId=<?= urlencode($att->id) ?>">
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

                                    <div class="email-extractions" style="margin-top: 5px;">
                                        <?php
                                        foreach ($extractions as $extraction) {
                                            if ($extraction->attachment_id == $att->id) {
                                                print_extraction($extraction);
                                            }
                                        }
                                        ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <?php 
        // Check if thread has incoming emails that might need a reply
        $hasIncomingEmails = false;
        if (isset($thread->emails)) {
            foreach ($thread->emails as $email) {
                if ($email->email_type === 'IN') {
                    $hasIncomingEmails = true;
                    break;
                }
            }
        }
        
        // Only show reply form if there are incoming emails and user has permission
        if ($hasIncomingEmails && $thread->canUserAccess($userId)): 
            // Get valid reply recipients
            $replyRecipients = getThreadReplyRecipients($thread);
        ?>
        <div id="reply-section">
            <h2>Reply to Thread</h2>
            <form method="POST" action="/thread-reply" class="reply-form">
                <input type="hidden" name="thread_id" value="<?= htmlescape($thread->id) ?>">
                <input type="hidden" name="entity_id" value="<?= htmlescape($entityId) ?>">
                
                <div class="form-group">
                    <label for="reply_subject">Subject</label>
                    <input type="text" id="reply_subject" name="reply_subject" 
                           value="Re: <?= htmlescape($thread->title) ?>" required>
                </div>
                
                <?php if (!empty($replyRecipients)): ?>
                <div class="form-group">
                    <label>Recipient</label>
                    <div class="recipients-list">
                        <?php foreach ($replyRecipients as $index => $email): ?>
                            <div class="recipient-item">
                                <input type="radio" 
                                       id="recipient_<?= $index ?>" 
                                       name="recipient" 
                                       value="<?= htmlescape($email) ?>"
                                       <?= $index === 0 ? 'checked' : '' ?> required>
                                <label for="recipient_<?= $index ?>"><?= htmlescape($email) ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <small class="form-help">Select one recipient for your reply.</small>
                </div>
                <?php else: ?>
                <div class="form-group">
                    <div class="alert-warning">
                        No valid recipient email address found in this thread.
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="form-group">
                    <label for="reply_body">Message</label>
                    <div class="editor-toolbar">
                        <button type="button" onclick="formatText('bold')" title="Bold">
                            <strong>B</strong>
                        </button>
                        <button type="button" onclick="formatText('italic')" title="Italic">
                            <em>I</em>
                        </button>
                        <button type="button" onclick="insertSuggestedReply()" title="Insert suggested reply">
                            📝 Suggested Reply
                        </button>
                    </div>
                    <textarea id="reply_body" name="reply_body" rows="10" required 
                              placeholder="Write your reply here..."></textarea>
                </div>
                
                <div class="form-group">
                    <?php if (!empty($replyRecipients)): ?>
                        <button type="submit" name="send_reply" class="button">Send reply</button>
                        <button type="submit" name="save_draft" class="button secondary">Stage reply</button>
                    <?php else: ?>
                        <button type="button" class="button" disabled>Send reply</button>
                        <button type="button" class="button secondary" disabled>Stage reply</button>
                    <?php endif; ?>
                </div>
            </form>
            
            <!-- Suggested reply content (hidden, populated by JavaScript) -->
            <div id="suggested-reply-content" style="display: none;">
<?php
// Generate suggested reply with previous emails
$suggestedReply = "Tidligere e-poster:\n\n";
if (isset($thread->emails)) {
    $emailCount = 0;
    foreach (array_reverse($thread->emails) as $email) {
        $emailCount++;
        if ($emailCount > 5) break; // Limit to last 5 emails
        
        $direction = ($email->email_type === 'IN') ? 'Mottatt' : 'Sendt';
        $suggestedReply .= "{$emailCount}. {$direction} den {$email->datetime_received}\n";
        if (isset($email->description) && $email->description) {
            $suggestedReply .= "   Sammendrag: " . strip_tags($email->description) . "\n";
        }
        $suggestedReply .= "\n";
    }
}
echo htmlescape($suggestedReply);

echo "\n\n--\n" . $thread->my_name;
?>
</div>
        </div>

        <style>
        #reply-section {
            margin-top: 30px;
            padding: 20px;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            background-color: #f8f9fa;
        }
        
        .reply-form .form-group {
            margin-bottom: 15px;
        }
        
        .reply-form label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #34495e;
        }
        
        .reply-form input[type="text"],
        .reply-form textarea {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }

        .reply-form textarea {
            height: 400px;
        }
        
        .editor-toolbar {
            margin-bottom: 5px;
            padding: 5px;
            background: #fff;
            border: 1px solid #ddd;
            border-bottom: none;
            border-radius: 4px 4px 0 0;
        }
        
        .editor-toolbar button {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            padding: 4px 8px;
            margin-right: 5px;
            cursor: pointer;
            border-radius: 3px;
        }
        
        .editor-toolbar button:hover {
            background: #e9ecef;
        }
        
        .button.secondary {
            background-color: #6c757d;
            color: white;
            margin-left: 10px;
        }
        
        .button.secondary:hover {
            background-color: #5a6268;
        }
        
        .recipients-list {
            margin-bottom: 10px;
        }
        
        .recipient-item {
            margin-bottom: 8px;
            display: flex;
            align-items: center;
        }
        
        .recipient-item input[type="radio"] {
            margin-right: 8px;
            width: auto;
        }
        
        .recipient-item label {
            margin-bottom: 0;
            font-weight: normal;
            cursor: pointer;
            color: #555;
        }
        
        .form-help {
            color: #6c757d;
            font-size: 0.875em;
            margin-top: 5px;
        }
        
        .alert-warning {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
            padding: 10px;
            border-radius: 4px;
        }
        </style>

        <script>
        function formatText(command) {
            const textarea = document.getElementById('reply_body');
            const start = textarea.selectionStart;
            const end = textarea.selectionEnd;
            const selectedText = textarea.value.substring(start, end);
            
            if (selectedText) {
                let formattedText;
                if (command === 'bold') {
                    formattedText = '<strong>' + selectedText + '</strong>';
                } else if (command === 'italic') {
                    formattedText = '<em>' + selectedText + '</em>';
                }
                
                textarea.value = textarea.value.substring(0, start) + formattedText + textarea.value.substring(end);
                textarea.focus();
                textarea.setSelectionRange(start + formattedText.length, start + formattedText.length);
            }
        }
        
        function insertSuggestedReply() {
            const textarea = document.getElementById('reply_body');
            const suggestedContent = document.getElementById('suggested-reply-content').textContent;
            
            if (textarea.value.trim() === '') {
                textarea.value = suggestedContent;
            } else {
                textarea.value += '\n\n' + suggestedContent;
            }
            textarea.focus();
        }
        </script>
        <?php endif; ?>
    </div>
</body>
</html>

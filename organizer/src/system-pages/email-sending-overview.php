<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../class/ThreadEmailSending.php';
require_once __DIR__ . '/../class/Thread.php';
require_once __DIR__ . '/../class/Database.php';

// Require authentication
requireAuth();

// Handle bulk action processing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $emailIds = isset($_POST['email_ids']) ? $_POST['email_ids'] : [];
    $processedCount = 0;
    $errorCount = 0;
    
    if ($action === 'set_ready_for_sending') {
        if (!is_array($emailIds) || empty($emailIds)) {
            throw new Exception("Invalid request: No email IDs provided.");
        }

        foreach ($emailIds as $emailId) {
            // Validate email ID is numeric and exists
            if (!is_numeric($emailId)) {
                throw new Exception("Invalid email. Not numeric.");
            }
            
            // Get the email to verify it exists and is in STAGING status
            $email = ThreadEmailSending::getById($emailId);
            if (!$email) {
                throw new Exception("Invalid email. Not in STAGING status or does not exist.");
            }
        }

        foreach ($emailIds as $emailId) { 
            $email = ThreadEmailSending::getById($emailId);
            if ($email->status !== ThreadEmailSending::STATUS_STAGING) {
                // Skip emails that are not in STAGING status
                $errorCount++;
                continue;
            }

            // Update the status
            if (ThreadEmailSending::updateStatus($emailId, ThreadEmailSending::STATUS_READY_FOR_SENDING)) {
                $processedCount++;
            } else {
                $errorCount++;
            }
        }
        
        // Redirect to prevent form resubmission
        http_response_code(302);
        header('Location: ' . $_SERVER['REQUEST_URI'] . '?success_bulk_action_ready_for_sending='. $processedCount . '&error_bulk_action_ready_for_sending='. $errorCount);
        exit;
    }
    else {
        // Invalid action.
        throw new Exception("Invalid action.");
    }
}

// Get email sendings from the last 5 days with status SENT
$sentEmailsQuery = "
    SELECT tes.*, t.entity_id FROM thread_email_sendings tes
    LEFT JOIN threads t ON tes.thread_id = t.id
    WHERE status = ? 
    AND tes.updated_at >= NOW() - INTERVAL '5 days'
    ORDER BY tes.updated_at DESC
";
$sentEmails = Database::query($sentEmailsQuery, [ThreadEmailSending::STATUS_SENT]);

// Get all emails with status READY_FOR_SENDING
$readyEmailsQuery = "
    SELECT tes.*, t.entity_id FROM thread_email_sendings tes
    LEFT JOIN threads t ON tes.thread_id = t.id
    WHERE status = ?
    ORDER BY tes.created_at DESC
";
$readyEmails = Database::query($readyEmailsQuery, [ThreadEmailSending::STATUS_READY_FOR_SENDING]);

// Get all emails with status SENDING
$sendingEmailsQuery = "
    SELECT tes.*, t.entity_id FROM thread_email_sendings tes
    LEFT JOIN threads t ON tes.thread_id = t.id
    WHERE status = ?
    ORDER BY tes.created_at DESC
";
$sendingEmails = Database::query($sendingEmailsQuery, [ThreadEmailSending::STATUS_SENDING]);

// Get all emails with status STAGING
$stagingEmailsQuery = "
    SELECT tes.*, t.entity_id FROM thread_email_sendings tes
    LEFT JOIN threads t ON tes.thread_id = t.id
    WHERE status = ?
    ORDER BY tes.created_at DESC
";
$stagingEmails = Database::query($stagingEmailsQuery, [ThreadEmailSending::STATUS_STAGING]);

// Combine all emails
$allEmails = array_merge($stagingEmails, $sendingEmails, $readyEmails, $sentEmails);

// Count emails by status
$statusCounts = [
    'SENT' => count($sentEmails),
    'READY_FOR_SENDING' => count($readyEmails),
    'SENDING' => count($sendingEmails),
    'STAGING' => count($stagingEmails),
    'TOTAL' => count($allEmails)
];

// Function to get thread title by ID
function getThreadTitle($threadId) {
    $thread = Thread::loadFromDatabase($threadId);
    return $thread ? $thread->title : 'Unknown Thread';
}

// Function to truncate text
function truncateText($text, $length = 50) {
    if (strlen($text) <= $length) {
        return $text;
    }
    return substr($text, 0, $length) . '...';
}

// Function to format status with appropriate styling
function formatStatus($status) {
    $class = '';
    switch ($status) {
        case ThreadEmailSending::STATUS_SENT:
            $class = 'label_ok';
            break;
        case ThreadEmailSending::STATUS_READY_FOR_SENDING:
            $class = 'label_pending';
            break;
        case ThreadEmailSending::STATUS_SENDING:
            $class = 'label_warn';
            break;
        case ThreadEmailSending::STATUS_STAGING:
            $class = 'label_disabled';
            break;
        default:
            $class = 'label_disabled';
    }
    return '<span class="label ' . $class . '"><a href="#" onclick="return false;">' . htmlspecialchars($status) . '</a></span>';
}

?>
<!DOCTYPE html>
<html>
<head>
    <?php 
    $pageTitle = 'Email Sending Overview - Offpost';
    include __DIR__ . '/../head.php';
    ?>
    <style>
        dialog.smtp-response {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 15px;
            white-space: pre-wrap;
            font-family: monospace;
            max-width: 80%;
            max-height: 80vh;
            overflow-y: auto;
        }
        dialog.smtp-response::backdrop {
            background-color: rgba(0, 0, 0, 0.5);
        }
        dialog.smtp-response .dialog-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        dialog.smtp-response .dialog-header h3 {
            margin: 0;
        }
        dialog.smtp-response .dialog-header .close-button {
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
            justify-content: space-around;
        }
        .summary-item {
            text-align: center;
        }
        .summary-count {
            font-size: 1.5em;
            font-weight: bold;
        }
        .summary-label {
            color: #666;
        }
        .toggle-response {
            cursor: pointer;
            color: #3498db;
            text-decoration: underline;
        }
        /* Table styling for better fit */
        table {
            table-layout: fixed;
            width: 100%;
        }
        th.checkbox-col, td.checkbox-col {
            width: 5%;
        }
        th.id-col, td.id-col {
            width: 5%;
        }
        th.thread-col, td.thread-col {
            width: 25%;
        }
        th.to-from-col, td.to-from-col {
            width: 25%;
        }
        th.status-col, td.status-col {
            width: 10%;
        }
        th.date-col, td.date-col {
            width: 20%;
        }
        th.actions-col, td.actions-col {
            width: 10%;
        }

        /* Label styling */
        span.label {
            font-size: 0.2em;
        }
        
        /* Bulk Actions styling */
        .bulk-actions-container {
            margin-bottom: 15px;
            padding: 10px;
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
        }
        
        .bulk-actions-container select,
        .bulk-actions-container button {
            margin-right: 10px;
        }
        
        .selected-count-container {
            display: inline-block;
            margin-left: 10px;
            font-weight: bold;
        }
        
        .email-checkbox-container {
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php include __DIR__ . '/../header.php'; ?>

        <h1>Email Sending Overview</h1>
        
        <?php

        if (isset($_GET['success_bulk_action_ready_for_sending']) && $_GET['success_bulk_action_ready_for_sending'] != '0') {
            echo '<div class="alert alert-success">' 
                . 'Successfully set ' . htmlspecialchars((int)$_GET['success_bulk_action_ready_for_sending']) 
                . ' email(s) to "Ready for Sending".'
                . '</div>';
        }
        if (isset($_GET['error_bulk_action_ready_for_sending']) && $_GET['error_bulk_action_ready_for_sending'] != '0') {
            echo '<div class="alert alert-error">' 
                . 'Failed to set ' . htmlspecialchars((int)$_GET['error_bulk_action_ready_for_sending']) 
                . ' email(s) to "Ready for Sending".'
                . '</div>';
        }
        ?>

        <div class="summary-box">
            <div class="summary-item">
                <div class="summary-count"><?= $statusCounts['TOTAL'] ?></div>
                <div class="summary-label">Total</div>
            </div>
            <div class="summary-item">
                <div class="summary-count"><?= $statusCounts['STAGING'] ?></div>
                <div class="summary-label">Staging</div>
            </div>
            <div class="summary-item">
                <div class="summary-count"><?= $statusCounts['READY_FOR_SENDING'] ?></div>
                <div class="summary-label">Ready for Sending</div>
            </div>
            <div class="summary-item">
                <div class="summary-count"><?= $statusCounts['SENDING'] ?></div>
                <div class="summary-label">Currently Sending</div>
            </div>
            <div class="summary-item">
                <div class="summary-count"><?= $statusCounts['SENT'] ?></div>
                <div class="summary-label">Sent (Last 5 Days)</div>
            </div>
        </div>

        <!-- Bulk Actions Form -->
        <div class="bulk-actions-container">
            <form method="post" id="bulk-actions-form">
                <select name="action" id="bulk-action">
                    <option value="">-- Select Action --</option>
                    <option value="set_ready_for_sending">Set ready for sending</option>
                </select>
                <button type="submit" id="bulk-action-button" disabled>Apply to Selected</button>
                <div class="selected-count-container" id="selected-count-container">
                    <span id="selected-count">0</span> email(s) selected
                </div>
            </form>
        </div>

        <table>
            <tr>
                <th class="checkbox-col">
                    <div class="email-checkbox-container">
                        <input type="checkbox" id="select-all-emails" title="Select all staging emails">
                    </div>
                </th>
                <th class="id-col">ID</th>
                <th class="thread-col">Thread / Subject</th>
                <th class="to-from-col">To / From</th>
                <th class="status-col">Status</th>
                <th class="date-col">Created / Updated</th>
                <th class="actions-col">Actions</th>
            </tr>
            <?php foreach ($allEmails as $email): ?>
                <tr>
                    <td class="checkbox-col">
                        <?php if ($email['status'] === ThreadEmailSending::STATUS_STAGING): ?>
                            <div class="email-checkbox-container">
                                <input type="checkbox" class="email-checkbox" name="email_ids[]" value="<?= $email['id'] ?>" form="bulk-actions-form">
                            </div>
                        <?php endif; ?>
                    </td>
                    <td class="id-col"><?= $email['id'] ?></td>
                    <td class="thread-col">
                        <a href="/thread-view?threadId=<?= htmlspecialchars($email['thread_id']) ?>&entityId=<?= urlencode($email['entity_id']) ?>">
                            <?= htmlspecialchars(getThreadTitle($email['thread_id'])) ?>
                        </a><br>
                        <?= htmlspecialchars(truncateText($email['email_subject'], 20)) ?>
                    </td>
                    <td class="to-from-col">
                        <?= htmlspecialchars($email['email_to']) ?><br>
                        <?= htmlspecialchars($email['email_from']) ?>
                    </td>
                    <td class="status-col"><?= formatStatus($email['status']) ?></td>
                    <td class="date-col"><?= date('Y-m-d H:i', strtotime($email['created_at'])) ?><?php
                        if( $email['created_at'] != $email['updated_at'] ) {
                            echo '<br>' . date('Y-m-d H:i', strtotime($email['updated_at']));
                        }
                    ?></td>
                    <td class="actions-col">
                        <?php if ($email['status'] === ThreadEmailSending::STATUS_SENT): ?>
                            <a href="#" class="toggle-response" data-id="<?= $email['id'] ?>">Show Response</a>
                            <dialog id="response-<?= $email['id'] ?>" class="smtp-response">
                                <div class="dialog-header">
                                    <h3>Email Sending Details - ID: <?= $email['id'] ?></h3>
                                    <button class="close-button" onclick="document.getElementById('response-<?= $email['id'] ?>').close()">&times;</button>
                                </div>
                                <div class="dialog-content"><?php
                                    echo "<strong>SMTP Response:</strong>\n";
                                    echo htmlspecialchars($email['smtp_response'] ?: 'No response data available');

                                    if ($email['smtp_debug']) {
                                        echo "<hr>\n";
                                        echo "<strong>SMTP Debug:</strong>\n";
                                        echo htmlspecialchars($email['smtp_debug']) . "\n";
                                    }

                                    if ($email['error_message']) {
                                        echo "<hr>\n";
                                        echo "<strong>Error Message:</strong>\n";
                                        echo htmlspecialchars($email['error_message']) . "\n";
                                    }
                                ?>
                                </div>
                            </dialog>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($allEmails)): ?>
                <tr>
                    <td colspan="7" style="text-align: center;">No email sending records found</td>
                </tr>
            <?php endif; ?>
        </table>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Get references to bulk action elements
            const selectAllCheckbox = document.getElementById('select-all-emails');
            const emailCheckboxes = document.querySelectorAll('.email-checkbox');
            const bulkActionSelect = document.getElementById('bulk-action');
            const bulkActionButton = document.getElementById('bulk-action-button');
            const selectedCountElement = document.getElementById('selected-count');
            
            // Function to update the selected count and button state
            function updateBulkActionState() {
                const checkedBoxes = document.querySelectorAll('.email-checkbox:checked');
                const count = checkedBoxes.length;
                const actionSelected = bulkActionSelect.value !== '';
                
                selectedCountElement.textContent = count;
                bulkActionButton.disabled = count === 0 || !actionSelected;
                
                // Update select all checkbox state
                if (count === 0) {
                    selectAllCheckbox.indeterminate = false;
                    selectAllCheckbox.checked = false;
                } else if (count === emailCheckboxes.length) {
                    selectAllCheckbox.indeterminate = false;
                    selectAllCheckbox.checked = true;
                } else {
                    selectAllCheckbox.indeterminate = true;
                    selectAllCheckbox.checked = false;
                }
            }
            
            // Handle select all checkbox
            selectAllCheckbox.addEventListener('change', function() {
                emailCheckboxes.forEach(checkbox => {
                    checkbox.checked = this.checked;
                });
                updateBulkActionState();
            });
            
            // Handle individual email checkboxes
            emailCheckboxes.forEach(checkbox => {
                checkbox.addEventListener('change', updateBulkActionState);
            });
            
            // Handle bulk action select change
            bulkActionSelect.addEventListener('change', updateBulkActionState);
            
            // Initialize the state
            updateBulkActionState();
            
            // Add click handlers for opening SMTP response dialogs
            const toggleButtons = document.querySelectorAll('.toggle-response');
            toggleButtons.forEach(button => {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    const id = this.getAttribute('data-id');
                    const responseDialog = document.getElementById('response-' + id);
                    
                    if (responseDialog) {
                        responseDialog.showModal();
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

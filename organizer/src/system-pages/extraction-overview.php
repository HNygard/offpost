<?php
require_once __DIR__ . '/../class/Enums/ThreadEmailStatusType.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../class/Extraction/ThreadEmailExtraction.php';
require_once __DIR__ . '/../class/Extraction/ThreadEmailExtractionService.php';
require_once __DIR__ . '/../class/Extraction/ThreadEmailExtractorEmailBody.php';
require_once __DIR__ . '/../class/Extraction/ThreadEmailExtractorAttachmentPdf.php';
require_once __DIR__ . '/../class/Extraction/ThreadEmailExtractorPromptSaksnummer.php';
require_once __DIR__ . '/../class/Extraction/ThreadEmailExtractorPromptEmailLatestReply.php';
require_once __DIR__ . '/../class/Extraction/ThreadEmailExtractorPromptCopyAskingFor.php';
require_once __DIR__ . '/../class/Thread.php';
require_once __DIR__ . '/../class/ThreadEmail.php';
require_once __DIR__ . '/../class/ThreadEmailAttachment.php';
require_once __DIR__ . '/../class/Database.php';

// Require authentication
requireAuth();

// Define display limits
define('FAILED_EXTRACTIONS_DISPLAY_LIMIT', 20); // Show limited number in the UI
define('FAILED_EXTRACTIONS_QUERY_LIMIT', 100);  // Fetch more for counting purposes

// Get total counts for all extractions from the last 30 days
$recentExtractionsCountQuery = "
    SELECT COUNT(*) as total
    FROM thread_email_extractions e
    JOIN thread_emails te ON e.email_id = te.id
    JOIN threads t ON te.thread_id = t.id
    WHERE e.created_at >= NOW() - INTERVAL '30 days'
";
$totalRecentExtractions = Database::queryValue($recentExtractionsCountQuery, []);

// Get total counts for unclassified emails
$unclassifiedEmailsCountQuery = "
    SELECT COUNT(*) as total
    FROM thread_email_extractions e
    JOIN thread_emails te ON e.email_id = te.id
    JOIN threads t ON te.thread_id = t.id
    WHERE te.status_type = '" . \App\Enums\ThreadEmailStatusType::UNKNOWN->value . "'
        AND e.attachment_id is null
";
$totalUnclassifiedEmails = Database::queryValue($unclassifiedEmailsCountQuery, []);

// Get total counts for unclassified attachments
$unclassifiedAttachmentsCountQuery = "
    SELECT COUNT(*) as total
    FROM thread_email_extractions e
    JOIN thread_email_attachments tea ON e.attachment_id = tea.id
    JOIN thread_emails te ON e.email_id = te.id
    JOIN threads t ON te.thread_id = t.id
    WHERE tea.status_type = '" . \App\Enums\ThreadEmailStatusType::UNKNOWN->value . "'
";
$totalUnclassifiedAttachments = Database::queryValue($unclassifiedAttachmentsCountQuery, []);

// Get total count for failed extractions (with error messages)
$failedExtractionsCountQuery = "
    SELECT COUNT(*) as total
    FROM thread_email_extractions e
    WHERE e.error_message IS NOT NULL
";
$totalFailedExtractions = Database::queryValue($failedExtractionsCountQuery, []);

// Get limited failed extractions
$failedExtractionsQuery = "
    SELECT e.*, te.status_type, te.status_text, te.datetime_received, t.id as thread_id, t.entity_id, t.title as thread_title
    FROM thread_email_extractions e
    JOIN thread_emails te ON e.email_id = te.id
    JOIN threads t ON te.thread_id = t.id
    WHERE e.error_message IS NOT NULL
    ORDER BY e.created_at DESC
    LIMIT ?
";
$failedExtractions = Database::query($failedExtractionsQuery, [FAILED_EXTRACTIONS_QUERY_LIMIT]);

// Get limited extractions from the last 30 days (100 items)
$recentExtractionsQuery = "
    SELECT e.*, te.status_type, te.status_text, te.datetime_received, t.id as thread_id, t.entity_id, t.title as thread_title
    FROM thread_email_extractions e
    JOIN thread_emails te ON e.email_id = te.id
    JOIN threads t ON te.thread_id = t.id
    WHERE e.created_at >= NOW() - INTERVAL '30 days'
    ORDER BY e.created_at DESC
    LIMIT 100
";
$recentExtractions = Database::query($recentExtractionsQuery, []);

// Get limited extractions for unclassified emails (status_type = 'unknown') (100 items)
$unclassifiedEmailsQuery = "
    SELECT e.*, te.status_type, te.status_text, te.datetime_received, t.id as thread_id, t.entity_id, t.title as thread_title
    FROM thread_email_extractions e
    JOIN thread_emails te ON e.email_id = te.id
    JOIN threads t ON te.thread_id = t.id
    WHERE te.status_type = '" . \App\Enums\ThreadEmailStatusType::UNKNOWN->value . "'
    ORDER BY e.created_at DESC
    LIMIT 100
";
$unclassifiedEmails = Database::query($unclassifiedEmailsQuery, []);

// Get limited extractions for unclassified attachments (100 items)
$unclassifiedAttachmentsQuery = "
    SELECT e.*, tea.status_type, tea.status_text, tea.name as attachment_name, tea.filetype, 
           te.datetime_received, t.id as thread_id, t.entity_id, t.title as thread_title
    FROM thread_email_extractions e
    JOIN thread_email_attachments tea ON e.attachment_id = tea.id
    JOIN thread_emails te ON e.email_id = te.id
    JOIN threads t ON te.thread_id = t.id
    WHERE tea.status_type = '" . \App\Enums\ThreadEmailStatusType::UNKNOWN->value . "'
    ORDER BY e.created_at DESC
    LIMIT 100
";
$unclassifiedAttachments = Database::query($unclassifiedAttachmentsQuery, []);

// Combine all extractions for display
$allExtractions = array_merge($unclassifiedEmails, $unclassifiedAttachments);

// Set extraction counts using the total values from the database
$extractionCounts = [
    'TOTAL' => $totalRecentExtractions,
    'UNCLASSIFIED_EMAILS' => $totalUnclassifiedEmails,
    'UNCLASSIFIED_ATTACHMENTS' => $totalUnclassifiedAttachments,
    'TOTAL_UNCLASSIFIED' => $totalUnclassifiedEmails + $totalUnclassifiedAttachments,
    'FAILED' => $totalFailedExtractions
];

// Count displayed items
$displayedCounts = [
    'TOTAL' => count($recentExtractions),
    'UNCLASSIFIED_EMAILS' => count($unclassifiedEmails),
    'UNCLASSIFIED_ATTACHMENTS' => count($unclassifiedAttachments),
    'TOTAL_UNCLASSIFIED' => count($unclassifiedEmails) + count($unclassifiedAttachments),
    'FAILED' => count($failedExtractions)
];

// Function to truncate text
function truncateText($text, $length = 50) {
    if (!$text) return '';
    if (mb_strlen($text, 'UTF-8') <= $length) {
        return $text;
    }
    return mb_substr($text, 0, $length, 'UTF-8') . '...';
}

// Function to limit extracted text to approximately one A4 page
function limitExtractedText($text, $length = 3000) {
    if (!$text) return '';
    if (mb_strlen($text, 'UTF-8') <= $length) {
        return $text;
    }
    return mb_substr($text, 0, $length, 'UTF-8') . '... [Text truncated - showing approximately 1 page]';
}

// Function to format status with appropriate styling
function formatStatus($status) {
    $class = '';
    switch ($status) {
        case 'info':
            $class = 'label_info';
            break;
        case 'success':
            $class = 'label_ok';
            break;
        case 'danger':
            $class = 'label_danger';
            break;
        case 'disabled':
            $class = 'label_disabled';
            break;
        case 'unknown':
        default:
            $class = 'label_warn';
    }
    return '<span class="label ' . $class . '"><a href="#" onclick="return false;">' . htmlspecialchars($status) . '</a></span>';
}

// Function to get extraction type (email or attachment)
function getExtractionType($extraction) {
    return isset($extraction['attachment_id']) && $extraction['attachment_id'] ? 'Attachment' : 'Email';
}

?>
<!DOCTYPE html>
<html>
<head>
    <?php 
    $pageTitle = 'Email Extraction Overview - Offpost';
    include __DIR__ . '/../head.php';
    ?>
    <style>
        dialog.extraction-details {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 15px;
            font-family: monospace;
            max-width: 80%;
            max-height: 80vh;
            overflow-y: auto;
        }
        dialog.extraction-details::backdrop {
            background-color: rgba(0, 0, 0, 0.5);
        }
        dialog.extraction-details .dialog-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        dialog.extraction-details .dialog-header h3 {
            margin: 0;
        }
        dialog.extraction-details .dialog-header .close-button {
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
        th.type-col, td.type-col {
            width: 10%;
        }
        th.prompt-col, td.prompt-col {
            width: 15%;
        }
        th.status-col, td.status-col {
            width: 10%;
        }
        th.date-col, td.date-col {
            width: 15%;
        }
        th.actions-col, td.actions-col {
            width: 25%;
        }
        .extracted-text {
            max-height: 200px;
            overflow-y: auto;
            padding: 10px;
            background-color: #f5f5f5;
            border: 1px solid #ddd;
            border-radius: 4px;
            margin-top: 10px;
            white-space: pre-wrap;
            font-family: monospace;
        }
        .error-message {
            color: #e74c3c;
            font-weight: bold;
        }
        /* Label styling */
        span.label {
            font-size: 0.2em;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php include __DIR__ . '/../header.php'; ?>

        <h1>Email Extraction Overview</h1>

        <div class="summary-box">
            <div class="summary-item">
                <div class="summary-count"><?= $extractionCounts['TOTAL'] ?></div>
                <div class="summary-label">Total Extractions (Last 30 Days)</div>
            </div>
            <div class="summary-item">
                <div class="summary-count"><?= $extractionCounts['FAILED'] ?></div>
                <div class="summary-label">Failed Extractions</div>
            </div>
            <div class="summary-item">
                <div class="summary-count"><?= $extractionCounts['TOTAL_UNCLASSIFIED'] ?></div>
                <div class="summary-label">Total Unclassified</div>
            </div>
            <div class="summary-item">
                <div class="summary-count"><?= $extractionCounts['UNCLASSIFIED_EMAILS'] ?></div>
                <div class="summary-label">Unclassified Emails</div>
            </div>
            <div class="summary-item">
                <div class="summary-count"><?= $extractionCounts['UNCLASSIFIED_ATTACHMENTS'] ?></div>
                <div class="summary-label">Unclassified Attachments</div>
            </div>
        </div>

        <div class="alert alert-info">
            <strong>Note:</strong> For performance reasons, only the most recent 100 items are displayed in each category below.
            The summary counts above show the total number of items in the database.
            <?php if ($displayedCounts['TOTAL_UNCLASSIFIED'] < $extractionCounts['TOTAL_UNCLASSIFIED']): ?>
                <br>Displaying <?= $displayedCounts['TOTAL_UNCLASSIFIED'] ?> of <?= $extractionCounts['TOTAL_UNCLASSIFIED'] ?> total unclassified items.
            <?php endif; ?>
        </div>

        <div>
            <h3>Extraction Queue Status</h3>
            <ul>
                <li><b>Email body extractor:</b>
                <?php
                $emailExtractor = new ThreadEmailExtractorEmailBody();
                echo $emailExtractor->getNumberOfEmailsToProcess() . ' emails to process';
                ?></li>
                
                <li><b>PDF attachment extractor:</b>
                <?php
                $pdfExtractor = new ThreadEmailExtractorAttachmentPdf();
                echo $pdfExtractor->getNumberOfEmailsToProcess() . ' PDF attachments to process';
                ?></li>
                
                <li><b>Prompt - Saksnummer:</b>
                <?php
                $extractor = new ThreadEmailExtractorPromptSaksnummer();
                echo $extractor->getNumberOfEmailsToProcess() . ' emails/attachements to process';
                ?></li>

                <li><b>Prompt - Email latest reply:</b>
                <?php
                $extractor = new ThreadEmailExtractorPromptEmailLatestReply();
                echo $extractor->getNumberOfEmailsToProcess() . ' emails to process';
                ?></li>

                <li><b>Prompt - Asking for copy:</b>
                <?php
                $extractor = new ThreadEmailExtractorPromptCopyAskingFor();
                echo $extractor->getNumberOfEmailsToProcess() . ' emails to process';
                ?></li>
            </ul>
            
            <p>
                DEVELOPMENT ONLY:
                <a href="/scheduled-email-extraction" class="btn btn-primary">Process Next Email Body</a>
                <a href="/scheduled-email-extraction?type=attachment_pdf" class="btn btn-primary">Process Next PDF Attachment</a>
                <a href="/scheduled-email-extraction?type=prompt_saksnummer" class="btn btn-primary">Process Next Prompt saksnummer</a>
                <a href="/scheduled-email-extraction?type=prompt_email_latest_reply" class="btn btn-primary">Process Next Prompt email latest reply</a>
                <a href="/scheduled-email-extraction?type=prompt_copy_asking_for" class="btn btn-primary">Process Next Prompt copy asking for</a>
            </p>
        </div>

        <?php if ($extractionCounts['FAILED'] > 0): ?>
        <div style="margin-bottom: 20px; padding: 15px; background-color: #fff3cd; border: 1px solid #ffc107; border-radius: 4px;">
            <h3 style="margin-top: 0;">Failed Extractions (<?= $extractionCounts['FAILED'] ?>)</h3>
            <p>These extractions encountered errors and can be retried. Retrying will delete the failed extraction and allow it to be re-processed automatically.</p>
            
            <?php if (!empty($failedExtractions)): ?>
                <div style="margin-bottom: 10px;">
                    <label>
                        <input type="checkbox" id="select-all-failed" />
                        Select All (showing <?= count($failedExtractions) ?> of <?= $extractionCounts['FAILED'] ?>)
                    </label>
                    <button id="retry-selected" class="btn btn-primary" style="margin-left: 10px;">Retry Selected</button>
                    <span id="retry-status" style="margin-left: 10px; font-weight: bold;"></span>
                </div>
                
                <table style="margin-bottom: 15px;">
                    <tr>
                        <th style="width: 3%;"></th>
                        <th style="width: 5%;">ID</th>
                        <th style="width: 20%;">Thread</th>
                        <th style="width: 15%;">Prompt</th>
                        <th style="width: 10%;">Date</th>
                        <th style="width: 47%;">Error</th>
                    </tr>
                    <?php foreach (array_slice($failedExtractions, 0, FAILED_EXTRACTIONS_DISPLAY_LIMIT) as $extraction): ?>
                        <tr>
                            <td><input type="checkbox" class="failed-extraction-checkbox" value="<?= $extraction['extraction_id'] ?>" /></td>
                            <td><?= $extraction['extraction_id'] ?></td>
                            <td>
                                <a href="/thread-view?threadId=<?= htmlspecialchars($extraction['thread_id']) ?>">
                                    <?= htmlspecialchars(truncateText($extraction['thread_title'], 20)) ?>
                                </a>
                            </td>
                            <td><?= htmlspecialchars(truncateText($extraction['prompt_id'] ?: $extraction['prompt_text'], 15)) ?></td>
                            <td><?= date('Y-m-d H:i', strtotime($extraction['created_at'])) ?></td>
                            <td style="color: #e74c3c; font-size: 0.9em;">
                                <?= htmlspecialchars(truncateText($extraction['error_message'], 100)) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <h3>Unclassified Extractions</h3>
        <table>
            <tr>
                <th class="id-col">ID</th>
                <th class="thread-col">Thread / Email</th>
                <th class="type-col">Type</th>
                <th class="prompt-col">Prompt</th>
                <th class="status-col">Status</th>
                <th class="date-col">Created</th>
                <th class="actions-col">Actions</th>
            </tr>
            <?php foreach ($allExtractions as $extraction): ?>
                <tr>
                    <td class="id-col"><?= $extraction['extraction_id'] ?></td>
                    <td class="thread-col">
                        <a href="/thread-view?threadId=<?= htmlspecialchars($extraction['thread_id']) ?>">
                            <?= htmlspecialchars(truncateText($extraction['thread_title'], 20)) ?>
                        </a><br>
                        <?php if (isset($extraction['attachment_name'])): ?>
                            <small><?= htmlspecialchars(truncateText($extraction['attachment_name'], 20)) ?></small>
                        <?php else: ?>
                            <small>Email: <?= date('Y-m-d H:i', strtotime($extraction['datetime_received'])) ?></small>
                        <?php endif; ?>
                    </td>
                    <td class="type-col"><?= getExtractionType($extraction) ?></td>
                    <td class="prompt-col">
                        <b><?= htmlspecialchars($extraction['prompt_id'] ?: 'N/A') ?></b><br>
                        <?= htmlspecialchars(truncateText($extraction['prompt_text'], 20)) ?><br>
                        <small><?= htmlspecialchars($extraction['prompt_service']) ?></small>
                    </td>
                    <td class="status-col"><?= formatStatus($extraction['status_type']) ?></td>
                    <td class="date-col"><?= date('Y-m-d H:i', strtotime($extraction['created_at'])) ?></td>
                    <td class="actions-col">
                        <a href="#" class="toggle-details" data-id="<?= $extraction['extraction_id'] ?>">Show Extraction</a>
                        <?php if (isset($extraction['attachment_id']) && $extraction['attachment_id']): ?>
                            | <a href="/file?threadId=<?= urlencode($extraction['thread_id']) ?>&entityId=<?= urlencode($extraction['entity_id']) ?>&attachmentId=<?= urlencode($extraction['attachment_id']) ?>" target="_blank">View Attachment</a>
                        <?php else: ?>
                            | <a href="/file?threadId=<?= urlencode($extraction['thread_id']) ?>&entityId=<?= urlencode($extraction['entity_id']) ?>&body=<?= urlencode($extraction['email_id']) ?>" target="_blank">View Email</a>
                        <?php endif; ?>
                        | <a href="/thread-classify?entityId=<?= urlencode($extraction['entity_id']) ?>&threadId=<?= urlencode($extraction['thread_id']) ?>&emailId=<?= urlencode($extraction['email_id']) ?>">Classify</a>
                        
                        <dialog id="details-<?= $extraction['extraction_id'] ?>" class="extraction-details">
                            <div class="dialog-header">
                                <h3>Extraction Details - ID: <?= $extraction['extraction_id'] ?></h3>
                                <button class="close-button" onclick="document.getElementById('details-<?= $extraction['extraction_id'] ?>').close()">&times;</button>
                            </div>
                            <div class="dialog-content">
                                <p><strong>Type:</strong> <?= getExtractionType($extraction) ?></p>
                                <p><strong>Thread:</strong> <?= htmlspecialchars($extraction['thread_title']) ?></p>
                                <p><strong>Email Date:</strong> <?= date('Y-m-d H:i:s', strtotime($extraction['datetime_received'])) ?></p>
                                
                                <?php if (isset($extraction['attachment_name']) && $extraction['attachment_name']): ?>
                                    <p><strong>Attachment:</strong> <?= htmlspecialchars($extraction['attachment_name']) ?> (<?= $extraction['filetype'] ?>)</p>
                                <?php endif; ?>
                                
                                <p><strong>Prompt Service:</strong> <?= htmlspecialchars($extraction['prompt_service']) ?></p>
                                <p><strong>Prompt ID:</strong> <?= htmlspecialchars($extraction['prompt_id'] ?: 'N/A') ?></p>
                                <p><strong>Prompt Text:</strong></p>
                                <div class="extracted-text"><?= htmlspecialchars($extraction['prompt_text']) ?></div>
                                
                                <?php if ($extraction['error_message']): ?>
                                    <p><strong>Error:</strong></p>
                                    <div class="error-message"><?= htmlspecialchars($extraction['error_message']) ?></div>
                                <?php endif; ?>
                                
                                <?php if ($extraction['extracted_text']): ?>
                                    <p><strong>Extracted Text:</strong></p>
                                    <div class="extracted-text"><?= htmlspecialchars(limitExtractedText($extraction['extracted_text'])) ?></div>
                                <?php endif; ?>
                                
                                <p><strong>Created:</strong> <?= date('Y-m-d H:i:s', strtotime($extraction['created_at'])) ?></p>
                                <p><strong>Updated:</strong> <?= date('Y-m-d H:i:s', strtotime($extraction['updated_at'])) ?></p>
                            </div>
                        </dialog>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($allExtractions)): ?>
                <tr>
                    <td colspan="7" style="text-align: center;">No unclassified email extractions found</td>
                </tr>
            <?php endif; ?>
        </table>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Add click handlers for opening extraction details dialogs
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
            
            // Handle select all checkbox for failed extractions
            const selectAllCheckbox = document.getElementById('select-all-failed');
            if (selectAllCheckbox) {
                selectAllCheckbox.addEventListener('change', function() {
                    const checkboxes = document.querySelectorAll('.failed-extraction-checkbox');
                    checkboxes.forEach(checkbox => {
                        checkbox.checked = this.checked;
                    });
                });
            }
            
            // Handle retry selected button
            const retryButton = document.getElementById('retry-selected');
            const retryStatus = document.getElementById('retry-status');
            
            if (retryButton) {
                retryButton.addEventListener('click', function() {
                    const checkboxes = document.querySelectorAll('.failed-extraction-checkbox:checked');
                    const ids = Array.from(checkboxes).map(cb => parseInt(cb.value));
                    
                    if (ids.length === 0) {
                        retryStatus.textContent = 'Please select at least one extraction to retry';
                        retryStatus.style.color = '#dc3545';
                        return;
                    }
                    
                    if (!confirm(`Are you sure you want to retry ${ids.length} extraction(s)? This will delete the failed extractions and they will be re-processed automatically.`)) {
                        return;
                    }
                    
                    // Disable button and show loading status
                    retryButton.disabled = true;
                    retryStatus.textContent = 'Retrying extractions...';
                    retryStatus.style.color = '#0066cc';
                    
                    // Send retry request
                    fetch('/extraction-retry', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({ ids: ids })
                    })
                    .then(response => {
                        if (!response.ok) {
                            let errorMessage = 'HTTP error ' + response.status;
                            if (response.status === 400) {
                                errorMessage = 'Bad request: Invalid retry request.';
                            } else if (response.status === 404) {
                                errorMessage = 'Not found: Retry endpoint not found.';
                            } else if (response.status === 500) {
                                errorMessage = 'Server error: An error occurred while retrying.';
                            }
                            throw new Error(errorMessage);
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.success) {
                            retryStatus.textContent = data.message + ' - Refresh page to see updated status.';
                            retryStatus.style.color = 'green';
                            
                            // Remove checked rows from the table
                            checkboxes.forEach(checkbox => {
                                const row = checkbox.closest('tr');
                                if (row) row.remove();
                            });
                            
                            // Uncheck select all
                            if (selectAllCheckbox) {
                                selectAllCheckbox.checked = false;
                            }
                            
                            // Re-enable button after 3 seconds
                            setTimeout(() => {
                                retryButton.disabled = false;
                            }, 3000);
                        } else {
                            retryStatus.textContent = 'Error: ' + data.message;
                            retryStatus.style.color = '#dc3545';
                            retryButton.disabled = false;
                        }
                    })
                    .catch(error => {
                        retryStatus.textContent = 'Error: ' + error.message;
                        retryStatus.style.color = '#dc3545';
                        retryButton.disabled = false;
                    });
                });
            }
        });
    </script>
</body>
</html>

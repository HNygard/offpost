<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../class/ThreadEmailExtraction.php';
require_once __DIR__ . '/../class/ThreadEmailExtractionService.php';
require_once __DIR__ . '/../class/Thread.php';
require_once __DIR__ . '/../class/ThreadEmail.php';
require_once __DIR__ . '/../class/ThreadEmailAttachment.php';
require_once __DIR__ . '/../class/Database.php';

// Require authentication
requireAuth();

// Get all extractions from the last 30 days
$recentExtractionsQuery = "
    SELECT e.*, te.status_type, te.status_text, te.datetime_received, t.id as thread_id, t.entity_id, t.title as thread_title
    FROM thread_email_extractions e
    JOIN thread_emails te ON e.email_id = te.id
    JOIN threads t ON te.thread_id = t.id
    WHERE e.created_at >= NOW() - INTERVAL '30 days'
    ORDER BY e.created_at DESC
";
$recentExtractions = Database::query($recentExtractionsQuery, []);

// Get all extractions for unclassified emails (status_type = 'unknown')
$unclassifiedEmailsQuery = "
    SELECT e.*, te.status_type, te.status_text, te.datetime_received, t.id as thread_id, t.entity_id, t.title as thread_title
    FROM thread_email_extractions e
    JOIN thread_emails te ON e.email_id = te.id
    JOIN threads t ON te.thread_id = t.id
    WHERE te.status_type = 'unknown'
    ORDER BY e.created_at DESC
";
$unclassifiedEmails = Database::query($unclassifiedEmailsQuery, []);

// Get all extractions for unclassified attachments
$unclassifiedAttachmentsQuery = "
    SELECT e.*, tea.status_type, tea.status_text, tea.name as attachment_name, tea.filetype, 
           te.datetime_received, t.id as thread_id, t.entity_id, t.title as thread_title
    FROM thread_email_extractions e
    JOIN thread_email_attachments tea ON e.attachment_id = tea.id
    JOIN thread_emails te ON e.email_id = te.id
    JOIN threads t ON te.thread_id = t.id
    WHERE tea.status_type = 'unknown'
    ORDER BY e.created_at DESC
";
$unclassifiedAttachments = Database::query($unclassifiedAttachmentsQuery, []);

// Combine all extractions for display
$allExtractions = array_merge($unclassifiedEmails, $unclassifiedAttachments);

// Count extractions by type
$extractionCounts = [
    'TOTAL' => count($recentExtractions),
    'UNCLASSIFIED_EMAILS' => count($unclassifiedEmails),
    'UNCLASSIFIED_ATTACHMENTS' => count($unclassifiedAttachments),
    'TOTAL_UNCLASSIFIED' => count($unclassifiedEmails) + count($unclassifiedAttachments)
];

// Function to truncate text
function truncateText($text, $length = 50) {
    if (!$text) return '';
    if (strlen($text) <= $length) {
        return $text;
    }
    return substr($text, 0, $length) . '...';
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
            white-space: pre-wrap;
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
                        <a href="/thread-view?id=<?= htmlspecialchars($extraction['thread_id']) ?>">
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
                        <?= htmlspecialchars(truncateText($extraction['prompt_text'], 20)) ?><br>
                        <small><?= htmlspecialchars($extraction['prompt_service']) ?></small>
                    </td>
                    <td class="status-col"><?= formatStatus($extraction['status_type']) ?></td>
                    <td class="date-col"><?= date('Y-m-d H:i', strtotime($extraction['created_at'])) ?></td>
                    <td class="actions-col">
                        <a href="#" class="toggle-details" data-id="<?= $extraction['extraction_id'] ?>">Show Extraction</a>
                        <?php if (isset($extraction['attachment_id']) && $extraction['attachment_id']): ?>
                            | <a href="/file?threadId=<?= urlencode($extraction['thread_id']) ?>&attachment=<?= urlencode($extraction['attachment_id']) ?>" target="_blank">View Attachment</a>
                        <?php else: ?>
                            | <a href="/file?threadId=<?= urlencode($extraction['thread_id']) ?>&body=<?= urlencode($extraction['email_id']) ?>" target="_blank">View Email</a>
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
                                    <div class="extracted-text"><?= htmlspecialchars($extraction['extracted_text']) ?></div>
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
        });
    </script>
</body>
</html>

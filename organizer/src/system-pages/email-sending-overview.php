<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../class/ThreadEmailSending.php';
require_once __DIR__ . '/../class/Thread.php';
require_once __DIR__ . '/../class/Database.php';

// Require authentication
requireAuth();

// Get email sendings from the last 5 days with status SENT
$sentEmailsQuery = "
    SELECT * FROM thread_email_sendings 
    WHERE status = ? 
    AND created_at >= NOW() - INTERVAL '5 days'
    ORDER BY created_at DESC
";
$sentEmails = Database::query($sentEmailsQuery, [ThreadEmailSending::STATUS_SENT]);

// Get all emails with status READY_FOR_SENDING
$readyEmailsQuery = "
    SELECT * FROM thread_email_sendings 
    WHERE status = ? 
    ORDER BY created_at DESC
";
$readyEmails = Database::query($readyEmailsQuery, [ThreadEmailSending::STATUS_READY_FOR_SENDING]);

// Get all emails with status SENDING
$sendingEmailsQuery = "
    SELECT * FROM thread_email_sendings 
    WHERE status = ? 
    ORDER BY created_at DESC
";
$sendingEmails = Database::query($sendingEmailsQuery, [ThreadEmailSending::STATUS_SENDING]);

// Combine all emails
$allEmails = array_merge($sendingEmails, $readyEmails, $sentEmails);

// Count emails by status
$statusCounts = [
    'SENT' => count($sentEmails),
    'READY_FOR_SENDING' => count($readyEmails),
    'SENDING' => count($sendingEmails),
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
            $class = 'label_warn';
            break;
        case ThreadEmailSending::STATUS_SENDING:
            $class = 'label_warn';
            break;
        default:
            $class = 'label_disabled';
    }
    return '<span class="label ' . $class . '"><a href="#">' . htmlspecialchars($status) . '</a></span>';
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
        .smtp-response {
            display: none;
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 15px;
            margin-top: 10px;
            white-space: pre-wrap;
            font-family: monospace;
            max-height: 300px;
            overflow-y: auto;
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
        th.id-col, td.id-col {
            width: 5%;
        }
        th.thread-col, td.thread-col {
            width: 15%;
        }
        th.subject-col, td.subject-col {
            width: 15%;
        }
        th.to-col, td.to-col {
            width: 15%;
        }
        th.from-col, td.from-col {
            width: 15%;
        }
        th.status-col, td.status-col {
            width: 10%;
        }
        th.date-col, td.date-col {
            width: 10%;
        }
        th.actions-col, td.actions-col {
            width: 15%;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php include __DIR__ . '/../header.php'; ?>

        <h1>Email Sending Overview</h1>

        <div class="summary-box">
            <div class="summary-item">
                <div class="summary-count"><?= $statusCounts['TOTAL'] ?></div>
                <div class="summary-label">Total</div>
            </div>
            <div class="summary-item">
                <div class="summary-count"><?= $statusCounts['SENT'] ?></div>
                <div class="summary-label">Sent (Last 5 Days)</div>
            </div>
            <div class="summary-item">
                <div class="summary-count"><?= $statusCounts['READY_FOR_SENDING'] ?></div>
                <div class="summary-label">Ready for Sending</div>
            </div>
            <div class="summary-item">
                <div class="summary-count"><?= $statusCounts['SENDING'] ?></div>
                <div class="summary-label">Currently Sending</div>
            </div>
        </div>

        <table>
            <tr>
                <th class="id-col">ID</th>
                <th class="thread-col">Thread</th>
                <th class="subject-col">Subject</th>
                <th class="to-col">To</th>
                <th class="from-col">From</th>
                <th class="status-col">Status</th>
                <th class="date-col">Created</th>
                <th class="date-col">Updated</th>
                <th class="actions-col">Actions</th>
            </tr>
            <?php foreach ($allEmails as $email): ?>
                <tr>
                    <td class="id-col"><?= $email['id'] ?></td>
                    <td class="thread-col">
                        <a href="/thread-view?id=<?= htmlspecialchars($email['thread_id']) ?>">
                            <?= htmlspecialchars(truncateText(getThreadTitle($email['thread_id']), 20)) ?>
                        </a>
                    </td>
                    <td class="subject-col"><?= htmlspecialchars(truncateText($email['email_subject'], 20)) ?></td>
                    <td class="to-col"><?= htmlspecialchars(truncateText($email['email_to'], 20)) ?></td>
                    <td class="from-col"><?= htmlspecialchars(truncateText($email['email_from'], 20)) ?></td>
                    <td class="status-col"><?= formatStatus($email['status']) ?></td>
                    <td class="date-col"><?= date('Y-m-d H:i', strtotime($email['created_at'])) ?></td>
                    <td class="date-col"><?= date('Y-m-d H:i', strtotime($email['updated_at'])) ?></td>
                    <td class="actions-col">
                        <?php if ($email['status'] === ThreadEmailSending::STATUS_SENT): ?>
                            <a href="#" class="toggle-response" data-id="<?= $email['id'] ?>">View Response</a>
                            <div id="response-<?= $email['id'] ?>" class="smtp-response">
                                <strong>SMTP Response:</strong>
                                <?= htmlspecialchars($email['smtp_response'] ?: 'No response data available') ?>
                                
                                <?php if ($email['smtp_debug']): ?>
                                    <hr>
                                    <strong>SMTP Debug:</strong>
                                    <?= htmlspecialchars($email['smtp_debug']) ?>
                                <?php endif; ?>
                                
                                <?php if ($email['error_message']): ?>
                                    <hr>
                                    <strong>Error Message:</strong>
                                    <?= htmlspecialchars($email['error_message']) ?>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($allEmails)): ?>
                <tr>
                    <td colspan="9" style="text-align: center;">No email sending records found</td>
                </tr>
            <?php endif; ?>
        </table>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Add click handlers for toggling SMTP response visibility
            const toggleButtons = document.querySelectorAll('.toggle-response');
            toggleButtons.forEach(button => {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    const id = this.getAttribute('data-id');
                    const responseDiv = document.getElementById('response-' + id);
                    
                    if (responseDiv.style.display === 'block') {
                        responseDiv.style.display = 'none';
                        this.textContent = 'View Response';
                    } else {
                        responseDiv.style.display = 'block';
                        this.textContent = 'Hide Response';
                    }
                });
            });
        });
    </script>
</body>
</html>

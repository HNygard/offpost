<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../class/Ai/OpenAiRequestLog.php';

// Require authentication
requireAuth();

use Offpost\Ai\OpenAiRequestLog;

// Define constants
define('THREAD_TITLE_MAX_LENGTH', 30);

// Get filter parameters
$source = $_GET['source'] ?? null;
$startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$limit = (int)($_GET['limit'] ?? 100);

// Get logs based on filters with thread information
$logs = [];
if ($source) {
    $logs = OpenAiRequestLog::getBySourceWithThreadInfo($source, $limit);
} else if ($startDate && $endDate) {
    $logs = OpenAiRequestLog::getByDateRangeWithThreadInfo($startDate . ' 00:00:00', $endDate . ' 23:59:59', $limit);
} else {
    $logs = OpenAiRequestLog::getAllWithThreadInfo($limit);
}

// Get token usage statistics
$tokenUsage = OpenAiRequestLog::getTokenUsage($source, $startDate, $endDate);

// Get unique sources for the filter dropdown
$sources = [];
$allLogs = OpenAiRequestLog::getAll(1000);
foreach ($allLogs as $log) {
    if (!in_array($log['source'], $sources)) {
        $sources[] = $log['source'];
    }
}
sort($sources);

?>
<!DOCTYPE html>
<html>
<head>
    <?php 
    $pageTitle = 'OpenAI Request Log - Offpost';
    include __DIR__ . '/../head.php';
    ?>
    <style>
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
        pre {
            white-space: pre-wrap;
            word-wrap: break-word;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php include __DIR__ . '/../header.php'; ?>

    <h1>OpenAI Request Log Overview</h1>
    
    <div class="summary-box">
        <div class="summary-item">
            <div class="summary-count"><?= number_format($tokenUsage['input_tokens']) ?></div>
            <div class="summary-label">Input Tokens</div>
        </div>
        <div class="summary-item">
            <div class="summary-count"><?= number_format($tokenUsage['output_tokens']) ?></div>
            <div class="summary-label">Output Tokens</div>
        </div>
        <div class="summary-item">
            <div class="summary-count"><?= number_format($tokenUsage['input_tokens'] + $tokenUsage['output_tokens']) ?></div>
            <div class="summary-label">Total Tokens</div>
        </div>
    </div>
    
    <form method="get">
        <table>
            <tr>
                <td>
                    <label for="source">Source:</label>
                    <select name="source" id="source">
                        <option value="">All Sources</option>
                        <?php foreach ($sources as $src): ?>
                            <option value="<?= htmlspecialchars($src) ?>" <?= $source === $src ? 'selected' : '' ?>>
                                <?= htmlspecialchars($src) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td>
                    <label for="start_date">Start Date:</label>
                    <input type="date" id="start_date" name="start_date" value="<?= htmlspecialchars($startDate) ?>">
                </td>
                <td>
                    <label for="end_date">End Date:</label>
                    <input type="date" id="end_date" name="end_date" value="<?= htmlspecialchars($endDate) ?>">
                </td>
                <td>
                    <label for="limit">Limit:</label>
                    <input type="number" id="limit" name="limit" value="<?= $limit ?>">
                </td>
                <td>
                    <button type="submit">Filter</button>
                </td>
            </tr>
        </table>
    </form>
    
    <div style="margin-bottom: 10px;">
        <button id="retry-selected" class="btn btn-primary" disabled>Retry Selected Requests</button>
        <span id="retry-status" style="margin-left: 10px;"></span>
    </div>
    
    <table>
        <thead>
            <tr>
                <th><input type="checkbox" id="select-all" title="Select all"></th>
                <th>ID</th>
                <th>Source</th>
                <th>Thread</th>
                <th>Time</th>
                <th>Endpoint</th>
                <th>Status</th>
                <th>Tokens</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($logs)): ?>
                <tr>
                    <td colspan="9" class="text-center">No logs found</td>
                </tr>
            <?php else: ?>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><input type="checkbox" class="request-checkbox" value="<?= $log['id'] ?>"></td>
                        <td><?= $log['id'] ?></td>
                        <td><?= htmlspecialchars($log['source']) ?></td>
                        <td>
                            <?php if (!empty($log['thread_id'])): ?>
                                <a href="/thread-view?threadId=<?= urlencode($log['thread_id']) ?>&entityId=<?= urlencode($log['thread_entity_id']) ?>" title="<?= htmlspecialchars($log['thread_title']) ?>">
                                    <?= htmlspecialchars(mb_substr($log['thread_title'], 0, THREAD_TITLE_MAX_LENGTH)) ?><?= mb_strlen($log['thread_title']) > THREAD_TITLE_MAX_LENGTH ? '...' : '' ?>
                                </a>
                            <?php else: ?>
                                <span style="color: #999;">N/A</span>
                            <?php endif; ?>
                        </td>
                        <td><?= date('Y-m-d H:i:s', strtotime($log['time'])) ?></td>
                        <td><?= htmlspecialchars($log['endpoint']) ?></td>
                        <td>
                            <?php if ($log['response_code'] >= 400): ?>
                                <span class="badge bg-danger"><?= $log['response_code'] ?></span>
                            <?php elseif ($log['response_code'] >= 200): ?>
                                <span class="badge bg-success"><?= $log['response_code'] ?></span>
                            <?php else: ?>
                                <span class="badge bg-secondary"><?= $log['response_code'] ?: 'N/A' ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            In: <?= $log['tokens_input'] ?: 'N/A' ?><br>
                            Out: <?= $log['tokens_output'] ?: 'N/A' ?>
                        </td>
                        <td>
                            <a href="#" class="toggle-response" data-id="<?= $log['id'] ?>">Show Details</a>
                            <dialog id="response-<?= $log['id'] ?>" class="smtp-response">
                                <div class="dialog-header">
                                    <h3>OpenAI Request Details - ID: <?= $log['id'] ?></h3>
                                    <button class="close-button" onclick="document.getElementById('response-<?= $log['id'] ?>').close()">&times;</button>
                                </div>
                                <div class="dialog-content">
                                    <?php if (!empty($log['thread_id'])): ?>
                                        <strong>Thread:</strong>
                                        <a href="/thread-view?threadId=<?= urlencode($log['thread_id']) ?>&entityId=<?= urlencode($log['thread_entity_id']) ?>">
                                            <?= htmlspecialchars($log['thread_title']) ?>
                                        </a>
                                        <br><br>
                                    <?php endif; ?>
                                    
                                    <strong>Content:</strong>
                                    <pre><?php
                                    $request = json_decode($log['request']);
                                    foreach($request->input as $input) {
                                        if ($input->role == 'system') {
                                            echo '<span style="color: #888;">';
                                        }
                                        echo '<b>role: ' . htmlspecialchars($input->role) . "</b>\n";
                                        echo htmlspecialchars($input->content);

                                        if ($input->role == 'system') {
                                            echo '</span>';
                                        }
                                        echo "\n\n";
                                    }
                                    ?></pre>
                                    
                                    <hr>

                                    <strong>Full HTTP Request:</strong>
                                    <pre><?= htmlspecialchars($log['request']) ?></pre>
                                    
                                    <hr>
                                    <strong>Response:</strong>
                                    <pre><?= htmlspecialchars($log['response'] ?: 'No response') ?></pre>
                                    
                                    <hr>
                                    <table class="table table-sm">
                                        <tr>
                                            <th>Source:</th>
                                            <td><?= htmlspecialchars($log['source']) ?></td>
                                            <th>Time:</th>
                                            <td><?= date('Y-m-d H:i:s', strtotime($log['time'])) ?></td>
                                        </tr>
                                        <tr>
                                            <th>Endpoint:</th>
                                            <td><?= htmlspecialchars($log['endpoint']) ?></td>
                                            <th>Response Code:</th>
                                            <td><?= $log['response_code'] ?: 'N/A' ?></td>
                                        </tr>
                                        <tr>
                                            <th>Input Tokens:</th>
                                            <td><?= $log['tokens_input'] ?: 'N/A' ?></td>
                                            <th>Output Tokens:</th>
                                            <td><?= $log['tokens_output'] ?: 'N/A' ?></td>
                                        </tr>
                                    </table>
                                </div>
                            </dialog>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Add click handlers for opening response dialogs
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
            
            // Select all checkbox functionality
            const selectAllCheckbox = document.getElementById('select-all');
            const requestCheckboxes = document.querySelectorAll('.request-checkbox');
            const retryButton = document.getElementById('retry-selected');
            const retryStatus = document.getElementById('retry-status');
            
            // Update retry button state based on selected checkboxes
            function updateRetryButtonState() {
                const checkedCount = document.querySelectorAll('.request-checkbox:checked').length;
                retryButton.disabled = checkedCount === 0;
            }
            
            // Select all functionality
            if (selectAllCheckbox) {
                selectAllCheckbox.addEventListener('change', function() {
                    requestCheckboxes.forEach(checkbox => {
                        checkbox.checked = this.checked;
                    });
                    updateRetryButtonState();
                });
            }
            
            // Individual checkbox change
            requestCheckboxes.forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    // Update select all checkbox state
                    const allChecked = Array.from(requestCheckboxes).every(cb => cb.checked);
                    const anyChecked = Array.from(requestCheckboxes).some(cb => cb.checked);
                    
                    if (selectAllCheckbox) {
                        selectAllCheckbox.checked = allChecked;
                        selectAllCheckbox.indeterminate = anyChecked && !allChecked;
                    }
                    
                    updateRetryButtonState();
                });
            });
            
            // Retry selected requests
            retryButton.addEventListener('click', function() {
                const checkedCheckboxes = document.querySelectorAll('.request-checkbox:checked');
                const ids = Array.from(checkedCheckboxes).map(cb => parseInt(cb.value));
                
                if (ids.length === 0) {
                    alert('Please select at least one request to retry');
                    return;
                }
                
                // Confirm action
                if (!confirm(`Are you sure you want to retry ${ids.length} request(s)?`)) {
                    return;
                }
                
                // Disable button and show loading status
                retryButton.disabled = true;
                retryStatus.textContent = 'Retrying requests...';
                retryStatus.style.color = '#0066cc';
                
                // Send retry request
                fetch('/openai-request-log-retry', {
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
                        retryStatus.textContent = data.message;
                        retryStatus.style.color = 'green';
                        
                        // Uncheck all checkboxes
                        checkedCheckboxes.forEach(cb => cb.checked = false);
                        if (selectAllCheckbox) {
                            selectAllCheckbox.checked = false;
                            selectAllCheckbox.indeterminate = false;
                        }
                        updateRetryButtonState();
                        
                        // Show details if there were any errors
                        if (data.errorCount > 0) {
                            let errorDetails = '\n\nDetails:\n';
                            data.results.forEach(result => {
                                if (!result.success) {
                                    errorDetails += `ID ${result.id}: ${result.message}\n`;
                                }
                            });
                            console.log(errorDetails);
                        }
                        
                        // Reload the page after 2 seconds to show new retry entries
                        setTimeout(() => {
                            window.location.reload();
                        }, 2000);
                    } else {
                        retryStatus.textContent = 'Error: ' + data.message;
                        retryStatus.style.color = 'red';
                        retryButton.disabled = false;
                    }
                })
                .catch(error => {
                    retryStatus.textContent = 'Error: ' + error.message;
                    retryStatus.style.color = 'red';
                    retryButton.disabled = false;
                });
            });
        });
    </script>

</body>
</html>

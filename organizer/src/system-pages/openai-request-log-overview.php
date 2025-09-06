<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../class/Ai/OpenAiRequestLog.php';

// Require authentication
requireAuth();

use Offpost\Ai\OpenAiRequestLog;

// Get filter parameters
$source = $_GET['source'] ?? null;
$startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$limit = (int)($_GET['limit'] ?? 100);

// Get logs based on filters
$logs = [];
if ($source) {
    $logs = OpenAiRequestLog::getBySource($source, $limit);
} else if ($startDate && $endDate) {
    $logs = OpenAiRequestLog::getByDateRange($startDate . ' 00:00:00', $endDate . ' 23:59:59', $limit);
} else {
    $logs = OpenAiRequestLog::getAll($limit);
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
    
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Source</th>
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
                    <td colspan="8" class="text-center">No logs found</td>
                </tr>
            <?php else: ?>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><?= $log['id'] ?></td>
                        <td><?= htmlspecialchars($log['source']) ?></td>
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
        });
    </script>

</body>
</html>

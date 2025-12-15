<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../class/ScheduledTaskLogger.php';

// Require authentication
requireAuth();

// Get filter parameters
$taskName = $_GET['task_name'] ?? null;
$days = (int)($_GET['days'] ?? 7);
$limit = (int)($_GET['limit'] ?? 100);

// Get logs based on filters
$logs = [];
if ($taskName) {
    $logs = ScheduledTaskLogger::getLogsForTask($taskName, $limit);
} else {
    $logs = ScheduledTaskLogger::getRecentLogs($limit);
}

// Get bandwidth summary
$summary = ScheduledTaskLogger::getBandwidthSummary($days);

// Get unique task names for the filter dropdown
$taskNames = [];
foreach ($summary as $summaryItem) {
    $taskNames[] = $summaryItem['task_name'];
}

// Helper function to format bytes
function formatBytes($bytes) {
    if ($bytes < 1024) {
        return $bytes . ' B';
    } elseif ($bytes < 1048576) {
        return round($bytes / 1024, 2) . ' KB';
    } elseif ($bytes < 1073741824) {
        return round($bytes / 1048576, 2) . ' MB';
    } else {
        return round($bytes / 1073741824, 2) . ' GB';
    }
}

// Helper function to format duration
function formatDuration($seconds) {
    if ($seconds < 60) {
        return round($seconds, 1) . ' s';
    } elseif ($seconds < 3600) {
        return round($seconds / 60, 1) . ' min';
    } else {
        return round($seconds / 3600, 2) . ' hrs';
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <?php 
    $pageTitle = 'Scheduled Task Logs - Offpost';
    include __DIR__ . '/../head.php';
    ?>
    <style>
        .summary-box {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .summary-item {
            margin-bottom: 10px;
            padding: 10px;
            background-color: white;
            border-radius: 4px;
        }
        .summary-title {
            font-weight: bold;
            margin-bottom: 5px;
        }
        .summary-stats {
            display: flex;
            justify-content: space-between;
            font-size: 0.9em;
        }
        .warning {
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 10px;
            margin-bottom: 20px;
        }
        .status-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 0.85em;
            font-weight: bold;
        }
        .status-completed {
            background-color: #d4edda;
            color: #155724;
        }
        .status-running {
            background-color: #d1ecf1;
            color: #0c5460;
        }
        .status-failed {
            background-color: #f8d7da;
            color: #721c24;
        }
        pre {
            white-space: pre-wrap;
            word-wrap: break-word;
            background-color: #f8f9fa;
            padding: 10px;
            border-radius: 4px;
        }
        .bytes-highlight {
            font-weight: bold;
            color: #0056b3;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php include __DIR__ . '/../header.php'; ?>

    <h1>Scheduled Task Logs</h1>
    
    <div class="warning">
        <strong>⚠️ Bandwidth Analysis:</strong> This page shows which scheduled tasks are using the most Internet bandwidth. 
        Large numbers in the "Bytes Processed" column indicate tasks downloading/processing large email messages.
    </div>
    
    <h2>Bandwidth Summary (Last <?= $days ?> Days)</h2>
    <div class="summary-box">
        <?php if (empty($summary)): ?>
            <p>No task execution data available for the selected period.</p>
        <?php else: ?>
            <?php foreach ($summary as $item): ?>
                <div class="summary-item">
                    <div class="summary-title"><?= htmlspecialchars($item['task_name']) ?></div>
                    <div class="summary-stats">
                        <span>Runs: <strong><?= number_format($item['run_count']) ?></strong></span>
                        <span>Total: <strong class="bytes-highlight"><?= formatBytes($item['total_bytes']) ?></strong></span>
                        <span>Avg/Run: <strong><?= formatBytes($item['avg_bytes_per_run']) ?></strong></span>
                        <span>Max/Run: <strong><?= formatBytes($item['max_bytes_per_run']) ?></strong></span>
                        <span>Items: <strong><?= number_format($item['total_items']) ?></strong></span>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <h2>Recent Execution Log</h2>
    <form method="get">
        <table>
            <tr>
                <td>
                    <label for="task_name">Task:</label>
                    <select name="task_name" id="task_name">
                        <option value="">All Tasks</option>
                        <?php foreach ($taskNames as $name): ?>
                            <option value="<?= htmlspecialchars($name) ?>" <?= $taskName === $name ? 'selected' : '' ?>>
                                <?= htmlspecialchars($name) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td>
                    <label for="days">Summary Days:</label>
                    <input type="number" id="days" name="days" value="<?= $days ?>" min="1" max="90">
                </td>
                <td>
                    <label for="limit">Log Limit:</label>
                    <input type="number" id="limit" name="limit" value="<?= $limit ?>" min="10" max="1000">
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
                <th>Task Name</th>
                <th>Started At</th>
                <th>Duration</th>
                <th>Status</th>
                <th>Bytes Processed</th>
                <th>Items</th>
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
                        <td><?= htmlspecialchars($log['task_name']) ?></td>
                        <td><?= date('Y-m-d H:i:s', strtotime($log['started_at'])) ?></td>
                        <td>
                            <?php if ($log['completed_at']): ?>
                                <?= formatDuration($log['duration_seconds']) ?>
                            <?php else: ?>
                                Running...
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="status-badge status-<?= htmlspecialchars($log['status']) ?>">
                                <?= htmlspecialchars(strtoupper($log['status'])) ?>
                            </span>
                        </td>
                        <td class="bytes-highlight">
                            <?= formatBytes($log['bytes_processed']) ?>
                        </td>
                        <td><?= number_format($log['items_processed']) ?></td>
                        <td>
                            <a href="#" class="toggle-details" data-id="<?= $log['id'] ?>">Show Details</a>
                            <dialog id="details-<?= $log['id'] ?>" class="smtp-response">
                                <div class="dialog-header">
                                    <h3>Task Execution Details - ID: <?= $log['id'] ?></h3>
                                    <button class="close-button" onclick="document.getElementById('details-<?= $log['id'] ?>').close()">&times;</button>
                                </div>
                                <div class="dialog-content">
                                    <table class="table table-sm">
                                        <tr>
                                            <th>Task Name:</th>
                                            <td><?= htmlspecialchars($log['task_name']) ?></td>
                                        </tr>
                                        <tr>
                                            <th>Status:</th>
                                            <td>
                                                <span class="status-badge status-<?= htmlspecialchars($log['status']) ?>">
                                                    <?= htmlspecialchars(strtoupper($log['status'])) ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th>Started At:</th>
                                            <td><?= date('Y-m-d H:i:s', strtotime($log['started_at'])) ?></td>
                                        </tr>
                                        <tr>
                                            <th>Completed At:</th>
                                            <td><?= $log['completed_at'] ? date('Y-m-d H:i:s', strtotime($log['completed_at'])) : 'Still running' ?></td>
                                        </tr>
                                        <tr>
                                            <th>Duration:</th>
                                            <td><?= $log['duration_seconds'] ? formatDuration($log['duration_seconds']) : 'N/A' ?></td>
                                        </tr>
                                        <tr>
                                            <th>Bytes Processed:</th>
                                            <td class="bytes-highlight"><?= formatBytes($log['bytes_processed']) ?> (<?= number_format($log['bytes_processed']) ?> bytes)</td>
                                        </tr>
                                        <tr>
                                            <th>Items Processed:</th>
                                            <td><?= number_format($log['items_processed']) ?></td>
                                        </tr>
                                    </table>
                                    
                                    <?php if ($log['message']): ?>
                                        <hr>
                                        <strong>Message:</strong>
                                        <pre><?= htmlspecialchars($log['message']) ?></pre>
                                    <?php endif; ?>
                                    
                                    <?php if ($log['error_message']): ?>
                                        <hr>
                                        <strong>Error Message:</strong>
                                        <pre style="background-color: #f8d7da; border-left: 4px solid #dc3545;"><?= htmlspecialchars($log['error_message']) ?></pre>
                                    <?php endif; ?>
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
            // Add click handlers for opening details dialogs
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

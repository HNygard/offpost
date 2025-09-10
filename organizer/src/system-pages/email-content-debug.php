<?php
require_once(__DIR__ . '/../auth.php');
require_once(__DIR__ . '/../head.php');
require_once(__DIR__ . '/../class/ThreadStorageManager.php');
require_once(__DIR__ . '/../class/Extraction/ThreadEmailExtractorEmailBody.php');
require_once(__DIR__ . '/../class/ThreadDatabaseOperations.php');

// Require authentication
requireAuth();

try {
    $threadDb = new ThreadDatabaseOperations();
    $threads = $threadDb->getThreads();
    $total_emails = 0;
    $success_count = 0;
    $error_count = 0;
    $error_types = [];
?>
<h1>Email Content Debug</h1>

<div class="container">
    <div class="stats-section">
        <h2>Statistics</h2>
        <div class="stats-grid">
            <div class="stat-box">
                <div class="stat-label">Total Emails</div>
                <div class="stat-value" id="total-emails">0</div>
            </div>
            <div class="stat-box success">
                <div class="stat-label">Successful</div>
                <div class="stat-value" id="success-count">0</div>
            </div>
            <div class="stat-box error">
                <div class="stat-label">Failed</div>
                <div class="stat-value" id="error-count">0</div>
            </div>
        </div>
        <div id="error-types"></div>
    </div>

    <?php foreach ($threads as $threadsObj): ?>
        <?php foreach ($threadsObj->threads as $thread): ?>
            <table class="email-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Content</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $emails = $thread->emails;
                foreach ($emails as $email):
                    try {
                        $eml = ThreadStorageManager::getInstance()->getThreadEmailContent($thread->id, $email->id);
                        $email_content = ThreadEmailExtractorEmailBody::extractContentFromEmail($eml);
                        $status = 'success';
                        $error = null;
                        $success_count++;
                    } catch (Exception $e) {
                        $status = 'error';
                        $error = $e->getMessage();
                        $email_content = null;
                        $error_count++;
                        // Track error types
                        $error_type = get_class($e);
                        if (!isset($error_types[$error_type])) {
                            $error_types[$error_type] = 0;
                        }
                        $error_types[$error_type]++;
                    }
                    $total_emails++;
                ?>
                    <tr class="<?php echo $status; ?>">
                        <td>Thread: <a href="thread-view?entityId=<?php echo urlencode($thread->entity_id); ?>&threadId=<?php echo urlencode($thread->id); ?>"><?php echo htmlspecialchars($thread->id); ?></a></td>
                        <td><a href="file?entityId=<?php echo urlencode($thread->entity_id); ?>&threadId=<?php echo urlencode($thread->id); ?>&body=<?php echo urlencode($email->id); ?>"><?php echo htmlspecialchars($email->id); ?></a></td>
                        <td><?php echo htmlspecialchars($email->datetime_received); ?></td>
                        <td>
                            <?php echo $status; ?>
                            <?php if ($error): ?>
                                <div class="error-details"><?php echo htmlspecialchars($error); ?></div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endforeach; ?>
    <?php endforeach; ?>
</div>

<?php
// Close the try block and handle any database errors
} catch (Exception $e) {
    echo "<div class='error-message'>Error: " . htmlspecialchars($e->getMessage()) . "</div>";
    exit;
}
?>

<?php
// Update statistics directly in PHP
echo "<script>
    document.getElementById('total-emails').textContent = '{$total_emails}';
    document.getElementById('success-count').textContent = '{$success_count}';
    document.getElementById('error-count').textContent = '{$error_count}';
</script>";
?>

<div id="error-types">
    <h3>Error Types</h3>
    <ul>
    <?php 
    if (!empty($error_types)) {
        foreach ($error_types as $type => $count) {
            printf('<li><strong>%s</strong>: %d</li>', 
                htmlspecialchars($type),
                $count
            );
        }
    } else {
        echo '<li>No errors encountered</li>';
    }
    ?>
    </ul>
</div>

<style>
.stats-section {
    margin: 20px 0;
    padding: 20px;
    background: #f8f8f8;
    border-radius: 5px;
    border: 1px solid #ddd;
}
.stats-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
    margin: 20px 0;
}
.stat-box {
    padding: 15px;
    border-radius: 5px;
    text-align: center;
    background: white;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}
.stat-box.success {
    border-left: 4px solid #90EE90;
}
.stat-box.error {
    border-left: 4px solid #FFB6C1;
}
.stat-label {
    font-size: 0.9em;
    color: #666;
}
.stat-value {
    font-size: 1.8em;
    font-weight: bold;
    margin-top: 5px;
}
#error-types {
    margin-top: 20px;
    padding: 15px;
    background: white;
    border-radius: 5px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}
#error-types ul {
    list-style: none;
    padding: 0;
}
#error-types li {
    margin: 5px 0;
    padding: 5px 0;
    border-bottom: 1px solid #eee;
}
.container {
    padding: 20px;
}
.thread-section {
    margin-bottom: 30px;
    border: 1px solid #ddd;
    padding: 15px;
    border-radius: 5px;
}
.email-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 10px;
}
.email-table th,
.email-table td {
    padding: 10px;
    border: 1px solid #ddd;
    text-align: left;
}
.email-table th {
    background: #f8f8f8;
    font-weight: bold;
}
.email-table tr.success {
    background-color: #f0fff0;
}
.email-table tr.error {
    background-color: #fff0f0;
}
.error-details {
    color: #721c24;
    font-size: 0.9em;
    margin-top: 5px;
}
.content-preview {
    max-height: 300px;
    overflow-y: auto;
}
.content-length {
    color: #666;
    font-size: 0.9em;
    margin-bottom: 5px;
}
.content-text {
    white-space: pre-wrap;
    word-wrap: break-word;
    margin: 0;
    font-size: 0.9em;
    background: #f8f8f8;
    padding: 10px;
    border-radius: 3px;
}
.error-message {
    background-color: #fff0f0;
    border: 1px solid #FFB6C1;
    padding: 15px;
    margin: 20px;
    border-radius: 5px;
    color: #721c24;
}
</style>

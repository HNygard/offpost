<?php
require_once(__DIR__ . '/../auth.php');
require_once(__DIR__ . '/../head.php');
require_once(__DIR__ . '/../class/ThreadStorageManager.php');
require_once(__DIR__ . '/../class/Extraction/ThreadEmailExtractorEmailBody.php');
require_once(__DIR__ . '/../class/ThreadDatabaseOperations.php');
require_once(__DIR__ . '/../class/Database.php');

// Require authentication
requireAuth();

// Initialize statistics
$total_emails = 0;
$success_count = 0;
$error_count = 0;
$error_types = [];

// Batch size for processing
$batch_size = isset($_GET['size']) ? (int)$_GET['size'] : 20;
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

try {
    $db = Database::getInstance();
    
    // Get total count of emails
    $total_count_query = "
        SELECT COUNT(*) as total 
        FROM thread_emails te 
        JOIN threads t ON te.thread_id = t.id
        WHERE te.content_read_status IS NULL";
    $total_result = $db->query($total_count_query)->fetch(PDO::FETCH_ASSOC);
    $total_count = $total_result['total'];
    
    // Get batch of emails
    $query = "
        SELECT 
            t.id as thread_id,
            t.entity_id,
            te.id as email_id,
            te.datetime_received,
            te.content_read_status
        FROM thread_emails te
        JOIN threads t ON te.thread_id = t.id
        WHERE te.content_read_status IS NULL
        ORDER BY te.datetime_received
        LIMIT ? OFFSET ?";
    
    $stmt = $db->prepare($query);
    $stmt->execute([$batch_size, $offset]);
    $emails = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<h1>Email Content Debug</h1>

<div class="page-nav">
    <a href="email-sending-overview.php" class="nav-link">Email Sending Overview</a>
    <a href="extraction-overview.php" class="nav-link">Extraction Overview</a>
    <a href="scheduled-email-sending.php" class="nav-link">Scheduled Email Sending</a>
    <a href="scheduled-email-receiver.php" class="nav-link">Scheduled Email Receiver</a>
    <a href="scheduled-email-extraction.php" class="nav-link">Scheduled Email Extraction</a>
    <a href="scheduled-imap-handling.php" class="nav-link">Scheduled IMAP Handling</a>
    <a href="scheduled-thread-follow-up.php" class="nav-link">Scheduled Thread Follow-up</a>
    <a href="thread-status-overview.php" class="nav-link">Thread Status Overview</a>
</div>

<div class="container">
    <div class="stats-section">
        <h2>Statistics</h2>
        <div class="batch-info">
            <p>Processing emails <?php echo $offset + 1; ?> to <?php echo min($offset + $batch_size, $total_count); ?> of <?php echo $total_count; ?></p>
            <div class="page-numbers">
                <?php
                $total_pages = ceil($total_count / $batch_size);
                $current_page = floor($offset / $batch_size) + 1;
                
                // Always show first page
                if ($current_page > 1) {
                    echo '<a href="?offset=0" class="page-link">1</a>';
                    if ($current_page > 2) {
                        echo '<span class="page-ellipsis">...</span>';
                    }
                }
                
                // Show pages around current page
                for ($i = max(2, $current_page - 2); $i <= min($total_pages - 1, $current_page + 2); $i++) {
                    $page_offset = ($i - 1) * $batch_size;
                    $class = ($i === $current_page) ? 'page-link current' : 'page-link';
                    echo "<a href=\"?offset={$page_offset}\" class=\"{$class}\">{$i}</a>";
                }
                
                // Always show last page
                if ($current_page < $total_pages) {
                    if ($current_page < $total_pages - 1) {
                        echo '<span class="page-ellipsis">...</span>';
                    }
                    $last_page_offset = ($total_pages - 1) * $batch_size;
                    echo "<a href=\"?offset={$last_page_offset}\" class=\"page-link\">{$total_pages}</a>";
                }
                ?>
            </div>
        </div>
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

    <table class="email-table">
        <thead>
            <tr>
                <th>Thread ID</th>
                <th>Email ID</th>
                <th>Date</th>
                <th>Content Read Status</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
        <?php 
        foreach ($emails as $email):
            try {
                $eml = ThreadStorageManager::getInstance()->getThreadEmailContent($email['thread_id'], $email['email_id']);
                $email_content = ThreadEmailExtractorEmailBody::extractContentFromEmail($eml);
                
                $status = 'success';
                $error = null;
                $success_count++;
            } catch (Exception $e) {
                $status = 'error';
                $error = $e->getMessage();
                $error_count++;
                // Track error types
                $error_type = get_class($e);
                if (!isset($error_types[$error_type])) {
                    $error_types[$error_type] = 0;
                }
                $error_types[$error_type]++;
            }

            // Update content_read_status based on result
            Database::beginTransaction();
            $update_query = "UPDATE thread_emails SET content_read_status = ? WHERE id = ?";
            $update_stmt = $db->prepare($update_query);
            $update_stmt->execute([$status, $email['email_id']]);
            Database::commit();

            $total_emails++;
        ?>
            <tr class="<?php echo $status; ?>">
                <td>Thread: <a href="thread-view?entityId=<?php echo urlencode($email['entity_id']); ?>&threadId=<?php echo urlencode($email['thread_id']); ?>"><?php echo htmlspecialchars($email['thread_id']); ?></a></td>
                <td><a href="file?entityId=<?php echo urlencode($email['entity_id']); ?>&threadId=<?php echo urlencode($email['thread_id']); ?>&body=<?php echo urlencode($email['email_id']); ?>"><?php echo htmlspecialchars($email['email_id']); ?></a></td>
                <td><?php echo htmlspecialchars($email['datetime_received']); ?></td>
                <td><?php echo htmlspecialchars($email['content_read_status'] ?? 'null'); ?></td>
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
.batch-info {
    margin: 15px 0;
    padding: 10px;
    background: #fff;
    border-radius: 3px;
    text-align: center;
}
.batch-nav {
    display: inline-block;
    padding: 5px 15px;
    margin: 0 10px;
    background: #007bff;
    color: white;
    text-decoration: none;
    border-radius: 3px;
}
.page-numbers {
    margin: 15px 0;
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 5px;
}

.page-link {
    padding: 5px 10px;
    background: white;
    border: 1px solid #ddd;
    border-radius: 3px;
    color: #333;
    text-decoration: none;
    min-width: 20px;
    text-align: center;
}

.page-link:hover {
    background: #f0f0f0;
    border-color: #999;
}

.page-link.current {
    background: #007bff;
    color: white;
    border-color: #0056b3;
}

.page-ellipsis {
    color: #666;
    padding: 0 5px;
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

.page-nav {
    margin: 20px;
    padding: 15px;
    background: #f8f8f8;
    border-radius: 5px;
    border: 1px solid #ddd;
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.nav-link {
    padding: 8px 15px;
    background: white;
    border: 1px solid #ddd;
    border-radius: 3px;
    color: #333;
    text-decoration: none;
    font-size: 0.9em;
}

.nav-link:hover {
    background: #f0f0f0;
    border-color: #999;
}
</style>

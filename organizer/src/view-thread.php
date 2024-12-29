<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/class/Threads.php';

// Require authentication
requireAuth();

// Get thread ID and entity ID from URL parameters
$threadId = isset($_GET['threadId']) ? $_GET['threadId'] : null;
$entityId = isset($_GET['entityId']) ? $_GET['entityId'] : null;

if (!$threadId || !$entityId) {
    die('Thread ID and Entity ID are required');
}

/* @var Threads[] $threads */
$allThreads = getThreads();
$thread = null;
$threadEntity = null;

// Find the specific thread
foreach ($allThreads as $file => $threads) {
    if ($threads->entity_id === $entityId) {
        foreach ($threads->threads as $t) {
            if (getThreadId($t) === $threadId) {
                $thread = $t;
                $threadEntity = $threads;
                break 2;
            }
        }
    }
}

if (!$thread) {
    die('Thread not found');
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Thread - <?= htmlescape($thread->title) ?></title>
    <link href="style.css" rel="stylesheet">
</head>
<body>
    <div class="container">
        <?php include 'header.php'; ?>

        <h1>Thread: <?= htmlescape($thread->title) ?></h1>

        <div class="thread-details">
            <p>
                <strong>Entity:</strong> <?= htmlescape($threadEntity->title_prefix) ?> (<?= htmlescape($threadEntity->entity_id) ?>)<br>
                <strong>Identity:</strong> <?= htmlescape($thread->my_name) ?> &lt;<?= htmlescape($thread->my_email) ?>&gt;
            </p>

            <div class="status-labels">
                <?= $thread->sent ? '<span class="label label_ok">Sent</span>' : '<span class="label label_warn">Not sent</span>' ?>
                <?= $thread->archived ? '<span class="label label_ok">Archived</span>' : '<span class="label label_warn">Not archived</span>' ?>
            </div>

            <div class="labels">
                <?php foreach ($thread->labels as $label): ?>
                    <span class="label"><?= htmlescape($label) ?></span>
                <?php endforeach; ?>
            </div>

            <div class="action-links">
                <a href="classify-email.php?entityId=<?= htmlescape($entityId) ?>&threadId=<?= htmlescape($threadId) ?>">Classify</a>
                <a href="thread__send-email.php?entityId=<?= htmlescape($entityId) ?>&threadId=<?= htmlescape($threadId) ?>">Send email</a>
                <a href="setSuccessForThreadAndDocument.php?entityId=<?= htmlescape($entityId) ?>&threadId=<?= htmlescape($threadId) ?>">Archive thread</a>
            </div>
        </div>

        <h2>Emails in Thread</h2>
        <div class="emails-list">
            <?php
            if (!isset($thread->emails)) {
                $thread->emails = array();
            }
            foreach ($thread->emails as $email):
                $label_type = getLabelType('email', $email->status_type);
            ?>
                <div class="email-item<?= $email->ignore ? ' ignored' : '' ?>">
                    <div class="email-header">
                        <span class="datetime"><?= htmlescape($email->datetime_received) ?></span>
                        <span class="email-type"><?= htmlescape($email->email_type) ?></span>
                        <span class="<?= $label_type ?>"><?= htmlescape($email->status_text) ?></span>
                    </div>

                    <?php if (isset($email->description) && $email->description): ?>
                        <div class="email-description">
                            <?= htmlescape($email->description) ?>
                        </div>
                    <?php endif; ?>

                    <?php if (isset($email->id)): ?>
                        <div class="email-links">
                            <a href="file.php?entityId=<?= htmlescape($entityId) ?>&threadId=<?= htmlescape($threadId) ?>&body=<?= htmlescape($email->id) ?>">View email</a> (text)
                        </div>
                    <?php endif; ?>

                    <?php if (isset($email->attachments) && count($email->attachments) > 0): ?>
                        <div class="attachments">
                            <h4>Attachments:</h4>
                            <ul>
                                <?php foreach ($email->attachments as $att):
                                    $label_type = getLabelType('attachement', $att->status_type);
                                ?>
                                    <li>
                                        <span class="<?= $label_type ?>"><?= htmlescape($att->status_text) ?></span>
                                        <?= htmlescape($att->filetype) ?> - 
                                        <?php if (isset($att->location)): ?>
                                            <a href="file.php?entityId=<?= htmlescape($entityId) ?>&threadId=<?= htmlescape($threadId) ?>&attachment=<?= urlencode($att->location) ?>"><?= htmlescape($att->name) ?></a>
                                        <?php else: ?>
                                            <?= htmlescape($att->name) ?>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</body>
</html>

<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/class/ThreadUtils.php';
require_once __DIR__ . '/class/ThreadEmailService.php';
require_once __DIR__ . '/class/Threads.php';
require_once __DIR__ . '/class/ThreadAuthorization.php';
require_once __DIR__ . '/class/ThreadLabelFilter.php';
require_once __DIR__ . '/class/ThreadEmailClassifier.php';
require_once __DIR__ . '/class/Database.php';
require_once __DIR__ . '/class/ThreadFileOperations.php';
require_once __DIR__ . '/class/ThreadStorageManager.php';

// Require authentication
requireAuth();

$storageManager = ThreadStorageManager::getInstance();
$userId = $_SESSION['user']['sub']; // OpenID Connect subject identifier

// Get threads with authorization check built into query
$allThreads = $storageManager->getThreads($userId);

// Filter archived and labeled threads
$filteredThreads = [];
foreach ($allThreads as $file => $threads) {
    $filteredThreads[$file] = clone $threads;
    $filteredThreads[$file]->threads = [];
    
    if (!isset($threads->threads)) {
        continue;
    }

    foreach ($threads->threads as $thread) {
        if ($thread->archived && !isset($_GET['archived'])) {
            continue;
        }

        if (isset($_GET['label_filter']) && !ThreadLabelFilter::matches($thread, $_GET['label_filter'])) {
            continue;
        }
        
        $filteredThreads[$file]->threads[] = $thread;
    }
}

$allThreads = $filteredThreads;

?>
<!DOCTYPE html>
<html>
<head>
    <?php 
    $pageTitle = 'Offpost';
    include 'head.php';
    ?>
    <script src="/js/threadLabels.js"></script>
</head>
<body>
    <div class="container">
        <?php include 'header.php'; ?>

        <h1>Offpost - Email Engine Organizer</h1>


        <div style="font-size: 0.7em;">
            <h3 style="display: inline;">Old tools:</h3>
            <ul class="nav-links" style="display: inline;">
                <li><a href="/update-imap?update-only-before=<?= date('Y-m-d H:i:s') ?>">Update email threads (folders) to IMAP</a></li>
                <li><a href="/update-identities">Update identities into Roundcube</a></li>
            </ul>
        </div>

        <?php
        // Count total threads
        $totalThreads = 0;
        foreach ($allThreads as $file => $threads) {
            if (isset($threads->threads)) {
                $totalThreads += count($threads->threads);
            }
        }
        ?>
        <h2>Threads (<?= $totalThreads ?>)</h2>

        <ul class="nav-links">
            <li><a href="/thread-start">Start new thread</a></li>
            <li><?php if (isset($_GET['archived'])): ?>
                <a href="/">Show only active</a>
            <?php else: ?>
                <a href="?archived">Show archived</a>
            <?php endif; ?></li>
        </ul>

        <?php
            if (isset($_GET['label_filter']) && count($allThreads) > 0) {
                ?>
        Filtered on label: <?= htmlspecialchars($_GET['label_filter'], ENT_QUOTES) ?>
        <ul class="nav-links">
            <li><a href="/">Back to all threads</a></li>
            <li><a href="/api/threads?label=<?=urlencode($_GET['label_filter'])?>">View API response for label</a></li>
            <li>
                <form action="/archive-threads-by-label" method="post" style="display: inline;">
                    <input type="hidden" name="label" value="<?= htmlspecialchars($_GET['label_filter'], ENT_QUOTES) ?>">
                    <button type="submit" onclick="return confirm('Are you sure you want to archive all threads with this label?')">Archive all threads with this label</button>
                </form>
            </li>
        </ul>
        
        <?php
            }
        ?>

        <hr>
        <div id="label-summary"></div>
        <div id="current-filter"></div>

        <table>
            <tr>
                <td>Entity name / id</td>
                <th>Title / My name &lt;email&gt;</th>
                <td>Status</td>
                <td>Labels</td>
            </tr>
            <?php
            foreach ($allThreads as $file => $threads) {

                foreach ($threads->threads as $thread) {
                    ?>
                    <tr id="thread-<?= $thread->id ?>">
                        <?php /* Entity name / id */ ?>
                        <td>
                            <b><?= htmlescape($thread->getEntityName()) ?></b><br>
                            <span style="font-size: 0.8em;"><?= $threads->entity_id ?></span>
                        </td>
                        <?php /* Title / My name <email> */ ?>
                        <td>
                            <b><?= htmlescape($thread->title) ?></b><br>
                            <span style="font-size: 0.8em;">
                                <b><?= htmlescape($thread->my_name) ?></b>
                                &lt;<?= htmlescape($thread->my_email) ?>&gt;
                            </span><br>
                            <div class="action-links">
                                <a href="/thread-view?entityId=<?=
                                    htmlescape($threads->entity_id)?>&threadId=<?=
                                    htmlescape($thread->id)?>">View thread</a>
                                <a href="/thread-classify?entityId=<?=
                                    htmlescape($threads->entity_id)?>&threadId=<?=
                                    htmlescape($thread->id)?>">Classify</a>
                                <a href="/thread-send-email?entityId=<?=
                                    htmlescape($threads->entity_id)?>&threadId=<?=
                                    htmlescape($thread->id)?>">Send email</a>
                                <a href="/setSuccessForThreadAndDocument?entityId=<?=
                                    htmlescape($threads->entity_id)?>&threadId=<?=
                                    htmlescape($thread->id)?>">Archive thread</a>
                            </div>
                        </td>
                        <?php /* Status */ ?>
                        <td>
                            <?php 
                            switch ($thread->sending_status) {
                                case Thread::SENDING_STATUS_STAGED:
                                    echo '<span class="label label_info"><a href="?label_filter=staged">Staged</a></span>';
                                    break;
                                case Thread::SENDING_STATUS_READY_FOR_SENDING:
                                    echo '<span class="label label_warn"><a href="?label_filter=ready_for_sending">Ready for sending</a></span>';
                                    break;
                                case Thread::SENDING_STATUS_SENDING:
                                    echo '<span class="label label_warn"><a href="?label_filter=sending">Sending</a></span>';
                                    break;
                                case Thread::SENDING_STATUS_SENT:
                                    echo '<span class="label label_ok"><a href="?label_filter=sent">Sent</a></span>';
                                    break;
                                default:
                                    echo $thread->sent ? '<span class="label label_ok"><a href="?label_filter=sent">Sent</a></span>' : '<span class="label label_warn"><a href="?label_filter=not_sent">Not sent</a></span>';
                            }
                            ?><br>
                            <?= $thread->archived ? '<span class="label label_ok"><a href="?label_filter=archived">Archived</a></span>' : '<span class="label label_warn"><a href="?label_filter=not_archived">Not archived</a></span>' ?>
                        </td>
                        <?php /* Labels */ ?>
                        <td>
                            <?php
                            foreach ($thread->labels as $label) {
                                ?><span class="label"><a href="?label_filter=<?=urlencode($label)?>"><?= $label ?></a></span><?php
                            }
                            ?>
                        </td>
                        <td>
                            <?php
                            if (!isset($thread->emails)) {
                                $thread->emails = array();
                            }
                            foreach ($thread->emails as $email) {
                                $label_type = getLabelType('email', $email->status_type);
                                ?>
                                <div <?= $email->ignore ? ' style="color: gray;"' : '' ?>>
                                    <?= $email->datetime_received ?>:
                                    <?= $email->email_type ?> -
                                    <span class="<?= $label_type ?>"><?= $email->status_text ?></span>
                                    <?php
                                    if (ThreadEmailClassifier::getClassificationLabel($email) !== null) {
                                        ?>
                                        <span style="font-size: 0.8em"><br>
                                        [Classified by <?= ThreadEmailClassifier::getClassificationLabel($email) ?>]
                                        </span>
                                        <?php
                                    }
                                    ?>
                                    <br>
                                    <i><?= htmlescape(isset($email->description) ? $email->description : '') ?></i>
                                    <?php
                                    if (isset($email->attachments)) {
                                        foreach ($email->attachments as $att) {
                                            $label_type = getLabelType('attachement', $att->status_type);
                                            echo chr(10);
                                            ?>
                                            <li>
                                                <span class="<?= $label_type ?>"><?= $att->status_text ?></span>
                                                <?= $att->filetype ?> - <i><?= htmlentities($att->name, ENT_QUOTES) ?></i>
                                            </li>
                                            <?php
                                        }
                                    }
                                    ?>
                                </div>
                                <br>
                                <?php
                            }
                            ?>
                        </td>
                    </tr>
                    <?php
                }
            }
            ?>
        </table>
    </div>
</body>
</html>

<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/class/Threads.php';
require_once __DIR__ . '/class/ThreadAuthorization.php';

// Require authentication
requireAuth();

/* @var Threads[] $threads */
$allThreads = getThreads();
$userId = $_SESSION['user']['sub']; // OpenID Connect subject identifier

// Filter threads based on user access
$filteredThreads = [];
foreach ($allThreads as $file => $threads) {
    $filteredThreads[$file] = clone $threads;
    $filteredThreads[$file]->threads = [];
    
    if (!isset($threads->threads)) {
        continue;
    }

    foreach ($threads->threads as $thread) {
        if (ThreadAuthorizationManager::canUserAccessThread($thread->id, $userId)) {
            $filteredThreads[$file]->threads[] = $thread;
        }
    }
}

$allThreads = $filteredThreads;

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Engine Organizer</title>
    <link href="style.css" rel="stylesheet">
</head>
<body>
    <div class="container">
        <?php include 'header.php'; ?>

        <h1>Email Engine Organizer</h1>
        <h2>Threads</h2>

        <ul class="nav-links">
            <li><a href="start-thread.php">Start new thread</a></li>
            <li><a href="update-imap.php?update-only-before=<?= date('Y-m-d H:i:s') ?>">Update email threads (folders) to IMAP</a></li>
            <li><a href="update-identities.php">Update identities into Roundcube</a></li>
            <li><a href="?archived">Show archived</a></li>
        </ul>

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
                    if($thread->archived && !isset($_GET['archived'])) {
                        continue;
                    }

                    if (isset($_GET['label_filter'])) {
                        if (!in_array($_GET['label_filter'], $thread->labels)) {
                            continue;
                        }
                    }
                    ?>
                    <tr>
                        <?php /* Entity name / id */ ?>
                        <td>
                            <b><?= $threads->title_prefix ?></b><br>
                            <span style="font-size: 0.8em;"><?= $threads->entity_id ?></span>
                        </td>
                        <?php /* Title / My name <email> */ ?>
                        <td>
                            <b><?= $thread->title ?></b><br>
                            <span style="font-size: 0.8em;">
                                <b><?= $thread->my_name ?></b>
                                &lt;<?= $thread->my_email ?>&gt;
                            </span><br>
                            <div class="action-links">
                                <a href="view-thread.php?entityId=<?=
                                    htmlescape($threads->entity_id)?>&threadId=<?=
                                    htmlescape(getThreadId($thread))?>">View thread</a>
                                <a href="classify-email.php?entityId=<?=
                                    htmlescape($threads->entity_id)?>&threadId=<?=
                                    htmlescape(getThreadId($thread))?>">Classify</a>
                                <a href="thread__send-email.php?entityId=<?=
                                    htmlescape($threads->entity_id)?>&threadId=<?=
                                    htmlescape(getThreadId($thread))?>">Send email</a>
                                <a href="setSuccessForThreadAndDocument.php?entityId=<?=
                                    htmlescape($threads->entity_id)?>&threadId=<?=
                                    htmlescape(getThreadId($thread))?>">Archive thread</a>
                            </div>
                        </td>
                        <?php /* Status */ ?>
                        <td>
                            <?= $thread->sent ? '<span class="label label_ok">Sent</span>' : '<span class="label label_warn">Not sent</span> ' ?><br>
                            <?= $thread->archived ? '<span class="label label_ok">Archived</span>' : '<span class="label label_warn">Not archived</span> ' ?>
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

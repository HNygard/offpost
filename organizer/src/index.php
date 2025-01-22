<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/class/Threads.php';
require_once __DIR__ . '/class/ThreadAuthorization.php';
require_once __DIR__ . '/class/ThreadLabelFilter.php';
require_once __DIR__ . '/class/ThreadEmailClassifier.php';

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
        if($thread->archived && !isset($_GET['archived'])) {
            continue;
        }

        if (isset($_GET['label_filter']) && !ThreadLabelFilter::matches($thread, $_GET['label_filter'])) {
            continue;
        }
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
    <?php 
    $pageTitle = 'hello';
    include 'head.php';
    ?>
</head>
<body>
    <div class="container">
        <?php include 'header.php'; ?>

        <h1>hello</h1>
        <h2>Threads</h2>

        <ul class="nav-links">
            <li><a href="/thread-start">Start new thread</a></li>
            <li><a href="/update-imap?update-only-before=<?= date('Y-m-d H:i:s') ?>">Update email threads (folders) to IMAP</a></li>
            <li><a href="/update-identities">Update identities into Roundcube</a></li>
            <li><a href="?archived">Show archived</a></li>
        </ul>

        <?php

            if (isset($_GET['label_filter']) && count($allThreads) > 0) {
                ?>
        Filtered on label: <?= htmlspecialchars($_GET['label_filter'], ENT_QUOTES) ?>
        <ul class="nav-links">
            <li><a href="/">Back to all threads</a></li>
            <li><a href="/api/threads?label=<?=urlencode($_GET['label_filter'])?>">View API response for label</a></li>
        </ul>
        
        <?php
            }
        ?>

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
                            <?= $thread->sent ? '<span class="label label_ok"><a href="?label_filter=sent">Sent</a></span>' : '<span class="label label_warn"><a href="?label_filter=not_sent">Not sent</a></span>' ?><br>
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

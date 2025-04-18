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

// Get thread statuses for all threads
$threadIds = [];
foreach ($filteredThreads as $file => $threads) {
    if (isset($threads->threads)) {
        foreach ($threads->threads as $thread) {
            $threadIds[] = $thread->id;
        }
    }
}

// Get all thread statuses efficiently
$threadStatuses =
 ThreadStatusRepository::getAllThreadStatusesEfficient($threadIds, archived: false)
+ ThreadStatusRepository::getAllThreadStatusesEfficient($threadIds, archived: true);

// Helper function to convert thread status to human-readable string
function threadStatusToString($status) {
    switch ($status) {
        case ThreadStatusRepository::ERROR_THREAD_NOT_FOUND:
            return 'ERROR: Thread not found';
        case ThreadStatusRepository::ERROR_NO_FOLDER_FOUND:
            return 'ERROR: Email not synced';
        case ThreadStatusRepository::ERROR_MULTIPLE_FOLDERS:
            return 'ERROR: Multiple folders found';
        case ThreadStatusRepository::ERROR_NO_SYNC:
            return 'ERROR: No sync';
        case ThreadStatusRepository::ERROR_OLD_SYNC:
            return 'ERROR: Sync outdated';
        case ThreadStatusRepository::NOT_SENT:
            return 'Not sent';
        case ThreadStatusRepository::EMAIL_SENT_NOTHING_RECEIVED:
            return 'Sent, no response';
        case ThreadStatusRepository::STATUS_OK:
            return 'Email sync OK';
        default:
            return 'Unknown status';
    }
}

// Helper function to get label class for thread status
function getThreadStatusLabelClass($status) {
    switch ($status) {
        case ThreadStatusRepository::ERROR_NO_FOLDER_FOUND:
        case ThreadStatusRepository::ERROR_MULTIPLE_FOLDERS:
            return 'label_error';
        case ThreadStatusRepository::ERROR_OLD_SYNC:
            return 'label_warn';
        case ThreadStatusRepository::NOT_SENT:
            return 'label_info';
        case ThreadStatusRepository::EMAIL_SENT_NOTHING_RECEIVED:
            return 'label_warn';
        case ThreadStatusRepository::STATUS_OK:
            return 'label_ok';
        default:
            return 'label_info';
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <?php 
    $pageTitle = 'Offpost';
    include 'head.php';
    ?>
    <script src="/js/threadMultiSelect.js?3"></script>
    <script src="/js/threadLabels.js?3"></script>
    <script src="/js/tableSearch.js?3"></script>
</head>
<body>
    <div class="container">
        <?php include 'header.php'; ?>

        <h1>Offpost - Email Engine Organizer</h1>


        <?php if ($environment == 'development') { ?>
            <div style="font-size: 0.7em;">
                <h3 style="display: inline;">Dev tools:</h3>
                <ul class="nav-links" style="display: inline;">
                    <li><a href="/update-imap?update-only-before=<?= date('Y-m-d H:i:s') ?>">Update email threads (folders) to IMAP</a></li>

                    <li><a href="/scheduled-email-sending">Scheduled email sending</a></li>
                    <li><a href="/scheduled-email-receiver">Scheduled email receiver</a></li>
                </ul>
            </div>
        <?php } ?>
        <?php if (in_array($_SESSION['user']['sub'], $admins)) { ?>
            <div style="font-size: 0.7em;">
                <h3 style="display: inline;">Admin tools:</h3>
                <ul class="nav-links" style="display: inline;">
                    <li><a href="/email-sending-overview">Email sending overview</a></li>
                    <li><a href="/extraction-overview">Email extraction overview</a></li>
                    <li><a href="/update-imap">Update IMAP</a></li>
                    <li><a href="/update-identities">Update identities into Roundcube</a></li>
                </ul>
            </div>
        <?php } ?>

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
            <li><a href="/entities">View entities</a></li>
            <li><?php if (isset($_GET['archived'])): ?>
                <a href="/">Show only active</a>
            <?php else: ?>
                <a href="?archived">Show archived</a>
            <?php endif; ?></li>
        </ul>
        
        <?php
        // Display success/error messages if set
        if (isset($_SESSION['success_message'])) {
            echo '<div class="alert alert-success">' . htmlescape($_SESSION['success_message']) . '</div>';
            unset($_SESSION['success_message']);
        }
        
        if (isset($_SESSION['error_message'])) {
            echo '<div class="alert alert-error">' . htmlescape($_SESSION['error_message']) . '</div>';
            unset($_SESSION['error_message']);
        }
        ?>
        
        <!-- Bulk Actions Form -->
        <div class="bulk-actions-container">
            <form action="/thread-bulk-actions" method="post" id="bulk-actions-form">
                <select name="action" id="bulk-action">
                    <option value="">-- Select Action --</option>
                    <option value="archive">Archive thread</option>
                    <option value="ready_for_sending">Mark thread as ready for sending</option>
                    <option value="make_private">Mark thread as private</option>
                    <option value="make_public">Mark thread as public</option>
                </select>
                <button type="submit" id="bulk-action-button" disabled>Apply to Selected</button>
                <div class="selected-count-container" id="selected-count-container">
                    <span id="selected-count">0</span> thread(s) selected
                </div>
            </form>
        </div>

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

        <hr>
        <div id="label-summary"></div>
        <div id="current-filter"></div>

        <table>
            <tr>
                <th>
                    <div class="thread-checkbox-container">
                        <input type="checkbox" id="select-all-threads" title="Select all threads">
                    </div>
                </th>
                <td>Entity name / id<br>
                    <input type="text" id="entity-search" placeholder="Filter by entity name/id...">
                </td>
                <th>Title / My name &lt;email&gt;<br>
                    <input type="text" id="title-search" placeholder="Filter by title/name...">
                </th>
                <td>Status<br>
                    <input type="text" id="status-search" placeholder="Filter by status...">
                </td>
                <td>Labels<br>
                    <input type="text" id="label-search" placeholder="Filter by label...">
                </td>
            </tr>
            <?php
            foreach ($allThreads as $file => $threads) {

                foreach ($threads->threads as $thread) {
                    ?>
                    <tr id="thread-<?= $thread->id ?>">
                        <td>
                            <div class="thread-checkbox-container">
                                <input type="checkbox" class="thread-checkbox" name="thread_ids[]" value="<?= htmlescape($threads->entity_id) ?>:<?= htmlescape($thread->id) ?>" form="bulk-actions-form">
                            </div>
                        </td>
                        <?php /* Entity name / id */ ?>
                        <td>
                            <b><?= Entity::getNameHtml($thread->getEntity()) ?></b><br>
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
                            </div>
                        </td>
                        <?php /* Status */ ?>
                        <td>
                            <?php 
                            switch ($thread->sending_status) {
                                case Thread::SENDING_STATUS_STAGING:
                                    echo '<span class="label label_info"><a href="?label_filter=staging">' 
                                        . ThreadHistory::sendingStatusToString($thread->sending_status) . '</a></span>';
                                    break;
                                case Thread::SENDING_STATUS_READY_FOR_SENDING:
                                    echo '<span class="label label_warn"><a href="?label_filter=ready_for_sending">' 
                                        . ThreadHistory::sendingStatusToString($thread->sending_status) . '</a></span>';
                                    break;
                                case Thread::SENDING_STATUS_SENDING:
                                    echo '<span class="label label_warn"><a href="?label_filter=sending">' 
                                        . ThreadHistory::sendingStatusToString($thread->sending_status) . '</a></span>';
                                    break;
                                case Thread::SENDING_STATUS_SENT:
                                    echo '<span class="label label_ok"><a href="?label_filter=sent">' 
                                        . ThreadHistory::sendingStatusToString($thread->sending_status) . '</a></span>';
                                    break;
                                default:
                                    throw new Exception('Unknown sending status: ' . $thread->sending_status);
                            }
                            ?><br>
                            <?= $thread->archived ? '<span class="label label_ok"><a href="?label_filter=archived">Archived</a></span>' : '<span class="label label_warn"><a href="?label_filter=not_archived">Not archived</a></span>' ?>
                            
                            <?php
                            // Display thread status if available
                            if (isset($threadStatuses[$thread->id])) {
                                $statusData = $threadStatuses[$thread->id];
                                $statusCode = $statusData['status'];
                                $statusText = threadStatusToString($statusCode);
                                $statusClass = getThreadStatusLabelClass($statusCode);

                                $lastChecked = ($statusData['email_server_last_checked_at'] == null) 
                                    ? 'NOT CHECKED'
                                    : date('H:i:s d.m.Y', $statusData['email_server_last_checked_at']);
                                
                                echo '<br><span class="label ' . $statusClass . '"'
                                    . ' title="Email server last checked at ' . $lastChecked . '"'
                                    . '>' . htmlescape($statusText) . '</span>';
                                
                                // Display email counts if relevant
                                if ($statusCode == ThreadStatusRepository::EMAIL_SENT_NOTHING_RECEIVED || 
                                    $statusCode == ThreadStatusRepository::STATUS_OK) {
                                    echo '<br><span style="font-size: 0.8em;">Emails: '
                                         . $statusData['email_count_out'] . ' out, '
                                         . $statusData['email_count_in'] . ' in'
                                         . '</span>';
                                }
                            }
                            ?>
                        </td>
                        <?php /* Labels */ ?>
                        <td>
                            <?php
                            foreach ($thread->labels as $label) {
                                if (empty($label)) {
                                    continue;
                                }
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

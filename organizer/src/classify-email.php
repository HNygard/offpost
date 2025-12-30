<?php

require_once __DIR__ . '/class/Enums/ThreadEmailStatusType.php';
use App\Enums\ThreadEmailStatusType;
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/class/Threads.php';
require_once __DIR__ . '/class/ThreadEmailClassifier.php';
require_once __DIR__ . '/class/ThreadStorageManager.php';
require_once __DIR__ . '/class/ThreadEmailHistory.php';
require_once __DIR__ . '/class/ThreadAuthorization.php';
require_once __DIR__ . '/class/common.php';

$emailHistory = new ThreadEmailHistory();

// Require authentication
requireAuth();

// Check required parameters
if (!isset($_GET['threadId']) || !isset($_GET['emailId'])) {
    http_response_code(400);
    header('Content-Type: text/plain');
    die("Missing required parameters: threadId and emailId are required");
}

$threadId = $_GET['threadId'];
$emailId = $_GET['emailId'];

// Validate threadId format
if (!is_uuid($threadId)) {
    http_response_code(400);
    header('Content-Type: text/plain');
    die("Invalid threadId parameter");
}

$thread = Thread::loadFromDatabaseOrNone($threadId);

// Check if thread exists
if (!$thread) {
    http_response_code(404);
    header('Content-Type: text/plain');
    die("Thread not found: threadId={$threadId}");
}

// Check authorization
if (!ThreadAuthorizationManager::canUserAccessThread($threadId, $_SESSION['user']['sub'])) {
    http_response_code(403);
    header('Content-Type: text/plain');
    die("Unauthorized access to thread: {$threadId}");
}

// Check if email exists in thread
$emailFound = false;
foreach ($thread->emails as $email) {
    if ($email->id == $emailId) {
        $emailFound = true;
        break;
    }
}

if (!$emailFound) {
    http_response_code(404);
    header('Content-Type: text/plain');
    die("Email not found in thread: emailId={$emailId}, threadId={$threadId}");
}

// Run automatic classification if thread is found
if ($thread !== null) {
    $classifier = new ThreadEmailClassifier();
    $thread = $classifier->classifyEmails($thread);
    
    // Log auto-classification for each email
    foreach ($thread->emails as $email) {
        if (isset($email->auto_classified) && $email->auto_classified) {
            $emailHistory->logAction(
                $thread->id,
                $email->id,
                'auto_classified',
                null,
                [
                    'status_type' => ($email->status_type instanceof ThreadEmailStatusType ? $email->status_type->value : $email->status_type),
                    'status_text' => $email->status_text
                ]
            );
        }
    }
}

if (isset($_POST['submit'])) {
    $anyUnknown = false;
    $classifier = new ThreadEmailClassifier();
    foreach ($thread->emails as $email) {
        $emailId = str_replace(' ', '_', str_replace('.', '_', $email->id));
        $newIgnore = isset($_POST[$emailId . '-ignore']) && $_POST[$emailId . '-ignore'] == 'true';
        $newStatusText = $_POST[$emailId . '-status_text'];
        $newStatusTypeString = $_POST[$emailId . '-status_type'];
        $newStatusType = ThreadEmailStatusType::tryFrom($newStatusTypeString) ?? ThreadEmailStatusType::UNKNOWN; // Fallback to UNKNOWN
        
        // Check if status was actually changed
        $currentStatusValue = ($email->status_type instanceof ThreadEmailStatusType ? $email->status_type->value : $email->status_type);
        if ($currentStatusValue !== $newStatusType->value ||
            $email->status_text !== $newStatusText ||
            $email->ignore !== $newIgnore) {
            // Remove auto classification since status was manually changed
            $email = $classifier->removeAutoClassification($email);
            
            // Log classification change
            $emailHistory->logAction(
                $thread->id,
                $email->id,
                'classified',
                $_SESSION['user']['sub'],
                [
                    'status_type' => $newStatusType->value,
                    'status_text' => $newStatusText
                ]
            );
            
            // Log ignore status change if it changed
            if ($email->ignore !== $newIgnore) {
                $emailHistory->logAction(
                    $thread->id,
                    $email->id,
                    'ignored',
                    $_SESSION['user']['sub'],
                    ['ignored' => $newIgnore]
                );
            }
        }
        
        $email->ignore = $newIgnore;
        $email->status_text = $newStatusText;
        $email->status_type = $newStatusType; // Assign the enum instance
        $email->answer = $_POST[$emailId . '-answer'];
        if (isset($email->attachments)) {
            foreach ($email->attachments as $att) {
                $attId = str_replace(' ', '_', str_replace('.', '_', $att->location));
                $attNewStatusTypeString = $_POST[$emailId . '-att-' . $attId . '-status_type'];
                $att->status_text = $_POST[$emailId . '-att-' . $attId . '-status_text'];
                $att->status_type = ThreadEmailStatusType::tryFrom($attNewStatusTypeString) ?? ThreadEmailStatusType::UNKNOWN;
            }
        }
        if ($email->status_type == ThreadEmailStatusType::UNKNOWN) {
            $anyUnknown = true;
        }
    }
    if (!$anyUnknown) {
        // -> Remove any 'unknown' labels
        $labels = array();
        foreach ($thread->labels as $label) {
            if ($label != 'uklassifisert-epost') {
                $labels[] = $label;
            }
        }
        $thread->labels = $labels;
    }
    // Use ThreadStorageManager to save the threads
    ThreadStorageManager::getInstance()->updateThread($thread);

    // Redirect back to thread view
    header('Location: /thread-view?threadId=' . urlencode($threadId));
    exit;
}

function labelSelect($currentTypeInput, $id) {
    $currentTypeValue = $currentTypeInput instanceof ThreadEmailStatusType ? $currentTypeInput->value : $currentTypeInput;
    ?>
    <select name="<?= $id ?>">
        <?php foreach (ThreadEmailStatusType::cases() as $case): ?>
            <option value="<?= $case->value ?>" <?= $currentTypeValue == $case->value ? ' selected="selected"' : '' ?>>
                <?= htmlspecialchars($case->label()) ?> (<?= htmlspecialchars($case->value) ?>)
            </option>
        <?php endforeach; ?>
    </select>
    <?php
}
function secondsToHumanReadable($seconds) {
    $days = floor($seconds / (24 * 60 * 60));
    $seconds -= $days * (24 * 60 * 60);

    $hours = floor($seconds / (60 * 60));
    $seconds -= $hours * (60 * 60);

    $minutes = floor($seconds / 60);
    $seconds -= $minutes * 60;

    $result = "";
    if ($days > 0) {
        return $days . " day" . ($days == 1 ? "" : "s") . " ";
    }
    if ($hours > 0) {
        $result .= $hours . " hour" . ($hours == 1 ? "" : "s") . " ";
    }
    if ($minutes > 0) {
        $result .= $minutes . " minute" . ($minutes == 1 ? "" : "s") . " ";
    }
    $result .= $seconds . " second" . ($seconds == 1 ? "" : "s");

    return $result;
}

?>
<!DOCTYPE html>
<html>
<head>
    <?php 
    $pageTitle = 'Classify Email - Email Engine Organizer';
    include 'head.php';
    ?>
    <style>
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            color: #34495e;
            font-weight: bold;
        }
        .form-group input[type="text"],
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        .form-group textarea {
            resize: vertical;
        }
        .btn {
            background-color: #3498db;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            transition: background-color 0.2s;
        }
        .btn:hover {
            background-color: #2980b9;
        }
        .btn-open {
            float: right;
            font-size: 1.2em;
            padding: 8px 16px;
        }
        .email-item {
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 4px;
            margin-bottom: 15px;
        }
        .email-item.ignored {
            opacity: 0.7;
            background-color: #f5f5f5;
        }
        .viewer-container {
            width: 100%;
            height: 900px;
            border: none;
        }
        .textarea-small {
            height: 2em;
            width: 100%;
        }
        .textarea-large {
            height: 200px;
            width: 100%;
        }
        .suggestions {
            margin: 10px 0;
        }
        .suggestions span {
            color: #3498db;
            font-weight: bold;
            margin-right: 10px;
        }
        .suggestions a {
            text-decoration: none;
            margin: 0 5px;
            padding: 4px 8px;
            border-radius: 3px;
        }
        .suggestions a:hover {
            opacity: 0.8;
        }
        .attachment-item {
            margin: 15px 0;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            background-color: #f9f9f9;
        }
        .attachment-name {
            font-style: italic;
            margin: 5px 0;
        }
    </style>
    <script>
    function settForslag(emailId, forslagStatus, forslagTekst) {
        var selectElement = document.querySelector('select[name="' + emailId + '-status_type"]');
        selectElement.value = forslagStatus;

        var inputElement = document.querySelector('input[name="' + emailId + '-status_text"]');
        inputElement.value = forslagTekst;
    }
</script>

<body>
    <div class="container">
        <?php include 'header.php'; ?>
        
        <h1>Classify Email</h1>

        <div class="content">
            <table style="width: 100%">
                <tr>
                    <td style="width: 30%; vertical-align: top">
            <form method="post">
                <?php
                $firstOut = false;
                $last_email_time = 0;
                foreach ($thread->emails as $email) {
                    $emailId = str_replace(' ', '_', str_replace('.', '_', $email->id));
                    $time_since_last_email = strtotime($email->datetime_received) - $last_email_time;
                    $since_last_text = $last_email_time == 0 ? 'FIRST' : secondsToHumanReadable($time_since_last_email) . ' since last';
                    $last_email_time = strtotime($email->datetime_received);
                    ?>
                    <div class="email-item<?= $email->ignore ? ' ignored' : '' ?>">
                        <hr>
                        <?= $email->datetime_received ?> (<?= $since_last_text ?>):
                        <?= $email->email_type ?><br>
                        [Current classification: <?= ThreadEmailClassifier::getClassificationLabel($email) ?>]<br>

                        <div class="form-group">
                            <input type="button"
                                   class="btn btn-open"
                                   data-url="<?= '/file?threadId=' . urlencode($thread->id)
                                   . '&body=' . urlencode($email->id) ?>"
                                   onclick="document.getElementById('viewer-iframe').src = this.getAttribute('data-url');" value="Open">
                        </div>

                        <div class="form-group">
                            <label>
                                <input type="checkbox"
                                       value="true"
                                       name="<?= $emailId . '-ignore' ?>"
                                   <?= $email->ignore ? ' checked="checked"' : '' ?>> Ignore
                            </label>
                        </div>

                        <div class="form-group">
                            <label>Status Type</label>
                            <?php labelSelect($email->status_type, $emailId . '-status_type'); ?>
                        </div>

                        <div class="form-group">
                            <label>Status Text</label>
                            <input type="text" name="<?= $emailId . '-status_text' ?>" value="<?= htmlescape($email->status_text) ?>">
                        </div>

                        <?php
                        $forslag = array();
                        if (!$firstOut && $email->email_type == 'OUT') {
                            $forslag[] = array('info', 'Initiell henvendelse');
                            $firstOut = true;
                        }
                        if ($time_since_last_email < 60 && $email->email_type == 'IN') {
                            $forslag[] = array('disabled', 'Autosvar');
                        }
                        $autoforslag = count($forslag) > 0;

                        if (!$autoforslag) {
                            $forslag[] = array('success', 'Svar mottatt');
                            $forslag[] = array('danger', 'Avslag');
                        }

                        if (count($forslag)) {
                            ?><div class="suggestions">
                                <span>Suggestions:</span>
                                <?php foreach ($forslag as $f) { ?>
                                    <a onclick="settForslag('<?= $emailId ?>', '<?= $f[0] ?>', '<?= $f[1] ?>'); return false;"
                                       href="#" class="label-<?=$f[0]?>"><?= $f[0] ?> - <?= $f[1] ?></a>
                                <?php
                            }
                            ?></div><?php
                        }
                        ?>

                        <div class="form-group">
                            <label>Answer</label>
                            <textarea name="<?= $emailId . '-answer' ?>"
                                    class="<?= $autoforslag ? 'textarea-small' : 'textarea-large' ?>"><?= htmlescape(isset($email->answer) ? $email->answer : '') ?></textarea>
                        </div>

                        <br>
                        <i><?= htmlescape(isset($email->description) ? $email->description : '') ?></i>
                        <?php
                        if (isset($email->attachments)) {
                            foreach ($email->attachments as $att) {
                                $attId = str_replace(' ', '_', str_replace('.', '_', $att->location));
                                ?><div class="attachment-item">
                                    <div class="form-group">
                                        <div class="attachment-name">
                                            <?= $att->filetype ?> - <?= htmlentities($att->name, ENT_QUOTES) ?>
                                        </div>
                                        <input type="button"
                                               class="btn btn-open"
                                               data-url="<?= '/file?threadId=' . urlencode($threadId)
                                               . '&attachmentId=' . urlencode($att->id) ?>"
                                               onclick="document.getElementById('viewer-iframe').src = this.getAttribute('data-url');" value="Open">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label>Status Type</label>
                                        <?php labelSelect($att->status_type, $emailId . '-att-' . $attId . '-status_type'); ?>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label>Status Text</label>
                                        <input type="text"
                                               value="<?= htmlescape($att->status_text) ?>"
                                               name="<?= $emailId . '-att-' . $attId . '-status_text' ?>">
                                    </div>
                                </div>
                                <?php
                            }
                        }
                        ?>
                    </div>
                    <br>
                    <?php
                }
                ?>
                <hr>
                <div class="form-group">
                    <input type="submit" value="Save" name="submit" class="btn">
                </div>
            </form>
                    </td>
                    <td>
                        <iframe id="viewer-iframe" class="viewer-container"></iframe>
                    </td>
                </tr>
            </table>
        </div>
    </div>
</body>
</html>

<?php

require_once __DIR__ . '/class/Threads.php';

function logDebug($message) {
    echo $message . '<br>' . chr(10);
}

$entityId = $_GET['entityId'];
$threadId = $_GET['threadId'];
$threads = getThreadsForEntity($entityId);

$thread = null;
foreach ($threads->threads as $thread1) {
    if (getThreadId($thread1) == $threadId) {
        $thread = $thread1;
    }
}

if (isset($_POST['submit'])) {
    $anyUnknown = false;
    foreach ($thread->emails as $email) {
        $emailId = str_replace(' ', '_', str_replace('.', '_', $email->id));
        $email->ignore = isset($_POST[$emailId . '-ignore']) && $_POST[$emailId . '-ignore'] == 'true';
        $email->status_text = $_POST[$emailId . '-status_text'];
        $email->status_type = $_POST[$emailId . '-status_type'];
        $email->answer = $_POST[$emailId . '-answer'];
        if (isset($email->attachments)) {
            foreach ($email->attachments as $att) {
                $attId = str_replace(' ', '_', str_replace('.', '_', $att->location));
                $att->status_text = $_POST[$emailId . '-att-' . $attId . '-status_text'];
                $att->status_type = $_POST[$emailId . '-att-' . $attId . '-status_type'];
            }
        }
        if ($email->status_type == 'unknown') {
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
    saveEntityThreads($entityId, $threads);

    echo 'OK.';
    exit;
}

function labelSelect($currentType, $id) {
    if ($currentType != 'info'
        && $currentType != 'disabled'
        && $currentType != 'danger'
        && $currentType != 'success'
        && $currentType != 'unknown'
    ) {
        throw new Exception('Unknown type: ' . $currentType);
    }

    ?>
    <select name="<?= $id ?>">
        <option value="info" <?= $currentType == 'info' ? ' selected="selected"' : '' ?>>info</option>
        <option value="disabled" <?= $currentType == 'disabled' ? ' selected="selected"' : '' ?>>disabled</option>
        <option value="danger" <?= $currentType == 'danger' ? ' selected="selected"' : '' ?>>danger</option>
        <option value="success" <?= $currentType == 'success' ? ' selected="selected"' : '' ?>>success</option>
        <option value="unknown" <?= $currentType == 'unknown' ? ' selected="selected"' : '' ?>>unknown</option>
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
<link href="style.css" rel="stylesheet">

[<a href=".">Hovedside</a>]

<script>
    function settForslag(emailId, forslagStatus, forslagTekst) {
        var selectElement = document.querySelector('select[name="' + emailId + '-status_type"]');
        selectElement.value = forslagStatus;

        var inputElement = document.querySelector('input[name="' + emailId + '-status_text"]');
        inputElement.value = forslagTekst;
    }
</script>

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
                    <div <?= $email->ignore ? ' style="color: gray;"' : '' ?>>
                        <hr>
                        <?= $email->datetime_received ?> (<?= $since_last_text ?>):
                        <?= $email->email_type ?><br>

                        <input type="button"
                               style="font-size: 2em; padding: 0.5em; float: right"
                               data-url="<?= '/file.php?entityId=' . urlencode($threads->entity_id)
                               . '&threadId=' . urlencode(getThreadId($thread))
                               . '&body=' . urlencode($email->id) ?>"
                               onclick="document.getElementById('viewer-iframe').src = this.getAttribute('data-url');" value="Open"><br>

                        <input type="checkbox"
                               value="true"
                               name="<?= $emailId . '-ignore' ?>"
                            <?= $email->ignore ? ' checked="checked"' : '' ?>> Ignore<br>

                        <?php
                        labelSelect($email->status_type, $emailId . '-status_type');
                        ?>
                        Status type<br>
                        <input type="text" name="<?= $emailId . '-status_text' ?>" value="<?= htmlescape($email->status_text) ?>"> Status
                        text

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
                            ?><br><span style="color: blue">Forslag:</span><?php
                            foreach ($forslag as $f) {
                                ?>
                                [<a onclick="settForslag('<?= $emailId ?>', '<?= $f[0] ?>', '<?= $f[1] ?>'); return false;"
                                        href="#" class="label-<?=$f[0]?>"><?= $f[0] ?> - <?= $f[1] ?></a>]
                                <?php
                            }
                        }


                        $textarea_style = $autoforslag ? 'width: 200px; height: 1em' : 'width: 400px; height: 200px;'
                        ?>

                        <br>
                        <textarea name="<?= $emailId . '-answer' ?>"
                                  style="<?= $textarea_style ?>"><?= htmlescape(isset($email->answer) ? $email->answer : '') ?></textarea>

                        <br>
                        <i><?= htmlescape(isset($email->description) ? $email->description : '') ?></i>
                        <?php
                        if (isset($email->attachments)) {
                            foreach ($email->attachments as $att) {
                                $attId = str_replace(' ', '_', str_replace('.', '_', $att->location));
                                ?><br><br>
                                <?= $att->filetype ?> - <i><?= htmlentities($att->name, ENT_QUOTES) ?></i><br>
                                <input type="button"
                                       style="font-size: 2em; padding: 0.5em; float: right"
                                       data-url="<?= '/file.php?entityId=' . urlencode($threads->entity_id)
                                       . '&threadId=' . urlencode(getThreadId($thread))
                                       . '&attachment=' . urlencode($att->location) ?>"
                                       onclick="document.getElementById('viewer-iframe').src = this.getAttribute('data-url');" value="Open">
                                <br>
                                <?php
                                labelSelect($att->status_type, $emailId . '-att-' . $attId . '-status_type');
                                ?>
                                Status type<br>
                                <input type="text"
                                       value="<?= htmlescape($att->status_text) ?>"
                                       name="<?= $emailId . '-att-' . $attId . '-status_text' ?>"> Status text
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
                <input type="submit" value="Save" name="submit"
                       style="font-size: 2em; padding: 0.5em;">
            </form>
        </td>
        <td>
            <iframe id="viewer-iframe" style="width: 100%; height: 900px"></iframe>
        </td>
    </tr>
</table>

<?php

require_once __DIR__ . '/class/Threads.php';

/* @var Threads[] $threads */
$allThreads = getThreads();

function getLabelType($type, $status_type) {
    if ($status_type == 'info') {
        $label_type = 'label';
    }
    elseif ($status_type == 'disabled') {
        $label_type = 'label label_disabled';
    }
    elseif ($status_type == 'danger') {
        $label_type = 'label label_warn';
    }
    elseif ($status_type == 'success') {
        $label_type = 'label label_ok';
    }
    elseif ($status_type == 'unknown') {
        $label_type = 'label';
    }
    else {
        throw new Exception('Unknown status_type[' . $type . ']: ' . $status_type);
    }
    return $label_type;
}

?>

<h1>Threads</h1>

<li><a href="update-imap.php">Update email threads (folders) to IMAP</a></li>
<li><a href="update-identities.php">Update identities into Roundcube</a></li>
<li><a href="?archived">Show archived</a></li>

<style>
    table tr td,
    table tr th {
        border: 1px solid black;
        padding: 5px;
    }

    span.label {
        background-color: #94b1ef;
        border: 1px solid #3f4b65;
        border-radius: 2px;

        padding-right: 5px;
        padding-left: 5px;

        margin-right: 5px;
    }

    span.label.label_ok {
        background-color: #83f883;
    }

    span.label.label_warn {
        background-color: #f8ab69;
        border-color: #724f30;
    }

    span.label.label_disabled {
        background-color: #c9cdc9;
        border-color: #a8aca8;
    }
</style>

<table>

    <tr>
        <td>Entity name / id</td>
        <th>Title / My name &lt;email&gt;</th>
        <td>Status</td>
        <td>Labels</td>
    </tr>
    <?php

    foreach ($allThreads as $threads) {
        foreach ($threads->threads as $thread) {
            if($thread->archived && !isset($_GET['archived'])) {
                continue;
            }

            ?>
            <tr>
                <td>
                    <b><?= $threads->title_prefix ?></b><br>
                    <span style="font-size: 0.8em;"><?= $threads->entity_id ?></span>
                </td>
                <td>
                    <b><?= $thread->title ?></b><br>
                    <span style="font-size: 0.8em;">
                        <b><?= $thread->my_name ?></b>
                        &lt;<?= $thread->my_email ?>&gt;
                    </span>
                </td>
                <td>
                    <?= $thread->sent ? '<span class="label label_ok">Sent</span>' : '<span class="label label_warn">Not sent</span> ' ?><br>
                    <?= $thread->archived ? '<span class="label label_ok">Archived</span>' : '<span class="label label_warn">Not archived</span> ' ?></td>
                <td><?php
                    foreach ($thread->labels as $label) {
                        ?><span class="label"><?= $label ?></span><?php
                    }
                    ?></td>
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
                                        <?= $att->filetype ?> - <i><?= htmlentities($att->name, ENT_QUOTES) ?></i></li>
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
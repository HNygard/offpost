Hello world

<?php

/* @var Threads $threads */
$threads = json_decode(file_get_contents('/organizer-data/threads/threads-1129-forsand-kommune.json'));


?>

<h1>Threads</h1>

<li><a href="update-imap.php">Update email threads (folders) to IMAP</a></li>
<li><a href="update-identities.php">Update identities into Roundcube</a></li>

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
        <td>Entity</td>
        <th colspan="2">Title</th>
        <th>My name</th>
        <td>My email</td>
        <td colspan="2">Status</td>
        <td>Labels</td>
    </tr>
<?php

foreach ($threads->threads as $thread) {
    ?>
    <tr>
        <td><?= $threads->entity_id ?></td>
        <th><?= $threads->title_prefix ?></th>
        <th><?= $thread->title ?></th>
        <th><?= $thread->my_name ?></th>
        <td><?= $thread->my_email ?></td>
        <td><?= $thread->sent ? '<span class="label label_ok">Sent</span>': '<span class="label label_warn">Not sent</span> '?></td>
        <td><?= $thread->archived ? '<span class="label label_ok">Archived</span>': '<span class="label label_warn">Not archived</span> '?></td>
        <td><?php
            foreach ($thread->labels as $label) {
                ?><span class="label"><?=$label?></span><?php
            }
            ?></td>
        <td>
            <?php
            if (!isset($thread->emails)) {
                $thread->emails = array();
            }
            foreach ($thread->emails as $email) {
                if ($email->status_type == 'info') {
                    $label_type = 'label';
                }
                elseif ($email->status_type == 'disabled') {
                    $label_type = 'label label_disabled';
                }
                elseif ($email->status_type == 'danger') {
                    $label_type = 'label label_warn';
                }
                elseif ($email->status_type == 'success') {
                    $label_type = 'label label_ok';
                }
                else {
                    throw new Exception('Unknown status_type: ' . $email->status_type);
                }

                ?>
                <div <?= $email->ignore ? ' style="color: gray;"' : '' ?>>
                    <?= $email->datetime_received ?>:
                    <?= $email->email_type ?> -
                    <span class="<?=$label_type?>"><?= $email->status_text ?></span>
                    <br>
                    <i><?= htmlentities($email->description, ENT_QUOTES) ?></i>
                </div>
                <br>
                <?php
            }
            ?>
        </td>
    </tr>
    <?php
}
?>
</table>
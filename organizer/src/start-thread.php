<?php


require_once __DIR__ . '/class/Thread.php';
require_once __DIR__ . '/class/Threads.php';

if (!isset($_POST['entity_id'])) {
    ?>
    <form method="POST">
        <h1>Start email thread</h1>
        <input type="text" name="title" value=""> - Title<br>
        <input type="text" name="my_name" value=""> - My name<br>
        <input type="text" name="my_email" value="<?= htmlescape($_GET['my_email']) ?>"> - My email<br>
        <input type="text" name="labels" value=""> - Labels, space separated<br>
        <input type="text" name="entity_id" value=""> - Entity id<br>
        <input type="text" name="entity_title_prefix" value=""> - Entity title prefix (only used if first thread for this entity)<br>
        <input type="submit" value="Create thread">
    </form>
    <?php
    exit;
}

$thread = new Thread();
$thread->title = $_POST['title'];
$thread->my_name = $_POST['my_name'];
$thread->my_email = $_POST['my_email'];
$thread->labels = array();
$thread->sent = false;
$thread->archived = false;
$thread->emails = array();

$labels = explode(' ', $_POST['labels']);
foreach ($labels as $label) {
    $thread->labels[] = trim($label);
}
createThread($_POST['entity_id'], $_POST['entity_title_prefix'], $thread);

echo 'Created. <a href="./">Back</a>';
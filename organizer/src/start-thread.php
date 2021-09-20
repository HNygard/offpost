<?php


require_once __DIR__ . '/class/Thread.php';
require_once __DIR__ . '/class/Threads.php';

if (!isset($_POST['entity_id'])) {
    ?>
    <form method="POST">
        <h1>Start email thread</h1>
        <input type="text" name="title" value="<?= htmlescape(isset($_GET['title']) ? $_GET['title'] : '') ?>"> - Title<br>
        <input type="text" name="my_name" value="<?= htmlescape(isset($_GET['my_name']) ? $_GET['my_name'] : '') ?>"> - My name<br>
        <input type="text" name="my_email" value="<?= htmlescape($_GET['my_email']) ?>"> - My email<br>
        <input type="text" name="labels" value="<?= htmlescape(isset($_GET['labels']) ? $_GET['labels'] : '') ?>"> - Labels, space separated<br>
        <input type="text" name="entity_id" value="<?= htmlescape(isset($_GET['entity_id']) ? $_GET['entity_id'] : '') ?>"> - Entity id<br>
        <input type="text" name="entity_title_prefix" value="<?= htmlescape(isset($_GET['entity_title_prefix']) ? $_GET['entity_title_prefix'] : '') ?>"> - Entity title prefix (only used if first thread for this entity)<br>
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
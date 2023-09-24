<?php


require_once __DIR__ . '/class/Thread.php';
require_once __DIR__ . '/class/Threads.php';
require_once __DIR__ . '/class/random-profile.php';

$entityId = null;
$threadId = null;
if (isset($_GET['thread_id'])) {
    $entityId = $_GET['entity_id'];
    $threadId = $_GET['thread_id'];
}
if (isset($_POST['thread_id']) && !empty($_POST['thread_id'])) {
    $entityId = $_POST['entity_id'];
    $threadId = $_POST['thread_id'];
}
$thread = null;
if ($threadId != null) {
    $threads = getThreadsForEntity($entityId);

    foreach ($threads->threads as $thread1) {
        if (getThreadId($thread1) == $threadId) {
            $thread = $thread1;
        }
    }
    if ($thread == null) {
        throw new Exception('Unknown thread_id');
    }


    $_GET['my_name'] = $thread->my_name;
    $_GET['my_email'] = $thread->my_email;
}

if (!isset($_POST['entity_id'])) {

    if (isset($_GET['my_profile']) && $_GET['my_profile'] == 'RANDOM') {
        $obj = getRandomNameAndEmail();
        $_GET['my_name'] = $obj->firstName . $obj->middleName . ' ' . $obj->lastName;
        $_GET['my_email'] = $obj->email;
        if (isset($_GET['body'])) {
            $_GET['body'] .= "\n\n--\n" . $obj->firstName . $obj->middleName . ' ' . $obj->lastName;
        }
    }


    ?>
    <body  onload="document.getElementById('startthreadform-2023-09-17').submit();">
    <style>
        input {
            width:500px;
        }
        textarea {
            width: 700px;
            height: 300px;
        }
    </style>
    <form method="POST" id="startthreadform-<?=date('Y-m-d')?>">
        <h1>Start email thread</h1>
        <?php
        if ($thread != null) {
            ?><div style="background-color: #66CC66; font-size: 3em; padding: 10px;">Continue thread...</div><br><br><?php
        }
        ?>
        <input type="text" name="title" value="<?= htmlescape(isset($_GET['title']) ? $_GET['title'] : '') ?>"> - Title<br>
        <input type="text" name="my_name" value="<?= htmlescape(isset($_GET['my_name']) ? $_GET['my_name'] : '') ?>"> - My name<br>
        <input type="text" name="my_email" value="<?= htmlescape(isset($_GET['my_email']) ? $_GET['my_email'] : '') ?>"> - My email<br>
        <input type="text" name="labels" value="<?= htmlescape(isset($_GET['labels']) ? $_GET['labels'] : '') ?>"> - Labels, space separated<br>
        <input type="text" name="entity_id" value="<?= htmlescape(isset($_GET['entity_id']) ? $_GET['entity_id'] : '') ?>"> - Entity id<br>
        <input type="text" name="entity_title_prefix" value="<?= htmlescape(isset($_GET['entity_title_prefix']) ? $_GET['entity_title_prefix'] : '') ?>"> - Entity title prefix (only used if first thread for this entity)<br>
        <input type="text" name="entity_email" value="<?= htmlescape(isset($_GET['entity_email']) ? $_GET['entity_email'] : '') ?>"> - Entity email<br>
        <input type="text" name="thread_id" value="<?= htmlescape(isset($_GET['thread_id']) ? $_GET['thread_id'] : '') ?>"> - Thread id (continue thread)<br>
        <textarea name="body"><?= htmlescape(isset($_GET['body']) ? $_GET['body'] : '') ?></textarea><br><br>
        <input type="submit" value="Create thread">
    </form>
    </body>
    <?php
    exit;
}

if ($thread == null) {
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
    $newThread = createThread($_POST['entity_id'], $_POST['entity_title_prefix'], $thread);
    $threadId = getThreadId($newThread);

    $threads = getThreadsForEntity($_POST['entity_id']);

    $thread = null;
    foreach ($threads->threads as $thread1) {
        if (getThreadId($thread1) == $threadId) {
            $thread = $thread1;
        }
    }

    if ($thread == null) {
        throw new Exception('Error. Missing thread.');
    }
}


if (isset($_POST['body']) && !empty($_POST['body'])) {
    // -> Send email
    sendThreadEmail($thread, $_POST['entity_email'], $_POST['title'], $_POST['body'], $entityId, $threads);
}


echo '<br>Created. <a href="./">Back</a>';
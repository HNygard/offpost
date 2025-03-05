<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/class/Threads.php';
require_once __DIR__ . '/class/ThreadStorageManager.php';

// Require authentication
requireAuth();

$entityId = $_GET['entityId'];
$threadId = $_GET['threadId'];
$threads = ThreadStorageManager::getInstance()->getThreadsForEntity($entityId);

$thread = null;
foreach ($threads->threads as $thread1) {
    if ($thread1->id == $threadId) {
        $thread = $thread1;
    }
}

if (isset($_POST['submit'])) {
    // Update status to READY_FOR_SENDING before sending
    if ($thread->sending_status === Thread::SENDING_STATUS_STAGING) {
        $thread->sending_status = Thread::SENDING_STATUS_READY_FOR_SENDING;
        $dbOps = new ThreadDatabaseOperations();
        $dbOps->updateThread($thread, $_SESSION['user']['sub']);
    }
    
    $result = sendThreadEmail($thread, $_POST['email-to'], 
    $_POST['email-subject'], $_POST['email-body'], $entityId,
    userId: $_SESSION['user']['sub']
    );
    
    echo '<div style="height: 400px; overflow-y: scroll; font-family: monospace;">';
    echo $result['debug'];
    echo '</div>';
    
    if ($result['success']) {
        echo '<pre style="color: green; font-size: 2em;">Message has been sent</pre>';
    } else {
        echo '<pre style="color: red; font-size: 2em;">Message could not be sent. Mailer Error: ' . $result['error'] . '</pre>';
    }
    exit;
}


?>
<link href="style.css" rel="stylesheet">

[<a href=".">Hovedside</a>]

<form method="post">
    <input type="text" name="email-to"> - Email to<br>
    <input type="text" name="email-subject" value="<?= htmlescape($thread->title) ?>"> - Email subject<br>
    <br>
    Email body:<br>
    <textarea name="email-body" style="width: 500px; height: 300px;">
<?=$starterMessage . "\n"?>
-
<?= $signDelim . "\n" ?>
<?=htmlescape($thread->my_name)?></textarea><br>
    <br>
    <input type="submit" value="Save" name="submit">
</form>

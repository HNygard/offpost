<?php

require_once __DIR__ . '/class/Threads.php';

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
    sendThreadEmail($thread, $_POST['email-to'], $_POST['email-subject'], $_POST['email-body'], $entityId, $threads);
    exit;
}

$starterMessages = array(
    "Søker innsyn.",
    "Søker innsyn i:",
    "Ønsker innsyn:",
    "Jeg ønsker innsyn:",
    "Jeg ønsker innsyn i:",
    "Kunne jeg fått innsyn i følgende?",
    "Kunne jeg etter Offentleglova få innsyn i følgende?",
    "Etter Offl:",
    "Etter Offentleglova",
    "Etter Offentleglova ønsker jeg",
    "Etter Offentleglova søker jeg innsyn i:",
    "Vil ha innsyn i",
    "Jfr Offentleglova:",
    "Jfr Offentleglova søker jeg innsyn i:",
    "Jfr Offentleglova søker jeg innsyn i følgende:",
    "Jfr. Offl. søker jeg innsyn i:",
);
$rand = mt_rand(0, count($starterMessages) - 1);

$starterMessage = $starterMessages[$rand];
$signDelim = mt_rand(0, 10) > 5 ? '---' : '--';

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

--
<?=htmlescape($thread->my_name)?></textarea><br>
    <br>
    <input type="submit" value="Save" name="submit">
</form>

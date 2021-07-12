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
    require_once __DIR__ . '/vendor/autoload.php';
    require_once __DIR__ . '/username-password-imap.php';
    require_once __DIR__ . '/imap-connection.php';
    $mailbox = openConnection();

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->XMailer = 'Roundcube thread starter';
    $mail->isSMTP();
    $mail->CharSet = \PHPMailer\PHPMailer\PHPMailer::CHARSET_UTF8;

    $mail->Host = 'smtp.sendgrid.net';
    $mail->SMTPAuth = true;
    $mail->Username = $sendgridUsername;
    $mail->Password = $sendgridPassword;
    $mail->SMTPSecure = 'tls';
    $mail->Port = 587;

    echo '<pre>';
    echo $mail->Port . '<br>';
    $mail->From = $thread->my_email;
    $mail->FromName = $thread->my_name;
    $mail->addAddress($_POST['email-to']);     // Add a recipient
    $mail->addBCC($mail->From);

    $mail->WordWrap = 150;

    $mail->Subject = $_POST['email-subject'];
    $mail->Body = $_POST['email-body'];
    //$mail->isHTML(true);                                  // Set email format to HTML
    //$mail->AltBody = 'This is the body in plain text for non-HTML mail clients';

    $mail->SMTPDebug = 2;
    $mail->Timeout = 10;

    if (!$mail->send()) {
        echo 'Message could not be sent.';
        echo 'Mailer Error: ' . $mail->ErrorInfo;
    }
    else {
        echo 'Message has been sent';
    }

    // TODO: update label on thread => sent

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

$starterMessages = array(
    "Søker innsyn.",
    "Søker innsyn i:",
    "Ønsker innsyn:",
    "Jeg ønsker innsyn:",
    "Jeg ønsker innsyn i:",
    "Kunne jeg fått innsyn i følgende?",
    "Kunne jeg etter Offenetlova fått innsyn i følgende?",
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

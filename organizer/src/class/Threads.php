<?php

require_once __DIR__ . '/common.php';

function getThreads() {
    $files = getFilesInDirectoryNoRecursive('/organizer-data/threads');
    $threads = array();
    /* @var Threads[] $threads */
    foreach($files as $file) {
        $threads[$file] = json_decode(file_get_contents($file));
    }
    return $threads;
}

/**
 * @param String $entityID
 * @return Threads
 */
function getThreadsForEntity($entityID) {
    $path = '/organizer-data/threads/threads-' . $entityID . '.json';
    if (!file_exists($path)) {
        return null;
    }

    return json_decode(file_get_contents($path));
}

/**
 * @param $entityId
 * @param Threads $entity_threads
 */
function saveEntityThreads($entityId, $entity_threads) {
    $path = '/organizer-data/threads/threads-' . $entityId . '.json';
    file_put_contents($path, json_encode($entity_threads, JSON_PRETTY_PRINT ^ JSON_UNESCAPED_UNICODE ^ JSON_UNESCAPED_SLASHES));
}

function createThread($entityId, $entityTitlePrefix, Thread $thread) {
    $existingThreads = getThreadsForEntity($entityId);
    if ($existingThreads == null) {
        $existingThreads = new Threads();
        $existingThreads->entity_id = $entityId;
        $existingThreads->title_prefix = $entityTitlePrefix;
        $existingThreads->threads = array();
    }
    $existingThreads->threads[] = $thread;

    file_put_contents('/organizer-data/threads/threads-' . $entityId . '.json',
        json_encode($existingThreads, JSON_PRETTY_PRINT ^ JSON_UNESCAPED_UNICODE ^ JSON_UNESCAPED_SLASHES));

    return $thread;
}

function getThreadId($thread) {
    $email_folder = str_replace(' ', '_', mb_strtolower($thread->title, 'UTF-8'));
    $email_folder = str_replace('/', '-', $email_folder);
    return $email_folder;
}

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

function sendThreadEmail($thread, $emailTo, $emailSubject, $emailBody, $entityId, $threads)  {

    require_once __DIR__ . '/../vendor/autoload.php';
    require_once __DIR__ . '/../username-password-imap.php';
    require_once __DIR__ . '/../imap-connection.php';

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
    $mail->addAddress($emailTo);     // Add a recipient
    $mail->addBCC($mail->From);

    $mail->WordWrap = 150;

    $mail->Subject = $emailSubject;
    $mail->Body = $emailBody;
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

    // :: Update sent property on thread
    $thread->sent = true;
    saveEntityThreads($entityId, $threads);

    echo 'OK.';
}

class Threads {
    var $title_prefix;
    var $entity_id;

    /* @var $threads Thread[] */
    var $threads;
}
<?php

require_once __DIR__ . '/common.php';

function getThreads() {
    $files = getFilesInDirectoryNoRecursive('/organizer-data/threads');
    $threads = array();
    /* @var Threads[] $threads */
    foreach($files as $file) {
        if (basename($file) === '.gitignore') {
            continue;
        }

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

function getThreadFile($entityId, $thread, $attachement) {
    return file_get_contents('/organizer-data/threads/' . $entityId . '/' . getThreadId($thread) .'/'.$attachement);
}

/**
 * @param $entityId
 * @param Threads $entity_threads
 */
function saveEntityThreads($entityId, $entity_threads) {
    $path = '/organizer-data/threads/threads-' . $entityId . '.json';
    logDebug('Writing to [' . $path . '].');
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

/**
 * Interface for email sending service
 */
interface IEmailService {
    public function sendEmail($from, $fromName, $to, $subject, $body, $bcc = null);
    public function getLastError();
    public function getDebugOutput();
}

/**
 * PHPMailer implementation of email service
 */
class PHPMailerService implements IEmailService {
    private $host;
    private $username;
    private $password;
    private $lastError;
    private $debugOutput;

    public function __construct($host, $username, $password) {
        $this->host = $host;
        $this->username = $username;
        $this->password = $password;
    }

    public function sendEmail($from, $fromName, $to, $subject, $body, $bcc = null) {
        require_once __DIR__ . '/../vendor/autoload.php';

        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->XMailer = 'Roundcube thread starter';
        $mail->isSMTP();
        $mail->CharSet = \PHPMailer\PHPMailer\PHPMailer::CHARSET_UTF8;

        $mail->Host = $this->host;
        $mail->SMTPAuth = true;
        $mail->Username = $this->username;
        $mail->Password = $this->password;
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;

        ob_start();
        $mail->SMTPDebug = 2;
        
        $mail->From = $from;
        $mail->FromName = $fromName;
        $mail->addAddress($to);
        if ($bcc) {
            $mail->addBCC($bcc);
        }

        $mail->WordWrap = 150;
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->Timeout = 10;

        try {
            $result = $mail->send();
            $this->debugOutput = ob_get_clean();
            return $result;
        } catch (Exception $e) {
            $this->lastError = $mail->ErrorInfo;
            $this->debugOutput = ob_get_clean();
            return false;
        }
    }

    public function getLastError() {
        return $this->lastError;
    }

    public function getDebugOutput() {
        return $this->debugOutput;
    }
}

function sendThreadEmail($thread, $emailTo, $emailSubject, $emailBody, $entityId, $threads, IEmailService $emailService = null) {
    if ($emailService === null) {
        require_once __DIR__ . '/../username-password-imap.php';
        $emailService = new PHPMailerService(
            'smtp.sendgrid.net',
            $sendgridUsername,
            $sendgridPassword
        );
    }

    $success = $emailService->sendEmail(
        $thread->my_email,
        $thread->my_name,
        $emailTo,
        $emailSubject,
        $emailBody,
        $thread->my_email
    );

    if ($success) {
        $thread->sent = true;
        saveEntityThreads($entityId, $threads);
    }

    return [
        'success' => $success,
        'error' => $emailService->getLastError(),
        'debug' => $emailService->getDebugOutput()
    ];
}

class Threads {
    var $title_prefix;
    var $entity_id;

    /* @var $threads Thread[] */
    var $threads;
}

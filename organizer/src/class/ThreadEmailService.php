<?php

require_once __DIR__ . '/ThreadHistory.php';

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
    private $port;
    private $secure;
    private $lastError;
    private $debugOutput;

    public function __construct($host, $username, $password, $port = 587, $secure = 'tls') {
        $this->host = $host;
        $this->username = $username;
        $this->password = $password;
        $this->port = $port;
        $this->secure = $secure;
    }

    public function sendEmail($from, $fromName, $to, $subject, $body, $bcc = null) {
        require_once __DIR__ . '/../vendor/autoload.php';

        // Assert incoming parameters, one by one
        if (empty($from)) {
            $this->lastError = 'From address is required';
            return false;
        }
        if (empty($fromName)) {
            $this->lastError = 'From name is required';
            return false;
        }
        if (empty($to)) {
            $this->lastError = 'To address is required';
            return false;
        }
        if (empty($subject)) {
            $this->lastError = 'Subject is required';
            return false;
        }
        if (empty($body)) {
            $this->lastError = 'Body is required';
            return false;
        }

        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->XMailer = 'Roundcube thread starter';
        $mail->isSMTP();
        $mail->CharSet = \PHPMailer\PHPMailer\PHPMailer::CHARSET_UTF8;

        $mail->Host = $this->host;
        $mail->SMTPAuth = true;
        $mail->Username = $this->username;
        $mail->Password = $this->password;
        $mail->SMTPSecure = $this->secure;
        $mail->Port = $this->port;

        try {
            ob_start();
            $mail->SMTPDebug = 2;

            $mail->From = $from;
            $mail->FromName = $fromName;
            $mail->addAddress($to);
            if ($bcc) {
                $mail->addBCC($bcc);
            }

            if ($this->host == 'greenmail') {
                // -> In development mode using greenmail.

                if(str_contains($to, '@offpost.no')) {
                    // -> Email sent to our own domain. Let's send a copy to our incoming email box.
                    $mail->addAddress('greenmail-user@dev.offpost.no');
                }
                if (str_contains($bcc, '@offpost.no')) {
                    // -> Email sent with copy to our own domain, so likely to a public entity.

                    // The bcc must be visible in development mode to get sorted correctly when reading from IMAP.
                    $mail->addCC($bcc);

                    // Give our incoming email box a copy as well.
                    $mail->addBCC('greenmail-user@dev.offpost.no');

                    // Our public entity 
                    $mail->addAddress('public-entity@dev.offpost.no');
                }
            }

            $mail->WordWrap = 150;
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->Timeout = 10;

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

function sendThreadEmail(Thread $thread, $emailTo, $emailSubject, $emailBody, $entityId, $userId,
    IEmailService $emailService = null, ThreadDatabaseOperations $dbOps = null, ThreadHistory $history = null) {
    if ($emailService === null) {
        require __DIR__ . '/../username-password.php';
        $emailService = new PHPMailerService(
            $smtpServer,
            $smtpUsername,
            $smtpPassword,
            $smtpPort,
            $smtpSecure
        );
    }

    if ($dbOps === null) {
        $dbOps = new ThreadDatabaseOperations();
    }
    
    // Update status to SENDING before attempting to send
    $thread->sending_status = Thread::SENDING_STATUS_SENDING;
    $dbOps->updateThread($thread, $userId);

    $success = $emailService->sendEmail(
        $thread->my_email,
        $thread->my_name,
        $emailTo,
        $emailSubject,
        $emailBody,
        $thread->my_email
    );

    if ($success) {
        // Update to SENT if successful
        $thread->sending_status = Thread::SENDING_STATUS_SENT;
        $thread->sent = true; // For backward compatibility
        $dbOps->updateThread($thread, $userId);
    } else {
        // Revert to READY_FOR_SENDING if failed
        $thread->sending_status = Thread::SENDING_STATUS_READY_FOR_SENDING;
        $dbOps->updateThread($thread, $userId);
    }

    return [
        'success' => $success,
        'error' => $emailService->getLastError(),
        'debug' => $emailService->getDebugOutput()
    ];
}

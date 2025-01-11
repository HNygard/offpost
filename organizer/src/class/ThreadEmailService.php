<?php

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
        require_once __DIR__ . '/../username-password.php';
        $emailService = new PHPMailerService(
            $smtpServer,
            $smtpUsername,
            $smtpPassword,
            $smtpPort,
            $smtpSecure
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

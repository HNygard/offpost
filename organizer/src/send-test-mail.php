<?php /** @noinspection PhpUnhandledExceptionInspection */

require_once __DIR__ . '/vendor/autoload.php';

require_once __DIR__ . '/username-password-imap.php';
require_once __DIR__ . '/imap-connection.php';
$mailbox = openConnection();

$mail = new \PHPMailer\PHPMailer\PHPMailer(true);
$mail->XMailer = 'Offpost thread starter';
$mail->isSMTP();

/*
 *
Utgående server: send.one.com
Utgående port: 587 + TLS/SSL (eller 465 / 2525 / 25)
Utgående server autentisering: brukernavn og passord, samme som for innkommende server
 */
/*
$mail->Host = 'send.one.com';  // Specify main and backup SMTP servers
$mail->SMTPAuth = true;                               // Enable SMTP authentication
$mail->Username = $yourEmail;
$mail->Password = $yourEmailPassword;
$mail->SMTPSecure = 'tls';                            // Enable encryption, 'ssl' also accepted
$mail->Port = 587;
*/

// Direct
$mail->Host = 'smtp.sendgrid.net';
$mail->SMTPAuth = true;
$mail->Username = $sendgridUsername;
$mail->Password = $sendgridPassword;
$mail->SMTPSecure = 'tls';
$mail->Port = 587;

echo '<pre>';
echo $mail->Port . '<br>';
$mail->From = 'ola.nordmann@offpost.no';
$mail->FromName = 'Ola Nordmann';
$mail->addAddress('joe.user@hnygard.no', 'Joe User');     // Add a recipient
$mail->addBCC($mail->From);

$mail->WordWrap = 50;                                 // Set word wrap to 50 characters
//$mail->addAttachment('/var/tmp/file.tar.gz');         // Add attachments
//$mail->addAttachment('/tmp/image.jpg', 'new.jpg');    // Optional name
$mail->isHTML(true);                                  // Set email format to HTML

$mail->Subject = 'Epost til Joe 2';
$mail->Body = 'This is the HTML message body <b>in bold!</b>';
$mail->AltBody = 'This is the body in plain text for non-HTML mail clients';

$mail->SMTPDebug = 2;
$mail->Timeout = 10;

if (!$mail->send()) {
    echo 'Message could not be sent.';
    echo 'Mailer Error: ' . $mail->ErrorInfo;
}
else {
    echo 'Message has been sent';

//    $mail_string = $mail->getSentMIMEMessage();
//    imap_append($mailbox, $server . 'INBOX', $mail_string, "\\Seen");
}


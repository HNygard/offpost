<?php

require_once __DIR__ . '/vendor/autoload.php';

require_once __DIR__ . '/username-password-imap.php';

$mail = new \PHPMailer\PHPMailer\PHPMailer(true);
$mail->isSMTP();

/*
$mail->Host = 'send.one.com';  // Specify main and backup SMTP servers
$mail->SMTPAuth = true;                               // Enable SMTP authentication
$mail->Username = $yourEmail;
$mail->Password = $yourEmailPassword;
$mail->SMTPSecure = 'tls';                            // Enable encryption, 'ssl' also accepted
$mail->Port = 587;
*/
$mail->Host = 'smtp.sendgrid.net';
$mail->SMTPAuth = true;
$mail->Username = 'apikey';
$mail->Password = $sendgridPassword;
$mail->SMTPSecure = 'tls';
$mail->Port = 587;

/*
 *
Utgående server: send.one.com
Utgående port: 587 + TLS/SSL (eller 465 / 2525 / 25)
Utgående server autentisering: brukernavn og passord, samme som for innkommende server
 */

echo '<pre>';
echo $mail->Port . '<br>';
$mail->From = 'ola.nordmann@offpost.no';
$mail->FromName = 'Mailer';
$mail->addAddress('ola.nordmann@offpost.no', 'Joe User');     // Add a recipient

$mail->WordWrap = 50;                                 // Set word wrap to 50 characters
//$mail->addAttachment('/var/tmp/file.tar.gz');         // Add attachments
//$mail->addAttachment('/tmp/image.jpg', 'new.jpg');    // Optional name
$mail->isHTML(true);                                  // Set email format to HTML

$mail->Subject = 'Here is the subject';
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
}

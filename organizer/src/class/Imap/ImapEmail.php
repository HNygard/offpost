<?php

namespace Imap;

class ImapEmail {
    public int $uid;
    public string $subject;
    public int $timestamp;
    public string $date;
    public ?string $toaddress;
    public string $fromaddress;
    public string $senderaddress;
    public string $reply_toaddress;
    public string $body;
    public object $mailHeaders;

    /**
     * Create a new ImapEmail instance from IMAP headers and body
     * 
     * @param ImapConnection $connection IMAP connection
     * @param int $uid Email UID
     * @param object $headers Email headers
     * @param string $body Email body
     * @return ImapEmail
     */
    public static function fromImap(ImapConnection $connection, int $uid, object $headers, string $body): self {
        $email = new self();
        
        // Basic email information
        $email->uid = $uid;
        $email->subject = $headers->subject;
        $email->timestamp = strtotime($headers->date);
        $email->date = $headers->date;
        
        // Clean up and convert character encodings
        $email->toaddress = isset($headers->toaddress) ? $connection->utf8($headers->toaddress) : null;
        $email->fromaddress = $connection->utf8($headers->fromaddress);
        $email->senderaddress = $connection->utf8($headers->senderaddress);
        $email->reply_toaddress = $connection->utf8($headers->reply_toaddress);

        // Convert personal names to UTF-8
        if (isset($headers->to[0]->personal)) {
            $headers->to[0]->personal = $connection->utf8($headers->to[0]->personal);
        }
        if (isset($headers->from[0]->personal)) {
            $headers->from[0]->personal = $connection->utf8($headers->from[0]->personal);
        }
        if (isset($headers->sender[0]->personal)) {
            $headers->sender[0]->personal = $connection->utf8($headers->sender[0]->personal);
        }
        if (isset($headers->reply_to[0]->personal)) {
            $headers->reply_to[0]->personal = $connection->utf8($headers->reply_to[0]->personal);
        }

        // Ensure body is UTF-8
        $email->body = $body;
        if (json_encode($email->body, JSON_PRETTY_PRINT ^ JSON_UNESCAPED_UNICODE ^ JSON_UNESCAPED_SLASHES) === false) {
            $email->body = mb_convert_encoding($email->body, 'UTF-8', 'ISO-8859-1');
        }

        $email->mailHeaders = $headers;
        
        return $email;
    }
}

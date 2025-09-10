<?php

namespace Imap;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../Extraction/ThreadEmailExtractorEmailBody.php';

use Exception;
use Laminas\Mail\Storage\Message;
use ThreadEmailExtractorEmailBody;

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
    public array $attachments;

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

    /**
     * Get email direction (IN/OUT) based on sender
     */
    public function getEmailDirection(string $myEmail): string {
        $from = $this->mailHeaders->from[0]->mailbox . '@' . $this->mailHeaders->from[0]->host;
        return ($from === $myEmail) ? 'OUT' : 'IN';
    }

    /**
     * Generate unique filename for email based on date and direction
     */
    public function generateEmailFilename(string $myEmail): string {
        $datetime = date('Y-m-d_His', strtotime($this->date));
        $direction = $this->getEmailDirection($myEmail);
        return $datetime . ' - ' . $direction;
    }

    /**
     * Get addresses from email headers
     */
    public function getEmailAddresses($rawEmail = null): array {
        $addresses = [];
        
        if (isset($this->mailHeaders->to)) {
            foreach ($this->mailHeaders->to as $email) {
                if (isset($email->mailbox) && isset($email->host)) {
                    $addresses[] = $email->mailbox . '@' . $email->host;
                }
            }
        }
        foreach ($this->mailHeaders->from as $email) {
            $addresses[] = $email->mailbox . '@' . $email->host;
        }
        foreach ($this->mailHeaders->reply_to as $email) {
            $addresses[] = $email->mailbox . '@' . $email->host;
        }
        foreach ($this->mailHeaders->sender as $email) {
            $addresses[] = $email->mailbox . '@' . $email->host;
        }
        // Add CC addresses
        if (isset($this->mailHeaders->cc)) {
            foreach ($this->mailHeaders->cc as $email) {
                $addresses[] = $email->mailbox . '@' . $email->host;
            }
        }

        if ($rawEmail !== null) {
            try {
                $message = new \Laminas\Mail\Storage\Message(['raw' => ThreadEmailExtractorEmailBody::stripProblematicHeaders($rawEmail)]);
                $x_forwarded_for = $message->getHeaders()->get('x-forwarded-for');
                if ($x_forwarded_for !== false ) {
                    if ($x_forwarded_for instanceof ArrayIterator) {
                        foreach ($x_forwarded_for as $header) {
                            $addresses[] = $header->getFieldValue();
                        }
                    }
                    else {
                        $addresses[] = $x_forwarded_for->getFieldValue();
                    }
                }
            }
            catch(\Throwable $e) {
                // Handle exception if needed
                $addresses[] = 'exception-during-getting-x-forwarded-for (' . $e->getMessage() . ' B64 trace: ' . base64_encode(jTraceEx($e)) . ')';
            }

        }

        $addresses = array_map('strtolower', $addresses);

        // Use array_values to reindex the array after removing duplicates
        return array_values(array_unique($addresses));
    }

    static function getEmailSubject($eml_or_partial_eml) {
        try {
            $message = new Message(['raw' => $eml_or_partial_eml]);
            $subject = $message->getHeader('subject')->getFieldValue();
        }
        catch (Exception $e) {
            $subject = 'Error getting subject - ' . $e->getMessage();
        }
        return $subject;
    }
}

<?php

require_once __DIR__ . '/Database.php';

/**
 * Class representing an email to be sent for a thread
 */
class ThreadEmailSending {
    // Status constants
    const STATUS_STAGING = 'STAGING';
    const STATUS_READY_FOR_SENDING = 'READY_FOR_SENDING';
    const STATUS_SENDING = 'SENDING';
    const STATUS_SENT = 'SENT';
    
    var $id;
    var $thread_id;
    var $email_content;
    var $email_subject;
    var $email_to;
    var $email_from;
    var $email_from_name;
    var $status;
    var $smtp_response;
    var $smtp_debug;
    var $error_message;
    var $created_at;
    var $updated_at;
    
    /**
     * Check if email is in STAGING status
     * 
     * @return bool True if in STAGING status
     */
    public function isStaged() {
        return $this->status === self::STATUS_STAGING;
    }
    
    /**
     * Check if email is in READY_FOR_SENDING status
     * 
     * @return bool True if in READY_FOR_SENDING status
     */
    public function isReadyForSending() {
        return $this->status === self::STATUS_READY_FOR_SENDING;
    }
    
    /**
     * Check if email is in SENDING status
     * 
     * @return bool True if in SENDING status
     */
    public function isSending() {
        return $this->status === self::STATUS_SENDING;
    }
    
    /**
     * Check if email is in SENT status
     * 
     * @return bool True if in SENT status
     */
    public function isSent() {
        return $this->status === self::STATUS_SENT;
    }
    
    /**
     * Create a new ThreadEmailSending record
     * 
     * @param string $threadId The thread ID
     * @param string $emailContent The email content
     * @param string $emailSubject The email subject
     * @param string $emailTo The recipient email address
     * @param string $emailFrom The sender email address
     * @param string $emailFromName The sender name
     * @param string $status The initial status (default: STAGING)
     * @return ThreadEmailSending|null The created record or null on failure
     */
    public static function create($threadId, $emailContent, $emailSubject, $emailTo, $emailFrom, $emailFromName, $status = self::STATUS_STAGING) {
        $result = Database::execute(
            "INSERT INTO thread_email_sendings 
            (thread_id, email_content, email_subject, email_to, email_from, email_from_name, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?)",
            [
                $threadId,
                $emailContent,
                $emailSubject,
                $emailTo,
                $emailFrom,
                $emailFromName,
                $status
            ]
        );
        
        if (!$result) {
            return null;
        }
        
        $id = Database::lastInsertId('thread_email_sendings_id_seq');
        return self::getById($id);
    }
    
    /**
     * Find the next email ready for sending
     * 
     * @return ThreadEmailSending|null The next email to send or null if none found
     */
    public static function findNextForSending() {
        $query = "
            SELECT id
            FROM thread_email_sendings
            WHERE status = ?
            ORDER BY created_at ASC 
            LIMIT 1
        ";
        
        $id = Database::queryValue($query, [self::STATUS_READY_FOR_SENDING]);
        
        if (!$id) {
            return null;
        }
        
        return self::getById($id);
    }
    
    /**
     * Update the status of a ThreadEmailSending record
     * 
     * @param int $id The record ID
     * @param string $status The new status
     * @param string|null $smtpResponse The SMTP response (for SENT status)
     * @param string|null $smtpDebug The SMTP debug output (for SENT status)
     * @param string|null $errorMessage The error message (if any)
     * @return bool True on success, false on failure
     */
    public static function updateStatus($id, $status, $smtpResponse = null, $smtpDebug = null, $errorMessage = null) {
        $params = [$status];
        $sql = "UPDATE thread_email_sendings SET status = ?";
        
        if ($smtpResponse !== null) {
            $sql .= ", smtp_response = ?";
            $params[] = $smtpResponse;
        }
        
        if ($smtpDebug !== null) {
            $sql .= ", smtp_debug = ?";
            $params[] = $smtpDebug;
        }
        
        if ($errorMessage !== null) {
            $sql .= ", error_message = ?";
            $params[] = $errorMessage;
        }
        
        $sql .= " WHERE id = ?";
        $params[] = $id;
        
        $result = Database::execute($sql, $params);
        return $result > 0;
    }
    
    /**
     * Get a ThreadEmailSending record by ID
     * 
     * @param int $id The record ID
     * @return ThreadEmailSending|null The record or null if not found
     */
    public static function getById($id) {
        $data = Database::queryOneOrNone(
            "SELECT * FROM thread_email_sendings WHERE id = ?",
            [$id]
        );
        
        if (!$data) {
            return null;
        }
        
        return self::createFromDatabaseRow($data);
    }
    
    /**
     * Get ThreadEmailSending records by thread ID
     * 
     * @param string $threadId The thread ID
     * @return array Array of ThreadEmailSending objects
     */
    public static function getByThreadId($threadId) {
        $rows = Database::query(
            "SELECT * FROM thread_email_sendings WHERE thread_id = ? ORDER BY created_at",
            [$threadId]
        );
        
        $result = [];
        foreach ($rows as $row) {
            $result[] = self::createFromDatabaseRow($row);
        }
        
        return $result;
    }
    
    /**
     * Create a ThreadEmailSending object from a database row
     * 
     * @param array $data The database row
     * @return ThreadEmailSending The created object
     */
    private static function createFromDatabaseRow($data) {
        $obj = new ThreadEmailSending();
        $obj->id = $data['id'];
        $obj->thread_id = $data['thread_id'];
        $obj->email_content = $data['email_content'];
        $obj->email_subject = $data['email_subject'];
        $obj->email_to = $data['email_to'];
        $obj->email_from = $data['email_from'];
        $obj->email_from_name = $data['email_from_name'];
        $obj->status = $data['status'];
        $obj->smtp_response = $data['smtp_response'];
        $obj->smtp_debug = $data['smtp_debug'];
        $obj->error_message = $data['error_message'];
        $obj->created_at = $data['created_at'];
        $obj->updated_at = $data['updated_at'];
        
        return $obj;
    }
}

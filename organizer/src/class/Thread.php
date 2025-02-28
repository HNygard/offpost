<?php

require_once __DIR__ . '/ThreadAuthorization.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/ThreadEmail.php';
require_once __DIR__ . '/Entity.php';

class Thread implements JsonSerializable {
    // Sending status constants
    const SENDING_STATUS_STAGING = 'STAGING';
    const SENDING_STATUS_READY_FOR_SENDING = 'READY_FOR_SENDING';
    const SENDING_STATUS_SENDING = 'SENDING';
    const SENDING_STATUS_SENT = 'SENT';

    var $id;
    var $id_old;
    var $entity_id;
    var $title;
    var $my_name;
    var $my_email;
    var $labels;
    var $sent; // Kept for backward compatibility
    var $sending_status;
    var $initial_request;
    var $archived;
    var $public = false;
    var $sentComment;

    /* @var ThreadEmail[] $emails */
    var $emails;

    private function generateUuid(): string {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    public function __construct() {
        $this->id = $this->generateUuid();
        $this->sending_status = self::SENDING_STATUS_STAGING; // Default to STAGING
    }

    /**
     * Check if thread is in STAGING status
     */
    public function isStaged() {
        return $this->sending_status === self::SENDING_STATUS_STAGING;
    }
    
    /**
     * Check if thread is in READY_FOR_SENDING status
     */
    public function isReadyForSending() {
        return $this->sending_status === self::SENDING_STATUS_READY_FOR_SENDING;
    }
    
    /**
     * Check if thread is in SENDING status
     */
    public function isSending() {
        return $this->sending_status === self::SENDING_STATUS_SENDING;
    }
    
    /**
     * Check if thread is in SENT status
     */
    public function isSent() {
        return $this->sending_status === self::SENDING_STATUS_SENT;
    }

    /**
     * Add a user to this thread
     */
    public function addUser($user_id, $is_owner = false) {
        return ThreadAuthorizationManager::addUserToThread($this->id, $user_id, $is_owner);
    }

    /**
     * Remove a user from this thread
     */
    public function removeUser($user_id) {
        ThreadAuthorizationManager::removeUserFromThread($this->id, $user_id);
    }

    /**
     * Check if a user can access this thread
     */
    public function canUserAccess($user_id) {
        if ($this->public) {
            return true;
        }
        return ThreadAuthorizationManager::canUserAccessThread($this->id, $user_id);
    }

    /**
     * Check if a user is the owner of this thread
     */
    public function isUserOwner($user_id) {
        return ThreadAuthorizationManager::isThreadOwner($this->id, $user_id);
    }

    /**
     * Specify data which should be serialized to JSON
     */
    public function jsonSerialize(): mixed {
        $data = get_object_vars($this);
        // Remove id_old from serialization
        unset($data['id_old']);
        return $data;
    }
    
    /**
     * Get entity name for this thread
     * @return string The entity name or entity ID if not found
     * @throws InvalidArgumentException If entity_id is null or empty
     */
    public function getEntityName() {
        $entity = Entity::getById($this->entity_id);
        return $entity->name;
    }

    /**
     * Load a thread from the database by its ID
     */
    public static function loadFromDatabase($id) {
        $data = Database::queryOne(
            "SELECT * FROM threads WHERE id_old = ? OR id = ?",
            [$id, $id]
        );

        if (!$data) {
            return null;
        }

        $thread = new Thread();
        $thread->id = $data['id'];
        $thread->id_old = $data['id_old'];
        $thread->title = $data['title'];
        $thread->my_name = $data['my_name'];
        $thread->my_email = $data['my_email'];
        $thread->entity_id = $data['entity_id'];
        // Convert PostgreSQL array to PHP array by removing {} and splitting by comma
        $labelsStr = trim($data['labels'] ?? '', '{}');
        $thread->labels = $labelsStr ? array_map(function($label) {
            return trim($label, '"'); // Remove quotes from each label
        }, explode(',', $labelsStr)) : [];
        $thread->sent = (bool)$data['sent'];
        $thread->archived = (bool)$data['archived'];
        $thread->public = (bool)$data['public'];
        $thread->sentComment = $data['sent_comment'];
        $thread->sending_status = $data['sending_status'] ?? ($data['sent'] ? self::SENDING_STATUS_SENT : self::SENDING_STATUS_STAGING);
        $thread->initial_request = $data['initial_request'] ?? null;

        // Load emails
        $emails = Database::query(
            "SELECT * FROM thread_emails WHERE thread_id = ? ORDER BY id_old, timestamp_received",
            [$data['id']] // Use the UUID from the threads table
        );

        $thread->emails = [];
        foreach ($emails as $emailData) {
            $email = new ThreadEmail();
            $email->timestamp_received = $emailData['timestamp_received'];
            $email->id = $emailData['id'];
            $email->id_old = $emailData['id_old'];
            $email->datetime_received = $emailData['datetime_received'] ? new DateTime($emailData['datetime_received']) : null;
            $email->ignore = (bool)$emailData['ignore'];
            $email->email_type = $emailData['email_type'];
            $email->status_type = $emailData['status_type'];
            $email->status_text = $emailData['status_text'];
            $email->description = $emailData['description'];
            $email->answer = $emailData['answer'];
            // Load attachments from thread_email_attachments table
            $attachments = Database::query(
                "SELECT * FROM thread_email_attachments WHERE email_id = ?",
                [$emailData['id']]
            );
            $email->attachments = array_map(function($att) {
                return [
                    'name' => $att['name'],
                    'filename' => $att['filename'],
                    'filetype' => $att['filetype'],
                    'location' => $att['location'],
                    'status_type' => $att['status_type'],
                    'status_text' => $att['status_text']
                ];
            }, $attachments);
            $thread->emails[] = $email;
        }

        return $thread;
    }
}

<?php

require_once __DIR__ . '/ThreadAuthorization.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/ThreadEmail.php';

class Thread {
    var $id;
    var $id_old;
    var $title;
    var $my_name;
    var $my_email;
    var $labels;
    var $sent;
    var $archived;
    var $public = false;
    var $sentComment; // Added property

    /* @var ThreadEmail[] $emails */
    var $emails;

    public function __construct() {
        $this->id = uniqid('thread_', true);
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
     * Load a thread from the database by its ID
     */
    public static function loadFromDatabase($id) {
        $data = Database::queryOne(
            "SELECT * FROM threads WHERE id_old = ?",
            [$id]
        );

        if (!$data) {
            return null;
        }

        $thread = new Thread();
        $thread->id = $data['id'];
        $thread->title = $data['title'];
        $thread->my_name = $data['my_name'];
        $thread->my_email = $data['my_email'];
        // Convert PostgreSQL array to PHP array by removing {} and splitting by comma
        $labelsStr = trim($data['labels'] ?? '', '{}');
        $thread->labels = $labelsStr ? array_map(function($label) {
            return trim($label, '"'); // Remove quotes from each label
        }, explode(',', $labelsStr)) : [];
        $thread->sent = (bool)$data['sent'];
        $thread->archived = (bool)$data['archived'];
        $thread->public = (bool)$data['public'];
        $thread->sentComment = $data['sent_comment'];

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

<?php

require_once __DIR__ . '/ThreadDatabaseOperations.php';

class ThreadStorageManager {
    private static $instance = null;
    private $dbOps;
    
    private function __construct() {
        $this->dbOps = new ThreadDatabaseOperations();
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    

    /**
     * @return Threads[]
     */
    public function getThreads($userId = null) {
        return $this->dbOps->getThreads($userId);
    }
    
    public function getThreadsForEntity($entityId) {
        return $this->dbOps->getThreadsForEntity($entityId);
    }
    
    public function createThread($entityId, Thread $thread, $userId = 'system') {
        return $this->dbOps->createThread($entityId, $thread, $userId);
    }

    public function updateThread(Thread $thread, $userId = 'system') {
        return $this->dbOps->updateThread($thread, $userId);
    }

    public function getThreadEmailContent($thread_id, $email_id) {
        // Get email content from database
        $content = Database::queryValue(
            "SELECT content FROM thread_emails WHERE thread_id = ? AND id = ?",
            [$thread_id, $email_id]
        );

        return stream_get_contents($content);
    }
    public function getThreadEmailAttachment(Thread $thread, $attachment_location) {
        // Get attachment from database
        $rows = Database::queryOne(
            "SELECT tea.name, tea.filename, tea.filetype, tea.location, tea.status_type, tea.status_text, 
                    tea.content
             FROM thread_email_attachments tea
             JOIN thread_emails te ON tea.email_id = te.id
             WHERE te.thread_id = ? AND tea.location = ?",
            [$thread->id, $attachment_location]
        );
        
        if (empty($rows)) {
            throw new Exception("Thread Email Attachment not found [thread_id=$thread->id, attachment_id=$attachment_location]");
        }
        
        $attachment = new ThreadEmailAttachment();
        $attachment->name = $rows['name'];
        $attachment->filename = $rows['filename'];
        $attachment->filetype = $rows['filetype'];
        $attachment->location = $rows['location'];
        $attachment->status_type = $rows['status_type'];
        $attachment->status_text = $rows['status_text'];
        
        // Handle the encoded bytea data
        $content = $rows['content'];
        if (is_resource($content)) {
            $content = stream_get_contents($content);
        }
        if (substr($content, 0, 2) === '\\x') {
            // Convert hex format to binary
            $content = hex2bin(substr($content, 2));
        }
        $attachment->content = $content;
        
        return $attachment;
    }
}

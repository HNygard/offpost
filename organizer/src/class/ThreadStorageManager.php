<?php

require_once __DIR__ . '/ThreadFileOperations.php';
require_once __DIR__ . '/ThreadDatabaseOperations.php';

class ThreadStorageManager {
    private static $instance = null;
    private $fileOps;
    private $dbOps;
    private $useDatabase;
    
    private function __construct() {
        $this->fileOps = new ThreadFileOperations();
        $this->dbOps = new ThreadDatabaseOperations();
        // Default to database storage for optimized queries
        $this->useDatabase = true;
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getThreads($userId = null) {
        return $this->useDatabase ? $this->dbOps->getThreads($userId) : $this->fileOps->getThreads($userId);
    }
    
    public function getThreadsForEntity($entityId) {
        return $this->useDatabase ? $this->dbOps->getThreadsForEntity($entityId) : $this->fileOps->getThreadsForEntity($entityId);
    }
    
    public function createThread($entityId, Thread $thread, $userId = 'system') {
        return $this->useDatabase ? 
            $this->dbOps->createThread($entityId, $thread, $userId) : 
            $this->fileOps->createThread($entityId, $thread, $userId);
    }

    public function updateThread(Thread $thread, $userId = 'system') {
        return $this->useDatabase ?
            $this->dbOps->updateThread($thread, $userId) :
            $this->fileOps->updateThread($thread, $userId);
    }

    public function getThreadEmailContent($entityId, Thread $thread, $email_id) {
        // Get email content from database
        $content = Database::queryValue(
            "SELECT content FROM thread_emails WHERE thread_id = ? AND id = ?",
            [$thread->id, $email_id]
        );
        
        if (!$content) {
            throw new Exception("Thread Email have no content [thread_id=$thread->id, email_id=$email_id]");
        }
        
        return $content;
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

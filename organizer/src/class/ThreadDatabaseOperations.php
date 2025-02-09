<?php

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/Thread.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Threads.php';

class ThreadDatabaseOperations {
    private $db;

    public function __construct() {
        $this->db = new Database();
    }

    public function getThreads() {
        $threads = array();
        
        // Get all unique entity_ids
        $entityIds = $this->db->query("SELECT DISTINCT entity_id FROM threads ORDER BY entity_id");
        
        foreach ($entityIds as $row) {
            $entityId = $row['entity_id'];
            $threads["threads-$entityId.json"] = $this->getThreadsForEntity($entityId);
        }
        
        return $threads;
    }

    public function getThreadsForEntity($entityId) {
        $threads = new Threads();
        
        // Get entity info
        $entityInfo = $this->db->queryOne(
            "SELECT entity_id FROM threads WHERE entity_id = ? LIMIT 1",
            [$entityId]
        );
        
        if (!$entityInfo) {
            return null;
        }
        
        //$threads->title_prefix = $entityInfo['title_prefix'];
        $threads->entity_id = $entityInfo['entity_id'];
        $threads->threads = array();
        
        // Get all threads for this entity
        $threadRows = $this->db->query(
            "SELECT id, id_old, title, my_name, my_email, sent, archived, labels, sent_comment FROM threads WHERE entity_id = ? ORDER BY id",
            [$entityId]
        );
        
        foreach ($threadRows as $row) {
            $thread = new Thread();
            $thread->id = $row['id'];
            $thread->id_old = $row['id_old'];
            $thread->title = $row['title'];
            $thread->my_name = $row['my_name'];
            $thread->my_email = $row['my_email'];
            $thread->sent = (bool)$row['sent'];
            $thread->archived = (bool)$row['archived'];
            // Parse PostgreSQL array format
            if ($row['labels'] !== null) {
                $labelsStr = trim($row['labels'], '{}');
                if ($labelsStr) {
                    // Split by comma, but not within quotes
                    $labels = preg_split('/,\s*(?=(?:[^"]*"[^"]*")*[^"]*$)/', $labelsStr);
                    // Remove quotes and unescape double quotes
                    $thread->labels = array_map(function($label) {
                        return str_replace('""', '"', trim($label, '"'));
                    }, $labels);
                } else {
                    $thread->labels = [];
                }
            } else {
                $thread->labels = [];
            }
            $thread->sentComment = $row['sent_comment'];
            
            // Get emails for this thread
            $emails = $this->db->query(
                "SELECT id, datetime_received, email_type, status_type, status_text, description, ignore FROM thread_emails WHERE thread_id = ? ORDER BY datetime_received",
                [$thread->id]
            );
            
            $thread->emails = array();
            foreach ($emails as $email) {
                $emailObj = new stdClass();
                $emailObj->datetime_received = $email['datetime_received'];
                $emailObj->email_type = $email['email_type'];
                $emailObj->status_type = $email['status_type'];
                $emailObj->status_text = $email['status_text'];
                $emailObj->description = $email['description'];
                $emailObj->ignore = (bool)$email['ignore'];
                
                // Get attachments for this email
                $attachments = $this->db->query(
                    "SELECT name, filetype, status_type, status_text FROM thread_email_attachments WHERE email_id = ?",
                    [$email['id']]
                );
                
                if ($attachments) {
                    $emailObj->attachments = array();
                    foreach ($attachments as $att) {
                        $attObj = new stdClass();
                        $attObj->name = $att['name'];
                        $attObj->filetype = $att['filetype'];
                        $attObj->status_type = $att['status_type'];
                        $attObj->status_text = $att['status_text'];
                        $emailObj->attachments[] = $attObj;
                    }
                }
                
                $thread->emails[] = $emailObj;
            }
            
            $threads->threads[] = $thread;
        }
        
        return $threads;
    }

    private function formatLabelsForPostgres(?array $labels): ?string {
        if ($labels === null || empty($labels)) {
            return null;
        }
        
        // Format labels as PostgreSQL array string with proper quoting and spacing
        $escapedLabels = array_map(function($label) {
            return '"' . str_replace('"', '""', trim($label)) . '"';
        }, $labels);
        
        return sprintf('{%s}', implode(', ', $escapedLabels));
    }

    private function generateUuid(): string {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    public function createThread($entityId, $entityTitlePrefix, Thread $thread) {
        // Generate UUID for new thread
        $uuid = $this->generateUuid();
        
        $this->db->execute(
            "INSERT INTO threads (id, id_old, entity_id, title, my_name, my_email, sent, archived, labels, sent_comment) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $uuid,
                $thread->id_old ?? null, // Use existing id_old if set, otherwise null
                $entityId,
                $thread->title,
                $thread->my_name,
                $thread->my_email,
                $thread->sent ? 't' : 'f',
                $thread->archived ? 't' : 'f',
                $this->formatLabelsForPostgres($thread->labels),
                $thread->sentComment
            ]
        );
        
        // Set the UUID as the thread's ID
        $thread->id = $uuid;
        return $thread;
    }
}

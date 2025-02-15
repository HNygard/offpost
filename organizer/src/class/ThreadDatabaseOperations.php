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

    public function getThreads($userId = null) {
        $threads = array();
        
        // Get all threads with emails and attachments in a single query
        $query = "
            WITH user_check AS (
                SELECT ? AS user_id
            ),
            thread_data AS (
                SELECT 
                    t.entity_id,
                    t.id as thread_id,
                    t.id_old,
                    t.title,
                    t.my_name,
                    t.my_email,
                    t.sent,
                    t.archived,
                    t.labels,
                    t.sent_comment,
                    t.public,
                    e.id as email_id,
                    e.datetime_received,
                    e.email_type,
                    e.status_type as email_status_type,
                    e.status_text as email_status_text,
                    e.description,
                    e.ignore,
                    a.name as attachment_name,
                    a.filetype as attachment_filetype,
                    a.status_type as attachment_status_type,
                    a.status_text as attachment_status_text,
                    CASE 
                        WHEN t.public THEN true
                        WHEN ta.thread_id IS NOT NULL THEN true
                        ELSE false
                    END as has_access
                FROM threads t
                LEFT JOIN thread_emails e ON t.id = e.thread_id
                LEFT JOIN thread_email_attachments a ON e.id = a.email_id
                LEFT JOIN thread_authorizations ta ON t.id = ta.thread_id AND ta.user_id = (SELECT user_id FROM user_check)
                WHERE (SELECT user_id FROM user_check) IS NULL OR t.public = true OR ta.thread_id IS NOT NULL
                ORDER BY t.entity_id, t.id, e.datetime_received, a.id
            )
            SELECT * FROM thread_data";
        
        $rows = $this->db->query($query, [$userId]);
        
        $currentEntityId = null;
        $currentThreadId = null;
        $currentEmailId = null;
        $currentThreads = null;
        $currentThread = null;
        $currentEmail = null;
        
        foreach ($rows as $row) {
            // New entity
            if ($currentEntityId !== $row['entity_id']) {
                if ($currentThreads !== null) {
                    $threads["threads-$currentEntityId.json"] = $currentThreads;
                }
                
                $currentThreads = new Threads();
                $currentThreads->entity_id = $row['entity_id'];
                $currentThreads->threads = array();
                $currentEntityId = $row['entity_id'];
            }
            
            // New thread
            if ($currentThreadId !== $row['thread_id']) {
                $currentThread = new Thread();
                $currentThread->id = $row['thread_id'];
                $currentThread->id_old = $row['id_old'];
                $currentThread->title = $row['title'];
                $currentThread->my_name = $row['my_name'];
                $currentThread->my_email = $row['my_email'];
                $currentThread->sent = (bool)$row['sent'];
                $currentThread->archived = (bool)$row['archived'];
                $currentThread->public = (bool)$row['public'];
                
                // Parse PostgreSQL array format
                if ($row['labels'] !== null) {
                    $labelsStr = trim($row['labels'], '{}');
                    if ($labelsStr) {
                        $labels = preg_split('/,\s*(?=(?:[^"]*"[^"]*")*[^"]*$)/', $labelsStr);
                        $currentThread->labels = array_map(function($label) {
                            return str_replace('""', '"', trim($label, '"'));
                        }, $labels);
                    } else {
                        $currentThread->labels = [];
                    }
                } else {
                    $currentThread->labels = [];
                }
                
                $currentThread->sentComment = $row['sent_comment'];
                $currentThread->emails = array();
                
                $currentThreads->threads[] = $currentThread;
                $currentThreadId = $row['thread_id'];
            }
            
            // Skip if no email data
            if ($row['email_id'] === null) {
                continue;
            }
            
            // New email
            if ($currentEmailId !== $row['email_id']) {
                $currentEmail = new stdClass();
                $currentEmail->datetime_received = $row['datetime_received'];
                $currentEmail->email_type = $row['email_type'];
                $currentEmail->status_type = $row['email_status_type'];
                $currentEmail->status_text = $row['email_status_text'];
                $currentEmail->description = $row['description'];
                $currentEmail->ignore = (bool)$row['ignore'];
                $currentEmail->attachments = array();
                
                $currentThread->emails[] = $currentEmail;
                $currentEmailId = $row['email_id'];
            }
            
            // Add attachment if exists
            if ($row['attachment_name'] !== null) {
                $attObj = new stdClass();
                $attObj->name = $row['attachment_name'];
                $attObj->filetype = $row['attachment_filetype'];
                $attObj->status_type = $row['attachment_status_type'];
                $attObj->status_text = $row['attachment_status_text'];
                $currentEmail->attachments[] = $attObj;
            }
        }
        
        // Add last entity's threads
        if ($currentEntityId !== null) {
            $threads["threads-$currentEntityId.json"] = $currentThreads;
        }
        
        return $threads;
    }

    public function getThreadsForEntity($entityId) {
        $threads = new Threads();
        $threads->entity_id = $entityId;
        $threads->threads = array();
        
        // Get all threads for this entity using the same optimized query
        $query = "
            WITH thread_data AS (
                SELECT 
                    t.id as thread_id,
                    t.id_old,
                    t.title,
                    t.my_name,
                    t.my_email,
                    t.sent,
                    t.archived,
                    t.labels,
                    t.sent_comment,
                    t.public,
                    e.id as email_id,
                    e.datetime_received,
                    e.email_type,
                    e.status_type as email_status_type,
                    e.status_text as email_status_text,
                    e.description,
                    e.ignore,
                    a.name as attachment_name,
                    a.filetype as attachment_filetype,
                    a.status_type as attachment_status_type,
                    a.status_text as attachment_status_text
                FROM threads t
                LEFT JOIN thread_emails e ON t.id = e.thread_id
                LEFT JOIN thread_email_attachments a ON e.id = a.email_id
                WHERE t.entity_id = ?
                ORDER BY t.id, e.datetime_received, a.id
            )
            SELECT * FROM thread_data";
        
        $rows = $this->db->query($query, [$entityId]);
        
        $currentThreadId = null;
        $currentEmailId = null;
        $currentThread = null;
        $currentEmail = null;
        
        foreach ($rows as $row) {
            // New thread
            if ($currentThreadId !== $row['thread_id']) {
                $currentThread = new Thread();
                $currentThread->id = $row['thread_id'];
                $currentThread->id_old = $row['id_old'];
                $currentThread->title = $row['title'];
                $currentThread->my_name = $row['my_name'];
                $currentThread->my_email = $row['my_email'];
                $currentThread->sent = (bool)$row['sent'];
                $currentThread->archived = (bool)$row['archived'];
                $currentThread->public = (bool)$row['public'];
                
                // Parse PostgreSQL array format
                if ($row['labels'] !== null) {
                    $labelsStr = trim($row['labels'], '{}');
                    if ($labelsStr) {
                        $labels = preg_split('/,\s*(?=(?:[^"]*"[^"]*")*[^"]*$)/', $labelsStr);
                        $currentThread->labels = array_map(function($label) {
                            return str_replace('""', '"', trim($label, '"'));
                        }, $labels);
                    } else {
                        $currentThread->labels = [];
                    }
                } else {
                    $currentThread->labels = [];
                }
                
                $currentThread->sentComment = $row['sent_comment'];
                $currentThread->emails = array();
                
                $threads->threads[] = $currentThread;
                $currentThreadId = $row['thread_id'];
            }
            
            // Skip if no email data
            if ($row['email_id'] === null) {
                continue;
            }
            
            // New email
            if ($currentEmailId !== $row['email_id']) {
                $currentEmail = new stdClass();
                $currentEmail->datetime_received = $row['datetime_received'];
                $currentEmail->email_type = $row['email_type'];
                $currentEmail->status_type = $row['email_status_type'];
                $currentEmail->status_text = $row['email_status_text'];
                $currentEmail->description = $row['description'];
                $currentEmail->ignore = (bool)$row['ignore'];
                $currentEmail->attachments = array();
                
                $currentThread->emails[] = $currentEmail;
                $currentEmailId = $row['email_id'];
            }
            
            // Add attachment if exists
            if ($row['attachment_name'] !== null) {
                $attObj = new stdClass();
                $attObj->name = $row['attachment_name'];
                $attObj->filetype = $row['attachment_filetype'];
                $attObj->status_type = $row['attachment_status_type'];
                $attObj->status_text = $row['attachment_status_text'];
                $currentEmail->attachments[] = $attObj;
            }
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

    public function updateThread(Thread $thread) {
        $this->db->execute(
            "UPDATE threads 
             SET title = ?, my_name = ?, my_email = ?, sent = ?, archived = ?, 
                 labels = ?, sent_comment = ?, public = ?
             WHERE id = ?",
            [
                $thread->title,
                $thread->my_name,
                $thread->my_email,
                $thread->sent ? 't' : 'f',
                $thread->archived ? 't' : 'f',
                $this->formatLabelsForPostgres($thread->labels),
                $thread->sentComment,
                $thread->public ? 't' : 'f',
                $thread->id
            ]
        );
        return $thread;
    }

    public function createThread($entityId, $entityTitlePrefix, Thread $thread) {
        // Generate UUID for new thread
        $uuid = $this->generateUuid();
        
        $this->db->execute(
            "INSERT INTO threads (id, id_old, entity_id, title, my_name, my_email, sent, archived, labels, sent_comment, public) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
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
                $thread->sentComment,
                $thread->public ? 't' : 'f'
            ]
        );
        
        // Set the UUID as the thread's ID
        $thread->id = $uuid;
        return $thread;
    }
}

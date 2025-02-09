<?php

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/Thread.php';
require_once __DIR__ . '/Database.php';

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
            $thread->labels = json_decode($row['labels'], true) ?? [];
            $thread->sentComment = $row['sent_comment'];
            
            // Get emails for this thread
            $emails = $this->db->query(
                "SELECT datetime_received, email_type, status_type, status_text, description, ignore FROM thread_emails WHERE thread_id = ? ORDER BY datetime_received",
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
                    "SELECT id, name, filtype, status_type, status_text FROM thread_email_attachments WHERE email_id = ?",
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

    public function createThread($entityId, $entityTitlePrefix, Thread $thread) {
        $existingThreads = $this->getThreadsForEntity($entityId);
        if ($existingThreads === null) {
            // Insert new entity thread
            $this->db->execute(
                "INSERT INTO threads (entity_id, title_prefix, title, my_name, my_email, sent, archived, labels, sent_comment) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $entityId,
                    $entityTitlePrefix,
                    $thread->title,
                    $thread->my_name,
                    $thread->my_email,
                    $thread->sent ? 1 : 0,
                    $thread->archived ? 1 : 0,
                    json_encode($thread->labels),
                    $thread->sentComment
                ]
            );
        } else {
            // Insert additional thread for existing entity
            $this->db->execute(
                "INSERT INTO threads (entity_id, title_prefix, title, my_name, my_email, sent, archived, labels, sent_comment) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $entityId,
                    $existingThreads->title_prefix,
                    $thread->title,
                    $thread->my_name,
                    $thread->my_email,
                    $thread->sent ? 1 : 0,
                    $thread->archived ? 1 : 0,
                    json_encode($thread->labels),
                    $thread->sentComment
                ]
            );
        }
        
        // Get the inserted thread's ID
        $thread->id = $this->db->lastInsertId();
        return $thread;
    }
}

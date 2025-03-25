<?php

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/Thread.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Threads.php';
require_once __DIR__ . '/ThreadHistory.php';
require_once __DIR__ . '/ThreadEmail.php';
require_once __DIR__ . '/ThreadEmailAttachment.php';

class ThreadDatabaseOperations {
    private $history;

    public function __construct() {
        $this->history = new ThreadHistory();
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
                    t.sending_status,
                    t.initial_request,
                    t.sent,
                    t.archived,
                    t.labels,
                    t.sent_comment,
                    t.public,
                    e.id as email_id,
                    e.id_old as email_id_old,
                    e.datetime_received,
                    e.email_type,
                    e.status_type as email_status_type,
                    e.status_text as email_status_text,
                    e.description,
                    e.ignore,
                    a.name as attachment_name,
                    a.filename as attachment_filename,
                    a.filetype as attachment_filetype,
                    a.status_type as attachment_status_type,
                    a.status_text as attachment_status_text,
                    a.location as attachment_location,
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
                ORDER BY t.entity_id, t.created_at, e.datetime_received, a.id
            )
            SELECT * FROM thread_data";
        
        $rows = Database::query($query, [$userId]);
        
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
                $currentThread->sending_status = $row['sending_status'];
                $currentThread->initial_request = $row['initial_request'];
                $currentThread->sent = (bool)$row['sent'];
                $currentThread->archived = (bool)$row['archived'];
                $currentThread->public = (bool)$row['public'];
                $currentThread->entity_id = $row['entity_id'];
                
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
                $currentEmail = new ThreadEmail();
                $currentEmail->id = $row['email_id'];
                $currentEmail->id_old = $row['email_id_old'];
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
                $attObj = new ThreadEmailAttachment();
                $attObj->name = $row['attachment_name'];
                $attObj->filename = $row['attachment_filename'];
                $attObj->filetype = $row['attachment_filetype'];
                $attObj->status_type = $row['attachment_status_type'];
                $attObj->status_text = $row['attachment_status_text'];
                $attObj->location = $row['attachment_location'];
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
                    t.entity_id,
                    t.title,
                    t.my_name,
                    t.my_email,
                    t.sent,
                    t.archived,
                    t.labels,
                    t.sent_comment,
                    t.public,
                    e.id as email_id,
                    e.id_old as email_id_old,
                    e.datetime_received,
                    e.email_type,
                    e.status_type as email_status_type,
                    e.status_text as email_status_text,
                    e.description,
                    e.ignore,
                    a.name as attachment_name,
                    a.filename as attachment_filename,
                    a.filetype as attachment_filetype,
                    a.status_type as attachment_status_type,
                    a.status_text as attachment_status_text,
                    a.location as attachment_location
                FROM threads t
                LEFT JOIN thread_emails e ON t.id = e.thread_id
                LEFT JOIN thread_email_attachments a ON e.id = a.email_id
                WHERE t.entity_id = ?
                ORDER BY t.created_at, e.datetime_received, a.id
            )
            SELECT * FROM thread_data";
        
        $rows = Database::query($query, [$entityId]);
        
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
                $currentThread->entity_id = $row['entity_id'];
                
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
                $currentEmail = new ThreadEmail();
                $currentEmail->id = $row['email_id'];
                $currentEmail->id_old = $row['email_id_old'];
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
                $attObj = new ThreadEmailAttachment();
                $attObj->name = $row['attachment_name'];
                $attObj->filename = $row['attachment_filename'];
                $attObj->filetype = $row['attachment_filetype'];
                $attObj->status_type = $row['attachment_status_type'];
                $attObj->status_text = $row['attachment_status_text'];
                $attObj->location = $row['attachment_location'];
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

    public function updateThread(Thread $thread, $userId) {
        // Get current thread state to detect changes
        $currentThread = Thread::loadFromDatabase($thread->id);

        Database::execute(
            "UPDATE threads 
             SET title = ?, my_name = ?, my_email = ?, sent = ?, archived = ?, 
                 labels = ?, sent_comment = ?, public = ?, sending_status = ?, initial_request = ?,
                 request_law_basis = ?, request_follow_up_plan = ?
             WHERE id = ?",
            [
                $thread->title,
                $thread->my_name,
                $thread->my_email,
                $thread->sending_status === Thread::SENDING_STATUS_SENT ? 't' : 'f', // Keep sent in sync with sending_status
                $thread->archived ? 't' : 'f',
                $this->formatLabelsForPostgres($thread->labels),
                $thread->sentComment,
                $thread->public ? 't' : 'f',
                $thread->sending_status,
                $thread->initial_request,
                $thread->request_law_basis,
                $thread->request_follow_up_plan,
                $thread->id
            ]
        );

        if ($thread->entity_id != $currentThread->entity_id) {
            throw new Exception("Cannot move thread to a different entity");
        }

        Entity::getById($thread->entity_id);

        // Log changes
        if ($currentThread) {
            $details = [];
            // Check for changes that require detailed logging
            if ($currentThread->title !== $thread->title) {
                $details['title'] = $thread->title;
            }
            if ($currentThread->labels !== $thread->labels) {
                $details['labels'] = $thread->labels;
            }
            if ($currentThread->my_name !== $thread->my_name) {
                $details['my_name'] = $thread->my_name;
            }
            if ($currentThread->my_email !== $thread->my_email) {
                $details['my_email'] = $thread->my_email;
            }
            if ($currentThread->sentComment !== $thread->sentComment) {
                $details['sent_comment'] = $thread->sentComment;
            }
            if ($currentThread->initial_request !== $thread->initial_request) {
                $details['initial_request'] = $thread->initial_request;
            }
            if ($currentThread->request_law_basis !== $thread->request_law_basis) {
                $details['request_law_basis'] = $thread->request_law_basis;
            }
            if ($currentThread->request_follow_up_plan !== $thread->request_follow_up_plan) {
                $details['request_follow_up_plan'] = $thread->request_follow_up_plan;
            }
            if (!empty($details)) {
                $this->history->logAction($thread->id, 'edited', $userId, $details);
            }

            // Check for status changes
            if ($currentThread->archived !== $thread->archived) {
                $this->history->logAction($thread->id, $thread->archived ? 'archived' : 'unarchived', $userId);
            }
            if ($currentThread->public !== $thread->public) {
                $this->history->logAction($thread->id, $thread->public ? 'made_public' : 'made_private', $userId);
            }
            if ($currentThread->sending_status !== $thread->sending_status) {
                $this->history->logAction($thread->id, 'status_changed', $userId, [
                    'from' => $currentThread->sending_status,
                    'to' => $thread->sending_status
                ]);
            }
        }
        
        return $thread;
    }

    public function createThread($entityId, Thread $thread, $userId) {
        // Check entity
        $entity = Entity::getById($entityId);

        // Generate UUID for new thread
        $uuid = $this->generateUuid();

        if ($thread->request_law_basis != null && !in_array(
            $thread->request_law_basis,
            [Thread::REQUEST_LAW_BASIS_OFFENTLEGLOVA, Thread::REQUEST_LAW_BASIS_OTHER]
        )) {
            throw new Exception("Invalid request law basis");
        }
        if ($thread->request_follow_up_plan != null && !in_array(
            $thread->request_follow_up_plan,
            [Thread::REQUEST_FOLLOW_UP_PLAN_SPEEDY, Thread::REQUEST_FOLLOW_UP_PLAN_SLOW]
        )) {
            throw new Exception("Invalid request follup plan");
        }
        
        try {
            Database::execute(
                "INSERT INTO threads (id, id_old, entity_id, title, my_name, my_email, sent, archived, labels, sent_comment, public, sending_status, initial_request, request_law_basis, request_follow_up_plan) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $uuid,
                    $thread->id_old ?? null, // Use existing id_old if set, otherwise null
                    $entity->entity_id,
                    $thread->title,
                    $thread->my_name,
                    $thread->my_email,
                    $thread->sending_status === Thread::SENDING_STATUS_SENT ? 't' : 'f', // Keep sent in sync with sending_status
                    $thread->archived ? 't' : 'f',
                    $this->formatLabelsForPostgres($thread->labels),
                    $thread->sentComment,
                    $thread->public ? 't' : 'f',
                    $thread->sending_status ?? Thread::SENDING_STATUS_STAGING,
                    $thread->initial_request,
                    $thread->request_law_basis,
                    $thread->request_follow_up_plan
                ]
            );
        }
        catch(Exception $e) {
            throw new Exception("Failed to create thread for my_name=" . $thread->my_name . ", my_email=" . $thread->my_email . ": " . $e->getMessage(), 0, $e);
        }
        
        // Set the UUID as the thread's ID
        $thread->id = $uuid;
        
        // Set the entity_id on the Thread object
        $thread->entity_id = $entityId;
        
        // Log thread creation
        $this->history->logAction($uuid, 'created', $userId);
        
        return $thread;
    }
}

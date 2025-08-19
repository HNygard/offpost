<?php

require_once __DIR__ . '/Database.php';

/**
 * Repository class for retrieving thread statuses
 */
class ThreadStatusRepository {
    // Thread status constants
    const ERROR_NO_FOLDER_FOUND = 'ERROR_NO_FOLDER_FOUND';
    const ERROR_MULTIPLE_FOLDERS = 'ERROR_MULTIPLE_FOLDERS';
    const ERROR_NO_SYNC = 'ERROR_NO_SYNC';
    const ERROR_OLD_SYNC_REQUESTED_UPDATE = 'ERROR_OLD_SYNC_REQUESTED_UPDATE';
    const ERROR_OLD_SYNC = 'ERROR_OLD_SYNC';
    const ERROR_THREAD_NOT_FOUND = "ERROR_THREAD_NOT_FOUND";
    const ERROR_INBOX_SYNC = 'ERROR_INBOX_SYNC';
    const ERROR_SENT_SYNC = 'ERROR_SENT_SYNC';
    const NOT_SENT = 'NOT_SENT';
    const EMAIL_SENT_NOTHING_RECEIVED = 'EMAIL_SENT_NOTHING_RECEIVED';
    const STATUS_OK = 'STATUS_OK';
    
    /**
     * Get status for a specific thread
     * 
     * @param string $threadId The thread UUID
     * @return string The thread status (one of the class constants)
     */
    public static function getThreadStatus(string $threadId): string {
        // Use the more efficient method with thread ID filter
        $statuses = self::getAllThreadStatusesEfficient([$threadId]);
        
        return isset($statuses[$threadId]) ? $statuses[$threadId]->status : self::ERROR_THREAD_NOT_FOUND;
    }
    
    /**
     * Get statuses for all non-archived threads with a single SQL query
     * This is more efficient than calling getThreadStatus() for each thread
     * 
     * @param array|null $threadIds Optional array of thread IDs to filter by
     * @param string|null $status Optional status to filter by (one of the class constants)
     * @return ThreadStatus[] Statuses with thread_id as key
     */
    public static function getAllThreadStatusesEfficient(array $threadIds = null, string $status = null, $archived = false): array {
        $params = [];
        
        // Build the base query
        $query = "
            WITH thread_data AS (
                SELECT 
                    t.id AS thread_id,
                    t.entity_id AS entity_id,
                    request_law_basis,
                    request_follow_up_plan,
                    COUNT(DISTINCT ifs.id) AS folder_count,
                    MAX(ifs.last_checked_at) AS last_checked_at,
                    MAX(ifs.requested_update_time) AS requested_update_time,
                    COUNT(te_in.id) AS email_count_in,
                    COUNT(te_out.id) AS email_count_out,
                    MAX(te_in.timestamp_received) AS email_last_received,
                    MAX(te_out.timestamp_received) AS email_last_sent,
                    GREATEST(
                        MAX(te_in.timestamp_received),
                        MAX(te_out.timestamp_received)
                    ) AS email_last_activity,
                    (SELECT MAX(ifs_inbox.last_checked_at) FROM imap_folder_status ifs_inbox WHERE ifs_inbox.folder_name = 'INBOX')
                        AS last_checked_at_inbox,
                    (SELECT MAX(ifs_sent.last_checked_at) FROM imap_folder_status ifs_sent WHERE ifs_sent.folder_name = 'INBOX.Sent')
                        AS last_checked_at_sent
                FROM 
                    threads t
                LEFT JOIN 
                    imap_folder_status ifs ON t.id = ifs.thread_id
                LEFT JOIN 
                    thread_emails te_in ON t.id = te_in.thread_id AND te_in.email_type = 'IN'
                LEFT JOIN 
                    thread_emails te_out ON t.id = te_out.thread_id AND te_out.email_type = 'OUT'
                WHERE 
                    1 = 1
        ";

        if (!$archived) {
            $query .= " AND t.archived = false";
        } else {
            $query .= " AND t.archived = true";
        }
        
        // Add thread IDs filter if provided
        if ($threadIds !== null && count($threadIds) > 0) {
            $placeholders = implode(',', array_fill(0, count($threadIds), '?'));
            $query .= " AND t.id IN ($placeholders)";
            $params = array_merge($params, $threadIds);
        }
        
        $query .= "
                GROUP BY 
                    t.id
            )
            SELECT
                thread_id,
                entity_id,
                request_law_basis,
                request_follow_up_plan,
                CASE
                    -- Technical checks for this thread
                    WHEN folder_count = 0 THEN 'ERROR_NO_FOLDER_FOUND'
                    WHEN folder_count > 1 THEN 'ERROR_MULTIPLE_FOLDERS'
                    WHEN last_checked_at IS NULL THEN 'ERROR_NO_SYNC'

                    -- Check status for sync of inbox and sent folders
                    WHEN last_checked_at_inbox IS NULL THEN 'ERROR_INBOX_SYNC'
                    WHEN last_checked_at_sent IS NULL THEN 'ERROR_SENT_SYNC'
                    WHEN last_checked_at_inbox < NOW() - INTERVAL '10 minutes' THEN 'ERROR_INBOX_SYNC'
                    WHEN last_checked_at_sent < NOW() - INTERVAL '10 minutes' THEN 'ERROR_SENT_SYNC'
                    
                    -- Up-to-date checks for this thread
                    WHEN requested_update_time IS NOT NULL THEN 'ERROR_OLD_SYNC_REQUESTED_UPDATE'
                    WHEN last_checked_at < NOW() - INTERVAL '6 hours' THEN 'ERROR_OLD_SYNC'
                    WHEN email_count_out = 0 AND email_count_in = 0 THEN 'NOT_SENT'
                    WHEN email_count_out = 1 AND email_count_in = 0 THEN 'EMAIL_SENT_NOTHING_RECEIVED'
                    ELSE 'STATUS_OK'
                END AS status,
                email_count_in,
                email_count_out,
                last_checked_at as email_server_last_checked_at,
                email_last_activity,
                email_last_received,
                email_last_sent
            FROM 
                thread_data
        ";
        
        // Add status filter if provided
        if ($status !== null) {
            $query .= "
            WHERE
                CASE
                    -- Technical checks for this thread
                    WHEN folder_count = 0 THEN 'ERROR_NO_FOLDER_FOUND'
                    WHEN folder_count > 1 THEN 'ERROR_MULTIPLE_FOLDERS'
                    WHEN last_checked_at IS NULL THEN 'ERROR_NO_SYNC'

                    -- Check status for sync of inbox and sent folders
                    WHEN last_checked_at_inbox IS NULL THEN 'ERROR_INBOX_SYNC'
                    WHEN last_checked_at_sent IS NULL THEN 'ERROR_SENT_SYNC'
                    WHEN last_checked_at_inbox < NOW() - INTERVAL '10 minutes' THEN 'ERROR_INBOX_SYNC'
                    WHEN last_checked_at_sent < NOW() - INTERVAL '10 minutes' THEN 'ERROR_SENT_SYNC'
                    
                    -- Up-to-date checks for this thread
                    WHEN last_checked_at < NOW() - INTERVAL '6 hours' THEN 'ERROR_OLD_SYNC'
                    WHEN email_count_out = 0 AND email_count_in = 0 THEN 'NOT_SENT'
                    WHEN email_count_out = 1 AND email_count_in = 0 THEN 'EMAIL_SENT_NOTHING_RECEIVED'
                    ELSE 'STATUS_OK'
                END = ?
            ";
            $params[] = $status;
        }

        $query .= '
            ORDER BY email_last_activity ASC';
        
        $statuses = Database::query($query, $params);
        
        $result = [];
        foreach ($statuses as $status) {
            if ($status['email_server_last_checked_at'] != null) {
                $status['email_server_last_checked_at'] = strtotime($status['email_server_last_checked_at']);
            }
            if ($status['email_last_activity'] != null) {
                $status['email_last_activity'] = strtotime($status['email_last_activity']);
            }
            if ($status['email_last_received'] != null) {
                $status['email_last_received'] = strtotime($status['email_last_received']);
            }
            if ($status['email_last_sent'] != null) {
                $status['email_last_sent'] = strtotime($status['email_last_sent']);
            }

            $thread_status = new ThreadStatus();
            $thread_status->thread_id = $status['thread_id'];
            $thread_status->entity_id = $status['entity_id'];
            $thread_status->request_law_basis = $status['request_law_basis'];
            $thread_status->request_follow_up_plan = $status['request_follow_up_plan'];
            $thread_status->status = $status['status'];
            $thread_status->email_count_in = $status['email_count_in'];
            $thread_status->email_count_out = $status['email_count_out'];
            $thread_status->email_server_last_checked_at = $status['email_server_last_checked_at'];
            $thread_status->email_last_activity = $status['email_last_activity'];
            $thread_status->email_last_received = $status['email_last_received'];
            $thread_status->email_last_sent = $status['email_last_sent'];
            $result[$status['thread_id']] = $thread_status;
        }
        
        return $result;
    }
    
    /**
     * Get threads with a specific status
     * 
     * @param string $status The status to filter by (one of the class constants)
     * @return ThreadStatus[] Array of thread IDs with the specified status
     */
    public static function getThreadsByStatus(string $status): array {
        // Use the new status filter parameter for better efficiency
        return self::getAllThreadStatusesEfficient(null, $status);
    }
    
    /**
     * Get recent incoming emails from threads that the user has access to
     * 
     * @param string $userId The user ID to check access for
     * @param int $limit Maximum number of emails to return (default 20)
     * @return array Array of objects containing thread and email information
     */
    public static function getRecentIncomingEmailsForUser(string $userId, int $limit = 20): array {
        $query = "
            SELECT 
                te.id as email_id,
                te.thread_id,
                te.timestamp_received,
                te.datetime_received,
                te.email_type,
                te.status_type as email_status_type,
                te.status_text as email_status_text,
                te.description as email_description,
                te.imap_headers,
                t.title as thread_title,
                t.entity_id,
                t.labels as thread_labels,
                t.my_name,
                t.my_email,
                t.request_law_basis,
                t.request_follow_up_plan,
                t.sending_status as thread_sending_status,
                t.archived as thread_archived
            FROM thread_emails te
            JOIN threads t ON te.thread_id = t.id
            LEFT JOIN thread_authorizations ta ON t.id = ta.thread_id AND ta.user_id = ?
            WHERE te.email_type = 'IN'
            AND t.archived = false
            AND (t.public = true OR ta.thread_id IS NOT NULL)
            ORDER BY te.timestamp_received DESC
            LIMIT ?
        ";
        
        $results = Database::query($query, [$userId, $limit]);
        
        $emails = [];
        foreach ($results as $row) {
            $email = new stdClass();
            
            // Email information
            $email->email_id = $row['email_id'];
            $email->timestamp_received = $row['timestamp_received'];
            $email->datetime_received = $row['datetime_received'];
            $email->email_type = $row['email_type'];
            $email->email_status_type = $row['email_status_type'];
            $email->email_status_text = $row['email_status_text'];
            $email->email_description = $row['email_description'];
            
            // Parse IMAP headers to get from/subject information
            $imapHeaders = json_decode($row['imap_headers'], true);
            $email->from_email = $imapHeaders['from'][0]['email'] ?? 'unknown';
            $email->from_name = $imapHeaders['from'][0]['name'] ?? $email->from_email;
            $email->subject = $imapHeaders['subject'] ?? 'No subject';
            
            // Thread information
            $email->thread_id = $row['thread_id'];
            $email->thread_title = $row['thread_title'];
            $email->entity_id = $row['entity_id'];
            $email->thread_labels = $row['thread_labels'] ? 
                array_map(function($label) {
                    return trim($label, '\"');
                }, explode(',', trim($row['thread_labels'], '{}'))) : [];
            $email->my_name = $row['my_name'];
            $email->my_email = $row['my_email'];
            $email->request_law_basis = $row['request_law_basis'];
            $email->request_follow_up_plan = $row['request_follow_up_plan'];
            $email->thread_sending_status = $row['thread_sending_status'];
            $email->thread_archived = (bool)$row['thread_archived'];
            
            $emails[] = $email;
        }
        
        return $emails;
    }
}

class ThreadStatus {
    var $thread_id;
    var $entity_id;
    var $request_law_basis;
    var $request_follow_up_plan;
    var $status;
    var $email_count_in;
    var $email_count_out;
    var $email_server_last_checked_at;
    var $email_last_activity;
    var $email_last_received;
    var $email_last_sent;
}

<?php

require_once __DIR__ . '/Database.php';

/**
 * Repository class for retrieving thread statuses
 */
class ThreadStatusRepository {
    // Thread status constants
    const ERROR_NO_FOLDER_FOUND = 'ERROR_NO_FOLDER_FOUND';
    const ERROR_MULTIPLE_FOLDERS = 'ERROR_MULTIPLE_FOLDERS';
    const ERROR_OLD_SYNC = 'ERROR_OLD_SYNC';
    const NOT_SENT = 'NOT_SENT';
    const EMAIL_SENT_NOTHING_RECEIVED = 'EMAIL_SENT_NOTHING_RECEIVED';
    const UNKNOWN = 'UNKNOWN';
    
    /**
     * Get status for a specific thread
     * 
     * @param string $threadId The thread UUID
     * @return string The thread status (one of the class constants)
     */
    public static function getThreadStatus(string $threadId): string {
        // Use the more efficient method with thread ID filter
        $statuses = self::getAllThreadStatusesEfficient([$threadId]);
        
        // Return the status if found, otherwise return UNKNOWN
        return $statuses[$threadId]['status'] ?? self::UNKNOWN;
    }
    
    /**
     * Get statuses for all non-archived threads with a single SQL query
     * This is more efficient than calling getThreadStatus() for each thread
     * 
     * @param array|null $threadIds Optional array of thread IDs to filter by
     * @param string|null $status Optional status to filter by (one of the class constants)
     * @return array Array of thread statuses with thread_id as key
     */
    public static function getAllThreadStatusesEfficient(array $threadIds = null, string $status = null): array {
        $result = [];
        $params = [];
        
        // Build the base query
        $query = "
            WITH thread_data AS (
                SELECT 
                    t.id AS thread_id,
                    COUNT(DISTINCT ifs.id) AS folder_count,
                    MAX(ifs.last_checked_at) AS last_checked_at,
                    COUNT(te_in.id) AS email_count_in,
                    COUNT(te_out.id) AS email_count_out
                FROM 
                    threads t
                LEFT JOIN 
                    imap_folder_status ifs ON t.id = ifs.thread_id
                LEFT JOIN 
                    thread_emails te_in ON t.id = te_in.thread_id AND te_in.email_type = 'IN'
                LEFT JOIN 
                    thread_emails te_out ON t.id = te_out.thread_id AND te_out.email_type = 'OUT'
                WHERE 
                    t.archived = false
        ";
        
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
                CASE
                    WHEN folder_count = 0 THEN 'ERROR_NO_FOLDER_FOUND'
                    WHEN folder_count > 1 THEN 'ERROR_MULTIPLE_FOLDERS'
                    WHEN last_checked_at < NOW() - INTERVAL '6 hours' THEN 'ERROR_OLD_SYNC'
                    WHEN email_count_out = 0 AND email_count_in = 0 THEN 'NOT_SENT'
                    WHEN email_count_out = 1 AND email_count_in = 0 THEN 'EMAIL_SENT_NOTHING_RECEIVED'
                    ELSE 'UNKNOWN'
                END AS status,
                email_count_in,
                email_count_out
            FROM 
                thread_data
        ";
        
        // Add status filter if provided
        if ($status !== null) {
            $query .= "
            WHERE
                CASE
                    WHEN folder_count = 0 THEN 'ERROR_NO_FOLDER_FOUND'
                    WHEN folder_count > 1 THEN 'ERROR_MULTIPLE_FOLDERS'
                    WHEN last_checked_at < NOW() - INTERVAL '6 hours' THEN 'ERROR_OLD_SYNC'
                    WHEN email_count_out = 0 AND email_count_in = 0 THEN 'NOT_SENT'
                    WHEN email_count_out = 1 AND email_count_in = 0 THEN 'EMAIL_SENT_NOTHING_RECEIVED'
                    ELSE 'UNKNOWN'
                END = ?
            ";
            $params[] = $status;
        }
        
        $statuses = Database::query($query, $params);
        
        foreach ($statuses as $status) {
            $result[$status['thread_id']] = $status;
        }
        
        return $result;
    }
    
    /**
     * Get threads with a specific status
     * 
     * @param string $status The status to filter by (one of the class constants)
     * @return array Array of thread IDs with the specified status
     */
    public static function getThreadsByStatus(string $status): array {
        // Use the new status filter parameter for better efficiency
        $filteredStatuses = self::getAllThreadStatusesEfficient(null, $status);
        
        return array_keys($filteredStatuses);
    }
}

<?php

require_once __DIR__ . '/Database.php';

class ImapFolderLog {
    /**
     * Log a folder processing event
     * 
     * @param string $folderName The IMAP folder name
     * @param string $status Status of the processing (e.g., 'success', 'error', 'warning')
     * @param string $message Detailed message about the processing
     * @return bool Success status
     * @deprecated Use createLog and updateLog instead
     */
    public static function log(string $folderName, string $status, string $message): bool {
        return Database::execute(
            "INSERT INTO imap_folder_log (folder_name, status, message) VALUES (?, ?, ?)",
            [$folderName, $status, $message]
        ) > 0;
    }
    
    /**
     * Create a new log entry for a folder processing event
     * 
     * @param string $folderName The IMAP folder name
     * @param string $status Initial status of the processing (e.g., 'started', 'in_progress')
     * @param string $message Initial message about the processing
     * @return int ID of the created log entry
     */
    public static function createLog(string $folderName, string $status, string $message): ?int {
        $result = Database::queryOne(
            "INSERT INTO imap_folder_log (folder_name, status, message) VALUES (?, ?, ?) RETURNING id",
            [$folderName, $status, $message]
        );

        return (int)$result['id'];
    }
    
    /**
     * Update an existing log entry
     * 
     * @param int $logId ID of the log entry to update
     * @param string $status New status of the processing
     * @param string $message New message about the processing
     * @return bool Success status
     */
    public static function updateLog(int $logId, string $status, string $message): bool {
        return Database::execute(
            "UPDATE imap_folder_log SET status = ?, message = ? WHERE id = ?",
            [$status, $message, $logId]
        ) > 0;
    }
    
    /**
     * Get the most recent log entry for a folder
     * 
     * @param string $folderName The IMAP folder name
     * @return array|null Log entry or null if not found
     */
    public static function getMostRecentForFolder(string $folderName): ?array {
        $logs = Database::query(
            "SELECT * FROM imap_folder_log WHERE folder_name = ? ORDER BY created_at DESC LIMIT 1",
            [$folderName]
        );
        
        return !empty($logs) ? $logs[0] : null;
    }
    
    /**
     * Get logs for a specific folder
     * 
     * @param string $folderName The IMAP folder name
     * @param int $limit Maximum number of logs to return (default: 100)
     * @return array Array of log records
     */
    public static function getForFolder(string $folderName, int $limit = 100): array {
        return Database::query(
            "SELECT * FROM imap_folder_log WHERE folder_name = ? ORDER BY created_at DESC LIMIT ?",
            [$folderName, $limit]
        );
    }
    
    /**
     * Get all logs
     * 
     * @param int $limit Maximum number of logs to return (default: 100)
     * @return array Array of log records
     */
    public static function getAll(int $limit = 100): array {
        return Database::query(
            "SELECT * FROM imap_folder_log ORDER BY created_at DESC LIMIT ?",
            [$limit]
        );
    }
    
    /**
     * Get logs by status
     * 
     * @param string $status Status to filter by (e.g., 'success', 'error', 'warning')
     * @param int $limit Maximum number of logs to return (default: 100)
     * @return array Array of log records
     */
    public static function getByStatus(string $status, int $limit = 100): array {
        return Database::query(
            "SELECT * FROM imap_folder_log WHERE status = ? ORDER BY created_at DESC LIMIT ?",
            [$status, $limit]
        );
    }
}

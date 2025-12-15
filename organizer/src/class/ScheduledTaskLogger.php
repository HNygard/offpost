<?php

require_once __DIR__ . '/Database.php';

/**
 * Logger for scheduled tasks to track execution time, bandwidth usage, and status
 * 
 * This class helps identify which scheduled tasks are using the most Internet bandwidth
 * by tracking bytes processed and execution time for each task run.
 */
class ScheduledTaskLogger {
    private ?int $logId = null;
    private string $taskName;
    private int $bytesProcessed = 0;
    private int $itemsProcessed = 0;
    
    /**
     * Create a new scheduled task logger
     * 
     * @param string $taskName Name of the scheduled task (e.g., 'scheduled-email-receiver')
     */
    public function __construct(string $taskName) {
        $this->taskName = $taskName;
    }
    
    /**
     * Start logging for this task
     * Creates a database record with 'running' status
     * 
     * @return void
     */
    public function start(): void {
        $result = Database::queryOne(
            "INSERT INTO scheduled_task_log (task_name, status) VALUES (?, 'running') RETURNING id",
            [$this->taskName]
        );
        
        $this->logId = (int)$result['id'];
        error_log("[ScheduledTaskLogger] Started task '{$this->taskName}' with log ID {$this->logId}");
    }
    
    /**
     * Add bytes to the bytes processed counter
     * This should be called whenever data is downloaded or processed
     * 
     * @param int $bytes Number of bytes processed
     * @return void
     */
    public function addBytesProcessed(int $bytes): void {
        $this->bytesProcessed += $bytes;
    }
    
    /**
     * Increment the items processed counter
     * This could be number of emails, folders, etc.
     * 
     * @param int $count Number of items to add (default: 1)
     * @return void
     */
    public function addItemsProcessed(int $count = 1): void {
        $this->itemsProcessed += $count;
    }
    
    /**
     * Complete the task with success status
     * 
     * @param string|null $message Optional success message
     * @return void
     */
    public function complete(?string $message = null): void {
        if ($this->logId === null) {
            error_log("[ScheduledTaskLogger] Warning: complete() called but task was never started");
            return;
        }
        
        Database::execute(
            "UPDATE scheduled_task_log 
             SET completed_at = CURRENT_TIMESTAMP, 
                 status = 'completed', 
                 bytes_processed = ?, 
                 items_processed = ?,
                 message = ?
             WHERE id = ?",
            [$this->bytesProcessed, $this->itemsProcessed, $message, $this->logId]
        );
        
        $bytesFormatted = $this->formatBytes($this->bytesProcessed);
        error_log("[ScheduledTaskLogger] Completed task '{$this->taskName}' - {$bytesFormatted} processed, {$this->itemsProcessed} items");
    }
    
    /**
     * Mark the task as failed with error details
     * 
     * @param string $errorMessage Error message to log
     * @return void
     */
    public function fail(string $errorMessage): void {
        if ($this->logId === null) {
            error_log("[ScheduledTaskLogger] Warning: fail() called but task was never started");
            return;
        }
        
        Database::execute(
            "UPDATE scheduled_task_log 
             SET completed_at = CURRENT_TIMESTAMP, 
                 status = 'failed', 
                 bytes_processed = ?, 
                 items_processed = ?,
                 error_message = ?
             WHERE id = ?",
            [$this->bytesProcessed, $this->itemsProcessed, $errorMessage, $this->logId]
        );
        
        error_log("[ScheduledTaskLogger] Failed task '{$this->taskName}' - Error: {$errorMessage}");
    }
    
    /**
     * Format bytes into human-readable format
     * 
     * @param int $bytes Number of bytes
     * @return string Formatted string (e.g., "1.5 MB")
     */
    private function formatBytes(int $bytes): string {
        return self::formatBytesStatic($bytes);
    }
    
    /**
     * Format bytes into human-readable format (static version)
     * 
     * @param int $bytes Number of bytes
     * @return string Formatted string (e.g., "1.5 MB")
     */
    public static function formatBytesStatic(int $bytes): string {
        if ($bytes < 1024) {
            return $bytes . ' B';
        } elseif ($bytes < 1048576) {
            return round($bytes / 1024, 2) . ' KB';
        } elseif ($bytes < 1073741824) {
            return round($bytes / 1048576, 2) . ' MB';
        } else {
            return round($bytes / 1073741824, 2) . ' GB';
        }
    }
    
    /**
     * Format duration into human-readable format
     * 
     * @param float $seconds Duration in seconds
     * @return string Formatted string (e.g., "1.5 min")
     */
    public static function formatDuration(float $seconds): string {
        if ($seconds < 60) {
            return round($seconds, 1) . ' s';
        } elseif ($seconds < 3600) {
            return round($seconds / 60, 1) . ' min';
        } else {
            return round($seconds / 3600, 2) . ' hrs';
        }
    }
    
    /**
     * Get recent log entries for all tasks, ordered by bytes processed
     * This helps identify bandwidth-heavy tasks
     * 
     * @param int $limit Maximum number of logs to return (default: 100)
     * @return array Array of log records
     */
    public static function getRecentLogs(int $limit = 100): array {
        return Database::query(
            "SELECT 
                id,
                task_name,
                started_at,
                completed_at,
                EXTRACT(EPOCH FROM (completed_at - started_at)) as duration_seconds,
                status,
                bytes_processed,
                items_processed,
                message,
                error_message
             FROM scheduled_task_log 
             ORDER BY started_at DESC 
             LIMIT ?",
            [$limit]
        );
    }
    
    /**
     * Get bandwidth usage summary by task
     * Returns total bytes and average bytes per run for each task
     * 
     * @param int $days Number of days to include in summary (default: 7)
     * @return array Array of summary records
     */
    public static function getBandwidthSummary(int $days = 7): array {
        return Database::query(
            "SELECT 
                task_name,
                COUNT(*) as run_count,
                SUM(bytes_processed) as total_bytes,
                AVG(bytes_processed) as avg_bytes_per_run,
                MAX(bytes_processed) as max_bytes_per_run,
                SUM(items_processed) as total_items
             FROM scheduled_task_log 
             WHERE started_at > CURRENT_TIMESTAMP - INTERVAL '1 day' * ?
             GROUP BY task_name
             ORDER BY total_bytes DESC",
            [$days]
        );
    }
    
    /**
     * Get logs for a specific task
     * 
     * @param string $taskName Task name to filter by
     * @param int $limit Maximum number of logs to return (default: 50)
     * @return array Array of log records
     */
    public static function getLogsForTask(string $taskName, int $limit = 50): array {
        return Database::query(
            "SELECT 
                id,
                task_name,
                started_at,
                completed_at,
                EXTRACT(EPOCH FROM (completed_at - started_at)) as duration_seconds,
                status,
                bytes_processed,
                items_processed,
                message,
                error_message
             FROM scheduled_task_log 
             WHERE task_name = ?
             ORDER BY started_at DESC 
             LIMIT ?",
            [$taskName, $limit]
        );
    }
}

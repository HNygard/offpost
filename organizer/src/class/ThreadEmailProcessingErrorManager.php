<?php

require_once __DIR__ . '/Database.php';

/**
 * Manages email processing errors stored in the database
 */
class ThreadEmailProcessingErrorManager {
    
    /**
     * Get all unresolved email processing errors
     * 
     * @return array Array of error records
     */
    public static function getUnresolvedErrors(): array {
        return Database::query(
            "SELECT e.*, t.title as suggested_thread_title 
             FROM thread_email_processing_errors e
             LEFT JOIN threads t ON e.suggested_thread_id = t.id
             WHERE e.resolved = false 
             ORDER BY e.created_at DESC"
        );
    }
    
    /**
     * Get count of unresolved errors
     * 
     * @return int Number of unresolved errors
     */
    public static function getUnresolvedErrorCount(): int {
        return Database::queryValue(
            "SELECT COUNT(*) FROM thread_email_processing_errors WHERE resolved = false"
        );
    }
    
    /**
     * Resolve an error by creating a manual mapping
     * 
     * @param int $errorId Error ID
     * @param string $threadId Thread ID to map to
     * @param string $userId User who resolved the error
     * @param string $description Optional description for the mapping
     * @return bool True if successful
     */
    public static function resolveError(int $errorId, string $threadId, string $description = ''): bool {
        try {
            Database::beginTransaction();
            
            // Get the error details
            $error = Database::queryRow(
                "SELECT * FROM thread_email_processing_errors WHERE id = ?",
                [$errorId]
            );
            
            if (!$error) {
                Database::rollBack();
                throw new Exception('Error not found or already resolved');
            }
            
            // Create the manual mapping
            Database::execute(
                "INSERT INTO thread_email_mapping (email_identifier, thread_id, description) 
                 VALUES (?, ?, ?) ",
                [$error['email_identifier'], $threadId, $description]
            );
            
            // Mark the error as resolved
            self::dismissError($errorId);
            
            Database::commit();
            return true;
            
        } catch (Exception $e) {
            Database::rollBack();
            throw $e;
        }
    }
    
    /**
     * Delete/dismiss an error without creating a mapping
     * 
     * @param int $errorId Error ID
     * @return bool True if successful
     */
    public static function dismissError(int $errorId): bool {
        Database::execute(
            "DELETE FROM thread_email_processing_errors WHERE id = ?",
            [$errorId]
        );
    }
    
    /**
     * Get threads for dropdown selection
     * 
     * @param int $limit Maximum number of threads to return
     * @return array Array of thread records with id and title
     */
    public static function getThreadsForSelection(int $limit = 100): array {
        return Database::query(
            "SELECT id, title, my_email 
             FROM threads 
             WHERE archived = false 
             ORDER BY updated_at DESC 
             LIMIT ?",
            [$limit]
        );
    }
}
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
     */
    public static function resolveError(int $errorId, string $threadId, string $description = '') {
        try {
            Database::beginTransaction();
            
            // Get the error details
            $error = Database::queryOneOrNone(
                "SELECT * FROM thread_email_processing_errors WHERE id = ?",
                [$errorId]
            );
            
            if (!$error) {
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
        } catch (Exception $e) {
            Database::rollBack();
            throw $e;
        }
    }
    
    /**
     * Delete/dismiss an error without creating a mapping
     * 
     * @param int $errorId Error ID
     */
    public static function dismissError(int $errorId) {
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
    
    /**
     * Save email processing error to database for GUI resolution
     * 
     * @param string $emailIdentifier Email identifier
     * @param string $emailSubject Email subject
     * @param string $emailAddresses Comma-separated email addresses
     * @param string $errorType Type of error (no_matching_thread, multiple_matching_threads, unmatched_inbox_email)
     * @param string $errorMessage Error message
     * @param string|null $suggestedThreadId Suggested thread ID for resolution
     * @param string $folderName IMAP folder name
     */
    public static function saveEmailProcessingError(
        string $emailIdentifier,
        string $emailSubject,
        string $emailAddresses,
        string $errorType,
        string $errorMessage,
        ?string $suggestedThreadId,
        string $folderName
    ): void {
        $suggestedQuery = null;
        if ($suggestedThreadId) {
            $suggestedQuery = "INSERT INTO thread_email_mapping (email_identifier, thread_id, description) VALUES ('$emailIdentifier', '$suggestedThreadId', '');";
        }

        try {
            Database::execute(
                "INSERT INTO thread_email_processing_errors 
                (email_identifier, email_subject, email_addresses, error_type, error_message, suggested_thread_id, suggested_query, folder_name) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?) 
                ON CONFLICT (email_identifier) WHERE resolved = false DO UPDATE SET 
                    email_subject = EXCLUDED.email_subject,
                    email_addresses = EXCLUDED.email_addresses,
                    error_message = EXCLUDED.error_message,
                    suggested_thread_id = EXCLUDED.suggested_thread_id,
                    suggested_query = EXCLUDED.suggested_query,
                    folder_name = EXCLUDED.folder_name",
                [
                    $emailIdentifier,
                    $emailSubject,
                    $emailAddresses,
                    $errorType,
                    $errorMessage,
                    $suggestedThreadId,
                    $suggestedQuery,
                    $folderName
                ]
            );
        } catch (Exception $e) {
            // Log the error but don't fail the main process
            error_log("Failed to save email processing error: " . $e->getMessage());
        }
    }
}
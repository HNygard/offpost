<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../class/Database.php';
require_once __DIR__ . '/../class/ThreadEmailProcessingErrorManager.php';

class ThreadEmailProcessingErrorManagerTest extends PHPUnit\Framework\TestCase {
    var $thread_id_random;
    protected function setUp(): void {
        parent::setUp();
        
        // Clean up any existing test data
        Database::execute("DELETE FROM thread_email_processing_errors WHERE email_identifier LIKE 'test-%'");
        Database::execute("DELETE FROM thread_email_mapping WHERE email_identifier LIKE 'test-%'");
        Database::execute("DELETE FROM threads WHERE title LIKE 'Test Thread for Error%'");
        
        // Create a test thread
        $thread = new Thread();
        $this->thread_id_random = $thread->id;
        Database::execute("
            INSERT INTO threads (id, title, my_name, my_email, entity_id, archived) 
            VALUES (?, ?, ?, ?, ?, ?)
        ", [
            $this->thread_id_random,
            'Test User',
            'Test Thread for Error Resolution',
            'ThreadEmailProcessingErrorManagerTest-' . mt_rand(1000, 9999) . time() . '@example.com',
            '000000000-test-entity-development',
            'f'  // PostgreSQL boolean: 'f' for false, 't' for true
        ]);
    }
    
    public function testResolveErrorWithValidError(): void {
        // :: Setup
        // Create a test error
        Database::execute("
            INSERT INTO thread_email_processing_errors 
            (email_identifier, email_subject, email_addresses, error_type, error_message, folder_name, resolved) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ", [
            'test-error-1',
            'Test Email Subject',
            'sender@example.com',
            'no_matching_thread',
            'No matching thread found',
            'INBOX',
            'false'
        ]);
        
        $errorId = Database::queryValue("SELECT id FROM thread_email_processing_errors WHERE email_identifier = ?", ['test-error-1']);
        
        // :: Act
        $result = ThreadEmailProcessingErrorManager::resolveError(
            $errorId,
            $this->thread_id_random,
            'Test description'
        );
        
        // :: Assert
        // Check that the error was deleted (dismissed)
        $errorExists = Database::queryValue(
            "SELECT COUNT(*) FROM thread_email_processing_errors WHERE id = ?",
            [$errorId]
        );
        $this->assertEquals(0, $errorExists, "Error should be deleted after resolution");
        
        // Check that a mapping was created
        $mapping = Database::queryOneOrNone(
            "SELECT * FROM thread_email_mapping WHERE email_identifier = ?",
            ['test-error-1']
        );
        $this->assertNotNull($mapping, "A mapping should be created");
        $this->assertEquals($this->thread_id_random, $mapping['thread_id'], "Mapping should point to correct thread");
        $this->assertEquals('Test description', $mapping['description'], "Mapping should have the description");
    }
    
    public function testResolveErrorWithNonExistentError(): void {
        // :: Setup
        $nonExistentErrorId = 99999999;
        
        // :: Act & Assert
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Error not found or already resolved');
        
        ThreadEmailProcessingErrorManager::resolveError(
            $nonExistentErrorId,
            $this->thread_id_random,
            'Test description'
        );
    }
    
    public function testDismissError(): void {
        // :: Setup
        // Create a test error
        Database::execute("
            INSERT INTO thread_email_processing_errors 
            (email_identifier, email_subject, email_addresses, error_type, error_message, folder_name, resolved) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ", [
            'test-error-dismiss',
            'Test Email Subject',
            'sender@example.com',
            'no_matching_thread',
            'No matching thread found',
            'INBOX',
            'false'
        ]);
        
        $errorId = Database::queryValue("SELECT id FROM thread_email_processing_errors WHERE email_identifier = ?", ['test-error-dismiss']);
        
        // :: Act
        ThreadEmailProcessingErrorManager::dismissError($errorId);
        
        // :: Assert
        $errorExists = Database::queryValue(
            "SELECT COUNT(*) FROM thread_email_processing_errors WHERE id = ?",
            [$errorId]
        );
        $this->assertEquals(0, $errorExists, "Error should be deleted after dismissal");
    }
    
    public function testGetUnresolvedErrors(): void {
        // :: Setup
        // Create multiple test errors
        Database::execute("
            INSERT INTO thread_email_processing_errors 
            (email_identifier, email_subject, email_addresses, error_type, error_message, folder_name, resolved) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ", [
            'test-error-unresolved-1',
            'Test Email 1',
            'sender1@example.com',
            'no_matching_thread',
            'No matching thread found',
            'INBOX',
            'false'
        ]);
        
        Database::execute("
            INSERT INTO thread_email_processing_errors 
            (email_identifier, email_subject, email_addresses, error_type, error_message, folder_name, resolved) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ", [
            'test-error-unresolved-2',
            'Test Email 2',
            'sender2@example.com',
            'multiple_matching_threads',
            'Multiple threads match',
            'INBOX',
            'false'
        ]);
        
        // :: Act
        $errors = ThreadEmailProcessingErrorManager::getUnresolvedErrors();
        
        // :: Assert
        $testErrors = array_filter($errors, function($error) {
            return str_starts_with($error['email_identifier'], 'test-error-unresolved');
        });
        $this->assertCount(2, $testErrors, "Should return 2 unresolved test errors");
    }
    
    public function testGetUnresolvedErrorCount(): void {
        // :: Setup
        // Get current count
        $initialCount = ThreadEmailProcessingErrorManager::getUnresolvedErrorCount();
        
        // Create a test error
        Database::execute("
            INSERT INTO thread_email_processing_errors 
            (email_identifier, email_subject, email_addresses, error_type, error_message, folder_name, resolved) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ", [
            'test-error-count',
            'Test Email',
            'sender@example.com',
            'no_matching_thread',
            'No matching thread found',
            'INBOX',
            'false'
        ]);
        
        // :: Act
        $newCount = ThreadEmailProcessingErrorManager::getUnresolvedErrorCount();
        
        // :: Assert
        $this->assertEquals($initialCount + 1, $newCount, "Count should increase by 1 after adding an error");
    }
}

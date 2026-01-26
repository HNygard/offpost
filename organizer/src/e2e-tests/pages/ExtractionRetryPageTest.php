<?php

require_once __DIR__ . '/common/E2EPageTestCase.php';
require_once __DIR__ . '/../../class/Extraction/ThreadEmailExtraction.php';
require_once __DIR__ . '/../../class/Extraction/ThreadEmailExtractionService.php';
require_once __DIR__ . '/../../class/Database.php';

class ExtractionRetryPageTest extends E2EPageTestCase {

    private $testExtractionIds = [];
    private $threadId = null;
    private $emailId = null;
    private $entityId = null;

    protected function setUp(): void {
        parent::setUp();
        // Create test extraction records
        $this->createTestExtractions();
    }
    
    private function createTestExtractions() {
        // Create a new thread
        $thread = new Thread();
        $thread->title = 'Test Thread for Extraction Retry';
        $thread->my_name = 'Test User';
        $thread->my_email = 'test-retry-' . uniqid() . '@example.com';
        $thread->sending_status = Thread::SENDING_STATUS_SENT;
        
        // Use a valid entity ID from entities_test.json
        $this->entityId = '000000000-test-entity-1';
        $thread = createThread($this->entityId, $thread);
        $this->threadId = $thread->id;
        
        // Create a test email in the database
        // Note: Using Thread constructor to generate a UUID (established pattern in this codebase)
        $thread = new Thread();
        $this->emailId = $thread->id; // Use the UUID generated in the Thread constructor
        Database::execute(
            "INSERT INTO thread_emails (id, thread_id, status_type, status_text, datetime_received, timestamp_received, content) 
            VALUES (?, ?, ?, ?, ?, ?, ?)",
            [
                $this->emailId,
                $this->threadId,
                \App\Enums\ThreadEmailStatusType::UNKNOWN->value,
                'Unclassified',
                date('Y-m-d H:i:s'),
                date('Y-m-d H:i:s'),
                'Test email content for retry'
            ]
        );
        
        // Create test extractions (one with error, one without)
        $extractionService = new ThreadEmailExtractionService();
        
        // Extraction 1: Failed extraction with error message
        $extraction1 = $extractionService->createExtraction(
            $this->emailId,
            'Test prompt text 1',
            'test-service',
            null,
            'test-prompt-id-1'
        );
        $extractionService->updateExtractionResults(
            $extraction1->extraction_id,
            null,
            'Test error message: extraction failed'
        );
        $this->testExtractionIds[] = $extraction1->extraction_id;
        
        // Extraction 2: Successful extraction
        $extraction2 = $extractionService->createExtraction(
            $this->emailId,
            'Test prompt text 2',
            'test-service',
            null,
            'test-prompt-id-2'
        );
        $extractionService->updateExtractionResults(
            $extraction2->extraction_id,
            'Test extracted text',
            null
        );
        $this->testExtractionIds[] = $extraction2->extraction_id;
    }

    public function testRetryExtractionSuccess() {
        // :: Setup
        $postData = [
            'ids' => [$this->testExtractionIds[0]]
        ];
        
        $response = $this->renderPage(
            '/extraction-retry', 
            'dev-user-id', 
            'POST', 
            '200 OK', 
            $postData
        );

        // :: Assert
        $responseData = json_decode($response->body, true);
        $this->assertNotNull($responseData, 'Response should be valid JSON');
        $this->assertTrue($responseData['success'], 'Response should indicate success');
        $this->assertEquals(1, $responseData['successCount'], 'Should have 1 successful retry');
        $this->assertEquals(0, $responseData['errorCount'], 'Should have 0 errors');
        
        // Check that the extraction was actually deleted
        $extractionService = new ThreadEmailExtractionService();
        $extraction = $extractionService->getExtractionById($this->testExtractionIds[0]);
        $this->assertNull($extraction, 'Extraction should be deleted');
        
        // Remove from cleanup list since it's already deleted
        $deletedId = $this->testExtractionIds[0];
        $this->testExtractionIds = array_diff($this->testExtractionIds, [$deletedId]);
    }

    public function testRetryMultipleExtractions() {
        // :: Setup
        $postData = [
            'ids' => $this->testExtractionIds
        ];
        
        $response = $this->renderPage(
            '/extraction-retry', 
            'dev-user-id', 
            'POST', 
            '200 OK', 
            $postData
        );

        // :: Assert
        $responseData = json_decode($response->body, true);
        $this->assertNotNull($responseData, 'Response should be valid JSON');
        $this->assertTrue($responseData['success'], 'Response should indicate success');
        $this->assertEquals(2, $responseData['successCount'], 'Should have 2 successful retries');
        $this->assertEquals(0, $responseData['errorCount'], 'Should have 0 errors');
        
        // Check that both extractions were deleted
        $extractionService = new ThreadEmailExtractionService();
        foreach ($this->testExtractionIds as $id) {
            $extraction = $extractionService->getExtractionById($id);
            $this->assertNull($extraction, "Extraction $id should be deleted");
        }
        
        // Clear cleanup list since they're all deleted
        $this->testExtractionIds = [];
    }

    public function testRetryInvalidId() {
        // :: Setup - try to retry non-existent extraction
        $invalidId = 999999;
        $postData = [
            'ids' => [$invalidId]
        ];
        
        $response = $this->renderPage(
            '/extraction-retry', 
            'dev-user-id', 
            'POST', 
            '200 OK', 
            $postData
        );

        // :: Assert
        $responseData = json_decode($response->body, true);
        $this->assertNotNull($responseData, 'Response should be valid JSON');
        $this->assertTrue($responseData['success'], 'Response should still be true (overall success)');
        $this->assertEquals(0, $responseData['successCount'], 'Should have 0 successful retries');
        $this->assertEquals(1, $responseData['errorCount'], 'Should have 1 error');
        $this->assertStringContainsString('not found', $responseData['results'][0]['message']);
    }

    public function testRetryMissingIdsParameter() {
        // :: Setup - send request without ids parameter
        $postData = [];
        
        $response = $this->renderPage(
            '/extraction-retry', 
            'dev-user-id', 
            'POST', 
            '400 Bad Request', 
            $postData
        );

        // :: Assert
        $responseData = json_decode($response->body, true);
        $this->assertNotNull($responseData, 'Response should be valid JSON');
        $this->assertFalse($responseData['success'], 'Response should indicate failure');
        $this->assertStringContainsString('ids array required', $responseData['message']);
    }

    public function testRetryInvalidIdsArray() {
        // :: Setup - send request with invalid ids (empty after filtering)
        $postData = [
            'ids' => [0, -1, 'invalid']
        ];
        
        $response = $this->renderPage(
            '/extraction-retry', 
            'dev-user-id', 
            'POST', 
            '400 Bad Request', 
            $postData
        );

        // :: Assert
        $responseData = json_decode($response->body, true);
        $this->assertNotNull($responseData, 'Response should be valid JSON');
        $this->assertFalse($responseData['success'], 'Response should indicate failure');
        $this->assertStringContainsString('No valid IDs provided', $responseData['message']);
    }

    public function testRetryNotLoggedIn() {
        // :: Setup - try to access without authentication
        $postData = [
            'ids' => [$this->testExtractionIds[0]]
        ];
        
        $response = $this->renderPage(
            '/extraction-retry', 
            null, // no user authentication
            'POST', 
            '302 Found', 
            $postData
        );
        
        // :: Assert - should redirect to login
        $this->assertStringContainsString('Location:', $response->headers);
    }

    public function testRetryGetMethodNotAllowed() {
        // :: Setup - try to use GET instead of POST
        $response = $this->renderPage(
            '/extraction-retry', 
            'dev-user-id', 
            'GET', 
            '405 Method Not Allowed'
        );

        // :: Assert
        $responseData = json_decode($response->body, true);
        $this->assertNotNull($responseData, 'Response should be valid JSON');
        $this->assertFalse($responseData['success'], 'Response should indicate failure');
        $this->assertStringContainsString('Method not allowed', $responseData['message']);
    }
}

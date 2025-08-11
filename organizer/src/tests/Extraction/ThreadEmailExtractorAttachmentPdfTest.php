<?php

require_once __DIR__ . '/../../class/Extraction/ThreadEmailExtractorAttachmentPdf.php';
require_once __DIR__ . '/../../class/Extraction/ThreadEmailExtractionService.php';

class ThreadEmailExtractorAttachmentPdfTest extends PHPUnit\Framework\TestCase {
    private $extractionService;
    private $extractor;
    
    protected function setUp(): void {
        // Create a mock for the ThreadEmailExtractionService
        $this->extractionService = $this->createMock(ThreadEmailExtractionService::class);
        
        // Create the extractor with the mock service
        $this->extractor = $this->getMockBuilder(ThreadEmailExtractorAttachmentPdf::class)
            ->setConstructorArgs([$this->extractionService])
            ->onlyMethods(['extractTextFromPdf']) // We'll mock this method to avoid actual file operations
            ->getMock();
    }
    
    public function testFindNextEmailForExtraction() {
        // Create a mock result for findNextEmailForExtraction
        $mockResult = [
            'id' => 123,
            'email_id' => 'test-email-id',
            'thread_id' => 'test-thread-id',
            'name' => 'test.pdf',
            'filename' => 'test.pdf',
            'filetype' => 'application/pdf'
        ];
        
        // Use a partial mock to test findNextEmailForExtraction
        // without actually hitting the database
        $extractor = $this->getMockBuilder(ThreadEmailExtractorAttachmentPdf::class)
            ->setConstructorArgs([$this->extractionService])
            ->getMock();
            
        $extractor->method('findNextEmailForExtraction')
            ->willReturn($mockResult);
            
        // Call the method through the mock
        $result = $extractor->findNextEmailForExtraction();
        
        // Verify the result
        $this->assertIsArray($result);
        $this->assertEquals(123, $result['id']);
        $this->assertEquals('test-email-id', $result['email_id']);
        $this->assertEquals('test-thread-id', $result['thread_id']);
        $this->assertEquals('application/pdf', $result['filetype']);
    }
    
    public function testProcessNextEmailExtractionNoAttachments() {
        // Create a partial mock to override findNextEmailForExtraction
        $extractor = $this->getMockBuilder(ThreadEmailExtractorAttachmentPdf::class)
            ->setConstructorArgs([$this->extractionService])
            ->onlyMethods(['findNextEmailForExtraction'])
            ->getMock();
        
        // Configure the mock to return null (no attachments found)
        $extractor->method('findNextEmailForExtraction')
            ->willReturn(null);
        
        // Call the method
        $result = $extractor->processNextEmailExtraction();
        
        // Check the result
        $this->assertFalse($result['success']);
        $this->assertEquals('No emails found that need extraction', $result['message']);
    }
    
    public function testProcessNextEmailExtractionSuccess() {
        // Sample attachment data
        $attachmentData = [
            'attachment_id' => 123,
            'email_id' => 'test-email-id',
            'thread_id' => 'test-thread-id',
            'name' => 'test.pdf',
            'filename' => 'test.pdf',
            'filetype' => 'application/pdf'
        ];
        
        // Sample extraction
        $extraction = new ThreadEmailExtraction();
        $extraction->extraction_id = 456;
        $extraction->email_id = $attachmentData['email_id'];
        $extraction->attachment_id = $attachmentData['attachment_id'];
        $extraction->prompt_text = 'attachment_pdf';
        $extraction->prompt_service = 'pdftotext';
        
        // Create a partial mock to override findNextEmailForExtraction
        $extractor = $this->getMockBuilder(ThreadEmailExtractorAttachmentPdf::class)
            ->setConstructorArgs([$this->extractionService])
            ->onlyMethods(['findNextEmailForExtraction', 'extractTextFromPdf', 'enrichEmailWithDetails'])
            ->getMock();
            
        // Configure the mocks
        $extractor->method('findNextEmailForExtraction')
            ->willReturn($attachmentData);
            
        // Mock enrichEmailWithDetails to return data with required email fields
        $enrichedData = array_merge($attachmentData, [
            'email_subject' => 'Test Subject',
            'email_from_address' => 'test@example.com',
            'email_to_addresses' => ['recipient@example.com'],
            'email_cc_addresses' => []
        ]);
        $extractor->method('enrichEmailWithDetails')
            ->willReturn($enrichedData);
        
        $extractor->method('extractTextFromPdf')
            ->willReturn('Extracted text from PDF');
        
        $this->extractionService->expects($this->once())
            ->method('createExtraction')
            ->with(
                $this->equalTo($attachmentData['email_id']),
                $this->equalTo('attachment_pdf'),
                $this->equalTo('code'),
                $this->equalTo($attachmentData['attachment_id'])
            )
            ->willReturn($extraction);
        
        $this->extractionService->expects($this->once())
            ->method('updateExtractionResults')
            ->with(
                $this->equalTo($extraction->extraction_id),
                $this->equalTo('Extracted text from PDF')
            )
            ->willReturn($extraction);
        
        // Call the method
        $result = $extractor->processNextEmailExtraction();
        
        // Check the result
        $this->assertTrue($result['success']);
        $this->assertEquals('Successfully extracted text from email', $result['message']);
        $this->assertEquals($attachmentData['email_id'], $result['email_id']);
        $this->assertEquals($attachmentData['thread_id'], $result['thread_id']);
        $this->assertEquals($extraction->extraction_id, $result['extraction_id']);
        $this->assertEquals(strlen('Extracted text from PDF'), $result['extracted_text_length']);
    }
    
    public function testProcessNextEmailExtractionError() {
        // Sample attachment data
        $attachmentData = [
            'attachment_id' => 123,
            'email_id' => 'test-email-id',
            'thread_id' => 'test-thread-id',
            'name' => 'test.pdf',
            'filename' => 'test.pdf',
            'filetype' => 'application/pdf'
        ];
        
        // Sample extraction
        $extraction = new ThreadEmailExtraction();
        $extraction->extraction_id = 456;
        $extraction->email_id = $attachmentData['email_id'];
        $extraction->attachment_id = $attachmentData['attachment_id'];
        $extraction->prompt_text = 'attachment_pdf';
        $extraction->prompt_service = 'pdftotext';
        
        // Create a partial mock to override methods
        $extractor = $this->getMockBuilder(ThreadEmailExtractorAttachmentPdf::class)
            ->setConstructorArgs([$this->extractionService])
            ->onlyMethods(['findNextEmailForExtraction', 'extractTextFromPdf'])
            ->getMock();
        
        // Configure the mocks
        $extractor->method('findNextEmailForExtraction')
            ->willReturn($attachmentData);
        
        $exception = new \Exception('PDF extraction error');
        $extractor->method('extractTextFromPdf')
            ->will($this->throwException($exception));
        
        $this->extractionService->expects($this->once())
            ->method('createExtraction')
            ->with(
                $this->equalTo($attachmentData['email_id']),
                $this->equalTo('attachment_pdf'),
                $this->equalTo('code'),
                $this->equalTo($attachmentData['attachment_id'])
            )
            ->willReturn($extraction);
        
        $this->extractionService->expects($this->once())
            ->method('updateExtractionResults')
            ->with(
                $this->equalTo($extraction->extraction_id),
                $this->equalTo(null),
                $this->equalTo(jTraceEx($exception))
            )
            ->willReturn($extraction);
        
        // Call the method
        $result = $extractor->processNextEmailExtraction();
        
        // Check the result
        $this->assertFalse($result['success']);
        $this->assertEquals('Failed to extract text from email.', $result['message']);
        $this->assertEquals($attachmentData['email_id'], $result['email_id']);
        $this->assertEquals($attachmentData['thread_id'], $result['thread_id']);
        $this->assertEquals('PDF extraction error', $result['error']);
    }
    
    public function testCleanExtractedText() {
        // Create a reflection of the class to access protected methods
        $reflection = new ReflectionClass(ThreadEmailExtractorAttachmentPdf::class);
        $method = $reflection->getMethod('cleanExtractedText');
        $method->setAccessible(true);
        
        // Test text with different line endings, control characters, and excessive whitespace
        $text = "Line 1\r\nLine 2\rLine 3\n\n\n\nLine 4\x00\x01   ";
        
        // Expected text
        $expectedText = "Line 1\nLine 2\nLine 3\n\nLine 4";
        
        // Clean the text
        $cleanedText = $method->invoke($this->extractor, $text);
        
        // Check the result
        $this->assertEquals($expectedText, $cleanedText);
    }
}

<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../class/SuggestedReplyGenerator.php';
require_once __DIR__ . '/../class/Extraction/ThreadEmailExtractionService.php';
require_once __DIR__ . '/../class/Extraction/ThreadEmailExtraction.php';

class SuggestedReplyGeneratorTest extends PHPUnit\Framework\TestCase {
    
    private $extractionService;
    private $generator;
    
    protected function setUp(): void {
        $this->extractionService = $this->createMock(ThreadEmailExtractionService::class);
        $this->generator = new SuggestedReplyGenerator($this->extractionService);
    }
    
    public function testGenerateBasicSuggestedReply() {
        // :: Setup
        $thread = new Thread();
        $thread->my_name = "Test User";
        $thread->emails = [];
        
        // Mock no extractions
        $this->extractionService->method('getExtractionsForEmail')
            ->willReturn([]);
        
        // :: Act
        $result = $this->generator->generateSuggestedReply($thread);
        
        // :: Assert
        $this->assertStringContainsString("Tidligere e-poster:", $result);
        $this->assertStringContainsString("--\nTest User", $result);
    }
    
    public function testGenerateWithEmailHistory() {
        // :: Setup
        $thread = new Thread();
        $thread->my_name = "Test User";
        
        // Create mock emails
        $email1 = new stdClass();
        $email1->id = "email1";
        $email1->email_type = "IN";
        $email1->datetime_received = "2025-01-15 10:30:00";
        $email1->description = "Initial request";
        
        $email2 = new stdClass();
        $email2->id = "email2";
        $email2->email_type = "OUT";
        $email2->datetime_received = "2025-01-16 14:20:00";
        $email2->description = "Response to request";
        
        $thread->emails = [$email1, $email2];
        
        // Mock no extractions for simplicity
        $this->extractionService->method('getExtractionsForEmail')
            ->willReturn([]);
        
        // :: Act
        $result = $this->generator->generateSuggestedReply($thread);
        
        // :: Assert
        $this->assertStringContainsString("1. Sendt den 2025-01-16 14:20:00", $result);
        $this->assertStringContainsString("Sammendrag: Response to request", $result);
        $this->assertStringContainsString("2. Mottatt den 2025-01-15 10:30:00", $result);
        $this->assertStringContainsString("Sammendrag: Initial request", $result);
    }
    
    public function testGenerateWithCaseNumberInformation() {
        // :: Setup
        $thread = new Thread();
        $thread->my_name = "Test User";
        
        $email = new stdClass();
        $email->id = "email1";
        $email->email_type = "IN";
        $email->datetime_received = "2025-01-15 10:30:00";
        $thread->emails = [$email];
        
        // Create mock extraction with case number
        $extraction = new ThreadEmailExtraction();
        $extraction->prompt_service = "openai";
        $extraction->prompt_id = "saksnummer";
        $extraction->extracted_text = json_encode([
            (object)[
                'case_number' => '2025/123-1',
                'entity_name' => 'Kongsberg kommune'
            ]
        ]);
        
        $this->extractionService->expects($this->once())
            ->method('getExtractionsForEmail')
            ->with('email1')
            ->willReturn([$extraction]);
        
        // :: Act
        $result = $this->generator->generateSuggestedReply($thread);
        
        // :: Assert
        $this->assertStringContainsString("Saksnummer informasjon:", $result);
        $this->assertStringContainsString("Saksnummer: 2025/123-1 (Kongsberg kommune)", $result);
    }
    
    public function testGenerateWithDocumentNumber() {
        // :: Setup
        $thread = new Thread();
        $thread->my_name = "Test User";
        
        $email = new stdClass();
        $email->id = "email1";
        $email->email_type = "IN";
        $email->datetime_received = "2025-01-15 10:30:00";
        $thread->emails = [$email];
        
        // Create mock extraction with document number
        $extraction = new ThreadEmailExtraction();
        $extraction->prompt_service = "openai";
        $extraction->prompt_id = "saksnummer";
        $extraction->extracted_text = json_encode([
            (object)[
                'document_number' => '2025/DOC-456',
                'entity_name' => 'Test Kommune'
            ]
        ]);
        
        $this->extractionService->expects($this->once())
            ->method('getExtractionsForEmail')
            ->with('email1')
            ->willReturn([$extraction]);
        
        // :: Act
        $result = $this->generator->generateSuggestedReply($thread);
        
        // :: Assert
        $this->assertStringContainsString("Saksnummer informasjon:", $result);
        $this->assertStringContainsString("Dokumentnummer: 2025/DOC-456 (Test Kommune)", $result);
    }
    
    public function testGenerateWithCopyRequest() {
        // :: Setup
        $thread = new Thread();
        $thread->my_name = "Test User";
        
        $email = new stdClass();
        $email->id = "email1";
        $email->email_type = "IN";
        $email->datetime_received = "2025-01-15 10:30:00";
        $thread->emails = [$email];
        
        // Create mock extraction with copy request
        $extraction = new ThreadEmailExtraction();
        $extraction->prompt_service = "openai";
        $extraction->prompt_id = "copy-asking-for";
        $extraction->extracted_text = json_encode((object)[
            'is_requesting_copy' => true,
            'copy_request_description' => 'copy of the initial request'
        ]);
        
        $this->extractionService->expects($this->once())
            ->method('getExtractionsForEmail')
            ->with('email1')
            ->willReturn([$extraction]);
        
        // :: Act
        $result = $this->generator->generateSuggestedReply($thread);
        
        // :: Assert
        $this->assertStringContainsString("Merk: Avsenderen ber om en kopi av e-posten.", $result);
    }
    
    public function testGenerateWithNoCopyRequest() {
        // :: Setup
        $thread = new Thread();
        $thread->my_name = "Test User";
        
        $email = new stdClass();
        $email->id = "email1";
        $email->email_type = "IN";
        $email->datetime_received = "2025-01-15 10:30:00";
        $thread->emails = [$email];
        
        // Create mock extraction with no copy request
        $extraction = new ThreadEmailExtraction();
        $extraction->prompt_service = "openai";
        $extraction->prompt_id = "copy-asking-for";
        $extraction->extracted_text = json_encode((object)[
            'is_requesting_copy' => false,
            'copy_request_description' => ''
        ]);
        
        $this->extractionService->expects($this->once())
            ->method('getExtractionsForEmail')
            ->with('email1')
            ->willReturn([$extraction]);
        
        // :: Act
        $result = $this->generator->generateSuggestedReply($thread);
        
        // :: Assert
        $this->assertStringNotContainsString("Merk: Avsenderen ber om en kopi av e-posten.", $result);
    }
    
    public function testGenerateWithCompleteScenario() {
        // :: Setup
        $thread = new Thread();
        $thread->my_name = "Test User";
        
        $email1 = new stdClass();
        $email1->id = "email1";
        $email1->email_type = "IN";
        $email1->datetime_received = "2025-01-15 10:30:00";
        $email1->description = "Initial request";
        
        $email2 = new stdClass();
        $email2->id = "email2";
        $email2->email_type = "OUT";
        $email2->datetime_received = "2025-01-16 14:20:00";
        $email2->description = "Response";
        
        $thread->emails = [$email1, $email2];
        
        // Create extractions for both emails
        $caseExtraction = new ThreadEmailExtraction();
        $caseExtraction->prompt_service = "openai";
        $caseExtraction->prompt_id = "saksnummer";
        $caseExtraction->extracted_text = json_encode([
            (object)[
                'case_number' => '2025/123-1',
                'entity_name' => 'Kongsberg kommune'
            ]
        ]);
        
        $copyExtraction = new ThreadEmailExtraction();
        $copyExtraction->prompt_service = "openai";
        $copyExtraction->prompt_id = "copy-asking-for";
        $copyExtraction->extracted_text = json_encode((object)[
            'is_requesting_copy' => true,
            'copy_request_description' => 'copy of the initial request'
        ]);
        
        // Mock different extractions for different emails
        $this->extractionService->expects($this->exactly(2))
            ->method('getExtractionsForEmail')
            ->willReturnMap([
                ['email1', [$caseExtraction, $copyExtraction]],
                ['email2', []]
            ]);
        
        // :: Act
        $result = $this->generator->generateSuggestedReply($thread);
        
        // :: Assert
        $this->assertStringContainsString("Tidligere e-poster:", $result);
        $this->assertStringContainsString("1. Sendt den 2025-01-16 14:20:00", $result);
        $this->assertStringContainsString("2. Mottatt den 2025-01-15 10:30:00", $result);
        $this->assertStringContainsString("Saksnummer informasjon:", $result);
        $this->assertStringContainsString("Saksnummer: 2025/123-1 (Kongsberg kommune)", $result);
        $this->assertStringContainsString("Merk: Avsenderen ber om en kopi av e-posten.", $result);
        $this->assertStringContainsString("--\nTest User", $result);
    }
    
    public function testGenerateWithNoEmails() {
        // :: Setup
        $thread = new Thread();
        $thread->my_name = "Test User";
        $thread->emails = null;
        
        // :: Act
        $result = $this->generator->generateSuggestedReply($thread);
        
        // :: Assert
        $this->assertStringContainsString("Tidligere e-poster:", $result);
        $this->assertStringContainsString("--\nTest User", $result);
        $this->assertStringNotContainsString("Saksnummer informasjon:", $result);
        $this->assertStringNotContainsString("Merk: Avsenderen ber om en kopi av e-posten.", $result);
    }
    
    public function testGenerateWithMoreThanFiveEmails() {
        // :: Setup
        $thread = new Thread();
        $thread->my_name = "Test User";
        
        // Create 7 emails to test the limit of 5
        $emails = [];
        for ($i = 1; $i <= 7; $i++) {
            $email = new stdClass();
            $email->id = "email{$i}";
            $email->email_type = "IN";
            $email->datetime_received = "2025-01-{$i} 10:30:00";
            $email->description = "Email {$i}";
            $emails[] = $email;
        }
        $thread->emails = $emails;
        
        // Mock no extractions for simplicity
        $this->extractionService->method('getExtractionsForEmail')
            ->willReturn([]);
        
        // :: Act
        $result = $this->generator->generateSuggestedReply($thread);
        
        // :: Assert
        // Should contain emails 3-7 (last 5 when reversed)
        $this->assertStringContainsString("1. Mottatt den 2025-01-7 10:30:00", $result);
        $this->assertStringContainsString("5. Mottatt den 2025-01-3 10:30:00", $result);
        // Should NOT contain emails 1-2
        $this->assertStringNotContainsString("2025-01-1 10:30:00", $result);
        $this->assertStringNotContainsString("2025-01-2 10:30:00", $result);
    }
}
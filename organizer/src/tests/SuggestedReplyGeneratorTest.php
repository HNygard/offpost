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
        
        // :: Assert - With deterministic input, verify exact output
        $expectedReply = "Tidligere e-poster:\n\n--\nTest User";
        $this->assertEquals($expectedReply, $result, 'Generated reply should match expected format exactly');
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
        
        // :: Assert - With deterministic input, verify exact output
        $expectedReply = "Tidligere e-poster:\n\n" .
            "1. Sendt den 2025-01-16 14:20:00\n" .
            "   Sammendrag: Response to request\n\n" .
            "2. Mottatt den 2025-01-15 10:30:00\n" .
            "   Sammendrag: Initial request\n\n" .
            "--\nTest User";
        $this->assertEquals($expectedReply, $result, 'Generated reply should match expected format exactly');
    }
    
    public function testGenerateWithCaseNumberInformation() {
        // :: Setup
        $thread = new Thread();
        $thread->my_name = "Test User";
        
        $email = new stdClass();
        $email->id = "email1";
        $email->email_type = "IN";
        $email->datetime_received = "2025-01-15 10:30:00";
        $email->description = "Email with case number";
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
        $expectedResult = 'Tidligere e-poster:

1. Mottatt den 2025-01-15 10:30:00
   Sammendrag: Email with case number
   Saksnummer: 2025/123-1 (Kongsberg kommune)

--
Test User';
        $this->assertEquals($expectedResult, $result);
    }
    
    public function testGenerateWithDocumentNumber() {
        // :: Setup
        $thread = new Thread();
        $thread->my_name = "Test User";
        
        $email = new stdClass();
        $email->id = "email1";
        $email->email_type = "IN";
        $email->datetime_received = "2025-01-15 10:30:00";
        $email->description = "Email with document number";
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
        $expectedResult = 'Tidligere e-poster:

1. Mottatt den 2025-01-15 10:30:00
   Sammendrag: Email with document number
   Dokumentnummer: 2025/DOC-456 (Test Kommune)

--
Test User';
        $this->assertEquals($expectedResult, $result);
    }
    
    public function testGenerateWithCopyRequest() {
        // :: Setup
        $thread = new Thread();
        $thread->my_name = "Test User";
        
        $email = new stdClass();
        $email->id = "email1";
        $email->email_type = "IN";
        $email->datetime_received = "2025-01-15 10:30:00";
        $email->description = "Email requesting copy";
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
        $expectedResult = 'Tidligere e-poster:

1. Mottatt den 2025-01-15 10:30:00
   Sammendrag: Email requesting copy
   Merk: Avsenderen ber om en kopi av e-posten.

--
Test User';
        
        $this->assertEquals($expectedResult, $result);
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
        $expectedResult = 'Tidligere e-poster:

1. Sendt den 2025-01-16 14:20:00
   Sammendrag: Response

2. Mottatt den 2025-01-15 10:30:00
   Sammendrag: Initial request
   Saksnummer: 2025/123-1 (Kongsberg kommune)
   Merk: Avsenderen ber om en kopi av e-posten.

--
Test User';
        $this->assertEquals($expectedResult, $result);
    }
    
    public function testGenerateWithNoEmails() {
        // :: Setup
        $thread = new Thread();
        $thread->my_name = "Test User";
        $thread->emails = null;
        
        // :: Act
        $result = $this->generator->generateSuggestedReply($thread);
        
        // :: Assert
        $expectedResult = 'Tidligere e-poster:

--
Test User';
        $this->assertEquals($expectedResult, $result);
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
        
        // :: Assert - With deterministic input, verify exact output
        // Should contain emails 3-7 (last 5 when reversed)
        $expectedReply = "Tidligere e-poster:\n\n" .
            "1. Mottatt den 2025-01-7 10:30:00\n" .
            "   Sammendrag: Email 7\n\n" .
            "2. Mottatt den 2025-01-6 10:30:00\n" .
            "   Sammendrag: Email 6\n\n" .
            "3. Mottatt den 2025-01-5 10:30:00\n" .
            "   Sammendrag: Email 5\n\n" .
            "4. Mottatt den 2025-01-4 10:30:00\n" .
            "   Sammendrag: Email 4\n\n" .
            "5. Mottatt den 2025-01-3 10:30:00\n" .
            "   Sammendrag: Email 3\n\n" .
            "--\nTest User";
        $this->assertEquals($expectedReply, $result, 'Generated reply should show exactly last 5 emails');
    }
    
    public function testNoDuplicateCaseNumbers() {
        // :: Setup
        $thread = new Thread();
        $thread->my_name = "Test User";
        
        // Create two emails with the same case number
        $email1 = new stdClass();
        $email1->id = "email1";
        $email1->email_type = "IN";
        $email1->datetime_received = "2025-01-15 10:30:00";
        $email1->description = "First email";
        
        $email2 = new stdClass();
        $email2->id = "email2";
        $email2->email_type = "IN";
        $email2->datetime_received = "2025-01-16 11:30:00";
        $email2->description = "Second email";
        
        $thread->emails = [$email1, $email2];
        
        // Both emails have the same case number
        $sameCaseNumberExtraction = function() {
            $extraction = new ThreadEmailExtraction();
            $extraction->prompt_service = "openai";
            $extraction->prompt_id = "saksnummer";
            $extraction->extracted_text = json_encode([
                (object)['case_number' => '2025/123-1', 'entity_name' => 'Test Kommune']
            ]);
            return $extraction;
        };
        
        $this->extractionService->expects($this->exactly(2))
            ->method('getExtractionsForEmail')
            ->willReturnMap([
                ['email1', [$sameCaseNumberExtraction()]],
                ['email2', [$sameCaseNumberExtraction()]]
            ]);
        
        // :: Act
        $result = $this->generator->generateSuggestedReply($thread);
        
        // :: Assert
        $expectedResult = 'Tidligere e-poster:

1. Mottatt den 2025-01-16 11:30:00
   Sammendrag: Second email
   Saksnummer: 2025/123-1 (Test Kommune)

2. Mottatt den 2025-01-15 10:30:00
   Sammendrag: First email

--
Test User';
        $this->assertEquals($expectedResult, $result);
    }
}
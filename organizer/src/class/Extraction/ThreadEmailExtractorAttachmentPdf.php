<?php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Extraction/ThreadEmailExtraction.php';
require_once __DIR__ . '/../Extraction/ThreadEmailExtractionService.php';
require_once __DIR__ . '/../Extraction/ThreadEmailExtractor.php';
require_once __DIR__ . '/../ThreadEmail.php';
require_once __DIR__ . '/../ThreadStorageManager.php';
require_once __DIR__ . '/../../error.php';

/**
 * Class for extracting text from PDF attachments
 * Uses pdftotext command line tool to extract text from PDF files
 */
class ThreadEmailExtractorAttachmentPdf extends ThreadEmailExtractor {
    /**
     * Path to the temporary directory for PDF extraction
     * This should be mounted as a tmpfs volume in Docker
     */
    private $tmpDir = '/tmp/pdf_extraction';
    
    /**
     * Constructor
     * 
     * @param ThreadEmailExtractionService $extractionService Extraction service instance
     */
    public function __construct(ThreadEmailExtractionService $extractionService = null) {
        parent::__construct($extractionService);
        
        // Ensure the temporary directory exists
        if (!file_exists($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }
    }
    
    /**
     * Get the number of PDF attachments that need extraction
     * 
     * @return int Number of attachments to process
     */
    public function getNumberOfEmailsToProcess() {
        // Count the number of PDF attachments that need extraction
        $query = "
            SELECT COUNT(tea.id) AS attachment_count
            FROM thread_email_attachments tea
            LEFT JOIN thread_email_extractions tee ON tea.email_id = tee.email_id 
                AND tea.id = tee.attachment_id 
                AND tee.prompt_service = 'code'
                AND tee.prompt_text = 'attachment_pdf'
            WHERE tee.extraction_id IS NULL
            AND tea.filetype = 'pdf'
        ";
        
        $result = Database::queryOneOrNone($query, []);
        
        return $result ? (int)$result['attachment_count'] : 0;
    }
    
    /**
     * Find the next PDF attachment that needs extraction
     * 
     * @return array|null Attachment data or null if none found
     */
    public function findNextEmailForExtraction() {
        // Find PDF attachments that don't have a text extraction yet
        $query = "
            SELECT tea.id as attachment_id, tea.email_id, te.thread_id, tea.name, tea.filename, tea.filetype
            FROM thread_email_attachments tea
            JOIN thread_emails te ON tea.email_id = te.id
            LEFT JOIN thread_email_extractions tee ON tea.email_id = tee.email_id 
                AND tea.id = tee.attachment_id 
                AND tee.prompt_service = 'code'
                AND tee.prompt_text = 'attachment_pdf'
            WHERE tee.extraction_id IS NULL
            AND tea.filetype = 'pdf'
            ORDER BY te.datetime_received ASC
            LIMIT 1
        ";
        
        $row = Database::queryOneOrNone($query, []);
        
        if (!$row) {
            return null;
        }
        return $row;
    }
    
    /**
     * Process the next PDF attachment extraction
     * 
     * @return array Result of the operation
     */
    public function processNextEmailExtraction() {
        return $this->processNextEmailExtractionInternal(
            'attachment_pdf',
            'code',
            function($attachment, $prompt_text, $prompt_service, $extraction_id) {
                // Extract text from PDF attachment
                return $this->extractTextFromPdf($attachment);
            }
        );
    }
    
    /**
     * Extract text from a PDF attachment
     * 
     * @param array $attachment Attachment data
     * @return string Extracted text
     * @throws Exception If extraction fails
     */
    protected function extractTextFromPdf($attachment) {
        // Get attachment content from database
        $query = "SELECT content FROM thread_email_attachments WHERE id = ?";
        $result = Database::queryOneOrNone($query, [$attachment['attachment_id']]);
        
        if (!$result || empty($result['content'])) {
            throw new Exception("Attachment content not found for ID: " . $attachment['attachment_id']);
        }
        
        $pdfContent = $result['content'];
        
        // Create unique filenames for input and output
        $uniqueId = uniqid('pdf_', true);
        $inputFile = $this->tmpDir . '/' . $uniqueId . '.pdf';
        $outputFile = $this->tmpDir . '/' . $uniqueId . '.txt';
        
        try {
            // Write PDF content to temporary file
            if (file_put_contents($inputFile, $pdfContent) === false) {
                throw new Exception("Failed to write PDF to temporary file: " . $inputFile);
            }
            
            // Execute pdftotext command
            $command = sprintf(
                'pdftotext -layout %s %s 2>&1',
                escapeshellarg($inputFile),
                escapeshellarg($outputFile)
            );
            
            $output = [];
            $returnCode = 0;
            exec($command, $output, $returnCode);
            
            // Handle different return codes from pdftotext
            $hasPdfWarning = false;
            if ($returnCode !== 0) {
                // Check if this is the "May not be a PDF file" warning with exit code 1
                $outputText = implode("\n", $output);
                if ($returnCode === 1 && strpos($outputText, 'Syntax Warning: May not be a PDF file') !== false) {
                    // This is a warning about file format, but pdftotext may have still extracted some text
                    // Continue processing to see if an output file was created
                    $hasPdfWarning = true;
                } else {
                    // This is a real error, throw exception
                    throw new Exception("pdftotext command failed with code $returnCode: " . $outputText);
                }
            }
            
            // Read extracted text
            if (!file_exists($outputFile)) {
                if ($hasPdfWarning) {
                    // If we had a PDF warning and no output file was created, return a static message
                    return "Can't read file as PDF. It may not be a PDF file.";
                }
                throw new Exception("Output file not created: " . $outputFile);
            }
            
            $extractedText = file_get_contents($outputFile);
            
            if ($extractedText === false) {
                throw new Exception("Failed to read extracted text from: " . $outputFile);
            }
            
            // Clean up the extracted text
            $extractedText = $this->cleanExtractedText($extractedText);
            
            return $extractedText;
        }
        finally {
            // Clean up temporary files
            if (file_exists($inputFile)) {
                unlink($inputFile);
            }
            if (file_exists($outputFile)) {
                unlink($outputFile);
            }
        }
    }
    
    /**
     * Clean up extracted text
     * 
     * @param string $text Text to clean
     * @return string Cleaned text
     */
    protected function cleanExtractedText($text) {
        // Normalize line endings
        $text = str_replace("\r\n", "\n", $text);
        $text = str_replace("\r", "\n", $text);
        
        // Remove excessive whitespace
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        
        // Remove null bytes and other control characters (except newlines and tabs)
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        
        // Trim whitespace
        $text = trim($text);
        
        return $text;
    }
}

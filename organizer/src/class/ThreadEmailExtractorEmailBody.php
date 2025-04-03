<?php

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/ThreadEmailExtraction.php';
require_once __DIR__ . '/ThreadEmailExtractionService.php';
require_once __DIR__ . '/ThreadEmail.php';
require_once __DIR__ . '/../error.php';

/**
 * Class for extracting text from email bodies
 * Used as foundation for automatic classification and follow up
 */
class ThreadEmailExtractorEmailBody {
    protected $extractionService;
    
    /**
     * Constructor
     * 
     * @param ThreadEmailExtractionService $extractionService Extraction service instance
     */
    public function __construct(ThreadEmailExtractionService $extractionService = null) {
        $this->extractionService = $extractionService ?: new ThreadEmailExtractionService();
    }
    
    /**
     * Find the next email that needs extraction
     * 
     * @return array|null Email data or null if none found
     */
    public function findNextEmailForExtraction() {
        // Find emails that don't have a body text extraction yet
        $query = "
            SELECT te.id, te.thread_id, te.status_type, te.status_text
            FROM thread_emails te
            LEFT JOIN thread_email_extractions tee ON te.id = tee.email_id 
                AND tee.attachment_id IS NULL 
                AND tee.prompt_service = 'code'
                AND tee.prompt_text = 'email_body'
            WHERE tee.extraction_id IS NULL
            ORDER BY te.datetime_received ASC
            LIMIT 1
        ";
        
        return Database::queryOne($query, []);
    }
    
    /**
     * Process the next email extraction
     * 
     * @return array Result of the operation
     */
    public function processNextEmailExtraction() {
        // Find the next email that needs extraction
        $email = $this->findNextEmailForExtraction();
        
        if (!$email) {
            return [
                'success' => false,
                'message' => 'No emails found that need extraction'
            ];
        }
        
        try {
            // Create extraction record
            $extraction = $this->extractionService->createExtraction(
                $email['id'],
                'email_body',
                'code'
            );
            
            // Extract text from email body
            $extractedText = $this->extractTextFromEmailBody($email['id']);
            
            // Update extraction with results
            $updatedExtraction = $this->extractionService->updateExtractionResults(
                $extraction->extraction_id,
                $extractedText
            );
            
            return [
                'success' => true,
                'message' => 'Successfully extracted text from email body',
                'email_id' => $email['id'],
                'thread_id' => $email['thread_id'],
                'extraction_id' => $extraction->extraction_id,
                'extracted_text_length' => strlen($extractedText)
            ];
        } catch (\Exception $e) {
            if (!defined('PHPUNIT_RUNNING')) {
                echo "Error processing email: {$email['id']}. " . $e->getMessage() . "\n";
                echo jTraceEx($e) . "\n\n";
            }

            // If extraction was created but failed to update, update with error
            if (isset($extraction) && $extraction->extraction_id) {
                $this->extractionService->updateExtractionResults(
                    $extraction->extraction_id,
                    null,
                    $e->getMessage()
                );
            }
            
            return [
                'success' => false,
                'message' => 'Failed to extract text from email body',
                'email_id' => $email['id'],
                'thread_id' => $email['thread_id'],
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Extract text from email body
     * 
     * @param string $emailId Email ID
     * @return string Extracted text
     */
    protected function extractTextFromEmailBody($emailId) {
        // Get email content
        $query = "SELECT * FROM thread_emails WHERE id = ?";
        $email = Database::queryOne($query, [$emailId]);
        
        if (!$email) {
            throw new \Exception("Email not found: $emailId");
        }
        
        // Get email file path
        $threadId = $email['thread_id'];
        $thread = Database::queryOne("SELECT * FROM threads WHERE id = ?", [$threadId]);
        
        if (!$thread) {
            throw new \Exception("Thread not found: $threadId");
        }
        
        $entityId = $thread['entity_id'];
        $emailFilePath = "/organizer-data/threads/$entityId/thread_$threadId/$email[filename]";
        
        if (!file_exists($emailFilePath)) {
            throw new \Exception("Email file not found: $emailFilePath");
        }
        
        // Parse email file
        $emailContent = file_get_contents($emailFilePath);
        
        // Extract text from email
        $extractedText = $this->extractTextFromEmailContent($emailContent);
        
        return $extractedText;
    }
    
    /**
     * Extract text from email content
     * 
     * @param string $emailContent Raw email content
     * @return string Extracted text
     */
    protected function extractTextFromEmailContent($emailContent) {
        // Parse email using Laminas Mail
        $message = new \Laminas\Mail\Storage\Message(['raw' => $emailContent]);
        
        // Get body text
        $body = '';
        
        // Check if message is multipart
        if ($message->isMultipart()) {
            // Try to find text/plain or text/html part
            $plainTextPart = null;
            $htmlPart = null;
            
            foreach (new \RecursiveIteratorIterator($message) as $part) {
                $contentType = $part->getHeaderField('content-type');
                
                if (strpos($contentType, 'text/plain') === 0) {
                    $plainTextPart = $part;
                } elseif (strpos($contentType, 'text/html') === 0) {
                    $htmlPart = $part;
                }
            }
            
            // Prefer plain text over HTML
            if ($plainTextPart) {
                $body = $plainTextPart->getContent();
            } elseif ($htmlPart) {
                $body = $this->convertHtmlToText($htmlPart->getContent());
            }
        } else {
            // Single part message
            $contentType = $message->getHeaderField('content-type');
            
            if (strpos($contentType, 'text/plain') === 0) {
                $body = $message->getContent();
            } elseif (strpos($contentType, 'text/html') === 0) {
                $body = $this->convertHtmlToText($message->getContent());
            }
        }
        
        // Clean up the text
        $body = $this->cleanText($body);
        
        return $body;
    }
    
    /**
     * Convert HTML to plain text
     * 
     * @param string $html HTML content
     * @return string Plain text
     */
    protected function convertHtmlToText($html) {
        // Remove scripts, styles, and comments
        $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);
        $html = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $html);
        $html = preg_replace('/<!--(.*?)-->/is', '', $html);
        
        // Replace common HTML elements with text equivalents
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html);
        $html = preg_replace('/<\/p>/i', "\n\n", $html);
        $html = preg_replace('/<\/h[1-6]>/i', "\n\n", $html);
        $html = preg_replace('/<li>/i', "- ", $html);
        $html = preg_replace('/<\/li>/i', "\n", $html);
        
        // Remove all remaining HTML tags
        $text = strip_tags($html);
        
        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        return $text;
    }
    
    /**
     * Clean up extracted text
     * 
     * @param string $text Text to clean
     * @return string Cleaned text
     */
    protected function cleanText($text) {
        // Normalize line endings
        $text = str_replace("\r\n", "\n", $text);
        $text = str_replace("\r", "\n", $text);
        
        // Remove excessive whitespace
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = trim($text);
        
        return $text;
    }
}

<?php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Extraction/ThreadEmailExtraction.php';
require_once __DIR__ . '/../Extraction/ThreadEmailExtractionService.php';
require_once __DIR__ . '/../Extraction/ThreadEmailExtractor.php';
require_once __DIR__ . '/../ThreadEmail.php';
require_once __DIR__ . '/../ThreadStorageManager.php';
require_once __DIR__ . '/../../error.php';

/**
 * Class for extracting text from email bodies
 * Used as foundation for automatic classification and follow up
 */
class ThreadEmailExtractorEmailBody extends ThreadEmailExtractor {

    /**
     * Get the number of emails that need extraction
     * 
     * @return int Number of emails to process
     */
    public function getNumberOfEmailsToProcess() {
        // Count the number of emails that need extraction
        $query = "
            SELECT COUNT(te.id) AS email_count
            FROM thread_emails te
            LEFT JOIN thread_email_extractions tee ON te.id = tee.email_id 
                AND tee.attachment_id IS NULL 
                AND tee.prompt_service = 'code'
                AND tee.prompt_text = 'email_body'
            WHERE tee.extraction_id IS NULL
        ";
        
        $result = Database::queryOneOrNone($query, []);
        
        return $result ? (int)$result['email_count'] : 0;
    }
    
    /**
     * Find the next email that needs extraction
     * 
     * @return array|null Email data or null if none found
     */
    public function findNextEmailForExtraction() {
        // Find emails that don't have a body text extraction yet
        $query = "
            SELECT te.id as email_id, te.thread_id, te.status_type, te.status_text
            FROM thread_emails te
            LEFT JOIN thread_email_extractions tee ON te.id = tee.email_id 
                AND tee.attachment_id IS NULL 
                AND tee.prompt_service = 'code'
                AND tee.prompt_text = 'email_body'
            WHERE tee.extraction_id IS NULL
            ORDER BY te.datetime_received ASC
            LIMIT 1
        ";
        
        $row = Database::queryOneOrNone($query, []);
        
        if (!$row) {
            return null;
        }
        return $row;
    }
    
    public function processNextEmailExtraction() {
        return $this->processNextEmailExtractionInternal(
            'email_body',
            'code',
            function($email, $prompt_text, $prompt_service) {
                // Extract text from email body
                $extractedTexts = $this->extractTextFromEmailBody($email['thread_id'], $email['email_id']);

                $extractedText = '';
                if (!empty($extractedTexts->plain_text)) {
                    $extractedText .= $extractedTexts->plain_text;
                }
                if (!empty($extractedTexts->html)) {
                    $extractedText = trim($extractedText . "\n\n" . $extractedTexts->html);
                }
                return $extractedText;
            }
        );
    }
    
    /**
     * Extract text from email body
     * 
     * @param string $emailId Email ID
     * @return ExtractedEmailBody Extracted text
     */
    protected function extractTextFromEmailBody($threadId, $emailId) {
        $eml =  ThreadStorageManager::getInstance()->getThreadEmailContent($threadId, $emailId); 
        $email_content = self::extractContentFromEmail($eml);
        return $email_content;
    }

    /**
     * Remove headers that contain invalid email addresses causing parsing errors
     * This preserves privacy by not sanitizing anonymized email addresses
     * 
     * @param string $eml Raw email content
     * @return string Email content with problematic headers removed
     */
    private static function removeProblematicHeaders($eml) {
        // Split into headers and body
        $parts = explode("\n\n", $eml, 2);
        if (count($parts) < 2) {
            $parts = explode("\r\n\r\n", $eml, 2);
        }
        
        $headers = $parts[0];
        $body = isset($parts[1]) ? $parts[1] : '';
        
        // Remove headers that contain <removed> or other obvious placeholder values
        // This preserves the user's anonymization intent
        $headerLines = explode("\n", $headers);
        $cleanHeaders = [];
        
        foreach ($headerLines as $line) {
            // Skip headers with <removed> placeholder or other obvious anonymization
            if (preg_match('/:\s*<removed>/', $line)) {
                continue;
            }
            $cleanHeaders[] = $line;
        }
        
        // Rejoin headers and body
        return implode("\n", $cleanHeaders) . "\n\n" . $body;
    }

    public static function extractContentFromEmail($eml) {
        try {
            $message = new \Laminas\Mail\Storage\Message(['raw' => $eml]);
        } catch (\Laminas\Mail\Exception\InvalidArgumentException $e) {
            // Handle emails with invalid headers (like anonymized email addresses)
            // by removing problematic headers while preserving privacy
            $eml = self::removeProblematicHeaders($eml);
            $message = new \Laminas\Mail\Storage\Message(['raw' => $eml]);
        } catch (\Laminas\Mail\Header\Exception\InvalidArgumentException $e) {
            // Handle specific header parsing exceptions as well
            $eml = self::removeProblematicHeaders($eml);
            $message = new \Laminas\Mail\Storage\Message(['raw' => $eml]);
        }

        $htmlConvertPart = function ($html, $part) {
            if (!$part || !($part instanceof \Laminas\Mail\Storage\Message)) {
                return $html;
            }
            
            if ($part->getHeaders()->has('content-transfer-encoding') !== false) {
                $encoding = $part->getHeaderField('content-transfer-encoding');
            }
            else {
                $encoding = null;
            }
            
            if ($encoding == 'base64') {
                $html = base64_decode($html);
            }   
            if ($encoding == 'quoted-printable') {
                // Use quoted-printable decoder with explicit charset
                $charset = 'UTF-8';
                
                // Try to get charset from content-type
                try {
                    $contentType = $part->getHeaderField('content-type');
                    if (is_array($contentType) && isset($contentType['charset'])) {
                        $charset = $contentType['charset'];
                    }
                } catch (Exception $e) {
                    // Ignore and use default charset
                }
                
                $html = quoted_printable_decode($html);
            }

            return $html;
        };
        $fixEncoding = function ($html, $charset) {
            if (empty($html)) {
                return $html;
            }

            // If already valid UTF-8, return as is
            if (mb_check_encoding($html, 'UTF-8')) {
                return $html;
            }

            // Try multiple encodings, prioritizing those common in Norwegian content
            $encodings = ['ISO-8859-1', 'Windows-1252', 'ISO-8859-15', 'UTF-8'];
            
            foreach ($encodings as $encoding) {
                $converted = @mb_convert_encoding($html, 'UTF-8', $encoding);
                if (mb_check_encoding($converted, 'UTF-8') && strpos($converted, '?') === false) {
                    return $converted;
                }
            }

            // Force ISO-8859-1 as a last resort
            return mb_convert_encoding($html, 'UTF-8', 'ISO-8859-1');
        };
        
        $email_content = new ExtractedEmailBody();
        if ($message->isMultipart()) {
            $plainTextPart = false;
            $htmlPart = false;

            foreach (new RecursiveIteratorIterator($message) as $part) {
                if (strtok($part->contentType, ';') == 'text/plain') {
                    $plainTextPart = $part;
                }
                if (strtok($part->contentType, ';') == 'text/html') {
                    $htmlPart = $part;
                }
            }

            $plainText = $plainTextPart ? $plainTextPart->getContent() : '';
            $html = $htmlPart ? $htmlPart->getContent() : '';

            // Get charset from content-type if available
            $plainTextCharset = $message->getHeaders()->getEncoding();
            $htmlCharset = $message->getHeaders()->getEncoding();
            
            if ($plainTextPart) {
                try {
                    $contentType = $plainTextPart->getHeaderField('content-type');
                    if (is_array($contentType) && isset($contentType['charset'])) {
                        $plainTextCharset = $contentType['charset'];
                    }
                } catch (Exception $e) {
                    // Ignore and use default charset
                }
            }
            
            if ($htmlPart) {
                try {
                    $contentType = $htmlPart->getHeaderField('content-type');
                    if (is_array($contentType) && isset($contentType['charset'])) {
                        $htmlCharset = $contentType['charset'];
                    }
                } catch (Exception $e) {
                    // Ignore and use default charset
                }
            }
            
            // First decode the content based on transfer encoding
            $decodedPlainText = $htmlConvertPart($plainText, $plainTextPart);
            $decodedHtml = $htmlConvertPart($html, $htmlPart);
            
            // Then convert charset to UTF-8
            $convertedPlainText = $fixEncoding($decodedPlainText, $plainTextCharset);
            $convertedHtml =  $fixEncoding($decodedHtml, $htmlCharset);
            
            $email_content->plain_text = self::cleanText($convertedPlainText);
            $email_content->html = self::convertHtmlToText($convertedHtml);
        }
        else {
            // If the message is not multipart, simply echo the content

            $charset = $message->getHeaders()->getEncoding();
            if ($message->getHeaders()->get('content-type') !== false) {
                // Example:
                // Content-Type: text/plain;
                //  charset="UTF-8";
                //  format="flowed"
                $content_type = $message->getHeaders()->get('content-type')->getFieldValue();
                preg_match('/charset=["\']?([\w-]+)["\']?/i', $content_type, $matches);
                if (isset($matches[1])) {
                    $charset = $matches[1];
                }
            }
            
            $email_content->plain_text = self::cleanText($fixEncoding($message->getContent(), $charset));
        }


        return $email_content;
    }
    
    /**
     * Convert HTML to plain text
     * 
     * @param string $html HTML content
     * @return string Plain text
     */
    protected static function convertHtmlToText($html) {
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
    protected static function cleanText($text) {
        // Normalize line endings
        $text = str_replace("\r\n", "\n", $text);
        $text = str_replace("\r", "\n", $text);
        
        // Remove excessive whitespace
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = trim($text);
        
        return $text;
    }
}

class ExtractedEmailBody {
    public $plain_text;
    public $html;
}

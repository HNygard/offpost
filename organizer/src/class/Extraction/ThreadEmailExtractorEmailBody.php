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

    public static function extractContentFromEmail($eml) {
        if (empty($eml)) {
            throw new Exception("Empty email content provided for extraction");
        }

        try {
            $message = self::readLaminasMessage_withErrorHandling(['raw' => $eml]);
        } catch (Exception $e) {
            error_log("Error parsing email content: " . $e->getMessage() . " . EML: " . $eml);

            $email_content = new ExtractedEmailBody();
            $email_content->plain_text = "ERROR\n\n".$eml;
            $email_content->html = '<pre>' . jTraceEx($e) . '</pre>';
            return $email_content;
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

    /**
     * Strip problematic headers that cause parsing issues in Laminas Mail
     * 
     * @param string $eml Raw email content
     * @return string Cleaned email content
     */
    public static function stripProblematicHeaders($eml) {
        // List of headers that should be stripped to avoid parsing issues
        $problematicHeaders = [
            'DKIM-Signature',           // Can contain malformed data that breaks parsing
            'ARC-Seal',                 // Authentication headers not needed for content extraction
            'ARC-Message-Signature',    // Authentication headers not needed for content extraction
            'ARC-Authentication-Results', // Authentication headers not needed for content extraction
            'Authentication-Results',    // Authentication headers not needed for content extraction
        ];

        // Split email into header and body parts
        $parts = preg_split('/\r?\n\r?\n/', $eml, 2);
        if (count($parts) < 2) {
            // If there's no clear header/body separation, return as-is
            return $eml;
        }

        $headerPart = $parts[0];
        $bodyPart = $parts[1];

        // Process headers line by line
        $headerLines = preg_split('/\r?\n/', $headerPart);
        $cleanedHeaders = [];
        $skipCurrentHeader = false;

        foreach ($headerLines as $line) {
            // Check if this is a new header (starts at beginning of line with header name)
            if (preg_match('/^([A-Za-z-]+):\s*/', $line, $matches)) {
                $headerName = $matches[1];
                $skipCurrentHeader = in_array($headerName, $problematicHeaders);
                
                if ($skipCurrentHeader) {
                    // Keep the header name but replace content with "REMOVED"
                    $cleanedHeaders[] = $headerName . ": REMOVED";
                } else {
                    $cleanedHeaders[] = $line;
                }
            } elseif (!$skipCurrentHeader && (substr($line, 0, 1) === ' ' || substr($line, 0, 1) === "\t")) {
                // This is a continuation line for a header we're keeping
                $cleanedHeaders[] = $line;
            }
            // If $skipCurrentHeader is true, we ignore continuation lines for problematic headers
        }

        // Rebuild the email
        return implode("\n", $cleanedHeaders) . "\n\n" . $bodyPart;
    }

    /**
     * Read Laminas Mail Message with error handling for problematic headers.
     * 
     * We will split out headers and read one by one until we find the problematic one,
     * then add it to exception message for easier debugging.
     * 
     * @param mixed $eml
     * @return Laminas\Mail\Storage\Message
     */
    public static function readLaminasMessage_withErrorHandling($eml) {
        try {
            return new \Laminas\Mail\Storage\Message(['raw' => self::stripProblematicHeaders($eml)]);
        } catch (\Laminas\Mail\Header\Exception\InvalidArgumentException $e) {
            // We hit some invalid header.
            // Laminas\Mail\Header\Exception\InvalidArgumentException: Invalid header value detected
            error_log("Error parsing email content: " . $e->getMessage() . " . EML: " . $eml);

            $headers = preg_split('/\r?\n/', $eml);
            $currentHeader = '';
            foreach ($headers as $line) {
                if (preg_match('/^([A-Za-z-]+):\s*/', $line, $matches)) {
                    // New header
                    $currentHeader = $matches[1];
                } elseif (substr($line, 0, 1) === ' ' || substr($line, 0, 1) === "\t") {
                    // Continuation line
                    // Do nothing, just continue
                } else {
                    // Not a header line, skip
                    continue;
                }   
                try {
                    // Try to parse the email up to the current header
                    $partialEml = implode("\n", array_slice($headers, 0, array_search($line, $headers) + 1));
                    $message = new \Laminas\Mail\Storage\Message(['raw' => self::stripProblematicHeaders($partialEml)]);
                } catch (\Laminas\Mail\Header\Exception\InvalidArgumentException $e2) {
                    // Failed to parse at this header, log and throw
                    throw new Exception("Failed to parse email due to problematic header: " . $currentHeader . ". Original error: " . $e2->getMessage());
                }
            }
            // If we got here, we couldn't find the problematic header
            throw new Exception("Failed to parse email, but couldn't isolate problematic header.", 0, $e);
        }
    }
}

class ExtractedEmailBody {
    public $plain_text;
    public $html;
}

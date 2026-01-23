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
            $message = self::readLaminasMessage_withErrorHandling($eml);
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
     * Fix malformed encoded-words in email headers
     * 
     * Some email clients produce malformed encoded-words where the closing ?= is missing
     * and the next header name appears immediately after. This method fixes such cases.
     * 
     * Example of malformed header:
     * Subject: =?iso-8859-1?Q?text?Thread-Topic:
     * Should be:
     * Subject: =?iso-8859-1?Q?text?=
     * 
     * @param string $headerLine Header line to fix
     * @return string Fixed header line
     */
    private static function fixMalformedEncodedWords($headerLine) {
        // Pattern components for readability
        // Encoded word format: =?charset?encoding?content
        $encodedWordStart = '=\?[^?]+\?';           // =?charset?
        $encoding = '[BQbq]';                        // B or Q encoding (base64 or quoted-printable)
        $encodedContent = '[^?]*';                   // The encoded content
        $missingClose = '\?';                        // The ? that should be followed by = but isn't
        $nextHeaderName = '([A-Za-z][A-Za-z0-9-]*)'; // The next header name that appears too early
        $headerColon = ':';                          // The colon after header name
        
        // Full pattern: match encoded word missing ?= followed by header name
        $pattern = "/({$encodedWordStart}{$encoding}\?{$encodedContent}){$missingClose}{$nextHeaderName}{$headerColon}(.*)$/";
        
        if (preg_match($pattern, $headerLine, $matches, PREG_OFFSET_CAPTURE)) {
            // $matches[1][0] = the encoded word without proper closing
            // $matches[1][1] = the offset of the encoded word in the header line
            // $matches[2] and $matches[3] = the header name and rest of the line (we drop them)
            
            $matchPos = $matches[1][1];
            $beforeMatch = substr($headerLine, 0, $matchPos);
            $encodedWord = $matches[1][0];
            
            // Preserve everything before the malformed encoded-word and just fix its closing
            return $beforeMatch . $encodedWord . '?=';
        }
        
        return $headerLine;
    }

    /**
     * Fix charset mismatches in RFC 2047 encoded-words
     * 
     * Some email clients (especially Microsoft Outlook/Exchange) incorrectly declare
     * iso-8859-1 charset but include UTF-8 encoded bytes. Additionally, they may
     * include raw UTF-8 bytes instead of Q-encoded format (=XX).
     * 
     * Example of problematic header:
     * To: =?iso-8859-1?Q?Alfred_Sj\xc3\xb8berg?= <alfred.sjoberg@offpost.no>
     * 
     * This contains:
     * - Declaration: iso-8859-1
     * - Content: UTF-8 bytes \xc3\xb8 (ø) as raw bytes instead of =C3=B8
     * - In ISO-8859-1, ø should be \xf8
     * 
     * This method detects UTF-8 byte sequences in iso-8859-1 encoded-words and
     * either converts the charset declaration or fixes the encoding.
     * 
     * @param string $eml Raw email content
     * @return string Fixed email content
     */
    private static function fixCharsetMismatchInEncodedWords($eml) {
        // Pattern to match encoded-words with potential charset issues
        // Format: =?charset?encoding?content?=
        // We focus on iso-8859-1 with Q encoding (quoted-printable)
        $pattern = '/=\?(iso-8859-1)\?([QBqb])\?([^?]*)\?=/i';
        
        $eml = preg_replace_callback($pattern, function($matches) {
            $charset = $matches[1];
            $encoding = strtoupper($matches[2]);
            $content = $matches[3];
            
            // Only process Q encoding (quoted-printable)
            if ($encoding !== 'Q') {
                return $matches[0]; // Return unchanged for Base64
            }
            
            // Check if content contains UTF-8 byte sequences
            // UTF-8 2-byte sequence pattern: \xC2-\xDF followed by \x80-\xBF
            // (Note: \xC0 and \xC1 are invalid UTF-8 start bytes, excluded to avoid overlong encodings)
            // Common for Norwegian characters:
            // - ø: \xC3\xB8
            // - å: \xC3\xA5
            // - æ: \xC3\xA6
            $hasUtf8Bytes = preg_match('/[\xC2-\xDF][\x80-\xBF]/', $content);
            
            if (!$hasUtf8Bytes) {
                return $matches[0]; // No UTF-8 bytes detected, return unchanged
            }
            
            // Strategy: Change the charset declaration to UTF-8
            // This allows the parser to correctly interpret the bytes
            // We also need to ensure raw bytes are properly Q-encoded
            
            // First, ensure all non-ASCII bytes are Q-encoded (=XX format)
            $fixedContent = '';
            $len = strlen($content);
            for ($i = 0; $i < $len; $i++) {
                $byte = $content[$i];
                $ord = ord($byte);
                
                // If it's a raw high-bit byte (> 127), Q-encode it
                if ($ord > 127) {
                    $fixedContent .= sprintf('=%02X', $ord);
                } else {
                    $fixedContent .= $byte;
                }
            }
            
            // Return with UTF-8 charset declaration (uppercase for better compatibility)
            return "=?UTF-8?Q?{$fixedContent}?=";
        }, $eml);
        
        return $eml;
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
                    // Fix malformed encoded-words in the header
                    $cleanedHeaders[] = self::fixMalformedEncodedWords($line);
                }
            } elseif (!$skipCurrentHeader && (substr($line, 0, 1) === ' ' || substr($line, 0, 1) === "\t")) {
                // This is a continuation line for a header we're keeping
                // Also fix malformed encoded-words in continuation lines
                $cleanedHeaders[] = self::fixMalformedEncodedWords($line);
            }
            // If $skipCurrentHeader is true, we ignore continuation lines for problematic headers
        }

        // Rebuild the email
        return implode("\n", $cleanedHeaders) . "\n\n" . $bodyPart;
    }

    /**
     * Analyze a header value and identify problematic characters.
     * This replicates the validation logic from Laminas\Mail\Header\HeaderValue::isValid()
     * but provides detailed information about which character(s) are invalid.
     * 
     * @param string $value Header value to analyze
     * @return array Array with 'valid' boolean and 'issues' array containing problem details
     */
    private static function debuggingAnalyzeHeaderValue($value) {
        $issues = [];
        $total = strlen($value);
        
        for ($i = 0; $i < $total; $i += 1) {
            $ord = ord($value[$i]);
            $char = $value[$i];
            
            // bare LF means we aren't valid
            if ($ord === 10) {
                $issues[] = [
                    'position' => $i,
                    'character' => '\n',
                    'ord' => $ord,
                    'reason' => 'Bare LF (line feed) without CR (carriage return)',
                    'context' => self::debuggingGetCharacterContext($value, $i)
                ];
                continue;
            }
            
            // Characters > 127 are not valid in headers (must use encoded-words)
            if ($ord > 127) {
                $issues[] = [
                    'position' => $i,
                    'character' => $char,
                    'ord' => $ord,
                    'reason' => 'Non-ASCII character (ord > 127) - should use encoded-word format',
                    'context' => self::debuggingGetCharacterContext($value, $i)
                ];
                continue;
            }

            // Check for proper CRLF sequences
            if ($ord === 13) { // CR
                if ($i + 2 >= $total) {
                    $issues[] = [
                        'position' => $i,
                        'character' => '\r',
                        'ord' => $ord,
                        'reason' => 'CR (carriage return) at end of value without LF and space/tab',
                        'context' => self::debuggingGetCharacterContext($value, $i)
                    ];
                    continue;
                }

                $lf = ord($value[$i + 1]);
                $sp = ord($value[$i + 2]);

                if ($lf !== 10 || ! in_array($sp, [9, 32], true)) {
                    $issues[] = [
                        'position' => $i,
                        'character' => '\r',
                        'ord' => $ord,
                        'reason' => 'Invalid CRLF sequence - CR must be followed by LF and space/tab',
                        'next_chars' => sprintf('0x%02X 0x%02X', $lf, $sp),
                        'context' => self::debuggingGetCharacterContext($value, $i)
                    ];
                    continue;
                }

                // skip over the LF following this
                $i += 2;
            }
        }
        
        return [
            'valid' => empty($issues),
            'issues' => $issues
        ];
    }
    
    /**
     * Get context around a character position for debugging.
     * 
     * @param string $value The full string
     * @param int $position Position of the character
     * @param int $contextLength Number of characters to show on each side
     * @return string Context string showing the character in its surroundings
     */
    private static function debuggingGetCharacterContext($value, $position, $contextLength = 20) {
        $start = max(0, $position - $contextLength);
        $end = min(strlen($value), $position + $contextLength + 1);
        
        $before = substr($value, $start, $position - $start);
        $char = substr($value, $position, 1);
        $after = substr($value, $position + 1, $end - $position - 1);
        
        // Make special characters visible
        $before = self::debuggingMakeSpecialCharsVisible($before);
        $char = self::debuggingMakeSpecialCharsVisible($char);
        $after = self::debuggingMakeSpecialCharsVisible($after);
        
        return sprintf('...%s[%s]%s...', $before, $char, $after);
    }
    
    /**
     * Make special characters visible for debugging output.
     * 
     * @param string $str String to process
     * @return string String with special characters made visible
     */
    private static function debuggingMakeSpecialCharsVisible($str) {
        $replacements = [
            "\r" => '\r',
            "\n" => '\n',
            "\t" => '\t',
        ];
        
        $result = str_replace(array_keys($replacements), array_values($replacements), $str);
        
        // Replace other non-printable and high-ASCII characters with hex representation
        $result = preg_replace_callback('/[\x00-\x1F\x7F-\xFF]/', function($matches) {
            return sprintf('\x%02X', ord($matches[0]));
        }, $result);
        
        return $result;
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
        // First fix charset mismatches in encoded-words (e.g., UTF-8 bytes in iso-8859-1 headers)
        $eml = self::fixCharsetMismatchInEncodedWords($eml);
        // Then strip problematic headers
        $eml = self::stripProblematicHeaders($eml);
        try {
            return new \Laminas\Mail\Storage\Message(['raw' => $eml]);
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
                    // Failed to parse at this header, analyze the header value for problematic characters
                    $headerValue = preg_replace('/^[A-Za-z-]+:\s*/', '', $line);
                    $analysis = self::debuggingAnalyzeHeaderValue($headerValue);
                    
                    $debugInfo = "Failed to parse email due to problematic header: " . $currentHeader . "\n"
                        . "Original error: " . $e->getMessage() . "\n"
                        . "New error: " . $e2->getMessage() . "\n\n";
                    
                    // Add character-level debugging information
                    if (!empty($analysis['issues'])) {
                        $debugInfo .= "CHARACTER ANALYSIS:\n";
                        $debugInfo .= "Found " . count($analysis['issues']) . " problematic character(s) in header value:\n\n";
                        
                        foreach ($analysis['issues'] as $idx => $issue) {
                            $debugInfo .= sprintf(
                                "Issue #%d:\n"
                                . "  Position: %d\n"
                                . "  Character: %s (ASCII: %d / 0x%02X)\n"
                                . "  Reason: %s\n"
                                . "  Context: %s\n",
                                $idx + 1,
                                $issue['position'],
                                $issue['character'],
                                $issue['ord'],
                                $issue['ord'],
                                $issue['reason'],
                                $issue['context']
                            );
                            
                            if (isset($issue['next_chars'])) {
                                $debugInfo .= "  Next chars: " . $issue['next_chars'] . "\n";
                            }
                            
                            $debugInfo .= "\n";
                        }
                    }
                    
                    $debugInfo .= "Partial EML up to this header:\n" . $partialEml;
                    
                    // Log and throw with enhanced debugging information
                    throw new Exception($debugInfo);
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

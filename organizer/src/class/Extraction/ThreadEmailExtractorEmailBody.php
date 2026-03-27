<?php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Extraction/ThreadEmailExtraction.php';
require_once __DIR__ . '/../Extraction/ThreadEmailExtractionService.php';
require_once __DIR__ . '/../Extraction/ThreadEmailExtractor.php';
require_once __DIR__ . '/../ThreadEmail.php';
require_once __DIR__ . '/../ThreadStorageManager.php';
require_once __DIR__ . '/../../error.php';

use ZBateson\MailMimeParser\MailMimeParser;
use ZBateson\MailMimeParser\Message;

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
            function($email, $prompt_text, $prompt_service, $extraction_id) {
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
     * Parse raw email content using Zbateson mail-mime-parser
     *
     * @param string $eml Raw email content
     * @return Message Parsed message object
     */
    public static function parseEmail(string $eml): Message {
        $parser = new MailMimeParser();
        return $parser->parse($eml, false);
    }

    /**
     * Extract content from a raw email string
     *
     * @param string $eml Raw email content
     * @return ExtractedEmailBody Extracted email body content
     */
    public static function extractContentFromEmail($eml) {
        if (empty($eml)) {
            throw new Exception("Empty email content provided for extraction");
        }

        try {
            $message = self::parseEmail($eml);
        } catch (Exception $e) {
            error_log("Error parsing email content: " . $e->getMessage() . " . EML length: " . strlen($eml));

            $email_content = new ExtractedEmailBody();
            $email_content->plain_text = "ERROR\n\n".$eml;
            $email_content->html = '<pre>' . jTraceEx($e) . '</pre>';
            return $email_content;
        }

        $email_content = new ExtractedEmailBody();

        // Zbateson handles all encoding/decoding automatically
        $plainText = $message->getTextContent();
        $html = $message->getHtmlContent();

        // Clean up extracted content
        // Zbateson handles charset conversion and always returns valid UTF-8
        if ($plainText !== null) {
            $email_content->plain_text = self::cleanText($plainText);
        } else {
            $email_content->plain_text = '';
        }

        if ($html !== null) {
            $email_content->html = self::convertHtmlToText($html);
        } else {
            $email_content->html = '';
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

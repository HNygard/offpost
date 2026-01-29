<?php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Extraction/ThreadEmailExtraction.php';
require_once __DIR__ . '/../Extraction/ThreadEmailExtractionService.php';
require_once __DIR__ . '/../Extraction/ThreadEmailExtractor.php';
require_once __DIR__ . '/../ThreadEmail.php';
require_once __DIR__ . '/../ThreadStorageManager.php';
require_once __DIR__ . '/../../error.php';
require_once __DIR__ . '/Prompts/PromptService.php';
require_once __DIR__ . '/Prompts/EmailBodyExtractionPrompt.php';

/**
 * Class for extracting text from email bodies using OpenAI API.
 *
 * This is an alternative to ThreadEmailExtractorEmailBody that uses OpenAI
 * instead of the Laminas Mail PHP library for parsing emails.
 *
 * Benefits of using OpenAI for email parsing:
 * - Better handling of malformed emails
 * - Graceful handling of encoding issues
 * - Can understand and extract content from complex MIME structures
 * - More resilient to unusual email formats
 */
class ThreadEmailExtractorEmailBodyOpenAi extends ThreadEmailExtractor {

    private PromptService $promptService;
    private EmailBodyExtractionPrompt $prompt;

    /**
     * Constructor
     *
     * @param string|null $openai_api_key OpenAI API key (optional, will use env if not provided)
     * @param ThreadEmailExtractionService|null $extractionService Extraction service instance
     */
    public function __construct(string $openai_api_key = null, ThreadEmailExtractionService $extractionService = null) {
        parent::__construct($extractionService);

        // If no API key provided, try to get it from environment or secrets
        if ($openai_api_key === null) {
            $openai_api_key = getenv('OPENAI_API_KEY');
            if (file_exists('/run/secrets/openai_api_key')) {
                $openai_api_key = trim(explode("\n", file_get_contents('/run/secrets/openai_api_key'))[1]);
            }
            if (!$openai_api_key) {
                throw new Exception("OPENAI_API_KEY environment variable is required,"
                . " or the file /run/secrets/openai_api_key must exist (mounted by docker compose)");
            }
        }

        $this->promptService = new PromptService($openai_api_key);
        $this->prompt = new EmailBodyExtractionPrompt();
    }

    /**
     * Get the number of emails that need extraction via OpenAI
     *
     * @return int Number of emails to process
     */
    public function getNumberOfEmailsToProcess() {
        // Count emails that don't have an OpenAI body extraction yet
        $query = "
            SELECT COUNT(te.id) AS email_count
            FROM thread_emails te
            LEFT JOIN thread_email_extractions tee ON te.id = tee.email_id
                AND tee.attachment_id IS NULL
                AND tee.prompt_service = 'openai'
                AND tee.prompt_id = ?
            WHERE tee.extraction_id IS NULL
        ";

        $result = Database::queryOneOrNone($query, [$this->prompt->getPromptId()]);

        return $result ? (int)$result['email_count'] : 0;
    }

    /**
     * Find the next email that needs extraction
     *
     * @return array|null Email data or null if none found
     */
    public function findNextEmailForExtraction() {
        // Find emails that don't have an OpenAI body text extraction yet
        $query = "
            SELECT te.id as email_id, te.thread_id, te.status_type, te.status_text
            FROM thread_emails te
            LEFT JOIN thread_email_extractions tee ON te.id = tee.email_id
                AND tee.attachment_id IS NULL
                AND tee.prompt_service = 'openai'
                AND tee.prompt_id = ?
            WHERE tee.extraction_id IS NULL
            ORDER BY te.datetime_received ASC
            LIMIT 1
        ";

        $row = Database::queryOneOrNone($query, [$this->prompt->getPromptId()]);

        if (!$row) {
            return null;
        }
        return $row;
    }

    /**
     * Process the next email extraction using OpenAI
     *
     * @return array Result of the operation
     */
    public function processNextEmailExtraction() {
        return $this->processNextEmailExtractionInternal(
            $this->prompt->getPromptText(),
            'openai',
            function($email, $prompt_text, $prompt_service, $extraction_id) {
                // Get the raw email content
                $eml = ThreadStorageManager::getInstance()->getThreadEmailContent(
                    $email['thread_id'],
                    $email['email_id']
                );

                if (empty($eml)) {
                    throw new Exception("Empty email content for email_id: {$email['email_id']}");
                }

                // Preprocess the EML to remove very large attachments that could exceed token limits
                $preprocessedEml = $this->preprocessEmlForOpenAi($eml);

                // Use OpenAI to extract the email body text
                $extractedText = $this->promptService->run(
                    $this->prompt,
                    $preprocessedEml,
                    $extraction_id
                );

                return $extractedText;
            },
            $this->prompt->getPromptId()
        );
    }

    /**
     * Preprocess EML content to make it suitable for OpenAI processing.
     *
     * This removes large base64 encoded attachments that would waste tokens
     * and potentially exceed context limits.
     *
     * @param string $eml Raw email content
     * @return string Preprocessed email content
     */
    private function preprocessEmlForOpenAi(string $eml): string {
        // Remove large base64 encoded blocks (likely attachments)
        // Keep the headers and text content, replace base64 with placeholder
        $result = preg_replace_callback(
            '/^(Content-Transfer-Encoding:\s*base64.*?\r?\n\r?\n)([A-Za-z0-9+\/=\r\n]{1000,})/ms',
            function($matches) {
                // Calculate approximate size
                $base64Length = strlen(preg_replace('/\s/', '', $matches[2]));
                $approximateBytes = (int)($base64Length * 0.75);
                $sizeKB = round($approximateBytes / 1024, 1);
                return $matches[1] . "[Base64 attachment removed - approximately {$sizeKB}KB]";
            },
            $eml
        );

        // Also remove inline base64 data URIs that might be in HTML
        $result = preg_replace(
            '/data:[^;]+;base64,[A-Za-z0-9+\/=]{1000,}/',
            '[Inline base64 data removed]',
            $result
        );

        // Truncate extremely long emails to prevent token overflow
        // gpt-4o-mini has 128k context, but we want to be conservative
        // Roughly 4 chars = 1 token, so 100k chars ≈ 25k tokens (leaving room for response)
        $maxLength = 100000;
        if (strlen($result) > $maxLength) {
            $result = substr($result, 0, $maxLength) . "\n\n[Email truncated due to length - " . strlen($eml) . " bytes total]";
        }

        return $result;
    }

    /**
     * Extract content from a raw email using OpenAI (static method for direct use)
     *
     * @param string $eml Raw email content
     * @param string $openai_api_key OpenAI API key
     * @return string Extracted text content
     */
    public static function extractContentFromEmailStatic(string $eml, string $openai_api_key): string {
        $promptService = new PromptService($openai_api_key);
        $prompt = new EmailBodyExtractionPrompt();

        // Preprocess the email
        $extractor = new self($openai_api_key);
        $preprocessedEml = $extractor->preprocessEmlForOpenAi($eml);

        return $promptService->run($prompt, $preprocessedEml);
    }
}

<?php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Extraction/ThreadEmailExtraction.php';
require_once __DIR__ . '/../Extraction/ThreadEmailExtractionService.php';
require_once __DIR__ . '/../Extraction/ThreadEmailExtractor.php';
require_once __DIR__ . '/../Extraction/Prompts/PromptService.php';
require_once __DIR__ . '/../ThreadEmail.php';
require_once __DIR__ . '/../Thread.php';
require_once __DIR__ . '/../ThreadUtils.php';
require_once __DIR__ . '/../../error.php';

/**
 * Base class for extracting information from emails using AI prompts
 * Uses existing extractions (email_body or attachment_pdf) as input for AI prompts
 */
abstract class ThreadEmailExtractorPrompt extends ThreadEmailExtractor {
    /**
     * @var PromptService The prompt service instance
     */
    protected $promptService;

    /**
     * @var OpenAiPrompt The prompt to use for extraction
     */
    protected $prompt;
    
    /**
     * @var array Prompt text sources to use as input
     */
    protected $inputFromPromptTextSources = null;
    
    /**
     * Constructor
     * 
     * @param ThreadEmailExtractionService $extractionService Extraction service instance
     * @param PromptService $promptService Prompt service instance
     */
    public function __construct(
        ThreadEmailExtractionService $extractionService = null,
        PromptService $promptService = null
    ) {
        parent::__construct($extractionService);
        
        // If no prompt service is provided, create one with the API key from environment
        if ($promptService === null) {
            $openai_api_key = getenv('OPENAI_API_KEY');
            if (file_exists('/run/secrets/openai_api_key')) {
                $openai_api_key = trim(explode("\n", file_get_contents('/run/secrets/openai_api_key'))[1]);
            }
            if (!$openai_api_key) {
                throw new Exception("OPENAI_API_KEY environment variable is required,"
                . " or the file /run/secrets/openai_api_key must exist (mounted by docker compose)");
            }
            $this->promptService = new PromptService($openai_api_key);
        } else {
            $this->promptService = $promptService;
        }

        // Get the prompt
        $prompts = $this->promptService->getAvailablePrompts();
        $this->prompt = $prompts[$this->getPromptId()];

        if ($this->inputFromPromptTextSources === null) {
            throw new Exception("No input sources defined for prompt " . $this->getPromptId());
        }
    }
    
    /**
     * Get the prompt ID to use for extraction
     * 
     * @return string Prompt ID
     */
    abstract protected function getPromptId(): string;
    
    /**
     * Get the number of emails that need extraction
     * 
     * @return int Number of emails to process
     */
    public function getNumberOfEmailsToProcess() {
        // Count emails that have existing extractions but don't have an extraction for this prompt yet
        $query = "
            SELECT COUNT(DISTINCT te.id) AS email_count
            FROM thread_emails te
            JOIN thread_email_extractions tee_source ON te.id = tee_source.email_id 
                AND tee_source.prompt_service = 'code'
                AND tee_source.prompt_text IN (" . implode(',', array_fill(0, count($this->inputFromPromptTextSources), '?')) . ")
            LEFT JOIN thread_email_extractions tee_target ON te.id = tee_target.email_id 
                AND tee_target.prompt_service = ?
                AND tee_target.prompt_id = ?
            WHERE tee_target.extraction_id IS NULL
        ";
        
        // Prepare parameters: first the allowed prompt text sources, then the prompt service and ID
        $params = array_merge($this->inputFromPromptTextSources, [$this->prompt->getPromptService(), $this->prompt->getPromptId()]);
        
        $result = Database::queryOneOrNone($query, $params);
        
        return $result ? (int)$result['email_count'] : 0;
    }
    
    /**
     * Find the next email that needs extraction
     * 
     * @return array|null Email data or null if none found
     */
    public function findNextEmailForExtraction() {
        // First, check for emails that have an extraction with the same prompt_service 
        // but different prompt_id (old/outdated prompt version)
        // We need to delete these to trigger re-extraction with the new prompt
        $deleteOldQuery = "
            SELECT tee_old.extraction_id
            FROM thread_emails te
            JOIN thread_email_extractions tee_source
                ON te.id = tee_source.email_id
                AND tee_source.prompt_service = 'code'
                AND tee_source.prompt_text IN (" . implode(',', array_fill(0, count($this->inputFromPromptTextSources), '?')) . ")
            JOIN thread_email_extractions tee_old
                ON te.id = tee_old.email_id
                AND tee_old.prompt_service = ?
                AND (tee_old.prompt_id IS NULL OR tee_old.prompt_id != ?)
            WHERE tee_source.extracted_text IS NOT NULL
            LIMIT 1
        ";
        
        $deleteParams = array_merge(
            $this->inputFromPromptTextSources, 
            [$this->prompt->getPromptService(), $this->prompt->getPromptId()]
        );
        
        $oldExtraction = Database::queryOneOrNone($deleteOldQuery, $deleteParams);
        
        if ($oldExtraction) {
            // Delete the old extraction to trigger re-processing
            require_once __DIR__ . '/ThreadEmailExtractionService.php';
            $extractionService = new ThreadEmailExtractionService();
            $extractionService->deleteExtraction($oldExtraction['extraction_id']);
            error_log("Deleted old extraction {$oldExtraction['extraction_id']} with outdated prompt_id to trigger re-extraction");
        }
        
        // Find emails that have existing extractions but don't have an extraction for this prompt yet
        $query = "
            SELECT 
                te.id as email_id,
                te.thread_id,
                te.email_type,
                te.datetime_received,

                tee_source.extraction_id as source_extraction_id,
                tee_source.extracted_text as source_extracted_text,
                tee_source.prompt_text as source_prompt_text,
                tee_source.attachment_id as source_attachment_id,

                t.title as thread_title,
                t.entity_id as thread_entity_id,
                t.my_name as thread_my_name,
                t.my_email as thread_my_email

            FROM thread_emails te

            JOIN threads t ON te.thread_id = t.id

            JOIN thread_email_extractions tee_source
                ON te.id = tee_source.email_id
                AND tee_source.prompt_service = 'code'
                AND tee_source.prompt_text IN (" . implode(',', array_fill(0, count($this->inputFromPromptTextSources), '?')) . ")
            
            -- Check if the target extraction already exists
            LEFT JOIN thread_email_extractions tee_target
                ON te.id = tee_target.email_id
                AND tee_target.prompt_service = ?
                AND tee_target.prompt_id = ?
            WHERE tee_target.extraction_id IS NULL
                AND tee_source.extracted_text IS NOT NULL
            ORDER BY 
                CASE WHEN te.datetime_received >= '2025-01-01' THEN 0 ELSE 1 END,
                te.datetime_received ASC
            LIMIT 1
        ";
        
        // Prepare parameters: first the allowed prompt text sources, then the prompt service and ID
        $params = array_merge($this->inputFromPromptTextSources, [$this->prompt->getPromptService(), $this->prompt->getPromptId()]);
        
        $row = Database::queryOneOrNone($query, $params);
        
        if (!$row) {
            return null;
        }
        return $row;
    }
    
    /**
     * Process the next email for extraction
     * 
     * @return array Result of the operation
     */
    public function processNextEmailExtraction() {
        
        return $this->processNextEmailExtractionInternal(
            $this->prompt->getPromptText(),
            $this->prompt->getPromptService(),
            function($row, $prompt_text, $prompt_service) {
                // Prepare input for the prompt
                $promptInput = $this->preparePromptInput($row);
                
                // Run the prompt against the prepared input
                $response = $this->promptService->run($this->prompt, $promptInput);
                
                // Return the extracted text (AI response)
                return $response;
            },
            $this->getPromptId()
        );
    }
    
    /**
     * Prepare input for the prompt based on email data and existing extractions
     * 
     * @param array $email Email data including source extraction and pre-extracted email details
     * @return string Prepared input for the prompt
     */
    protected function preparePromptInput($row) {
        // Start with the extracted text from the source extraction
        $input = $row['source_extracted_text'];
        
        // Use pre-extracted email details
        $subject = $row['email_subject'];
        $fromAddress = $row['email_from_address'];
        $toAddresses = $row['email_to_addresses'];
        $ccAddresses = $row['email_cc_addresses'];
        
        // Add basic email and thread details
        $details = [
            'Thread Details:',
            '- Thread title: ' . $row['thread_title'],
            '- Thread entity ID: ' . $row['thread_entity_id'],
            '- Thread my name: ' . $row['thread_my_name'],
            '- Thread my email: ' . $row['thread_my_email'],
            'Email Details:',
            '- Date: ' . $row['datetime_received'],
        ];
        
        // Add subject
        $details[] = '- Subject: ' . $subject;
        
        $details[] = '- Direction: ' . $row['email_type'];
        
        // Add from/to/cc details if available
        if (!empty($fromAddress)) {
            $details[] = '- From: ' . $fromAddress;
        }
        if (!empty($toAddresses)) {
            $details[] = '- To: ' . implode(', ', $toAddresses);
        }
        if (!empty($ccAddresses)) {
            $details[] = '- CC: ' . implode(', ', $ccAddresses);
        }
        
        if ($row['source_prompt_text'] === 'attachment_pdf') {
            $details[] =  '- Source: PDF Attachment';
        }
        if ($row['source_prompt_text'] === 'email_body') {
            $details[] =  '- Source: Email body';
        }
        
        // Add the details at the beginning of the input
        $input = implode("\n", $details) . "\n\n" . $input;
        
        return $input;
    }
}

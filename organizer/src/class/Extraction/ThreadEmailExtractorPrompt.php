<?php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Extraction/ThreadEmailExtraction.php';
require_once __DIR__ . '/../Extraction/ThreadEmailExtractionService.php';
require_once __DIR__ . '/../Extraction/ThreadEmailExtractor.php';
require_once __DIR__ . '/../Extraction/Prompts/PromptService.php';
require_once __DIR__ . '/../ThreadEmail.php';
require_once __DIR__ . '/../Thread.php';
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
                AND tee_source.prompt_text IN ('email_body', 'attachment_pdf')
            LEFT JOIN thread_email_extractions tee_target ON te.id = tee_target.email_id 
                AND tee_target.prompt_service = 'openai'
                AND tee_target.prompt_id = ?
            WHERE tee_target.extraction_id IS NULL
        ";
        
        $result = Database::queryOneOrNone($query, [$this->getPromptId()]);
        
        return $result ? (int)$result['email_count'] : 0;
    }
    
    /**
     * Find the next email that needs extraction
     * 
     * @return array|null Email data or null if none found
     */
    public function findNextEmailForExtraction() {
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
                AND tee_source.prompt_text IN ('email_body', 'attachment_pdf')
            
            -- Check if the target extraction already exists
            LEFT JOIN thread_email_extractions tee_target
                ON te.id = tee_target.email_id
                AND tee_target.prompt_service = ?
                AND tee_target.prompt_id = ?
            WHERE tee_target.extraction_id IS NULL
                AND tee_source.extracted_text IS NOT NULL
            ORDER BY te.datetime_received ASC
            LIMIT 1
        ";
        
        $row = Database::queryOneOrNone($query, [$this->prompt->getPromptService(), $this->getPromptId()]);
        
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
     * @param array $email Email data including source extraction
     * @return string Prepared input for the prompt
     */
    protected function preparePromptInput($row) {
        // Start with the extracted text from the source extraction
        $input = $row['source_extracted_text'];
        
        // Add basic email and thread details
        $details = [
            'Thread Details:',
            '- Thread title: ' . $row['thread_title'],
            '- Thread entity ID: ' . $row['thread_entity_id'],
            '- Thread my name: ' . $row['thread_my_name'],
            '- Thread my email: ' . $row['thread_my_email'],
            'Email Details:',
            '- Date: ' . $row['datetime_received'],
            // TODO: Make subject available
            //'- Subject: ' . $row['subject'],
            '- Direction: ' . $row['email_type'],
            // TODO: Make to/from/cc as fields on thread_emails. 
            //'- From name: ' . $row['from_name'],
            //'- From: ' . $row['from_address'],
            //'- To: ' . $row['to_address'],
        ];
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

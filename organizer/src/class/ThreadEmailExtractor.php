<?php

abstract class ThreadEmailExtractor {

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
     * Get the number of emails that need extraction
     * 
     * @return int Number of emails to process
     */
    public abstract function getNumberOfEmailsToProcess();

    /**
     * Process the next email extraction
     *
     * @return array The result of the email extraction process
     */
    public abstract function processNextEmailExtraction();


    /**
     * Process the next email extraction
     * 
     * @return array Result of the operation
     */
    protected function processNextEmailExtractionInternal($prompt_text,
    $prompt_service, $extract_text_function) {
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
                $prompt_text,
                $prompt_service
            );

            $extractedText = $extract_text_function($email, $prompt_text, $prompt_service);
            
            // Update extraction with results
            $updatedExtraction = $this->extractionService->updateExtractionResults(
                $extraction->extraction_id,
                $extractedText
            );
            
            return [
                'success' => true,
                'message' => 'Successfully extracted text from email',
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
                    jTraceEx($e)
                );
            }
            
            return [
                'success' => false,
                'message' => 'Failed to extract text from email.',
                'email_id' => $email['id'],
                'thread_id' => $email['thread_id'],
                'error' => $e->getMessage()
            ];
        }
    }
}
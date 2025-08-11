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
     * Get additional email data (e.g., imap_headers) for a given email ID
     * 
     * @param string $emailId The email ID to fetch data for
     * @return array|null Email data or null if not found
     */
    protected function getEmailData($emailId) {
        require_once __DIR__ . '/../Database.php';
        
        $query = "SELECT imap_headers FROM thread_emails WHERE id = ?";
        $result = Database::queryOneOrNone($query, [$emailId]);
        
        return $result;
    }

    /**
     * Process the next email extraction
     * 
     * @return array Result of the operation
     */
    protected function processNextEmailExtractionInternal($prompt_text,
    $prompt_service, $extract_text_function, $prompt_id = null) {
        // Find the next email that needs extraction
        $email = $this->findNextEmailForExtraction();
        
        if (!$email) {
            return [
                'success' => false,
                'message' => 'No emails found that need extraction'
            ];
        }
        
        // Fetch additional email data (imap_headers) for this email
        $emailData = $this->getEmailData($email['email_id']);
        if ($emailData) {
            // Extract email details from imap_headers if available
            require_once __DIR__ . '/../ThreadUtils.php';
            
            $emailData['email_subject'] = isset($emailData['imap_headers']) ? getEmailSubjectFromImapHeaders($emailData['imap_headers']) : '';
            $emailData['email_from_address'] = isset($emailData['imap_headers']) ? getEmailFromAddressFromImapHeaders($emailData['imap_headers']) : '';
            $emailData['email_to_addresses'] = isset($emailData['imap_headers']) ? getEmailToAddressesFromImapHeaders($emailData['imap_headers']) : [];
            $emailData['email_cc_addresses'] = isset($emailData['imap_headers']) ? getEmailCcAddressesFromImapHeaders($emailData['imap_headers']) : [];
            
            // Merge the additional data into the email array
            $email = array_merge($email, $emailData);
        } else {
            // Ensure email detail fields are always present with default values
            $email['email_subject'] = '';
            $email['email_from_address'] = '';
            $email['email_to_addresses'] = [];
            $email['email_cc_addresses'] = [];
        }
        
        try {
            // Create extraction record
            $extraction = $this->extractionService->createExtraction(
                $email['email_id'],
                $prompt_text,
                $prompt_service,
                $email['attachment_id'] ?? null,
                $prompt_id
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
                'email_id' => $email['email_id'],
                'thread_id' => $email['thread_id'],
                'attachment_id' => $email['attachment_id'] ?? null,
                'extraction_id' => $extraction->extraction_id,
                'extracted_text_length' => strlen($extractedText)
            ];
        } catch (\Exception $e) {
            if (!defined('PHPUNIT_RUNNING')) {
                echo "Error processing email: [email_id={$email['email_id']}]. [attachment_id=" . ($email['attachment_id'] ?? null) . "] " . $e->getMessage() . "\n";
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
                'email_id' => $email['email_id'],
                'thread_id' => $email['thread_id'],
                'attachment_id' => $email['attachment_id'] ?? null,
                'extraction_id' => $extraction->extraction_id ?? null,
                'error' => $e->getMessage()
            ];
        }
    }
}
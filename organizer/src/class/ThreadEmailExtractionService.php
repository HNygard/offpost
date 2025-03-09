<?php

require_once __DIR__ . '/ThreadEmailExtraction.php';
require_once __DIR__ . '/Database.php';

/**
 * Service class for creating and updating ThreadEmailExtraction records
 * Used as foundation for automatic classification and follow up
 */
class ThreadEmailExtractionService {
    /**
     * Create a new ThreadEmailExtraction record
     * 
     * @param string $emailId The UUID of the email
     * @param string $promptText The text of the prompt used for extraction
     * @param string $promptService The service used for the extraction (e.g., 'openai', 'azure')
     * @param int|null $attachmentId The ID of the attachment (if applicable)
     * @param string|null $promptId An identifier for the prompt used
     * @return ThreadEmailExtraction The created extraction object
     * @throws Exception If the extraction could not be created
     */
    public function createExtraction(
        string $emailId, 
        string $promptText, 
        string $promptService, 
        ?int $attachmentId = null, 
        ?string $promptId = null
    ): ThreadEmailExtraction {
        try {
            // Validate required parameters
            if (empty($emailId)) {
                throw new Exception("Email ID is required");
            }
            if (empty($promptText)) {
                throw new Exception("Prompt text is required");
            }
            if (empty($promptService)) {
                throw new Exception("Prompt service is required");
            }

            // Create extraction record in database
            $sql = "INSERT INTO thread_email_extractions 
                    (email_id, attachment_id, prompt_id, prompt_text, prompt_service) 
                    VALUES (?, ?, ?, ?, ?) 
                    RETURNING extraction_id, created_at, updated_at";
            
            $params = [$emailId, $attachmentId, $promptId, $promptText, $promptService];
            $result = Database::queryOne($sql, $params);

            if (!$result) {
                throw new Exception("Failed to create extraction record");
            }

            // Create and return ThreadEmailExtraction object
            $extraction = new ThreadEmailExtraction();
            $extraction->extraction_id = $result['extraction_id'];
            $extraction->email_id = $emailId;
            $extraction->attachment_id = $attachmentId;
            $extraction->prompt_id = $promptId;
            $extraction->prompt_text = $promptText;
            $extraction->prompt_service = $promptService;
            $extraction->created_at = $result['created_at'];
            $extraction->updated_at = $result['updated_at'];

            return $extraction;
        } catch (Exception $e) {
            throw new Exception("Error creating extraction: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Update an existing ThreadEmailExtraction record with extraction results
     * 
     * @param int $extractionId The ID of the extraction to update
     * @param string|null $extractedText The extracted text content
     * @param string|null $errorMessage Any error message that occurred during extraction
     * @return ThreadEmailExtraction The updated extraction object
     * @throws Exception If the extraction could not be updated
     */
    public function updateExtractionResults(
        int $extractionId, 
        ?string $extractedText = null, 
        ?string $errorMessage = null
    ): ThreadEmailExtraction {
        // Validate parameters
        if (empty($extractionId)) {
            throw new Exception("Extraction ID is required");
        }
        if ($extractedText === null && $errorMessage === null) {
            throw new Exception("Either extracted text or error message must be provided");
        }

        // Update extraction record in database
        $sql = "UPDATE thread_email_extractions 
                SET extracted_text = ?, error_message = ? 
                WHERE extraction_id = ? 
                RETURNING *";
        
        $params = [$extractedText, $errorMessage, $extractionId];
        $result = Database::queryOne($sql, $params);

        if (!$result) {
            throw new Exception("Failed to update extraction record");
        }

        // Create and return updated ThreadEmailExtraction object
        $extraction = new ThreadEmailExtraction();
        $extraction->extraction_id = $result['extraction_id'];
        $extraction->email_id = $result['email_id'];
        $extraction->attachment_id = $result['attachment_id'];
        $extraction->prompt_id = $result['prompt_id'];
        $extraction->prompt_text = $result['prompt_text'];
        $extraction->prompt_service = $result['prompt_service'];
        $extraction->extracted_text = $result['extracted_text'];
        $extraction->error_message = $result['error_message'];
        $extraction->created_at = $result['created_at'];
        $extraction->updated_at = $result['updated_at'];

        return $extraction;
    }

    /**
     * Get a ThreadEmailExtraction by its ID
     * 
     * @param int $extractionId The ID of the extraction to retrieve
     * @return ThreadEmailExtraction|null The extraction object or null if not found
     * @throws Exception If there was an error retrieving the extraction
     */
    public function getExtractionById(int $extractionId): ?ThreadEmailExtraction {
        try {
            $sql = "SELECT * FROM thread_email_extractions WHERE extraction_id = ?";
            $result = Database::query($sql, [$extractionId]);

            if (empty($result)) {
                return null;
            }

            $row = $result[0];
            $extraction = new ThreadEmailExtraction();
            $extraction->extraction_id = $row['extraction_id'];
            $extraction->email_id = $row['email_id'];
            $extraction->attachment_id = $row['attachment_id'];
            $extraction->prompt_id = $row['prompt_id'];
            $extraction->prompt_text = $row['prompt_text'];
            $extraction->prompt_service = $row['prompt_service'];
            $extraction->extracted_text = $row['extracted_text'];
            $extraction->error_message = $row['error_message'];
            $extraction->created_at = $row['created_at'];
            $extraction->updated_at = $row['updated_at'];

            return $extraction;
        } catch (Exception $e) {
            throw new Exception("Error retrieving extraction: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get all ThreadEmailExtractions for a specific email
     * 
     * @param string $emailId The UUID of the email
     * @return array An array of ThreadEmailExtraction objects
     * @throws Exception If there was an error retrieving the extractions
     */
    public function getExtractionsForEmail(string $emailId): array {
        try {
            $sql = "SELECT * FROM thread_email_extractions WHERE email_id = ? ORDER BY created_at DESC";
            $results = Database::query($sql, [$emailId]);

            $extractions = [];
            foreach ($results as $row) {
                $extraction = new ThreadEmailExtraction();
                $extraction->extraction_id = $row['extraction_id'];
                $extraction->email_id = $row['email_id'];
                $extraction->attachment_id = $row['attachment_id'];
                $extraction->prompt_id = $row['prompt_id'];
                $extraction->prompt_text = $row['prompt_text'];
                $extraction->prompt_service = $row['prompt_service'];
                $extraction->extracted_text = $row['extracted_text'];
                $extraction->error_message = $row['error_message'];
                $extraction->created_at = $row['created_at'];
                $extraction->updated_at = $row['updated_at'];
                
                $extractions[] = $extraction;
            }

            return $extractions;
        } catch (Exception $e) {
            throw new Exception("Error retrieving extractions for email: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get all ThreadEmailExtractions for a specific attachment
     * 
     * @param int $attachmentId The ID of the attachment
     * @return array An array of ThreadEmailExtraction objects
     * @throws Exception If there was an error retrieving the extractions
     */
    public function getExtractionsForAttachment(int $attachmentId): array {
        try {
            $sql = "SELECT * FROM thread_email_extractions WHERE attachment_id = ? ORDER BY created_at DESC";
            $results = Database::query($sql, [$attachmentId]);

            $extractions = [];
            foreach ($results as $row) {
                $extraction = new ThreadEmailExtraction();
                $extraction->extraction_id = $row['extraction_id'];
                $extraction->email_id = $row['email_id'];
                $extraction->attachment_id = $row['attachment_id'];
                $extraction->prompt_id = $row['prompt_id'];
                $extraction->prompt_text = $row['prompt_text'];
                $extraction->prompt_service = $row['prompt_service'];
                $extraction->extracted_text = $row['extracted_text'];
                $extraction->error_message = $row['error_message'];
                $extraction->created_at = $row['created_at'];
                $extraction->updated_at = $row['updated_at'];
                
                $extractions[] = $extraction;
            }

            return $extractions;
        } catch (Exception $e) {
            throw new Exception("Error retrieving extractions for attachment: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Delete a ThreadEmailExtraction by its ID
     * 
     * @param int $extractionId The ID of the extraction to delete
     * @return bool True if the extraction was deleted, false otherwise
     * @throws Exception If there was an error deleting the extraction
     */
    public function deleteExtraction(int $extractionId): bool {
        try {
            $sql = "DELETE FROM thread_email_extractions WHERE extraction_id = ?";
            $rowCount = Database::execute($sql, [$extractionId]);
            
            return $rowCount > 0;
        } catch (Exception $e) {
            throw new Exception("Error deleting extraction: " . $e->getMessage(), 0, $e);
        }
    }
}

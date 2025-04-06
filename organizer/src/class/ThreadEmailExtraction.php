<?php

/**
 * Represents a text extraction from an email or attachment
 * Used as foundation for automatic classification and follow up
 */
class ThreadEmailExtraction {
    /**
     * @var int The unique identifier for the extraction
     */
    var $extraction_id;
    
    /**
     * @var string The UUID of the email this extraction is associated with
     */
    var $email_id;
    
    /**
     * @var string|null The UUID of the attachment this extraction is associated with (if applicable)
     */
    var $attachment_id;
    
    /**
     * @var string|null An identifier for the prompt used for extraction
     */
    var $prompt_id;
    
    /**
     * @var string The text of the prompt used for extraction
     */
    var $prompt_text;
    
    /**
     * @var string The service used for the extraction (e.g., 'openai', 'azure', etc.)
     */
    var $prompt_service;
    
    /**
     * @var string|null The extracted text content
     */
    var $extracted_text;
    
    /**
     * @var string|null Any error message that occurred during extraction
     */
    var $error_message;
    
    /**
     * @var string The timestamp when the extraction was created
     */
    var $created_at;
    
    /**
     * @var string The timestamp when the extraction was last updated
     */
    var $updated_at;
}

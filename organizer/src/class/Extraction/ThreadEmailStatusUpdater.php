<?php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../ThreadEmail.php';
require_once __DIR__ . '/ThreadEmailExtractionService.php';
require_once __DIR__ . '/../Enums/ThreadEmailStatusType.php';

use App\Enums\ThreadEmailStatusType;

/**
 * Service for updating ThreadEmail status fields based on AI extraction results
 * This service handles updating description, status_text, and status_type 
 * but only if the email hasn't been manually classified
 */
class ThreadEmailStatusUpdater {
    
    private ThreadEmailExtractionService $extractionService;

    public function __construct(ThreadEmailExtractionService $extractionService = null) {
        $this->extractionService = $extractionService ?? new ThreadEmailExtractionService();
    }

    /**
     * Update ThreadEmail status based on AI summary extraction
     * Only updates if the email has unknown status (not manually classified)
     * 
     * @param int $emailId The email ID to update
     * @param string $aiSummary The AI-generated summary
     * @return bool True if update was performed, false if skipped
     * @throws Exception If update fails
     */
    public function updateFromAISummary(int $emailId, string $aiSummary): bool {
        if (empty($emailId) || empty($aiSummary)) {
            throw new Exception("Email ID and AI summary are required");
        }

        // Check if email is already manually classified
        if ($this->isManuallyClassified($emailId)) {
            return false; // Skip update - human classification takes precedence
        }

        // Update the email with AI-derived information
        $sql = "UPDATE thread_emails 
                SET 
                    description = ?,
                    status_text = ?,
                    status_type = ?,
                    auto_classification = 'prompt'
                WHERE id = ?
                    AND (status_type = ? OR status_type = ?)"; // Only update if currently unknown

        $statusType = $this->determineStatusTypeFromSummary($aiSummary);
        $statusText = $this->generateStatusTextFromSummary($aiSummary);

        $params = [
            $aiSummary, // description
            $statusText, // status_text
            $statusType->value, // status_type
            $emailId,
            ThreadEmailStatusType::UNKNOWN->value,
            'unknown' // Legacy unknown value
        ];

        $result = Database::query($sql, $params);
        
        if ($result === false) {
            throw new Exception("Failed to update thread email status");
        }

        return true;
    }

    /**
     * Check if an email has been manually classified
     * 
     * @param int $emailId Email ID to check
     * @return bool True if manually classified
     */
    private function isManuallyClassified(int $emailId): bool {
        $sql = "SELECT auto_classification, status_type 
                FROM thread_emails 
                WHERE id = ?";
        
        $result = Database::queryOne($sql, [$emailId]);
        
        if (!$result) {
            return false;
        }

        // If there's no auto_classification, it means it was manually set
        // If status_type is not unknown, and auto_classification is not set, it's manual
        return empty($result['auto_classification']) && 
               $result['status_type'] !== ThreadEmailStatusType::UNKNOWN->value &&
               $result['status_type'] !== 'unknown';
    }

    /**
     * Determine appropriate status type based on AI summary content
     * 
     * @param string $summary AI-generated summary
     * @return ThreadEmailStatusType Appropriate status type
     */
    private function determineStatusTypeFromSummary(string $summary): ThreadEmailStatusType {
        $summary_lower = mb_strtolower($summary, 'UTF-8');

        // Look for keywords that indicate specific status types
        // Order matters - check more specific patterns first
        
        if (preg_match('/\b(mer tid|utsette|forlenge|frist)\b/u', $summary_lower)) {
            return ThreadEmailStatusType::ASKING_FOR_MORE_TIME;
        }
        
        if (preg_match('/\b(kopi|kopi av|kan vi få|send|videresend)\b/u', $summary_lower)) {
            return ThreadEmailStatusType::ASKING_FOR_COPY;
        }
        
        if (preg_match('/\b(avslag|avslår|kan ikke|avvise)\b/u', $summary_lower)) {
            return ThreadEmailStatusType::REQUEST_REJECTED;
        }
        
        if (preg_match('/\b(sendt|vedlagt|informasjon|dokumenter|vedlegg)\b/u', $summary_lower)) {
            return ThreadEmailStatusType::INFORMATION_RELEASE;
        }

        // Default to unknown for now - could be enhanced with more sophisticated analysis
        return ThreadEmailStatusType::UNKNOWN;
    }

    /**
     * Generate appropriate status text from AI summary
     * 
     * @param string $summary AI-generated summary
     * @return string Appropriate status text
     */
    private function generateStatusTextFromSummary(string $summary): string {
        // For now, use the first 50 characters of the summary as status text
        // This could be enhanced to be more intelligent
        if (mb_strlen($summary, 'UTF-8') <= 50) {
            return $summary;
        }
        
        // Try to break at word boundary
        $truncated = mb_substr($summary, 0, 47, 'UTF-8');
        $lastSpace = mb_strrpos($truncated, ' ', 0, 'UTF-8');
        
        if ($lastSpace !== false && $lastSpace > 30) {
            return mb_substr($summary, 0, $lastSpace, 'UTF-8') . '...';
        }
        
        return $truncated . '...';
    }

    /**
     * Update multiple emails from extraction results
     * 
     * @param string $promptId The prompt ID to process
     * @param int $limit Maximum number of emails to process
     * @return array Summary of processing results
     */
    public function processExtractionResults(string $promptId = 'thread-email-summary', int $limit = 10): array {
        $sql = "SELECT 
                    e.email_id,
                    e.extracted_text,
                    e.extraction_id
                FROM thread_email_extractions e
                JOIN thread_emails te ON e.email_id = te.id
                WHERE e.prompt_id = ?
                    AND e.extracted_text IS NOT NULL
                    AND e.error_message IS NULL
                    AND (te.status_type = ? OR te.status_type = ?)
                    AND (te.auto_classification IS NULL OR te.auto_classification != 'prompt')
                ORDER BY e.created_at DESC
                LIMIT ?";

        $results = Database::query($sql, [
            $promptId,
            ThreadEmailStatusType::UNKNOWN->value,
            'unknown',
            $limit
        ]);

        $processed = 0;
        $skipped = 0;
        $errors = [];

        foreach ($results as $row) {
            try {
                $updated = $this->updateFromAISummary($row['email_id'], $row['extracted_text']);
                if ($updated) {
                    $processed++;
                } else {
                    $skipped++;
                }
            } catch (Exception $e) {
                $errors[] = "Email {$row['email_id']}: " . $e->getMessage();
            }
        }

        return [
            'processed' => $processed,
            'skipped' => $skipped,
            'errors' => $errors,
            'total_found' => count($results)
        ];
    }
}
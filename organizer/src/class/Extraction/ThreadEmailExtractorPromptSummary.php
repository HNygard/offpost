<?php

require_once __DIR__ . '/../Extraction/ThreadEmailExtractorPrompt.php';
require_once __DIR__ . '/../Extraction/ThreadEmailStatusUpdater.php';

class ThreadEmailExtractorPromptSummary extends ThreadEmailExtractorPrompt {
    
    protected $inputFromPromptTextSources = ['email_body'];

    protected function getPromptId(): string {
        return 'thread-email-summary';
    }

    /**
     * After a successful summary extraction, feed it into email classification.
     * ThreadEmailStatusUpdater existed since the extraction work but was never
     * called from anywhere - manual classification always wins (the updater
     * only touches emails whose status is unknown).
     */
    public function processNextEmailExtraction() {
        $result = parent::processNextEmailExtraction();
        if (!empty($result['success']) && !empty($result['email_id']) && !empty($result['extraction_id'])) {
            $this->applySummaryClassification($result['email_id'], $result['extraction_id']);
        }
        return $result;
    }

    /**
     * Classify an incoming email from its stored summary extraction.
     * Split out from processNextEmailExtraction so it is testable without
     * running the OpenAI prompt.
     *
     * @return bool True when a classification was applied
     */
    public function applySummaryClassification(string $emailId, int $extractionId): bool {
        $emailType = Database::queryValue(
            "SELECT email_type FROM thread_emails WHERE id = ?", [$emailId]);
        if ($emailType !== 'IN') {
            // Outgoing mail is our own request; the summary keywords would misclassify it.
            return false;
        }
        $extraction = $this->extractionService->getExtractionById($extractionId);
        if ($extraction === null || $extraction->extracted_text === null
            || trim($extraction->extracted_text) === '') {
            return false;
        }
        $updater = new ThreadEmailStatusUpdater($this->extractionService);
        return $updater->updateFromAISummary($emailId, $extraction->extracted_text);
    }
}

<?php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../ThreadEmail.php';
require_once __DIR__ . '/ThreadEmailExtractionService.php';
require_once __DIR__ . '/../Enums/ThreadEmailStatusType.php';
require_once __DIR__ . '/../ThreadEmailResponseClassifier.php';
require_once __DIR__ . '/../ThreadUtils.php';

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
     * Only updates if the email is not manually classified. An earlier automatic
     * classification ('algo' or 'prompt') is replaced, so improved rules can be
     * re-applied (bin/backfill-email-classification-from-summaries.php --include-auto).
     *
     * @param string $emailId The email ID to update (UUID)
     * @param string $aiSummary The AI-generated summary
     * @return bool True if update was performed, false if skipped
     * @throws Exception If update fails
     */
    public function updateFromAISummary(string $emailId, string $aiSummary): bool {
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
                    AND (status_type IS NULL OR status_type = ? OR status_type = ? OR auto_classification IS NOT NULL)"; // Never overwrite a manual classification

        $statusType = $this->determineStatusType($emailId, $aiSummary);
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
     * @param string $emailId Email ID to check (UUID)
     * @return bool True if manually classified
     */
    private function isManuallyClassified(string $emailId): bool {
        $sql = "SELECT auto_classification, status_type 
                FROM thread_emails 
                WHERE id = ?";
        
        $result = Database::queryOne($sql, [$emailId]);
        
        if (!$result) {
            return false;
        }

        // NULL/unknown status = never classified. Otherwise: a status without
        // auto_classification was set manually and must not be overwritten.
        if ($result['status_type'] === null
            || $result['status_type'] === ThreadEmailStatusType::UNKNOWN->value
            || $result['status_type'] === 'unknown') {
            return false;
        }
        return empty($result['auto_classification']);
    }

    /**
     * Classify an unclassified incoming email from subject and attachments
     * only, without waiting for the AI summary. Catches auto-replies and emails
     * that are just a document from the entity's archive system, which often
     * have no body text for the summary prompt to work on.
     *
     * The email is marked auto_classification 'algo' also when the rules find
     * nothing, so classifyPendingByRules() does not pick it up again. A later AI
     * summary still replaces it (updateFromAISummary).
     *
     * @return ThreadEmailStatusType|null The status set, or null when skipped
     */
    public function classifyByRules(string $emailId): ?ThreadEmailStatusType {
        // The PDF text decides release vs. refusal. Wait for the attachment_pdf
        // extraction (it runs on the same cron), but not forever: after a day,
        // classify without it.
        $waitingForPdfText = Database::queryValue(
            "SELECT COUNT(*) FROM thread_email_attachments a
               JOIN thread_emails e ON e.id = a.email_id
              WHERE a.email_id = ? AND a.filetype = 'pdf' -- same filter as ThreadEmailExtractorAttachmentPdf
                AND e.timestamp_received > NOW() - INTERVAL '1 day'
                AND NOT EXISTS (SELECT 1 FROM thread_email_extractions x
                                 WHERE x.attachment_id = a.id AND x.prompt_text = 'attachment_pdf')",
            [$emailId]
        );
        if ((int)$waitingForPdfText > 0) {
            return null;
        }

        $statusType = $this->determineStatusType($emailId, null);
        $statusText = match ($statusType) {
            ThreadEmailStatusType::REQUEST_RECEIPT => 'Automatisk svar / mottaksbekreftelse',
            ThreadEmailStatusType::INFORMATION_RELEASE => 'Dokumenter mottatt',
            default => null,
        };

        // Only touch emails nobody (human, rules or AI) has classified yet.
        $rows = Database::query(
            "UPDATE thread_emails
                SET status_type = ?,
                    status_text = COALESCE(?, status_text),
                    auto_classification = 'algo'
              WHERE id = ?
                AND email_type = 'IN'
                AND (status_type IS NULL OR status_type = ? OR status_type = ?)
                AND auto_classification IS NULL
              RETURNING id",
            [
                $statusType->value,
                $statusText,
                $emailId,
                ThreadEmailStatusType::UNKNOWN->value,
                'unknown',
            ]
        );
        return count($rows) > 0 ? $statusType : null;
    }

    /**
     * Run classifyByRules() on unclassified incoming emails, newest first.
     *
     * @return array{found: int, classified: array<string,int>}
     */
    public function classifyPendingByRules(int $limit = 50): array {
        $rows = Database::query(
            "SELECT id FROM thread_emails
              WHERE email_type = 'IN'
                AND COALESCE(ignore, false) = false
                AND (status_type IS NULL OR status_type = ? OR status_type = ?)
                AND auto_classification IS NULL
              ORDER BY timestamp_received DESC
              LIMIT ?",
            [ThreadEmailStatusType::UNKNOWN->value, 'unknown', $limit]
        );

        $classified = [];
        foreach ($rows as $row) {
            $statusType = $this->classifyByRules($row['id']);
            if ($statusType !== null) {
                $classified[$statusType->value] = ($classified[$statusType->value] ?? 0) + 1;
            }
        }
        ksort($classified);
        return ['found' => count($rows), 'classified' => $classified];
    }

    /**
     * Subject, attachment types and PDF text from the database, plus the summary
     * (if any), through ThreadEmailResponseClassifier.
     */
    private function determineStatusType(string $emailId, ?string $aiSummary): ThreadEmailStatusType {
        $imapHeaders = Database::queryValue("SELECT imap_headers FROM thread_emails WHERE id = ?", [$emailId]);
        $subject = ($imapHeaders !== null && $imapHeaders !== false) ? getEmailSubjectFromImapHeaders($imapHeaders) : '';
        $filetypes = array_column(
            Database::query("SELECT filetype FROM thread_email_attachments WHERE email_id = ?", [$emailId]),
            'filetype'
        );
        $attachmentTexts = array_column(Database::query(
            "SELECT extracted_text FROM thread_email_extractions
              WHERE email_id = ? AND attachment_id IS NOT NULL AND prompt_text = 'attachment_pdf'
                AND extracted_text IS NOT NULL",
            [$emailId]
        ), 'extracted_text');
        return ThreadEmailResponseClassifier::classifyIncoming(
            $subject !== '' ? $subject : null,
            $aiSummary,
            $filetypes,
            $attachmentTexts
        );
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
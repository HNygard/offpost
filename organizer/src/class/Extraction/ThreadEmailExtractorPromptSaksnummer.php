<?php

require_once __DIR__ . '/../Extraction/ThreadEmailExtractorPrompt.php';

/**
 * Class for extracting case numbers (saksnummer) from emails using AI
 * Uses existing extractions as input for the SaksnummerPrompt
 */
class ThreadEmailExtractorPromptSaksnummer extends ThreadEmailExtractorPrompt {
    protected function getPromptId(): string {
        return 'saksnummer';
    }
}

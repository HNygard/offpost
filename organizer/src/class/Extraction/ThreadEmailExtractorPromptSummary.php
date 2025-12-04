<?php

require_once __DIR__ . '/../Extraction/ThreadEmailExtractorPrompt.php';

class ThreadEmailExtractorPromptSummary extends ThreadEmailExtractorPrompt {
    
    protected $inputFromPromptTextSources = ['email_body'];

    protected function getPromptId(): string {
        return 'thread-email-summary';
    }
}
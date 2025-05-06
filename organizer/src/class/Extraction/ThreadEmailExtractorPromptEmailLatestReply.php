<?php

require_once __DIR__ . '/../Extraction/ThreadEmailExtractorPrompt.php';

class ThreadEmailExtractorPromptEmailLatestReply extends ThreadEmailExtractorPrompt {
    protected $allowedPromptTextSources = ['email_body'];

    /**
     * Get the prompt ID to use for extraction
     * 
     * @return string Prompt ID
     */
    protected function getPromptId(): string {
        return 'email-latest-reply';
    }
}

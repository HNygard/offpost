<?php

require_once __DIR__ . '/../Extraction/ThreadEmailExtractorPrompt.php';

class ThreadEmailExtractorPromptEmailLatestReply extends ThreadEmailExtractorPrompt {
    protected $allowedPromptTextSources = ['email_body'];

    protected function getPromptId(): string {
        return 'email-latest-reply';
    }
}

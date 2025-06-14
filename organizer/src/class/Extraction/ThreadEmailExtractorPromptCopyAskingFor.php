<?php

require_once __DIR__ . '/../Extraction/ThreadEmailExtractorPrompt.php';

class ThreadEmailExtractorPromptCopyAskingFor extends ThreadEmailExtractorPrompt {

    protected $inputFromPromptTextSources = [
        'email-latest-reply',
        // TODO: This should be only attachments that are new
        'attachment_pdf'
    ];

    protected function getPromptId(): string {
        return 'copy-asking-for';
    }
}

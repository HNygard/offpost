<?php

class EmailLatestReplyPrompt extends OpenAiPrompt {
    public function getPromptId(): string {
        return 'email-latest-reply';
    }

    public function getPromptText(): string {
        return 'You are a system for analyzing emails.'
                . ' The task is to find the text in the email.'
                . ' Extract only the latest reply or message from the following email thread.'
                . ' Ignore any previous messages, or signatures.'
                . ' Extracted text must be exactly as it is in the email.'
                . ' Do not infer or guess based on nearby context.'
                ;
    }

    public function getModel(String $input_from_email): string {
        // gpt-4o worked good
        // gpt-4o-mini looks good to, but fraction of the cost
        return 'gpt-4o-mini-2024-07-18';
    }

    public function getInput(String $input_from_email): array {
        return [
            ['role' => 'system', 'content' => $this->getPromptText()],
            ['role' => 'user', 'content' => $input_from_email]
        ];
    }
}
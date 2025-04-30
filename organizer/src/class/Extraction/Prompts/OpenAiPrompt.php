<?php

abstract class OpenAiPrompt {
    public function getPromptService(): string {
        return 'openai';
    }

    public abstract function getPromptId(): string;

    public abstract function getPromptText(): string;

    public function getModel(String $input_from_email): string {
        // Example
        return 'gpt-4o';
    }
    public function getInput(String $input_from_email): array {
        // Example
        return [
            ['role' => 'user', 'content' => 'What is the capital of France?']
        ];
    }

    public function getStructuredOutput() {
        return null;
    }

    public function filterOutput($output): string {
        return $output;
    }
}
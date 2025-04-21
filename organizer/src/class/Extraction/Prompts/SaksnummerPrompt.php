<?php

class SaksnummerPrompt extends OpenAiPrompt {
    public function getPromptId(): string {
        return 'saksnummer';
    }

    public function getPromptText(): string {
        return 'You are a system for analyzing emails.'
                . ' The task is to find the saksnummer (case number) in the email.'
                . ' You must only respond witha a case number if you are very sure it is correct.'
                . "\n\n"
                . "Typical formats for saksnummer are:\n"
                . "- 2025/123 - Case number only. Case 123 in year 2025.\n"
                . "- 2025/123-2 - Case number and document numer. Document number 2 in case 123 in year 2025.\n"
                . "\n\n"
                . "The number is connected to a public entity. In the structured response, also include the entity name.\n\n"
                . "If you are unsure about the name of the public entity, including just the case number is fine.\n"
                ;
    }

    public function getModel(String $input_from_email): string {
        return 'gpt-4o';
    }

    public function getInput(String $input_from_email): array {
        return [
            ['role' => 'system', 'content' => $this->getPromptText()],
            ['role' => 'user', 'content' => $input_from_email]
        ];
    }
}
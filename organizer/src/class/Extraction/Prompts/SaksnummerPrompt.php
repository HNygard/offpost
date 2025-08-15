<?php

class SaksnummerPrompt extends OpenAiPrompt {
    public function getPromptId(): string {
        return 'saksnummer';
    }

    public function getPromptText(): string {
        return '🤖 You are a system for analyzing emails. Your task is to extract case numbers only if they are **explicitly** mentioned in one of the allowed formats.'
                . "\n\n" . '✅ Extract and return only if the format is valid. Do **not** guess or infer.'
                . "\n\n" . '📋 Valid formats include:'
                . "\n- Case number: 2025/123"
                . "\n- Document number: 2025/123-2 (includes a case number)"
                . "\n\n" . '📤 If a case number is found, return it in this JSON format:'
                . "\n```json"
                . "\n{"
                . "\n  \"found_case_number\": true,"
                . "\n  \"case_numbers\": ["
                . "\n    {"
                . "\n      \"case_number\": \"2025/123\","
                . "\n      \"document_number\": \"2025/123-2\","
                . "\n      \"entity_name\": \"Entity Name\""
                . "\n    }"
                . "\n  ]"
                . "\n}"
                . "\n```"
                . "\nIf the entity is not mentioned or unclear, set entity_name as empty string."
                . "\nIf document_number is not available, set as \"-\"."
                . "\nIf no case number is found, return: {\"found_case_number\": false, \"case_numbers\": []}"
                . "\n\n" . '❌ Extract only what is literally present in the email text. Do **not** fabricate any data.'
                . "\n\n" . '📧 Example input:'
                . "\n\"Dear Sir, regarding your inquiry about case number 2025/123 from the Municipality, the status has been updated.\""
                . "\n✅ Expected output:"
                . "\n```json"
                . "\n{"
                . "\n  \"found_case_number\": true,"
                . "\n  \"case_numbers\": ["
                . "\n    {"
                . "\n      \"case_number\": \"2025/123\","
                . "\n      \"document_number\": \"-\","
                . "\n      \"entity_name\": \"Municipality\""
                . "\n    }"
                . "\n  ]"
                . "\n}"
                . "\n```";
    }

    public function getModel(String $input_from_email): string {
        // gpt-4o worked good
        // gpt-4o-mini looks good to, but fraction of the cost
        return 'gpt-4o-mini-2024-07-18';
    }

    public function getInput(String $input_from_email): array {
        // Limit the input to approximate 1 page of text
        $length = 3000;
        if (mb_strlen($input_from_email, 'UTF-8') > $length) {
            $input_from_email = mb_substr($input_from_email, 0, $length, 'UTF-8') . '... [Text truncated - showing approximately 1 page]';
        }

        return [
            ['role' => 'system', 'content' => $this->getPromptText()],
            ['role' => 'user', 'content' => $input_from_email]
        ];
    }

    public function getStructuredOutput() { 
        return json_decode('{
            "name": "case_number_schema",
            "type": "json_schema",
            "schema": {
                "type": "object",
                "properties": {
                    "found_case_number": {"type": "boolean"},
                    "case_numbers": {
                        "type": "array",
                        "items": {
                            "type": "object",
                            "properties": {
                                "case_number": {"type": "string"},
                                "document_number": {"type": "string"},
                                "entity_name": {"type": "string"}
                            },
                            "required": ["case_number", "document_number", "entity_name"],
                            "additionalProperties": false
                        }
                    }
                },
                "required": ["found_case_number", "case_numbers"],
                "additionalProperties": false
            },
            "strict": true
        }');
    }

    public function filterOutput($output): string {
        $obj = json_decode($output);
        if (!$obj->found_case_number) {
            if (!empty($obj->case_number) || !empty($obj->entity_name)) {
                throw new Exception("Found case number or entity name, but found_case_number is false");
            }
            // No case number found.
            return '';
        }

        // Clean up the output
        unset($obj->found_case_number);
        foreach($obj->case_numbers as $key => $obj2) {
            if (empty($obj2->case_number)) {
                unset($obj2->case_number);
            }
            if (empty($obj2->document_number) || $obj2->document_number == '-') {
                unset($obj2->document_number);
            }
            if (empty($obj2->entity_name)) {
                unset($obj2->entity_name);
            }
        }
        return json_encode($obj->case_numbers, JSON_UNESCAPED_SLASHES ^ JSON_UNESCAPED_UNICODE);
    }
}
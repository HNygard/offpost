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
            if (empty($obj2->document_number)) {
                unset($obj2->document_number);
            }
            if (empty($obj2->entity_name)) {
                unset($obj2->entity_name);
            }
        }
        return json_encode($obj->case_numbers, JSON_UNESCAPED_SLASHES ^ JSON_UNESCAPED_UNICODE);
    }
}
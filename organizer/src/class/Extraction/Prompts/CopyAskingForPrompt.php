<?php

class CopyAskingForPrompt extends OpenAiPrompt {
    public function getPromptId(): string {
        return 'copy-asking-for';
    }

    public function getPromptText(): string {
        return 'You are a system for analyzing emails. ' .
               'The task is to determine whether the sender is explicitly asking for a copy of something — especially a copy of the initial request or a document mentioned earlier in the email thread. ' .
               'Only respond "true" if the email contains a **clear and direct** request for a copy.' . "\n\n" .
               
               'You should return true for emails that request:' . "\n" .
               '- A copy of the original request' . "\n" .
               '- A copy of any document, letter, or decision' . "\n" .
               '- Phrases like "Can I get a copy of what was sent?", "Please forward the initial request", etc.' . "\n\n" .
               
               'Do **not** return true if:' . "\n" .
               '- The message only mentions a case or number without requesting a copy' . "\n" .
               '- The request is vague or ambiguous (e.g., "What is this about?")' . "\n\n" .
               
               'If a copy is requested, include a brief description (in free text) of what they are asking for — e.g., "copy of initial request", "copy of the decision", etc.' . "\n" .
               'Respond only with structured JSON.';
    }

    public function getModel(String $input_from_email): string {
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
            "name": "copy_request_schema",
            "type": "json_schema",
            "schema": {
                "type": "object",
                "properties": {
                    "is_requesting_copy": { "type": "boolean" },
                    "copy_request_description": { "type": "string" }
                },
                "required": ["is_requesting_copy", "copy_request_description"],
                "additionalProperties": false
            },
            "strict": true
        }');
    }
}

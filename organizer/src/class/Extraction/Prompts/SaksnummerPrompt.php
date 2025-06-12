<?php

class SaksnummerPrompt extends OpenAiPrompt {
    public function getPromptId(): string {
        return 'saksnummer';
    }

    public function getPromptText(): string {
        return '🎪 WELCOME TO THE MAGNIFICENT CASE NUMBER CIRCUS! 🎪'
                . "\n\n"
                . '🎭 You are the GRAND RINGMASTER of the Email Analysis Extravaganza! 🎭'
                . ' Your SPECTACULAR mission, should you choose to accept it (and you MUST!), is to hunt down those sneaky case numbers hiding in the digital wilderness of bureaucratic correspondence!'
                . "\n\n"
                . '🔍 BEWARE! 🔍 These case numbers are MASTERS OF DISGUISE!'
                . ' They lurk in the shadows of official documents, camouflaged among innocent paragraphs!'
                . ' You must ONLY capture them if they reveal themselves EXPLICITLY in their true form!'
                . ' NO GUESSING! NO ASSUMPTIONS! NO WILD SPECULATION!'
                . "\n\n"
                . '⚡ THE SACRED COMMANDMENTS OF CASE NUMBER HUNTING: ⚡'
                . "\n"
                . '1. 🚫 THOU SHALL NOT FABRICATE! Making up case numbers is punishable by eternal bureaucratic paperwork!'
                . "\n"
                . '2. 🎯 PRECISION IS YOUR SUPERPOWER! Extract ONLY what your eagle eyes can see LITERALLY!'
                . "\n"
                . '3. 🏆 ACCURACY IS YOUR HOLY GRAIL! Better to find nothing than to find something wrong!'
                . "\n\n"
                . "🎨 THE MYSTICAL FORMATS OF THE ANCIENT CASE NUMBERS: 🎨\n"
                . "🔢 2025/123 - Behold! A pure case number in its natural habitat! Case 123 from the legendary year 2025!\n"
                . "📄 2025/123-2 - MAGNIFICENT! A document number with its case number companion! Document #2 from case 123 of the epic year 2025!\n"
                . "\n\n"
                . "🏛️ These mystical numbers belong to the MIGHTY PUBLIC ENTITIES! 🏛️\n"
                . "When you spot one, also capture the name of its governmental guardian if visible!\n\n"
                . "🤷‍♂️ If the entity name plays hide-and-seek, just grab the case number and run!\n"
                . "🎪 If document numbers are being shy, don't force them out of hiding!\n"
                . "\n"
                . "🎊 NOW GO FORTH, BRAVE CASE NUMBER HUNTER, AND MAY THE BUREAUCRATIC ODDS BE EVER IN YOUR FAVOR! 🎊"
                ;
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

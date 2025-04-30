<?php
require_once __DIR__ . '/../../../class/Ai/OpenAiIntegration.php';
require_once __DIR__ . '/../../../class/Extraction/Prompts/OpenAiPrompt.php';
require_once __DIR__ . '/../../../class/Extraction/Prompts/SaksnummerPrompt.php';

use Offpost\Ai\OpenAiIntegration;

class PromptService {
    private string $openai_api_key;

    private array $available_prompts = [];

    /**
     * Constructor
     * 
     * @param string $openai_api_key OpenAI API key
     */
    public function __construct(string $openai_api_key) {
        $this->openai_api_key = $openai_api_key;

        $saksnummer = new SaksnummerPrompt();
        $this->available_prompts[$saksnummer->getPromptId()] = $saksnummer;
    }

    public function getAvailablePrompts(): array {
        return $this->available_prompts;
    }

    public function run(OpenAiPrompt $prompt, String $emailInput) {
        $openai = new OpenAiIntegration($this->openai_api_key);
        $response = $openai->sendRequest(
            $prompt->getInput($emailInput),
            $prompt->getStructuredOutput(),
            $prompt->getModel($emailInput),
            'prompt_' . $prompt->getPromptId()
        );

        $return = null;
        foreach($response['output'] as $key => $value) {
            if ($value['status'] == 'completed') {
                if ($return != null) {
                    throw new Exception("Multiple completions found in response");
                }
                $return = $value['content'][0]['text'];
            }
        }

        if ($return == null) {
            return '';
        }
        return $prompt->filterOutput($return);
    }
}

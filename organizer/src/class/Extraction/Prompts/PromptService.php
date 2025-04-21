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
            $prompt->getModel($emailInput)
        );
        return $response;
    }
}
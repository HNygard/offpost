<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../class/Ai/OpenAiIntegration.php';
require_once __DIR__ . '/../class/Extraction/Prompts/PromptService.php';

use Offpost\Ai\OpenAiIntegration;

$openai_api_key = trim(explode("\n", file_get_contents(__DIR__ . '/../../../secrets/openai_api_key'))[1]);

// Parse command line arguments
$options = getopt('', ['test', 'prompt:']);

if (isset($options['prompt'])) {
    $prompt_id = $options['prompt'];
    echo "Prompt ID: $prompt_id\n";

    $prompt_service = new PromptService($openai_api_key);
    $available_prompts = $prompt_service->getAvailablePrompts();
    if (!isset($available_prompts[$prompt_id])) {
        echo "Prompt ID $prompt_id not found\n";
        exit(1);
    }
    $prompt = $available_prompts[$prompt_id];
    echo "Prompt found: " . get_class($prompt) . "\n";
    echo "Prompt ID: " . $prompt->getPromptId() . "\n";

    // Ask for input
    echo "Please enter the email input:\n";
    $email_input = trim(fgets(STDIN));
    if (empty($email_input)) {
        echo "No input provided. Exiting.\n";
        exit(1);
    }

    echo "\n\n\n";
    echo "Prompt Model: " . $prompt->getModel($email_input) . "\n";


    // Run the prompt
    $response = $prompt_service->run($prompt, $email_input);
    var_dump($response);
    echo "Prompt executed successfully.\n";
    exit;
}

if (!isset($options['test'])) {
    echo "Usage: php ai-check-models.php --test\n";
    echo "Usage: php ai-check-models.php --prompt <prompt id>\n";
    exit(1);
}

if (isset($options['test'])) {
    echo "Running in test mode\n";
    echo "Test request sent to OpenAI API\n";

    $openai = new OpenAiIntegration($openai_api_key);
    $request = $openai->sendRequest(
        [
            ['role' => 'user', 'content' => 'What is the capital of France?']
        ],
        'gpt-4o',
        'ai_check_models_test'
    );
    echo "Request:\n" . json_encode($request, JSON_PRETTY_PRINT ^ JSON_UNESCAPED_SLASHES ^ JSON_UNESCAPED_UNICODE) . "\n";
}

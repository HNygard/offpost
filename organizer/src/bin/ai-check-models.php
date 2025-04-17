<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../class/Ai/OpenAiIntegration.php';

use Offpost\Ai\OpenAiIntegration;

$openai_api_key = trim(explode("\n", file_get_contents(__DIR__ . '/../../../secrets/openai_api_key'))[1]);

// Parse command line arguments
$options = getopt('', ['test']);

if (!isset($options['test'])) {
    echo "Usage: php ai-check-models.php --test\n";
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
        'gpt-4o'
    );
    echo "Request:\n" . json_encode($request, JSON_PRETTY_PRINT ^ JSON_UNESCAPED_SLASHES ^ JSON_UNESCAPED_UNICODE) . "\n";
}
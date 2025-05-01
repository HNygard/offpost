<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../class/Ai/OpenAiIntegration.php';
require_once __DIR__ . '/../class/Extraction/Prompts/PromptService.php';
require_once __DIR__ . '/../class/common.php';

use Offpost\Ai\OpenAiIntegration;

$openai_api_key = trim(explode("\n", file_get_contents(__DIR__ . '/../../../secrets/openai_api_key'))[1]);

putenv('DB_PASSWORD_FILE=' . __DIR__ . '/../../../secrets/postgres_password');
putenv('DB_HOST=127.0.0.1');
putenv('DB_PORT=25432');
putenv('DB_NAME=offpost');
putenv('DB_USER=offpost');

// Parse command line arguments
$options = getopt('', ['test', 'prompt:', 'prompt-tester:']);

if (isset($options['prompt'])) {
    ###### Test prompt with manual input ######
    ###### Test prompt with manual input ######
    ###### Test prompt with manual input ######
    ###### Test prompt with manual input ######
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

if (isset($options['prompt-tester'])) {
    ######### Prompt TESTER ########
    ######### Prompt TESTER ########
    ######### Prompt TESTER ########
    ######### Prompt TESTER ########
    ######### Prompt TESTER ########

    $prompt_id = $options['prompt-tester'];
    echo "Prompt ID [$prompt_id]";

    $prompt_service = new PromptService($openai_api_key);
    $available_prompts = $prompt_service->getAvailablePrompts();
    if (!isset($available_prompts[$prompt_id])) {
        echo "Prompt Tester ID $prompt_id not found\n";
        exit(1);
    }
    $prompt = $available_prompts[$prompt_id];
    echo ", class [" . get_class($prompt) . "]\n";

    // Get test data
    $files = getDirContentsRecursive(__DIR__ . '/../../../data/test-prompts/' . $prompt_id);
    foreach ($files as $file) {
        $content = file_get_contents($file);

        // Split on lines starting with ###
        $lines = explode("\n", $content);
        $sections = array();
        $current_section = null;
        foreach($lines as $line) {
            if (str_starts_with($line, '###')) {
                $current_section = trim(str_replace('###', '', $line));
                continue;
            }
            $sections[$current_section][] = $line;
        }

        if (!isset($sections['TEST INPUT'])) {
            throw new Exception("No test input found in file $file");
        }
        if (!isset($sections['EXPECTED OUTPUT'])) {
            throw new Exception("No expected output found in file $file");
        }

        $input = trim(implode("\n", $sections['TEST INPUT']));
        $alternative_outputs = explode("\n", trim(implode("\n", $sections['EXPECTED OUTPUT'])));

        for ($i = 0; $i < 10; $i++) {
            echo basename($file) . " - ";
            echo $prompt->getModel($input);

            // Run the prompt
            $response = $prompt_service->run($prompt, $input);

            $response_ok = false;
            foreach($alternative_outputs as $key => $expected_output) {
                $alternative_outputs[$key] = trim($expected_output);
                if ($response == $expected_output) {
                    echo " - OK   - " . $response;
                    $response_ok = true;
                }
            }
            
            if (!$response_ok) {
                echo " - FAIL\n";
                foreach ($alternative_outputs as $expected_output) {
                    echo "         Expected .. : " . $expected_output . "\n";
                }
                echo "         Actual .... : " . $response . "\n";
                exit(1);
            }
            echo "\n";
        }
    }
    exit;
}

if (!isset($options['test'])) {
    echo "Usage: php ai-check-models.php --test\n";
    echo "Usage: php ai-check-models.php --prompt <prompt id>\n";
    echo "Usage: php ai-check-models.php --prompt-tester <prompt id>\n";
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

<?php
require_once __DIR__ . '/../../Ai/OpenAiIntegration.php';
require_once __DIR__ . '/OpenAiPrompt.php';
require_once __DIR__ . '/SaksnummerPrompt.php';
require_once __DIR__ . '/EmailLatestReplyPrompt.php';
require_once __DIR__ . '/CopyAskingForPrompt.php';
require_once __DIR__ . '/ThreadEmailSummaryPrompt.php';

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

        $email_latest_reply = new EmailLatestReplyPrompt();
        $this->available_prompts[$email_latest_reply->getPromptId()] = $email_latest_reply;

        $copy_asking_for = new CopyAskingForPrompt();
        $this->available_prompts[$copy_asking_for->getPromptId()] = $copy_asking_for;

        $thread_email_summary = new ThreadEmailSummaryPrompt();
        $this->available_prompts[$thread_email_summary->getPromptId()] = $thread_email_summary;
    }

    public function getAvailablePrompts(): array {
        return $this->available_prompts;
    }

    public function run(OpenAiPrompt $prompt, String $emailInput) {
        $openai = new OpenAiIntegration($this->openai_api_key);

        // Filter out any base64 looking strings as they might trigger content filters at OpenAI
        $emailInput = preg_replace('/(?:[A-Za-z0-9+\/=]{40,})/', '[Base64 looking string removed]', $emailInput);

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

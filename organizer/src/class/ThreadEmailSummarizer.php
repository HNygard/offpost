<?php

require_once 'OpenAIClient.php';

class ThreadEmailSummarizer {
    private $openAIClient;

    public function __construct($apiKey) {
        $this->openAIClient = new OpenAIClient($apiKey);
    }

    public function setOpenAIClient($openAIClient) {
        $this->openAIClient = $openAIClient;
    }

    public function summarize($emailContent) {
        return $this->openAIClient->summarizeEmail($emailContent);
    }

    public function processEmail($email) {
        $summary = $this->summarize($email->body);
        $email->summary = $summary;
        return $email;
    }
}

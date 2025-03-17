<?php

namespace OpenAI;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class ThreadEmailSummarizer {
    private $client;
    private $apiKey;
    private $summarizer;

    public function __construct($apiKey, OpenAISummarizer $summarizer) {
        $this->client = new Client();
        $this->apiKey = $apiKey;
        $this->summarizer = $summarizer;
    }

    public function processEmails(array $emails) {
        foreach ($emails as $email) {
            $summary = $this->summarizer->summarizeEmail($email->body);
            $this->updateThreadEmail($email, $summary);
        }
    }

    private function updateThreadEmail($email, $summary) {
        // Update the ThreadEmail object with the summary
        $email->summary = $summary;
    }
}

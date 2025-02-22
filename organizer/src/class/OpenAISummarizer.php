<?php

namespace OpenAI;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class OpenAISummarizer {
    private $client;
    private $apiKey;

    public function __construct($apiKey) {
        $this->client = new Client();
        $this->apiKey = $apiKey;
    }

    public function summarizeEmail($emailBody) {
        $response = $this->sendRequest($emailBody);
        return $this->processResponse($response);
    }

    private function sendRequest($emailBody) {
        try {
            $response = $this->client->post('https://api.openai.com/v1/engines/davinci-codex/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'prompt' => "Summarize the following email:\n\n" . $emailBody,
                    'max_tokens' => 150,
                ],
            ]);
            return $response;
        } catch (RequestException $e) {
            // Handle request exception
            return null;
        }
    }

    private function processResponse($response) {
        if ($response && $response->getStatusCode() == 200) {
            $data = json_decode($response->getBody(), true);
            return $data['choices'][0]['text'] ?? 'No summary available';
        }
        return 'Failed to get summary';
    }
}

<?php

class OpenAIClient {
    private $apiKey;
    private $apiUrl = 'https://api.openai.com/v1/engines/davinci-codex/completions';

    public function __construct($apiKey) {
        $this->apiKey = $apiKey;
    }

    public function sendRequest($data) {
        $ch = curl_init($this->apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            throw new Exception('Request Error: ' . curl_error($ch));
        }

        curl_close($ch);
        return json_decode($response, true);
    }

    public function summarizeEmail($emailContent) {
        $data = [
            'prompt' => 'Summarize the following email: ' . $emailContent,
            'max_tokens' => 150,
        ];

        $response = $this->sendRequest($data);
        if (isset($response['choices'][0]['text'])) {
            return $response['choices'][0]['text'];
        }

        throw new Exception('Failed to summarize email');
    }
}

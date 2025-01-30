<?php

class OpenAIClient {
    private $apiKey;
    private $apiUrl = 'https://api.openai.com/v1/engines/davinci-codex/completions';
    private $curlWrapper;

    public function __construct($apiKey) {
        $this->apiKey = $apiKey;
        $this->curlWrapper = new CurlWrapper();
    }

    public function setCurlWrapper($curlWrapper) {
        $this->curlWrapper = $curlWrapper;
    }

    public function sendRequest($data) {
        $this->curlWrapper->init($this->apiUrl);
        $this->curlWrapper->setOption(CURLOPT_RETURNTRANSFER, true);
        $this->curlWrapper->setOption(CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
        ]);
        $this->curlWrapper->setOption(CURLOPT_POST, true);
        $this->curlWrapper->setOption(CURLOPT_POSTFIELDS, json_encode($data));

        $response = $this->curlWrapper->execute();
        if ($this->curlWrapper->getErrno()) {
            throw new Exception('Request Error: ' . $this->curlWrapper->getError());
        }

        $this->curlWrapper->close();
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

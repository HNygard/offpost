<?php

namespace Offpost\Ai;

use Exception;

/**
 * Integration with OpenAI API for AI-powered features
 */
class OpenAiIntegration
{
    private string $apiKey;

    /**
     * Constructor
     * 
     * @param string $apiKey OpenAI API key
     */
    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
    }

    /**
     * Send a request to OpenAI API
     * 
     * @param array $input Array of input messages
     * @param string $model Model to use (defaults to gpt-4o)
     * @return array|null Response from OpenAI or null on error
     */
    public function sendRequest(array $input, string $model): ?array
    {
        $apiEndpoint = 'https://api.openai.com/v1/responses';
        
        $requestData = [
            'model' => $model,
            'input' => $input
        ];
        
        $ch = curl_init($apiEndpoint);
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = 'Curl error: ' . curl_error($ch);
        
        curl_close($ch);
        
        if ($error) {
            throw new Exception("OpenAI API error: $error");
        }
        if ($httpCode >= 400) {
            throw new Exception("OpenAI API error: HTTP code $httpCode\n$response");
        }
        
        return json_decode($response, true);
    }

    /**
     * Create a text message for the input array
     * 
     * @param string $content Text content
     * @param string $role Role (default: 'user')
     * @return array Message array
     */
    public function createTextMessage(string $content, string $role = 'user'): array
    {
        return [
            'role' => $role,
            'content' => $content
        ];
    }

    /**
     * Create an image message for the input array
     * 
     * @param string $imageUrl URL of the image
     * @param string $role Role (default: 'user')
     * @return array Message array
     */
    public function createImageMessage(string $imageUrl, string $role = 'user'): array
    {
        return [
            'role' => $role,
            'content' => [
                [
                    'type' => 'input_image',
                    'image_url' => $imageUrl
                ]
            ]
        ];
    }

    /**
     * Simple helper to analyze an image with a question
     * 
     * @param string $imageUrl URL of the image to analyze
     * @param string $question Question to ask about the image
     * @param string $model Model to use (defaults to class default)
     * @return array|null Response from OpenAI or null on error
     */
    public function analyzeImage(string $imageUrl, string $question, string $model = null): ?array
    {
        $input = [
            $this->createTextMessage($question),
            $this->createImageMessage($imageUrl)
        ];
        
        return $this->sendRequest($input, $model);
    }
}

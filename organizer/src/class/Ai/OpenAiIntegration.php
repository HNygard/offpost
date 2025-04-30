<?php

namespace Offpost\Ai;

use Exception;
require_once __DIR__ . '/OpenAiRequestLog.php';

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

    protected function internalSendRequest($apiEndpoint, $requestData) {
        $ch = curl_init($apiEndpoint);
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey
        ]);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);

        return array(
            'response' => $response,
            'httpCode' => $httpCode,
            'error' => $error
        );
    }

    /**
     * Send a request to OpenAI API
     * 
     * @param array $input Array of input messages
     * @param string $model Model to use (defaults to gpt-4o)
     * @param string $source Source of the request (for logging)
     * @return array|null Response from OpenAI or null on error
     */
    public function sendRequest(array $input, string $model, string $source = null): ?array
    {
        if ($source == null) {
            $source = 'unknown';
        }
        $apiEndpoint = 'https://api.openai.com/v1/responses';
        
        $requestData = [
            'model' => $model,
            'input' => $input
        ];
        
        // Log the request before sending
        $logId = OpenAiRequestLog::log(
            $source,
            $apiEndpoint,
            $requestData
        );
        
        // Send the request
        $responseData = $this->internalSendRequest($apiEndpoint, $requestData);
        $response = $responseData['response'];
        $httpCode = $responseData['httpCode'];
        $error = $responseData['error'];
        
        if ($error) {
            $error = 'Curl error: ' . $error;
            if ($response) {
                $error .= "\nResponse: $response";
            }
            // Log the error response
            OpenAiRequestLog::updateWithResponse($logId, $error, 0);
            throw new Exception("OpenAI API error: $error");
        }
        if ($httpCode >= 400) {
            // Log the error response
            OpenAiRequestLog::updateWithResponse($logId, $response, $httpCode);
            throw new Exception("OpenAI API error: HTTP code $httpCode\n$response");
        }
        
        $responseData = json_decode($response, true);
        
        // Extract token counts if available in the response
        $tokensInput = $responseData['usage']['input_tokens'] ?? null;
        $tokensOutput = $responseData['usage']['output_tokens'] ?? null;
        $model = $responseData['model'] ?? null;
        $status = $responseData['status'] ?? null;
        
        // Update the log with the response data
        OpenAiRequestLog::updateWithResponse(
            $logId,
            $response,
            $httpCode,
            $tokensInput,
            $tokensOutput,
            $model,
            $status
        );
        
        return $responseData;
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
     * @param string $source Source of the request (for logging)
     * @return array|null Response from OpenAI or null on error
     */
    public function analyzeImage(string $imageUrl, string $question, string $model = null, string $source = null): ?array
    {
        $input = [
            $this->createTextMessage($question),
            $this->createImageMessage($imageUrl)
        ];
        
        return $this->sendRequest($input, $model, $source);
    }
}

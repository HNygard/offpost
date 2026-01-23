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
        $errorNum = curl_errno($ch);
        
        // Collect detailed curl info for debugging
        $curlInfo = curl_getinfo($ch);
        
        curl_close($ch);

        return array(
            'response' => $response,
            'httpCode' => $httpCode,
            'error' => $error,
            'errorNum' => $errorNum,
            'curlInfo' => $curlInfo
        );
    }

    /**
     * Send a request to OpenAI API
     * 
     * @param array $input Array of input messages
     * @param $structured_output Structured output object
     * @param string $model Model to use (defaults to gpt-4o)
     * @param string $source Source of the request (for logging)
     * @return array|null Response from OpenAI or null on error
     */
    public function sendRequest(array $input, $structured_output, string $model, string $source = null): ?array
    {
        if ($source == null) {
            $source = 'unknown';
        }
        $apiEndpoint = 'https://api.openai.com/v1/responses';
        
        $requestData = [
            'model' => $model,
            'input' => $input
        ];
        if ($structured_output) {
            $requestData['text'] = [
                'format' => $structured_output
            ];
        }
        
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
        $errorNum = $responseData['errorNum'];
        $curlInfo = $responseData['curlInfo'];
        
        if ($error) {
            // Build detailed error message with debug information
            $debugInfo = [
                'error_message' => $error,
                'error_number' => $errorNum,
                'endpoint' => $apiEndpoint,
                'request_size_bytes' => strlen(json_encode($requestData)),
                'curl_info' => [
                    'url' => $curlInfo['url'] ?? null,
                    'content_type' => $curlInfo['content_type'] ?? null,
                    'http_code' => $curlInfo['http_code'] ?? null,
                    'total_time' => $curlInfo['total_time'] ?? null,
                    'namelookup_time' => $curlInfo['namelookup_time'] ?? null,
                    'connect_time' => $curlInfo['connect_time'] ?? null,
                    'pretransfer_time' => $curlInfo['pretransfer_time'] ?? null,
                    'starttransfer_time' => $curlInfo['starttransfer_time'] ?? null,
                    'redirect_time' => $curlInfo['redirect_time'] ?? null,
                    'redirect_count' => $curlInfo['redirect_count'] ?? null,
                    'primary_ip' => $curlInfo['primary_ip'] ?? null,
                    'primary_port' => $curlInfo['primary_port'] ?? null,
                ]
            ];
            
            $errorMessage = 'Curl error: ' . $error . ' (errno: ' . $errorNum . ')';
            $errorMessage .= "\nDebug info: " . json_encode($debugInfo, JSON_PRETTY_PRINT);
            
            if ($response) {
                $errorMessage .= "\nResponse: $response";
            }
            
            // Log the error response with debug information
            OpenAiRequestLog::updateWithResponse($logId, $errorMessage, 0);
            throw new Exception("OpenAI API error: $errorMessage");
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
     * @param $structured_output Structured output object
     * @param string $model Model to use (defaults to class default)
     * @param string $source Source of the request (for logging)
     * @return array|null Response from OpenAI or null on error
     */
    public function analyzeImage(string $imageUrl, string $question, $structured_output = null, string $model = null, string $source = null): ?array
    {
        $input = [
            $this->createTextMessage($question),
            $this->createImageMessage($imageUrl)
        ];
        
        return $this->sendRequest($input, $structured_output, $model, $source);
    }
}

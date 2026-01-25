<?php

namespace Offpost\Ai;

use Exception;
require_once __DIR__ . '/OpenAiRequestLog.php';

/**
 * Integration with OpenAI API for AI-powered features
 */
class OpenAiIntegration
{
    // Use 3 retries for OpenAI (vs 5 for IMAP) since OpenAI failures are typically
    // transient thread exhaustion that resolves quickly, while IMAP may have longer issues
    private const MAX_RETRIES = 3;
    private const RETRY_DELAY_MS = 100; // Base delay: 100ms for 1st retry, 200ms for 2nd retry (exponential backoff)
    
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
     * Check if an error indicates a thread/connection issue that might be retryable
     * 
     * @param int $errorNum Curl error number
     * @param string $error Curl error message
     * @return bool True if the error is retryable
     */
    private function isRetryableError(int $errorNum, string $error): bool
    {
        // CURLE_COULDNT_RESOLVE_HOST (6) with thread failure message
        // This happens when Apache/PHP runs out of threads for DNS resolution
        if ($errorNum === 6 && strpos($error, 'thread failed to start') !== false) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Wait before retrying with exponential backoff
     * 
     * @param int $attempt Current attempt number (1-based)
     */
    private function waitBeforeRetry(int $attempt): void
    {
        $delayMs = self::RETRY_DELAY_MS * pow(2, $attempt - 1); // Exponential backoff
        $delayMs = min($delayMs, 5000); // Cap at 5 seconds
        usleep($delayMs * 1000); // Convert to microseconds
    }

    protected function internalSendRequest($apiEndpoint, $requestData) {
        $ch = curl_init($apiEndpoint);
        
        $requestDataJson = json_encode($requestData);
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $requestDataJson);
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

        // Combine error info with curl info for debugging
        $debuggingInfo = $curlInfo;
        $debuggingInfo['error'] = $error;
        $debuggingInfo['error_number'] = $errorNum;
        $debuggingInfo['request_size_bytes'] = strlen($requestDataJson);

        return array(
            'response' => $response,
            'httpCode' => $httpCode,
            'error' => $error,
            'debuggingInfo' => $debuggingInfo
        );
    }

    /**
     * Send a request to OpenAI API
     * 
     * @param array $input Array of input messages
     * @param $structured_output Structured output object
     * @param string $model Model to use (defaults to gpt-4o)
     * @param string $source Source of the request (for logging)
     * @param int|null $extractionId Extraction ID that triggered this request
     * @return array|null Response from OpenAI or null on error
     */
    public function sendRequest(array $input, $structured_output, string $model, string $source = null, ?int $extractionId = null): ?array
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
            $requestData,
            null,
            null,
            null,
            null,
            null,
            null,
            $extractionId
        );
        
        // Send the request with retry logic
        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            // Send the request
            $responseData = $this->internalSendRequest($apiEndpoint, $requestData);
            $response = $responseData['response'];
            $httpCode = $responseData['httpCode'];
            $error = $responseData['error'];
            $debuggingInfo = $responseData['debuggingInfo'];
            
            if ($error) {
                $errorNum = $debuggingInfo['error_number'];
                
                // Check if this is a retryable error and we haven't exhausted retries
                if ($this->isRetryableError($errorNum, $error) && $attempt < self::MAX_RETRIES) {
                    error_log("OpenAI retry attempt $attempt/" . self::MAX_RETRIES . " for $source: $error (errno: $errorNum)");
                    $this->waitBeforeRetry($attempt);
                    continue;
                }
                
                // Non-retryable error or max retries reached
                // Remove redundant error_message field from debug info to avoid duplication
                if (isset($debuggingInfo['error_message'])) {
                    unset($debuggingInfo['error_message']);
                }
                
                // Build detailed error message with debug information
                $errorMessage = 'Curl error: ' . $error . ' (errno: ' . $debuggingInfo['error_number'] . ')';
                
                // If we retried, add that info to the error message
                if ($attempt > 1) {
                    $errorMessage .= " [Failed after $attempt attempts]";
                }
                
                $errorMessage .= "\nDebug info: " . json_encode([
                    'endpoint' => $apiEndpoint,
                    'debugging_info' => $debuggingInfo
                ], JSON_PRETTY_PRINT);
                
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
            
            // Success - log if we retried
            if ($attempt > 1) {
                error_log("OpenAI request for $source succeeded on attempt $attempt");
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

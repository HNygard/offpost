<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../class/Ai/OpenAiRequestLog.php';
require_once __DIR__ . '/../class/Ai/OpenAiIntegration.php';

// Require authentication
requireAuth();

use Offpost\Ai\OpenAiRequestLog;
use Offpost\Ai\OpenAiIntegration;

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get the request data - support both JSON and form-encoded data
$data = null;
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';

if (strpos($contentType, 'application/json') !== false) {
    // JSON request from AJAX
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
} else {
    // Form-encoded request (e.g., from tests or form submission)
    $data = $_POST;
}

if (!isset($data['ids']) || !is_array($data['ids'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request: ids array required']);
    exit;
}

$ids = array_map('intval', $data['ids']);
if (empty($ids)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No valid IDs provided']);
    exit;
}

// Get the log entries
$logs = OpenAiRequestLog::getByIds($ids);

if (empty($logs)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'No log entries found for the provided IDs']);
    exit;
}

// Initialize OpenAI integration
$openAiApiKey = getenv('OPENAI_API_KEY');
if (!$openAiApiKey) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'OpenAI API key not configured']);
    exit;
}

$openAi = new OpenAiIntegration($openAiApiKey);

// Retry each request
$results = [];
$successCount = 0;
$errorCount = 0;

foreach ($logs as $log) {
    try {
        // Parse the original request
        $requestData = json_decode($log['request'], true);
        
        // Validate request data structure (expects format from OpenAiIntegration::sendRequest)
        if (!$requestData || !isset($requestData['input']) || !isset($requestData['model'])) {
            $results[] = [
                'id' => $log['id'],
                'success' => false,
                'message' => 'Invalid request data in log entry - missing required fields (input, model)'
            ];
            $errorCount++;
            continue;
        }
        
        // Extract structured output if it exists (safely check nested array)
        $structuredOutput = null;
        if (isset($requestData['text']) && is_array($requestData['text']) && isset($requestData['text']['format'])) {
            $structuredOutput = $requestData['text']['format'];
        }
        
        // Retry the request with a new source indicating it's a retry
        $retrySource = $log['source'] . '_retry';
        $response = $openAi->sendRequest(
            $requestData['input'],
            $structuredOutput,
            $requestData['model'],
            $retrySource
        );
        
        $results[] = [
            'id' => $log['id'],
            'success' => true,
            'message' => 'Request retried successfully'
        ];
        $successCount++;
        
    } catch (Exception $e) {
        $results[] = [
            'id' => $log['id'],
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ];
        $errorCount++;
    }
}

// Return the results
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'message' => "Retried $successCount request(s) successfully" . ($errorCount > 0 ? ", $errorCount failed" : ""),
    'results' => $results,
    'successCount' => $successCount,
    'errorCount' => $errorCount
]);

<?php

namespace Offpost\Ai;

require_once __DIR__ . '/../Database.php';

use Database;
use Exception;

/**
 * Class for logging OpenAI API requests
 */
class OpenAiRequestLog {
    /**
     * Log an OpenAI API request
     * 
     * @param string $source Source of the request (e.g., 'extraction', 'classification')
     * @param string $endpoint API endpoint
     * @param array|string $request Request data
     * @param array|string|null $response Response data
     * @param int|null $responseCode HTTP response code
     * @param int|null $tokensInput Number of input tokens
     * @param int|null $tokensOutput Number of output tokens
     * @param string|null $model Model used for the request
     * @param string|null $status Status of the request
     * @return int ID of the created log entry
     */
    public static function log(
        string $source,
        string $endpoint,
        $request,
        $response = null,
        ?int $responseCode = null,
        ?int $tokensInput = null,
        ?int $tokensOutput = null,
        ?string $model = null,
        ?string $status = null
    ): int {
        // Convert request/response to JSON if they are arrays
        $requestJson = is_array($request) ? json_encode($request, JSON_PRETTY_PRINT) : $request;
        $responseJson = is_array($response) ? json_encode($response, JSON_PRETTY_PRINT) : $response;
        
        $result = Database::queryOne(
            "INSERT INTO openai_request_log (
                source, endpoint, request, response, response_code, tokens_input, tokens_output,
                model, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id",
            [
                $source,
                $endpoint,
                $requestJson,
                $responseJson,
                $responseCode,
                $tokensInput,
                $tokensOutput,
                $model,
                $status
            ]
        );
        
        return (int)$result['id'];
    }
    
    /**
     * Update an existing log entry with response data
     * 
     * @param int $logId ID of the log entry to update
     * @param array|string $response Response data
     * @param int $responseCode HTTP response code
     * @param int|null $tokensInput Number of input tokens
     * @param int|null $tokensOutput Number of output tokens
     * @param string|null $model Model used for the request
     * @param string|null $status Status of the request
     * @return bool Success status
     */
    public static function updateWithResponse(
        int $logId,
        $response,
        int $responseCode,
        ?int $tokensInput = null,
        ?int $tokensOutput = null,
        ?string $model = null,
        ?string $status = null
    ): bool {
        if ($logId <= 0) {
            throw new Exception("Invalid log ID: $logId");
        }
        
        $responseJson = is_array($response) ? json_encode($response, JSON_PRETTY_PRINT) : $response;
        
        return Database::execute(
            "UPDATE openai_request_log 
            SET response = ?, response_code = ?, tokens_input = ?, tokens_output = ?,
            model = ?, status = ?
            WHERE id = ?",
            [$responseJson, $responseCode, $tokensInput, $tokensOutput, 
            $model, $status,
            $logId]
        ) > 0;
    }
    
    /**
     * Get logs for a specific source
     * 
     * @param string $source Source to filter by
     * @param int $limit Maximum number of logs to return (default: 100)
     * @return array Array of log records
     */
    public static function getBySource(string $source, int $limit = 100): array {
        return Database::query(
            "SELECT * FROM openai_request_log WHERE source = ? ORDER BY time DESC LIMIT ?",
            [$source, $limit]
        );
    }
    
    /**
     * Get all logs
     * 
     * @param int $limit Maximum number of logs to return (default: 100)
     * @return array Array of log records
     */
    public static function getAll(int $limit = 100): array {
        return Database::query(
            "SELECT * FROM openai_request_log ORDER BY time DESC LIMIT ?",
            [$limit]
        );
    }
    
    /**
     * Get logs within a date range
     * 
     * @param string $startDate Start date (YYYY-MM-DD)
     * @param string $endDate End date (YYYY-MM-DD)
     * @param int $limit Maximum number of logs to return (default: 100)
     * @return array Array of log records
     */
    public static function getByDateRange(string $startDate, string $endDate, int $limit = 100): array {
        return Database::query(
            "SELECT * FROM openai_request_log 
            WHERE time >= ? AND time <= ? 
            ORDER BY time DESC LIMIT ?",
            [$startDate, $endDate, $limit]
        );
    }
    
    /**
     * Get total token usage by source
     * 
     * @param string|null $source Source to filter by (null for all sources)
     * @param string|null $startDate Start date (YYYY-MM-DD) (null for all time)
     * @param string|null $endDate End date (YYYY-MM-DD) (null for all time)
     * @return array Array with 'input_tokens' and 'output_tokens' counts
     */
    public static function getTokenUsage(?string $source = null, ?string $startDate = null, ?string $endDate = null): array {
        $params = [];
        $whereClause = [];
        
        if ($source !== null) {
            $whereClause[] = "source = ?";
            $params[] = $source;
        }
        
        if ($startDate !== null) {
            $whereClause[] = "time >= ?";
            $params[] = $startDate;
        }
        
        if ($endDate !== null) {
            $whereClause[] = "time <= ?";
            $params[] = $endDate;
        }
        
        $whereStr = !empty($whereClause) ? "WHERE " . implode(" AND ", $whereClause) : "";
        
        $result = Database::queryOne(
            "SELECT 
                COALESCE(SUM(tokens_input), 0) as input_tokens, 
                COALESCE(SUM(tokens_output), 0) as output_tokens 
            FROM openai_request_log 
            $whereStr",
            $params
        );
        
        return [
            'input_tokens' => (int)$result['input_tokens'],
            'output_tokens' => (int)$result['output_tokens']
        ];
    }
    
    /**
     * Get a single log entry by ID
     * 
     * @param int $id Log entry ID
     * @return array|null Log record or null if not found
     */
    public static function getById(int $id): ?array {
        return Database::queryOne(
            "SELECT * FROM openai_request_log WHERE id = ?",
            [$id]
        );
    }
    
    /**
     * Get multiple log entries by IDs
     * 
     * @param array $ids Array of log entry IDs
     * @return array Array of log records
     */
    public static function getByIds(array $ids): array {
        if (empty($ids)) {
            return [];
        }
        
        // Create placeholders for the IN clause
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        
        return Database::query(
            "SELECT * FROM openai_request_log WHERE id IN ($placeholders) ORDER BY time DESC",
            $ids
        );
    }
}

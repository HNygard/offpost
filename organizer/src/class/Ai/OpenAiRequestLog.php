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
     * @param int|null $extractionId Extraction ID that triggered this request
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
        ?string $status = null,
        ?int $extractionId = null
    ): int {
        // Convert request/response to JSON if they are arrays
        $requestJson = is_array($request) ? json_encode($request, JSON_PRETTY_PRINT) : $request;
        $responseJson = is_array($response) ? json_encode($response, JSON_PRETTY_PRINT) : $response;
        
        $result = Database::queryOne(
            "INSERT INTO openai_request_log (
                source, endpoint, request, response, response_code, tokens_input, tokens_output,
                model, status, extraction_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id",
            [
                $source,
                $endpoint,
                $requestJson,
                $responseJson,
                $responseCode,
                $tokensInput,
                $tokensOutput,
                $model,
                $status,
                $extractionId
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
     * @return array Array of log records with thread information
     */
    public static function getBySource(string $source, int $limit = 100): array {
        return self::getLogsWithThreadInfo(
            "WHERE orl.source = ?",
            [$source, $limit]
        );
    }
    
    /**
     * Get all logs
     * 
     * @param int $limit Maximum number of logs to return (default: 100)
     * @return array Array of log records with thread information
     */
    public static function getAll(int $limit = 100): array {
        return self::getLogsWithThreadInfo("", [$limit]);
    }
    
    /**
     * Get logs within a date range
     * 
     * @param string $startDate Start date (YYYY-MM-DD)
     * @param string $endDate End date (YYYY-MM-DD)
     * @param int $limit Maximum number of logs to return (default: 100)
     * @return array Array of log records with thread information
     */
    public static function getByDateRange(string $startDate, string $endDate, int $limit = 100): array {
        return self::getLogsWithThreadInfo(
            "WHERE orl.time >= ? AND orl.time <= ?",
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
        return Database::queryOneOrNone(
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
    
    /**
     * Get logs with thread information by joining with extractions and threads
     * 
     * @param string $whereClause WHERE clause for filtering (without the WHERE keyword)
     * @param array $params Query parameters (must include limit as the last parameter)
     * @return array Array of log records with thread information
     */
    private static function getLogsWithThreadInfo(string $whereClause, array $params): array {
        // Extract limit from params (it's always the last parameter)
        $limit = array_pop($params);
        
        // Build the query with LEFT JOINs to get thread information
        // Uses the extraction_id foreign key for reliable matching
        $query = "
            SELECT 
                orl.*,
                tee.extraction_id,
                tee.email_id,
                t.id as thread_id,
                t.entity_id as thread_entity_id,
                t.title as thread_title
            FROM openai_request_log orl
            LEFT JOIN thread_email_extractions tee ON orl.extraction_id = tee.extraction_id
            LEFT JOIN thread_emails te ON tee.email_id = te.id
            LEFT JOIN threads t ON te.thread_id = t.id
            $whereClause
            ORDER BY orl.time DESC
            LIMIT ?
        ";
        
        // Add limit back to params
        $params[] = $limit;
        
        return Database::query($query, $params);
    }
}

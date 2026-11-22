<?php

require_once __DIR__ . '/McpToolProvider.php';

/**
 * JSON-RPC 2.0 dispatcher for the MCP methods Offpost supports.
 */
class McpServer {
    /** Passed to handle() by the endpoint when json_decode failed. */
    public const PARSE_ERROR = '__mcp_parse_error__';

    public const SUPPORTED_PROTOCOL_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26'];

    private McpToolProvider $tools;

    public function __construct(McpToolProvider $tools) {
        $this->tools = $tools;
    }

    /**
     * @param mixed $request json_decode(..., true) result, or self::PARSE_ERROR
     * @return array|null Response, or null for a notification
     */
    public function handle(mixed $request, string $userId): ?array {
        if ($request === self::PARSE_ERROR) {
            return $this->error(null, -32700, 'Parse error');
        }
        if (!is_array($request) || array_is_list($request)) {
            return $this->error(null, -32600, 'Invalid Request');
        }

        $isNotification = !array_key_exists('id', $request);
        $id = $request['id'] ?? null;
        $method = $request['method'] ?? null;
        if (!is_string($method)) {
            return $this->error($id, -32600, 'Invalid Request');
        }
        if ($isNotification) {
            return null;
        }
        $params = $request['params'] ?? [];
        if (!is_array($params)) {
            return $this->error($id, -32602, 'Invalid params');
        }

        try {
            switch ($method) {
                case 'initialize':
                    return $this->result($id, $this->initialize($params));
                case 'ping':
                    return $this->result($id, new stdClass());
                case 'tools/list':
                    return $this->result($id, ['tools' => $this->tools->definitions()]);
                case 'tools/call':
                    return $this->toolsCall($id, $params, $userId);
                default:
                    return $this->error($id, -32601, 'Method not found');
            }
        } catch (Throwable $e) {
            error_log('MCP internal error: ' . get_class($e) . ': ' . $e->getMessage());
            return $this->error($id, -32603, 'Internal error');
        }
    }

    private function initialize(array $params): array {
        $requested = $params['protocolVersion'] ?? null;
        $version = in_array($requested, self::SUPPORTED_PROTOCOL_VERSIONS, true)
            ? $requested
            : self::SUPPORTED_PROTOCOL_VERSIONS[0];
        return [
            'protocolVersion' => $version,
            'capabilities' => ['tools' => new stdClass()],
            'serverInfo' => ['name' => 'offpost', 'version' => '1.0.0'],
        ];
    }

    private function toolsCall(mixed $id, array $params, string $userId): array {
        $name = $params['name'] ?? null;
        $args = $params['arguments'] ?? [];
        if (!is_string($name) || !is_array($args)) {
            return $this->error($id, -32602, 'Invalid params');
        }
        $known = array_column($this->tools->definitions(), 'name');
        if (!in_array($name, $known, true)) {
            return $this->error($id, -32602, 'Unknown tool: ' . $name);
        }

        try {
            $content = $this->tools->call($name, $args, $userId);
            return $this->result($id, ['content' => $content, 'isError' => false]);
        } catch (McpToolException $e) {
            return $this->result($id, [
                'content' => [['type' => 'text', 'text' => $e->getMessage()]],
                'isError' => true,
            ]);
        }
    }

    private function result(mixed $id, array|object $result): array {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private function error(mixed $id, int $code, string $message): array {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}

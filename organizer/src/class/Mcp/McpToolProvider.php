<?php

class McpToolException extends Exception {
}

/**
 * Thrown when a thread does not exist or the user may not access it.
 * The message is fixed so the two cases are indistinguishable.
 */
class McpNotFoundException extends McpToolException {
    public function __construct() {
        parent::__construct('Not found, or no access');
    }
}

interface McpToolProvider {
    /**
     * @return array MCP tool objects (name, description, inputSchema)
     */
    public function definitions(): array;

    /**
     * @return array List of MCP content items
     * @throws McpToolException for errors that should be shown to the model
     */
    public function call(string $name, array $args, string $userId): array;
}

<?php
// MCP (Model Context Protocol) endpoint, streamable HTTP, POST only. OAuth bearer auth.
// Deliberately starts no session and does not use auth.php.

function mcpJsonError(int $status, string $message): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['error' => $message]);
}

try {
    require_once __DIR__ . '/../../username-password.php';
    require_once __DIR__ . '/../../mcp-config.php';
    require_once __DIR__ . '/../../class/Mcp/McpHttp.php';
    require_once __DIR__ . '/../../class/OAuth/OAuthTokenService.php';
    require_once __DIR__ . '/../../class/Mcp/McpAttachmentLink.php';
    require_once __DIR__ . '/../../class/Mcp/McpServer.php';
    require_once __DIR__ . '/../../class/Mcp/McpTools.php';

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: POST');
        mcpJsonError(405, 'Method not allowed, use POST');
        exit;
    }

    $parts = parse_url($mcp_resource);
    $metadataUrl = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '')
        . '/.well-known/oauth-protected-resource';

    $token = McpHttp::bearerToken($_SERVER, function_exists('getallheaders') ? getallheaders() : []);
    if ($token === null) {
        header('WWW-Authenticate: ' . McpHttp::wwwAuthenticate($metadataUrl, false, $mcp_scopes));
        mcpJsonError(401, 'Missing bearer token');
        exit;
    }

    // Only tokens minted by Offpost's own token endpoint; a database failure is a 500, not a 401.
    $userId = (new OAuthTokenService($mcp_resource))->userIdForAccessToken($token);
    if ($userId === null) {
        header('WWW-Authenticate: ' . McpHttp::wwwAuthenticate($metadataUrl, true, $mcp_scopes));
        mcpJsonError(401, 'Invalid token');
        exit;
    }

    $request = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $request = McpServer::PARSE_ERROR;
    }
    $response = (new McpServer(new McpTools($mcp_link_secret === null ? null : new McpAttachmentLink($mcp_link_secret), $mcp_resource)))->handle($request, $userId);

    if ($response === null) {
        http_response_code(202);
    } else {
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
} catch (Throwable $e) {
    error_log('MCP endpoint error: ' . $e);
    if (ob_get_level() > 0) {
        ob_clean();
    }
    mcpJsonError(500, 'Internal error');
}

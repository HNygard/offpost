<?php
// Attachment download for links issued by the MCP tool get_attachment_link.
// Auth is the signed, short-lived token in the URL (the client fetches it with curl); no session, no auth.php.
// The token's user is re-checked against the thread at download time.

function mcpDownloadError(int $status, string $message): void {
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
}

try {
    require_once __DIR__ . '/../../username-password.php';
    require_once __DIR__ . '/../../mcp-config.php';
    require_once __DIR__ . '/../../class/Mcp/McpAttachmentLink.php';
    require_once __DIR__ . '/../../class/Database.php';
    require_once __DIR__ . '/../../class/Thread.php';
    require_once __DIR__ . '/../../class/NpApiService.php';
    require_once __DIR__ . '/../../class/ThreadStorageManager.php';

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        header('Allow: GET');
        mcpDownloadError(405, 'Method not allowed, use GET');
        exit;
    }

    if ($mcp_link_secret === null) {
        mcpDownloadError(500, 'Attachment links are not configured');
        exit;
    }

    try {
        $claims = (new McpAttachmentLink($mcp_link_secret))->verify((string)($_GET['token'] ?? ''), time());
    } catch (McpAuthException $e) {
        mcpDownloadError(403, 'Link invalid or expired');
        exit;
    }

    $uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    $thread = preg_match($uuidRe, $claims['tid']) && preg_match($uuidRe, $claims['aid'])
        ? Thread::loadFromDatabaseOrNone($claims['tid'])
        : null;
    $row = null;
    if ($thread !== null && $thread->canUserAccess($claims['sub'])) {
        $row = Database::queryOneOrNone(
            "SELECT tea.name, tea.filetype, tea.content IS NULL AS content_missing
             FROM thread_email_attachments tea
             JOIN thread_emails te ON te.id = tea.email_id
             WHERE te.thread_id = ? AND tea.id = ?",
            [$thread->id, $claims['aid']]
        );
    }
    if ($row === null || $row['content_missing'] === true || $row['content_missing'] === 't') {
        mcpDownloadError(404, 'Not found, or no access');
        exit;
    }

    $attachment = ThreadStorageManager::getInstance()->getThreadEmailAttachment($thread, $claims['aid']);
    // Same sanitising as np_attachment_get.php: attachment names are attacker-controlled.
    $safeName = preg_replace('/[\x00-\x1f\x7f"\\\\]/', '', $row['name']);
    if ($safeName === '') {
        $safeName = 'attachment';
    }
    header('Content-Type: ' . NpApiService::attachmentContentType($row['filetype']));
    header('Content-Disposition: attachment; filename="' . $safeName . '"');
    header('Content-Length: ' . strlen($attachment->content));
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    echo $attachment->content;
} catch (Throwable $e) {
    error_log('MCP attachment download error: ' . $e);
    if (ob_get_level() > 0) {
        ob_clean();
    }
    header_remove('Content-Disposition');
    header_remove('Content-Length');
    mcpDownloadError(500, 'Internal error');
}

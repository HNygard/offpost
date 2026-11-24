<?php
// Connected apps: the user's MCP/OAuth connections, with revoke. Needs an Offpost web session.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/class/common.php';
require_once __DIR__ . '/mcp-config.php';
require_once __DIR__ . '/class/OAuth/OAuthTokenService.php';

// Never framed or cached (the page carries a CSRF token).
header('X-Frame-Options: DENY');
header("Content-Security-Policy: frame-ancestors 'none'");
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

requireAuth();

function connectedAppsPlainError(int $status, string $message): void {
    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Connected apps</title></head><body><h1>Connected apps</h1><p>'
        . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></body></html>';
}

$tokenService = new OAuthTokenService($mcp_resource);
$userId = $_SESSION['user']['sub'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Same per-session token as the consent page.
    $csrf = $_POST['csrf'] ?? null;
    $sessionCsrf = $_SESSION['oauth_csrf'] ?? null;
    if (!is_string($csrf) || !is_string($sessionCsrf) || !hash_equals($sessionCsrf, $csrf)) {
        connectedAppsPlainError(400, 'Invalid request (CSRF check failed). Reload the page and try again.');
        return;
    }
    $tokenId = $_POST['token_id'] ?? null;
    if (!is_string($tokenId) || !ctype_digit($tokenId) || strlen($tokenId) > 18) {
        connectedAppsPlainError(400, 'Invalid request: token_id is missing or malformed.');
        return;
    }
    // Only the session user's own connections: revokeForUser matches on user id.
    if ($tokenService->revokeForUser($userId, (int) $tokenId)) {
        $_SESSION['success_message'] = 'The app was disconnected.';
    } else {
        $_SESSION['error_message'] = 'No such connection.';
    }
    header('Location: /connected-apps');
    http_response_code(303);
    return;
}

$_SESSION['oauth_csrf'] ??= bin2hex(random_bytes(32));
$csrf = $_SESSION['oauth_csrf'];
$tokens = $tokenService->listForUser($userId);
$formatTime = fn(string $t): string => date('Y-m-d H:i', strtotime($t));
$e = fn(?string $s): string => htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html>
<head>
    <?php
    $pageTitle = 'Connected apps - Offpost';
    include __DIR__ . '/head.php';
    ?>
</head>
<body>
    <div class="container">
        <?php include __DIR__ . '/header.php'; ?>

        <h1>Connected apps</h1>
        <p>Apps you have given access to your Offpost account over MCP. Access ends when you disconnect an app, or after 6 months without use.</p>

        <?php
        if (isset($_SESSION['success_message'])) {
            echo '<div class="alert alert-success">' . $e($_SESSION['success_message']) . '</div>';
            unset($_SESSION['success_message']);
        }
        if (isset($_SESSION['error_message'])) {
            echo '<div class="alert alert-error">' . $e($_SESSION['error_message']) . '</div>';
            unset($_SESSION['error_message']);
        }
        ?>

        <?php if (count($tokens) === 0): ?>
            <p>No connected apps.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr><th>App</th><th>Connected</th><th>Last used</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($tokens as $token): ?>
                    <tr>
                        <td><?= $e($token['client_name'] ?? 'Unnamed app') ?></td>
                        <td><?= $e($formatTime($token['created_at'])) ?></td>
                        <td><?= $e($formatTime($token['last_used_at'])) ?></td>
                        <td>
                            <form method="post" action="/connected-apps/revoke">
                                <input type="hidden" name="token_id" value="<?= $e((string) $token['id']) ?>">
                                <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                                <button type="submit">Disconnect</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</body>
</html>

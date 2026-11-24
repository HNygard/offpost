<?php
// Consent page template. Expects $clientName, $redirectHost, $userDisplay, $pendingId, $csrf.
$e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$pageTitle = 'Authorize access - Offpost';
?>
<!DOCTYPE html>
<html>
<head>
    <?php include __DIR__ . '/../../head.php'; ?>
</head>
<body>
    <div class="container">
        <h1><?= $e($clientName) ?> wants to access your Offpost account</h1>
        <p>It will be able to read your threads, start new requests and send emails in your name.</p>
        <p>Redirects to: <?= $e($redirectHost) ?></p>
        <p>Connected as: <?= $e($userDisplay) ?></p>
        <p>Access lasts until you revoke it under <a href="/connected-apps">Connected apps</a>, or after 6 months without use.</p>
        <form method="post" action="/oauth/authorize">
            <input type="hidden" name="pending_id" value="<?= $e($pendingId) ?>">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <button type="submit" name="decision" value="approve">Approve</button>
            <button type="submit" name="decision" value="deny">Deny</button>
        </form>
    </div>
</body>
</html>

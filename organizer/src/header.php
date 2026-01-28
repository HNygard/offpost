<?php
if (!isset($_SESSION)) {
    session_start();
}
?>
<div class="header-logo">
    <a href="/" style="text-decoration: none; color: inherit; display: flex; align-items: center;">
        <img src="/images/offpost-icon.png" alt="Offpost" class="logo" style="height: 3em;">
        Offpost
    </a>
</div>

<div class="user-info">
    <?php 
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($path !== '/'): ?>
        <div class="nav-back">
            <a href="/">← Back to threads</a>
        </div>
    <?php else: ?>
        <div class="spacer"></div>
    <?php endif; ?>

    <div class="login-info">
        Logged in as:
        <span title="<?= htmlspecialchars($_SESSION['user']['sub']) ?>">
            <?= htmlspecialchars($_SESSION['user']['name'] ?? $_SESSION['user']['email'] ?? 'Unknown User') ?>
        </span>
        <br>
        <a href="/logout">Logout</a>
    </div>
</div>

<?php if (in_array($_SESSION['user']['sub'], $admins)) { ?>
    <div style="font-size: 0.7em;">
        <h3 style="display: inline;">Admin tools:</h3>
        <ul class="nav-links" style="display: inline;">
            <li><a href="/email-sending-overview">Email sending overview</a></li>
            <li><a href="/extraction-overview">Email extraction overview</a></li>
            <li><a href="/thread-status-overview">Thread status overview</a></li>
            <li><a href="/openai-request-log-overview">OpenAI request log</a></li>
            <li><a href="/update-imap">Update IMAP</a></li>
            <li><a href="/update-identities">Update identities into Roundcube</a></li>
        </ul>
    </div>
<?php } ?>

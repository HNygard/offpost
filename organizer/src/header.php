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

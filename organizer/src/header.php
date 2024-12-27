<?php
if (!isset($_SESSION)) {
    session_start();
}
?>
<div class="user-info">
    <?php if (basename($_SERVER['PHP_SELF']) !== 'index.php'): ?>
        <div class="nav-back">
            <a href="./">← Back to threads</a>
        </div>
    <?php else: ?>
        <div class="spacer"></div>
    <?php endif; ?>
    <div class="login-info">
        Logged in as: <?= htmlspecialchars($_SESSION['user']['name'] ?? $_SESSION['user']['email'] ?? 'Unknown User') ?>
        <br>
        <a href="logout.php">Logout</a>
    </div>
</div>

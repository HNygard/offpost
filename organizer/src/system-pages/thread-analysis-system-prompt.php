<?php
// organizer/src/system-pages/thread-analysis-system-prompt.php
// Admin debug page showing one version of the analysis system prompt -
// step 2c, "Change 4b: admin debug pages"
// (docs/superpowers/plans/2026-09-27-step2c-analysis-in-prod.md). See
// docs/thread-analysis.md, "Debug pages".
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../class/Database.php';

// Require authentication
requireAuth();

$sha = $_GET['sha'] ?? '';
$shaRe = '/^[0-9a-f]{64}$/';
if (!preg_match($shaRe, $sha)) {
    http_response_code(400);
    header('Content-Type: text/plain');
    die("Invalid sha parameter");
}

$prompt = Database::queryOneOrNone(
    "SELECT sha256, text, created_at FROM thread_analysis_system_prompts WHERE sha256 = ?",
    [$sha]
);
if ($prompt === null) {
    http_response_code(404);
    header('Content-Type: text/plain');
    die("System prompt not found: sha={$sha}");
}

$runCount = (int) Database::queryValue(
    "SELECT COUNT(*) FROM thread_analysis_runs WHERE system_prompt_sha256 = ?",
    [$sha]
);

?>
<!DOCTYPE html>
<html>
<head>
    <?php
    $pageTitle = 'Thread Analysis - System Prompt - Offpost';
    include __DIR__ . '/../head.php';
    ?>
    <style>
        pre {
            white-space: pre-wrap;
            word-wrap: break-word;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php include __DIR__ . '/../header.php'; ?>

        <h1>System prompt <?= htmlspecialchars(substr($prompt['sha256'], 0, 8)) ?></h1>

        <p><strong>sha256:</strong> <?= htmlspecialchars($prompt['sha256']) ?></p>
        <p><strong>First used:</strong> <?= htmlspecialchars((string) $prompt['created_at']) ?></p>
        <p><strong>Runs using this version:</strong> <?= $runCount ?></p>

        <pre><?= htmlspecialchars($prompt['text']) ?></pre>
    </div>
</body>
</html>

<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../class/ThreadExportService.php';
require_once __DIR__ . '/../class/Entity.php';

// Require authentication
requireAuth();

// GET only, read-only page - no form handling.
$threads = ThreadExportService::listThreads();
$totalThreads = count($threads);

?>
<!DOCTYPE html>
<html>
<head>
    <?php
    $pageTitle = 'Thread Export - Offpost';
    include __DIR__ . '/../head.php';
    ?>
</head>
<body>
    <div class="container">
        <?php include __DIR__ . '/../header.php'; ?>

        <h1>Thread Export</h1>

        <p>
            Dumps every Offpost thread, with everything we know about it, to JSON, for local
            classification analysis. See <code>docs/thread-export-api.md</code> in the repository
            for the full API and CLI documentation.
        </p>

        <p>Total threads: <strong><?= (int) $totalThreads ?></strong></p>

        <p>To download every thread to a local folder, run the CLI from your own machine:</p>
        <pre>php tools/pull-thread-export.php --base-url=https://offpost.no --token-file=secrets/admin_api_token</pre>

        <p>
            The table below links straight to the per-thread download endpoint using your
            admin session; it does no bulk download - that is the CLI's job.
        </p>

        <table>
            <tr>
                <th>Title</th>
                <th>Entity</th>
                <th>Emails</th>
                <th>Last change</th>
                <th>Download</th>
            </tr>
            <?php foreach ($threads as $thread): ?>
                <?php
                $entityLabel = $thread['entity_id'];
                try {
                    $entity = Entity::getById($thread['entity_id']);
                    $entityLabel = $entity->name;
                } catch (Throwable $e) {
                    // Fall back to showing the raw entity_id.
                }
                ?>
                <tr>
                    <td><?= htmlspecialchars((string) ($thread['title'] ?? '')) ?></td>
                    <td><?= htmlspecialchars((string) $entityLabel) ?></td>
                    <td><?= (int) $thread['email_count'] ?></td>
                    <td><?= htmlspecialchars((string) ($thread['last_changed_at'] ?? '')) ?></td>
                    <td>
                        <a href="<?= htmlspecialchars('/api/admin/export/thread?id=' . $thread['id']) ?>" download>Download JSON</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($threads)): ?>
                <tr>
                    <td colspan="5" style="text-align: center;">No threads found</td>
                </tr>
            <?php endif; ?>
        </table>
    </div>
</body>
</html>

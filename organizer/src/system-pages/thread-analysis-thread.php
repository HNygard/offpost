<?php
// organizer/src/system-pages/thread-analysis-thread.php
// Admin debug page for one thread's analysis runs - step 2c, "Change 4b:
// admin debug pages" (docs/superpowers/plans/2026-09-27-step2c-analysis-in-prod.md).
// See docs/thread-analysis.md, "Debug pages".
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../class/common.php';
require_once __DIR__ . '/../class/Database.php';
require_once __DIR__ . '/../class/ThreadUtils.php';
require_once __DIR__ . '/../class/ThreadAnalysis/ThreadAnalysisRepository.php';

// Require authentication
requireAuth();

$threadId = $_GET['id'] ?? '';
if (!is_uuid($threadId)) {
    http_response_code(400);
    header('Content-Type: text/plain');
    die("Invalid id parameter");
}

$threadRow = Database::queryOneOrNone("SELECT id, title, entity_id FROM threads WHERE id = ?", [$threadId]);
if ($threadRow === null) {
    http_response_code(404);
    header('Content-Type: text/plain');
    die("Thread not found: id={$threadId}");
}

// Handle "Analyse" / "Analyse from the start" - request a run and redirect
// back to this page (avoids a form resubmission on refresh), following the
// POST handling style of system-pages/email-sending-overview.php.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'review') {
        $runId = (int) ($_POST['run_id'] ?? 0);
        $reviewStatus = (string) ($_POST['review_status'] ?? '');
        $reviewNotes = trim((string) ($_POST['review_notes'] ?? ''));
        $reviewedBy = $_SESSION['user']['sub'];
        try {
            ThreadAnalysisRepository::saveReview($runId, $reviewStatus, $reviewNotes === '' ? null : $reviewNotes, $reviewedBy);
        } catch (InvalidArgumentException $e) {
            http_response_code(400);
            header('Content-Type: text/plain');
            die($e->getMessage());
        }

        http_response_code(302);
        header('Location: /thread-analysis/thread?id=' . urlencode($threadId));
        exit;
    }

    $mode = $action === 'analyse_full' ? 'full' : ($action === 'analyse_incremental' ? 'incremental' : null);
    if ($mode === null) {
        http_response_code(400);
        header('Content-Type: text/plain');
        die("Unknown action: " . $action);
    }
    $requestedBy = $_SESSION['user']['sub'];
    ThreadAnalysisRepository::requestRun($threadId, $mode, $requestedBy);

    http_response_code(302);
    header('Location: /thread-analysis/thread?id=' . urlencode($threadId));
    exit;
}

$runs = array_reverse(ThreadAnalysisRepository::getRunsForThread($threadId));

// All of the thread's emails, for the (date, direction, subject) shown next
// to each event - one query for the whole page rather than one per event.
$emailRows = Database::query(
    "SELECT id, datetime_received, email_type AS direction, imap_headers FROM thread_emails WHERE thread_id = ?",
    [$threadId]
);
$emailsById = [];
foreach ($emailRows as $row) {
    $subject = $row['imap_headers'] !== null ? getEmailSubjectFromImapHeaders($row['imap_headers']) : '';
    $emailsById[$row['id']] = [
        'datetime_received' => $row['datetime_received'],
        'direction' => $row['direction'],
        'subject' => $subject,
    ];
}

function formatCostUsd($costUsd) {
    return '$' . number_format((float) $costUsd, 4);
}

function formatRunStatusBadge($status) {
    $class = match ($status) {
        'done' => 'label_ok',
        'failed' => 'label_error',
        'requested', 'claimed' => 'label_pending',
        'cancelled' => 'label_disabled',
        default => 'label_pending',
    };
    return '<span class="label ' . $class . '"><a href="#" onclick="return false;">' . htmlspecialchars($status) . '</a></span>';
}

function formatReviewStatusBadge($status) {
    $class = match ($status) {
        'CORRECT' => 'label_ok',
        'MINOR_ISSUES' => 'label_warn',
        'WRONG' => 'label_error',
        'NOT_REVIEWED' => 'label_pending',
        default => 'label_pending',
    };
    return '<span class="label ' . $class . '"><a href="#" onclick="return false;">' . htmlspecialchars($status) . '</a></span>';
}

function shortSha($sha) {
    return substr((string) $sha, 0, 8);
}

function formatRunDuration($claimedAt, $finishedAt) {
    if (!$claimedAt || !$finishedAt) {
        return 'N/A';
    }
    $seconds = strtotime($finishedAt) - strtotime($claimedAt);
    if ($seconds < 0) {
        return 'N/A';
    }
    return $seconds . 's';
}

function isTrueBool($value) {
    return $value === true || $value === 't' || $value === '1' || $value === 1;
}

?>
<!DOCTYPE html>
<html>
<head>
    <?php
    $pageTitle = 'Thread Analysis - ' . ($threadRow['title'] ?? $threadId) . ' - Offpost';
    include __DIR__ . '/../head.php';
    ?>
    <link href="/css/extractionDialog.css" rel="stylesheet">
    <link href="/css/contentDialog.css" rel="stylesheet">
    <script src="/js/contentDialog.js"></script>
    <style>
        h2 {
            margin-top: 2em;
        }
        h3 {
            margin-top: 1.5em;
        }
        pre {
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        .run-block {
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .call-block {
            border-top: 1px dashed #dee2e6;
            padding-top: 8px;
            margin-top: 8px;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php include __DIR__ . '/../header.php'; ?>

        <h1>
            Thread Analysis:
            <a href="<?= htmlspecialchars('/thread-view?threadId=' . $threadRow['id'] . '&entityId=' . $threadRow['entity_id']) ?>">
                <?= htmlspecialchars((string) ($threadRow['title'] ?? $threadRow['id'])) ?>
            </a>
        </h1>

        <form method="post" style="display:inline;">
            <input type="hidden" name="action" value="analyse_incremental">
            <button type="submit">Analyse</button>
        </form>
        <form method="post" style="display:inline;">
            <input type="hidden" name="action" value="analyse_full">
            <button type="submit">Analyse from the start</button>
        </form>

        <h2>Runs (<?= count($runs) ?>)</h2>

        <?php if (empty($runs)): ?>
            <p>No analysis runs for this thread yet.</p>
        <?php endif; ?>

        <?php foreach ($runs as $run): ?>
            <?php
            $events = ThreadAnalysisRepository::getEventsForRun((int) $run['id']);
            $calls = ThreadAnalysisRepository::getCallsForRun((int) $run['id']);
            $callsByEventId = [];
            foreach ($calls as $call) {
                $callsByEventId[$call['event_id']][] = $call;
            }
            ?>
            <div class="run-block">
                <h3>Run #<?= (int) $run['id'] ?> <?= formatRunStatusBadge($run['status']) ?></h3>
                <table>
                    <tr>
                        <th>Mode</th>
                        <th>Model</th>
                        <th>System prompt</th>
                        <th>Worker</th>
                        <th>Requested at</th>
                        <th>Claimed at</th>
                        <th>Finished at</th>
                        <th>Duration</th>
                        <th>Cost (USD)</th>
                        <th>Error</th>
                    </tr>
                    <tr>
                        <td><?= htmlspecialchars($run['mode']) ?></td>
                        <td><?= htmlspecialchars((string) ($run['model'] ?? '')) ?></td>
                        <td>
                            <?php if (!empty($run['system_prompt_sha256'])): ?>
                                <a href="<?= htmlspecialchars('/thread-analysis/system-prompt?sha=' . $run['system_prompt_sha256']) ?>">
                                    <?= htmlspecialchars(shortSha($run['system_prompt_sha256'])) ?>
                                </a>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars((string) ($run['worker'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string) ($run['requested_at'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string) ($run['claimed_at'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string) ($run['finished_at'] ?? '')) ?></td>
                        <td><?= htmlspecialchars(formatRunDuration($run['claimed_at'], $run['finished_at'])) ?></td>
                        <td>
                            <?php
                            $runCost = 0.0;
                            foreach ($calls as $call) {
                                $runCost += (float) ($call['cost_usd'] ?? 0);
                            }
                            echo formatCostUsd($runCost);
                            ?>
                        </td>
                        <td><?= htmlspecialchars((string) ($run['error'] ?? '')) ?></td>
                    </tr>
                </table>

                <?php if (in_array($run['status'], ['done', 'failed'], true)): ?>
                    <div class="review-block">
                        <form method="post">
                            <input type="hidden" name="action" value="review">
                            <input type="hidden" name="run_id" value="<?= (int) $run['id'] ?>">
                            <label>
                                Review status:
                                <select name="review_status">
                                    <?php foreach (ThreadAnalysisRepository::REVIEW_STATUSES as $statusOption): ?>
                                        <option value="<?= htmlspecialchars($statusOption) ?>" <?= $run['review_status'] === $statusOption ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($statusOption) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <br>
                            <label>
                                Notes:<br>
                                <textarea name="review_notes" rows="3" cols="60"><?= htmlspecialchars((string) ($run['review_notes'] ?? '')) ?></textarea>
                            </label>
                            <br>
                            <button type="submit">Save review</button>
                        </form>
                        <p class="review-saved">
                            <?= formatReviewStatusBadge($run['review_status']) ?>
                            <?php if (!empty($run['review_notes'])): ?>
                                <?= htmlspecialchars($run['review_notes']) ?>
                            <?php endif; ?>
                            <?php if (!empty($run['reviewed_by']) && !empty($run['reviewed_at'])): ?>
                                <em>by <?= htmlspecialchars($run['reviewed_by']) ?> at <?= htmlspecialchars((string) $run['reviewed_at']) ?></em>
                            <?php endif; ?>
                        </p>
                    </div>
                <?php endif; ?>

                <table>
                    <tr>
                        <th>#</th>
                        <th>Email</th>
                        <th>Email type</th>
                        <th>Note</th>
                        <th>Gap</th>
                        <th>Derived status</th>
                        <th>Attempts</th>
                        <th>Error</th>
                        <th>State</th>
                    </tr>
                    <?php foreach ($events as $event): ?>
                        <?php
                        $emailInfo = $emailsById[$event['email_id']] ?? null;
                        ?>
                        <tr>
                            <td><?= (int) $event['position'] ?></td>
                            <td>
                                <?php if ($emailInfo !== null): ?>
                                    <?= htmlspecialchars((string) $emailInfo['datetime_received']) ?>
                                    (<?= htmlspecialchars((string) $emailInfo['direction']) ?>)
                                    <br>
                                    <?= htmlspecialchars((string) $emailInfo['subject']) ?>
                                <?php else: ?>
                                    <?= htmlspecialchars((string) $event['email_id']) ?>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars((string) ($event['email_type'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string) ($event['email_note'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string) ($event['email_type_gap'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string) ($event['derived_thread_state_type'] ?? '')) ?></td>
                            <td><?= (int) $event['attempts'] ?></td>
                            <td><?= htmlspecialchars((string) ($event['error'] ?? '')) ?></td>
                            <td>
                                <?php if ($event['thread_state'] !== null): ?>
                                    <?php $stateTemplateId = 'analysis-state-' . htmlspecialchars((string) $event['id']); ?>
                                    <a href="#" class="content-dialog-link" data-dialog-title="State after this event" data-dialog-template="<?= $stateTemplateId ?>">Show state</a>
                                    <template id="<?= $stateTemplateId ?>"><pre><?= htmlspecialchars(json_encode($event['thread_state'], JSON_PRETTY_PRINT)) ?></pre></template>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php $eventCalls = $callsByEventId[$event['id']] ?? []; ?>
                        <?php if (!empty($eventCalls)): ?>
                            <tr>
                                <td></td>
                                <td colspan="8">
                                    <table>
                                        <tr>
                                            <th>Attempt</th>
                                            <th>Model</th>
                                            <th>Model resolved</th>
                                            <th>Claude Code version</th>
                                            <th>Input</th>
                                            <th>Cache creation</th>
                                            <th>Cache read</th>
                                            <th>Output</th>
                                            <th>Thinking</th>
                                            <th>Cost (USD)</th>
                                            <th>Duration</th>
                                            <th>Is error</th>
                                            <th>Stop reason</th>
                                            <th>Input text</th>
                                            <th>Response</th>
                                        </tr>
                                        <?php foreach ($eventCalls as $call): ?>
                                            <tr class="call-block">
                                                <td><?= (int) $call['attempt'] ?></td>
                                                <td><?= htmlspecialchars((string) ($call['model'] ?? '')) ?></td>
                                                <td><?= htmlspecialchars((string) ($call['model_resolved'] ?? '')) ?></td>
                                                <td><?= htmlspecialchars((string) ($call['claude_code_version'] ?? '')) ?></td>
                                                <td><?= number_format((int) ($call['input_tokens'] ?? 0)) ?></td>
                                                <td><?= number_format((int) ($call['cache_creation_input_tokens'] ?? 0)) ?></td>
                                                <td><?= number_format((int) ($call['cache_read_input_tokens'] ?? 0)) ?></td>
                                                <td><?= number_format((int) ($call['output_tokens'] ?? 0)) ?></td>
                                                <td><?= number_format((int) ($call['thinking_tokens'] ?? 0)) ?></td>
                                                <td><?= formatCostUsd($call['cost_usd'] ?? 0) ?></td>
                                                <td><?= $call['duration_ms'] !== null ? number_format((int) $call['duration_ms']) . 'ms' : '' ?></td>
                                                <td><?= isTrueBool($call['is_error']) ? 'yes' : 'no' ?></td>
                                                <td><?= htmlspecialchars((string) ($call['stop_reason'] ?? '')) ?></td>
                                                <td>
                                                    <?php $inputTemplateId = 'analysis-input-' . htmlspecialchars((string) $call['id']); ?>
                                                    <a href="#" class="content-dialog-link" data-dialog-title="Call input text" data-dialog-template="<?= $inputTemplateId ?>">Show input</a>
                                                    <template id="<?= $inputTemplateId ?>"><pre><?= htmlspecialchars((string) $call['input_text']) ?></pre></template>
                                                </td>
                                                <td>
                                                    <?php if ($call['response'] !== null): ?>
                                                        <?php $responseTemplateId = 'analysis-response-' . htmlspecialchars((string) $call['id']); ?>
                                                        <a href="#" class="content-dialog-link" data-dialog-title="Call response" data-dialog-template="<?= $responseTemplateId ?>">Show response</a>
                                                        <template id="<?= $responseTemplateId ?>"><pre><?= htmlspecialchars(json_encode($call['response'], JSON_PRETTY_PRINT)) ?></pre></template>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </table>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if (empty($events)): ?>
                        <tr><td colspan="9" style="text-align: center;">No events for this run</td></tr>
                    <?php endif; ?>
                </table>
            </div>
        <?php endforeach; ?>
    </div>
</body>
</html>

<?php
// organizer/src/system-pages/thread-analysis.php
// Admin debug overview for the analysis queue - step 2c, "Change 4b: admin
// debug pages" (docs/superpowers/plans/2026-09-27-step2c-analysis-in-prod.md).
// See docs/thread-analysis.md, "Debug pages".
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../class/ThreadAnalysis/ThreadAnalysisStats.php';
require_once __DIR__ . '/../class/ThreadAnalysis/ThreadAnalysisRepository.php';

// Require authentication
requireAuth();

// GET only, read-only page - no form handling.

$runCountsByStatus = ThreadAnalysisStats::getRunCountsByStatus();
$runCountsByReviewStatus = ThreadAnalysisStats::getRunCountsByReviewStatus();
$runsWithIssues = ThreadAnalysisRepository::getReviews(['MINOR_ISSUES', 'WRONG'], 100);
$usageTotals = ThreadAnalysisStats::getUsageTotals();
$costByModel = ThreadAnalysisStats::getCostByModel();
$costBySystemPrompt = ThreadAnalysisStats::getCostBySystemPrompt();
$queue = ThreadAnalysisStats::getQueue();
$recentRuns = ThreadAnalysisStats::getRecentRuns(100);
$emailTypeGaps = ThreadAnalysisStats::getEmailTypeGaps(100);
$disagreements = ThreadAnalysisStats::getDisagreements(100);

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

?>
<!DOCTYPE html>
<html>
<head>
    <?php
    $pageTitle = 'Thread Analysis - Offpost';
    include __DIR__ . '/../head.php';
    ?>
    <style>
        .summary-box {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 20px;
            display: flex;
            flex-wrap: wrap;
            justify-content: space-around;
        }
        .summary-item {
            text-align: center;
            margin: 10px;
            min-width: 140px;
        }
        .summary-count {
            font-size: 1.5em;
            font-weight: bold;
        }
        .summary-label {
            color: #666;
        }
        h2 {
            margin-top: 2em;
        }
        pre {
            white-space: pre-wrap;
            word-wrap: break-word;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php include __DIR__ . '/../header.php'; ?>

        <h1>Thread Analysis</h1>

        <h2>Runs by status</h2>
        <div class="summary-box">
            <?php if (empty($runCountsByStatus)): ?>
                <div class="summary-item">
                    <div class="summary-count">0</div>
                    <div class="summary-label">No runs yet</div>
                </div>
            <?php endif; ?>
            <?php foreach ($runCountsByStatus as $status => $count): ?>
                <div class="summary-item">
                    <div class="summary-count"><?= (int) $count ?></div>
                    <div class="summary-label"><?= htmlspecialchars($status) ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <h2>Runs by review status</h2>
        <div class="summary-box">
            <?php if (empty($runCountsByReviewStatus)): ?>
                <div class="summary-item">
                    <div class="summary-count">0</div>
                    <div class="summary-label">No runs yet</div>
                </div>
            <?php endif; ?>
            <?php foreach ($runCountsByReviewStatus as $status => $count): ?>
                <div class="summary-item">
                    <div class="summary-count"><?= (int) $count ?></div>
                    <div class="summary-label"><?= htmlspecialchars($status) ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <h2>Cost and tokens</h2>
        <table>
            <tr>
                <th>Period</th>
                <th>Calls</th>
                <th>Cost (USD)</th>
                <th>Input tokens</th>
                <th>Cache creation tokens</th>
                <th>Cache read tokens</th>
                <th>Output tokens</th>
                <th>Thinking tokens</th>
            </tr>
            <?php foreach (['today' => 'Today', 'last_7_days' => 'Last 7 days', 'all_time' => 'All time'] as $key => $label): ?>
                <?php $totals = $usageTotals[$key]; ?>
                <tr>
                    <td><?= htmlspecialchars($label) ?></td>
                    <td><?= (int) $totals['calls'] ?></td>
                    <td><?= formatCostUsd($totals['cost_usd']) ?></td>
                    <td><?= number_format($totals['input_tokens']) ?></td>
                    <td><?= number_format($totals['cache_creation_input_tokens']) ?></td>
                    <td><?= number_format($totals['cache_read_input_tokens']) ?></td>
                    <td><?= number_format($totals['output_tokens']) ?></td>
                    <td><?= number_format($totals['thinking_tokens']) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>

        <h2>Cost per model</h2>
        <table>
            <tr>
                <th>Model</th>
                <th>Calls</th>
                <th>Cost (USD)</th>
            </tr>
            <?php foreach ($costByModel as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($row['model'] ?? '(none)') ?></td>
                    <td><?= (int) $row['calls'] ?></td>
                    <td><?= formatCostUsd($row['cost_usd']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($costByModel)): ?>
                <tr><td colspan="3" style="text-align: center;">No calls yet</td></tr>
            <?php endif; ?>
        </table>

        <h2>Cost per system prompt version</h2>
        <table>
            <tr>
                <th>System prompt</th>
                <th>First used</th>
                <th>Calls</th>
                <th>Cost (USD)</th>
            </tr>
            <?php foreach ($costBySystemPrompt as $row): ?>
                <tr>
                    <td><a href="<?= htmlspecialchars('/thread-analysis/system-prompt?sha=' . $row['sha256']) ?>"><?= htmlspecialchars(shortSha($row['sha256'])) ?></a></td>
                    <td><?= htmlspecialchars((string) $row['first_used_at']) ?></td>
                    <td><?= (int) $row['calls'] ?></td>
                    <td><?= formatCostUsd($row['cost_usd']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($costBySystemPrompt)): ?>
                <tr><td colspan="4" style="text-align: center;">No calls yet</td></tr>
            <?php endif; ?>
        </table>

        <h2>Queue (<?= count($queue) ?>)</h2>
        <table>
            <tr>
                <th>Thread</th>
                <th>Status</th>
                <th>Mode</th>
                <th>Requested by</th>
                <th>Requested at</th>
                <th>Worker</th>
                <th>Lease expires</th>
            </tr>
            <?php foreach ($queue as $run): ?>
                <tr>
                    <td><a href="<?= htmlspecialchars('/thread-analysis/thread?id=' . $run['thread_id']) ?>"><?= htmlspecialchars((string) ($run['thread_title'] ?? $run['thread_id'])) ?></a></td>
                    <td><?= formatRunStatusBadge($run['status']) ?></td>
                    <td><?= htmlspecialchars($run['mode']) ?></td>
                    <td><?= htmlspecialchars($run['requested_by']) ?></td>
                    <td><?= htmlspecialchars((string) $run['requested_at']) ?></td>
                    <td><?= htmlspecialchars((string) ($run['worker'] ?? '')) ?></td>
                    <td><?= htmlspecialchars((string) ($run['lease_expires_at'] ?? '')) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($queue)): ?>
                <tr><td colspan="7" style="text-align: center;">Nothing queued</td></tr>
            <?php endif; ?>
        </table>

        <h2>Recent runs (up to 100)</h2>
        <table>
            <tr>
                <th>Thread</th>
                <th>Status</th>
                <th>Mode</th>
                <th>Model</th>
                <th>Events</th>
                <th>Cost (USD)</th>
                <th>Duration</th>
                <th>Error</th>
            </tr>
            <?php foreach ($recentRuns as $run): ?>
                <tr>
                    <td><a href="<?= htmlspecialchars('/thread-analysis/thread?id=' . $run['thread_id']) ?>"><?= htmlspecialchars((string) ($run['thread_title'] ?? $run['thread_id'])) ?></a></td>
                    <td><?= formatRunStatusBadge($run['status']) ?></td>
                    <td><?= htmlspecialchars($run['mode']) ?></td>
                    <td><?= htmlspecialchars((string) ($run['model'] ?? '')) ?></td>
                    <td><?= (int) $run['event_count'] ?></td>
                    <td><?= formatCostUsd($run['cost_usd']) ?></td>
                    <td><?= htmlspecialchars(formatRunDuration($run['claimed_at'], $run['finished_at'])) ?></td>
                    <td><?= htmlspecialchars((string) ($run['error'] ?? '')) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($recentRuns)): ?>
                <tr><td colspan="8" style="text-align: center;">No runs yet</td></tr>
            <?php endif; ?>
        </table>

        <h2>Email-type gaps (up to 100)</h2>
        <p>Events where the model noted a gap between what it expected and the email's classification.</p>
        <table>
            <tr>
                <th>Thread</th>
                <th>Email</th>
                <th>Email type</th>
                <th>Gap</th>
            </tr>
            <?php foreach ($emailTypeGaps as $gap): ?>
                <tr>
                    <td><a href="<?= htmlspecialchars('/thread-analysis/thread?id=' . $gap['thread_id']) ?>"><?= htmlspecialchars((string) ($gap['thread_title'] ?? $gap['thread_id'])) ?></a></td>
                    <td><?= htmlspecialchars((string) $gap['email_id']) ?> (#<?= (int) $gap['position'] ?>)</td>
                    <td><?= htmlspecialchars((string) ($gap['email_type'] ?? '')) ?></td>
                    <td><?= htmlspecialchars((string) $gap['email_type_gap']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($emailTypeGaps)): ?>
                <tr><td colspan="4" style="text-align: center;">No gaps recorded</td></tr>
            <?php endif; ?>
        </table>

        <h2>Disagreements with prod's classification (up to 100)</h2>
        <p>
            The latest completed run's events whose <code>email_type</code> differs from
            <code>thread_emails.status_type</code>. Prod's <code>unknown</code> and legacy values are left out.
        </p>
        <table>
            <tr>
                <th>Thread</th>
                <th>Email</th>
                <th>Analysis says</th>
                <th>Prod says</th>
                <th>Prod's value is</th>
            </tr>
            <?php foreach ($disagreements as $row): ?>
                <tr>
                    <td><a href="<?= htmlspecialchars('/thread-analysis/thread?id=' . $row['thread_id']) ?>"><?= htmlspecialchars((string) ($row['thread_title'] ?? $row['thread_id'])) ?></a></td>
                    <td><?= htmlspecialchars((string) $row['email_id']) ?> (#<?= (int) $row['position'] ?>)</td>
                    <td><?= htmlspecialchars((string) $row['analysis_email_type']) ?></td>
                    <td><?= htmlspecialchars((string) $row['prod_status_type']) ?></td>
                    <td><?= htmlspecialchars($row['prod_classification_source']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($disagreements)): ?>
                <tr><td colspan="5" style="text-align: center;">No disagreements found</td></tr>
            <?php endif; ?>
        </table>

        <h2>Runs with issues (up to 100)</h2>
        <p>Runs an admin reviewed as <code>MINOR_ISSUES</code> or <code>WRONG</code> - see "Change 9" in
            <code>docs/thread-analysis.md</code> for the local fix loop.</p>
        <table>
            <tr>
                <th>Thread</th>
                <th>Review status</th>
                <th>Notes</th>
                <th>System prompt</th>
                <th>Reviewed by</th>
            </tr>
            <?php foreach ($runsWithIssues as $run): ?>
                <tr>
                    <td><a href="<?= htmlspecialchars('/thread-analysis/thread?id=' . $run['thread_id']) ?>"><?= htmlspecialchars((string) ($run['thread_title'] ?? $run['thread_id'])) ?></a></td>
                    <td><?= formatReviewStatusBadge($run['review_status']) ?></td>
                    <td><?= htmlspecialchars((string) ($run['review_notes'] ?? '')) ?></td>
                    <td>
                        <?php if (!empty($run['system_prompt_sha256'])): ?>
                            <a href="<?= htmlspecialchars('/thread-analysis/system-prompt?sha=' . $run['system_prompt_sha256']) ?>">
                                <?= htmlspecialchars(shortSha($run['system_prompt_sha256'])) ?>
                            </a>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars((string) ($run['reviewed_by'] ?? '')) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($runsWithIssues)): ?>
                <tr><td colspan="5" style="text-align: center;">No runs with issues</td></tr>
            <?php endif; ?>
        </table>
    </div>
</body>
</html>

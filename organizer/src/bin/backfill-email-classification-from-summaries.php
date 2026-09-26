<?php
// Backfill email classification from already-stored AI summaries, plus the
// subject/attachment rules (ThreadEmailResponseClassifier).
//
// The summary extraction (prompt_id 'thread-email-summary') has been running on
// cron, but ThreadEmailStatusUpdater was never wired in - so incoming emails
// stayed status_type 'unknown' even when a summary existed. This applies the
// stored summaries to unclassified incoming emails, then runs the rules on the
// unclassified emails that have no summary. No OpenAI calls are made.
//
// --include-auto also re-classifies incoming emails that were classified
// automatically before ('prompt' or 'algo'), so improved rules reach old data.
// Manual classifications are never touched (updater guard).
//
// Usage (in the organizer container):
//   php bin/backfill-email-classification-from-summaries.php [--dry-run] [--limit=N] [--include-auto]

require_once __DIR__ . '/../class/Database.php';
require_once __DIR__ . '/../class/Extraction/ThreadEmailStatusUpdater.php';

$dryRun = in_array('--dry-run', $argv);
$includeAuto = in_array('--include-auto', $argv);
$limit = 0;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = (int)substr($arg, strlen('--limit='));
    }
}

$statusFilter = $includeAuto
    ? "(e.status_type IS NULL OR e.status_type IN ('unknown', 'UNKNOWN') OR e.auto_classification IS NOT NULL)"
    : "(e.status_type IS NULL OR e.status_type IN ('unknown', 'UNKNOWN'))";
$sql = "SELECT DISTINCT ON (e.id) e.id AS email_id, e.status_type, x.extracted_text
        FROM thread_emails e
        JOIN thread_email_extractions x ON x.email_id::text = e.id::text
        WHERE e.email_type = 'IN'
          AND $statusFilter
          AND COALESCE(e.ignore, false) = false
          AND x.prompt_id = 'thread-email-summary'
          AND x.extracted_text IS NOT NULL
          AND trim(x.extracted_text) != ''
        ORDER BY e.id, x.updated_at DESC";
if ($limit > 0) {
    $sql .= " LIMIT " . $limit;
}

$rows = Database::query($sql, []);
echo count($rows) . " incoming emails with a stored summary to (re)classify\n";

$updater = new ThreadEmailStatusUpdater();
$applied = 0;
$skipped = 0;
if ($dryRun) {
    // The updater writes; run everything in a transaction that is rolled back,
    // so the dry run shows the real outcome, subject and attachment rules included.
    Database::beginTransaction();
}
foreach ($rows as $row) {
    if ($updater->updateFromAISummary($row['email_id'], $row['extracted_text'])) {
        $applied++;
        if ($dryRun) {
            $new = Database::queryValue("SELECT status_type FROM thread_emails WHERE id = ?", [$row['email_id']]);
            if ($new !== $row['status_type']) {
                echo "would classify " . $row['email_id'] . " " . ($row['status_type'] ?? 'NULL') . " -> $new: "
                    . mb_substr($row['extracted_text'], 0, 80) . "...\n";
            }
        }
    }
    else {
        $skipped++;
    }
}
echo "summary: applied: $applied, skipped (manually classified): $skipped\n";

// Unclassified emails without a summary: subject and attachment rules.
$rules = $updater->classifyPendingByRules($limit > 0 ? $limit : 100000);
echo "rules: found {$rules['found']} unclassified emails, classified: " . json_encode($rules['classified']) . "\n";

if ($dryRun) {
    Database::rollBack();
    echo "dry run: rolled back\n";
}
else {
    echo "Resulting distribution:\n";
    foreach (Database::query("SELECT status_type, count(*) FROM thread_emails WHERE email_type = 'IN' GROUP BY 1 ORDER BY 2 DESC", []) as $r) {
        echo "  " . ($r['status_type'] ?? 'NULL') . ": " . $r['count'] . "\n";
    }
}

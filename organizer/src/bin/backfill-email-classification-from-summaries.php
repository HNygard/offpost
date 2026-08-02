<?php
// Backfill email classification from already-stored AI summaries.
//
// The summary extraction (prompt_id 'thread-email-summary') has been running on
// cron, but ThreadEmailStatusUpdater was never wired in - so incoming emails
// stayed status_type 'unknown' even when a summary existed. This applies the
// stored summaries to unclassified incoming emails. No OpenAI calls are made.
//
// Idempotent; manual classifications are never touched (updater guard).
//
// Usage (in the organizer container):
//   php bin/backfill-email-classification-from-summaries.php [--dry-run] [--limit=N]

require_once __DIR__ . '/../class/Database.php';
require_once __DIR__ . '/../class/Extraction/ThreadEmailStatusUpdater.php';

$dryRun = in_array('--dry-run', $argv);
$limit = 0;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = (int)substr($arg, strlen('--limit='));
    }
}

$sql = "SELECT DISTINCT ON (e.id) e.id AS email_id, x.extracted_text
        FROM thread_emails e
        JOIN thread_email_extractions x ON x.email_id::text = e.id::text
        WHERE e.email_type = 'IN'
          AND (e.status_type IS NULL OR e.status_type IN ('unknown', 'UNKNOWN'))
          AND COALESCE(e.ignore, false) = false
          AND x.prompt_id = 'thread-email-summary'
          AND x.extracted_text IS NOT NULL
          AND trim(x.extracted_text) != ''
        ORDER BY e.id, x.updated_at DESC";
if ($limit > 0) {
    $sql .= " LIMIT " . $limit;
}

$rows = Database::query($sql, []);
echo count($rows) . " unclassified incoming emails with a stored summary\n";

$updater = new ThreadEmailStatusUpdater();
$applied = 0;
$skipped = 0;
foreach ($rows as $row) {
    if ($dryRun) {
        echo "would classify " . $row['email_id'] . ": " . mb_substr($row['extracted_text'], 0, 80) . "...\n";
        continue;
    }
    if ($updater->updateFromAISummary($row['email_id'], $row['extracted_text'])) {
        $applied++;
    }
    else {
        $skipped++;
    }
}
if (!$dryRun) {
    echo "applied: $applied, skipped (already classified): $skipped\n";
    echo "Resulting distribution:\n";
    foreach (Database::query("SELECT status_type, count(*) FROM thread_emails WHERE email_type = 'IN' GROUP BY 1 ORDER BY 2 DESC", []) as $r) {
        echo "  " . ($r['status_type'] ?? 'NULL') . ": " . $r['count'] . "\n";
    }
}

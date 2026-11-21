<?php
// Find stored attachments that do not match the MIME part of the same name in
// the raw EML, and optionally repair them from the EML.
//
// Without --apply this is READ-ONLY: only SELECTs. It prints the findings and,
// per email, the repair actions it would take ("would fix_content ...",
// "would add_missing ..."). With --apply those actions are done, one
// transaction per email (see AttachmentPartRepair).
//
// Until the partNumber fix, ThreadEmailDatabaseSaver fetched attachment content
// by position among the attachments it kept, so an unsupported part (e.g. a
// .webp) shifted every later attachment onto the bytes of the part before it.
// Reported case: Gjesdal kommune, "Mobilabonnement Gjesdal.pdf" held the
// org chart webp. See AttachmentPartCheck and docs/email-parsing.md.
//
// Prints one line per finding that is not ok (ids, names, part numbers - no
// attachment content), then counts per status.
//
// Usage (in the organizer container):
//   php bin/attachment-part-check.php [--email=<email_id>] [--thread=<thread_id>] [--limit=N] [--all] [--apply]
//
// By default only incoming emails are checked; --all includes outgoing.

require_once __DIR__ . '/../class/Database.php';
require_once __DIR__ . '/../class/AttachmentPartCheck.php';
require_once __DIR__ . '/../class/AttachmentPartRepair.php';

ini_set('error_log', '/dev/null');
ini_set('display_errors', 'stderr');
// One email is held several times over (raw EML, header-repaired copy, parsed parts,
// stored attachments). Emails with ~70 MB of attachments exceed the default limit.
ini_set('memory_limit', '2G');

$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if (!preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
        fwrite(STDERR, "Unknown argument: $arg\n");
        exit(1);
    }
    $options[$m[1]] = $m[2] ?? true;
}
$limit = isset($options['limit']) ? (int)$options['limit'] : 0;
$apply = isset($options['apply']);

function bytes($value): ?string {
    if ($value === null) {
        return null;
    }
    if (is_resource($value)) {
        $value = stream_get_contents($value);
    }
    if (is_string($value) && str_starts_with($value, '\\x')) {
        return hex2bin(substr($value, 2));
    }
    return (string)$value;
}

$where = ['content IS NOT NULL'];
$params = [];
if (isset($options['email'])) {
    $where[] = 'id = ?';
    $params[] = $options['email'];
}
if (isset($options['thread'])) {
    $where[] = 'thread_id = ?';
    $params[] = $options['thread'];
}
if (!isset($options['all'])) {
    $where[] = "email_type = 'IN'";
}
$ids = Database::query(
    'SELECT id, thread_id FROM thread_emails WHERE ' . implode(' AND ', $where) . ' ORDER BY datetime_received',
    $params
);

$counts = [];
$actionCount = 0;
$done = 0;
foreach ($ids as $row) {
    $eml = bytes(Database::queryValue('SELECT content FROM thread_emails WHERE id = ?', [$row['id']]));
    $stored = array_map(
        fn($a) => ['id' => $a['id'], 'name' => $a['name'], 'filename' => $a['filename'], 'content' => bytes($a['content'])],
        Database::query(
            'SELECT id, name, filename, content FROM thread_email_attachments WHERE email_id = ? ORDER BY created_at, location',
            [$row['id']]
        )
    );

    try {
        $findings = AttachmentPartCheck::check($eml, $stored);
    }
    catch (Throwable $e) {
        $findings = [[
            'status' => 'parse_error', 'attachment_id' => null,
            'name' => get_class($e) . ': ' . $e->getMessage(), 'part_number' => null, 'content_matches_part' => null,
        ]];
    }

    foreach ($findings as $f) {
        $counts[$f['status']] = ($counts[$f['status']] ?? 0) + 1;
        if ($f['status'] === AttachmentPartCheck::OK) {
            continue;
        }
        echo implode("\t", [
            $f['status'],
            'thread=' . $row['thread_id'],
            'email=' . $row['id'],
            'attachment=' . ($f['attachment_id'] ?? '-'),
            'part=' . ($f['part_number'] ?? '-'),
            'content_is_part=' . ($f['content_matches_part'] ?? '-'),
            $f['name'],
        ]) . "\n";
    }

    if (!empty($findings) && $findings[0]['status'] !== 'parse_error') {
        if ($apply) {
            Database::beginTransaction();
        }
        try {
            $actions = AttachmentPartRepair::repairEmail($row['id'], $eml, $findings, $apply);
            if ($apply) {
                Database::commit();
            }
        }
        catch (Throwable $e) {
            if ($apply) {
                Database::rollBack();
            }
            $actions = [];
            echo "repair_error\temail={$row['id']}\t" . get_class($e) . ': ' . $e->getMessage() . "\n";
        }
        foreach ($actions as $action) {
            echo ($apply ? 'done ' : 'would ') . $action . "\temail={$row['id']}\n";
        }
        $actionCount += count($actions);
    }

    // Free this email before the next one is loaded, so two large emails are never in
    // memory at once.
    unset($eml, $stored, $findings);

    $done++;
    if ($done % 500 === 0) {
        fwrite(STDERR, "... $done emails\n");
    }
    if ($limit > 0 && $done >= $limit) {
        break;
    }
}

ksort($counts);
echo "\nChecked $done emails. Repair actions " . ($apply ? 'done' : 'that --apply would do') . ": $actionCount\n";
foreach ($counts as $status => $count) {
    echo "  $status: $count\n";
}

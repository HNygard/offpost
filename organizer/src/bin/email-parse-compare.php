<?php
// Compare the current email parsing (laminas) with the replacement
// (MimeEmailReader) on stored emails. READ-ONLY: only SELECTs, nothing is
// written to the database.
//
// For every thread_emails row both parsers produce an EmailParseSnapshot
// (subject, X-Forwarded-For, plain text, HTML text, parse error), plus the
// subject from imap_headers decoded both ways. Each field is categorised by
// EmailParseComparison: equal, whitespace_only, changed, old_failed_new_ok,
// new_failed (regression). See docs/email-parsing.md.
//
// The summary contains counts and email ids only. --show prints one email's
// parsed content to this console; --export-eml writes one raw EML to a file
// (to turn into an anonymised fixture). Neither is sent anywhere else.
//
// Usage (in the organizer container):
//   php bin/email-parse-compare.php [--limit=N] [--since=YYYY-MM-DD] [--examples=N]
//   php bin/email-parse-compare.php --show=<email_id>
//   php bin/email-parse-compare.php --export-eml=<email_id> --out=<file.eml>

require_once __DIR__ . '/../class/Database.php';
require_once __DIR__ . '/../class/Extraction/EmailParseSnapshot.php';
require_once __DIR__ . '/../class/Extraction/EmailParseComparison.php';

// The current extractor error_log()s the whole EML on parse failures. Keep
// email content out of the container logs during this run. Errors of this
// script itself must still be seen, so they go to stderr.
ini_set('error_log', '/dev/null');
ini_set('display_errors', 'stderr');

$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if (!preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
        fwrite(STDERR, "Unknown argument: $arg\n");
        exit(1);
    }
    $options[$m[1]] = $m[2] ?? true;
}
$limit = isset($options['limit']) ? (int)$options['limit'] : 0;
$since = $options['since'] ?? null;
$examples = isset($options['examples']) ? (int)$options['examples'] : 10;

function emailContent($value): string {
    if (is_resource($value)) {
        $value = stream_get_contents($value);
    }
    if (is_string($value) && str_starts_with($value, '\\x')) {
        return hex2bin(substr($value, 2));
    }
    return (string)$value;
}

function loadEmail(string $id): array {
    $row = Database::queryOneOrNone(
        "SELECT id, thread_id, email_type, datetime_received, imap_headers, content FROM thread_emails WHERE id = ?",
        [$id]
    );
    if (!$row) {
        fwrite(STDERR, "No thread_emails row with id $id\n");
        exit(1);
    }
    return $row;
}

if (isset($options['export-eml'])) {
    if (!isset($options['out'])) {
        fwrite(STDERR, "--export-eml needs --out=<file.eml>\n");
        exit(1);
    }
    $row = loadEmail($options['export-eml']);
    file_put_contents($options['out'], emailContent($row['content']));
    echo "Wrote {$options['out']}. Anonymise it before adding it as a fixture.\n";
    exit(0);
}

if (isset($options['show'])) {
    $row = loadEmail($options['show']);
    $eml = emailContent($row['content']);
    $old = EmailParseSnapshot::fromEml($eml, EmailParseSnapshot::PARSER_LAMINAS);
    $new = EmailParseSnapshot::fromEml($eml, EmailParseSnapshot::PARSER_MIME);
    $old['imap_subject'] = EmailParseSnapshot::subjectFromImapHeaders($row['imap_headers'], EmailParseSnapshot::PARSER_LAMINAS);
    $new['imap_subject'] = EmailParseSnapshot::subjectFromImapHeaders($row['imap_headers'], EmailParseSnapshot::PARSER_MIME);
    $categories = EmailParseComparison::compare($old, $new);
    $categories['imap_subject'] = EmailParseComparison::compareSubject($old['imap_subject'], $new['imap_subject']);

    $json = fn($v) => json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    echo "Email {$row['id']} (thread {$row['thread_id']}, {$row['email_type']}, {$row['datetime_received']})\n";
    foreach ($categories as $field => $category) {
        echo "\n== $field: $category\n";
        if ($category === EmailParseComparison::EQUAL) {
            continue;
        }
        echo "-- old:\n" . $json($old[$field]) . "\n-- new:\n" . $json($new[$field]) . "\n";
    }
    echo "\n== parse_error\n-- old: " . $json($old['parse_error']) . "\n-- new: " . $json($new['parse_error']) . "\n";
    exit(0);
}

$comparison = new EmailParseComparison($examples);
$seconds = ['laminas' => 0.0, 'mime' => 0.0];
$lastId = '00000000-0000-0000-0000-000000000000';
$done = 0;
$batchSize = 100;
while (true) {
    $params = [$lastId];
    $where = 'id > ?';
    if ($since !== null) {
        $where .= ' AND datetime_received >= ?';
        $params[] = $since;
    }
    $rows = Database::query(
        "SELECT id, imap_headers, content FROM thread_emails WHERE $where ORDER BY id LIMIT $batchSize",
        $params
    );
    if (!$rows) {
        break;
    }
    foreach ($rows as $row) {
        $eml = emailContent($row['content']);

        $start = microtime(true);
        $old = EmailParseSnapshot::fromEml($eml, EmailParseSnapshot::PARSER_LAMINAS);
        $oldImapSubject = EmailParseSnapshot::subjectFromImapHeaders($row['imap_headers'], EmailParseSnapshot::PARSER_LAMINAS);
        $seconds['laminas'] += microtime(true) - $start;

        $start = microtime(true);
        $new = EmailParseSnapshot::fromEml($eml, EmailParseSnapshot::PARSER_MIME);
        $newImapSubject = EmailParseSnapshot::subjectFromImapHeaders($row['imap_headers'], EmailParseSnapshot::PARSER_MIME);
        $seconds['mime'] += microtime(true) - $start;

        $categories = EmailParseComparison::compare($old, $new);
        $categories['imap_subject'] = EmailParseComparison::compareSubject($oldImapSubject, $newImapSubject);
        $comparison->add($row['id'], $categories);

        $lastId = $row['id'];
        $done++;
        if ($done % 500 === 0) {
            fwrite(STDERR, "... $done emails\n");
        }
        if ($limit > 0 && $done >= $limit) {
            break 2;
        }
    }
}

echo $comparison->report($examples);
printf("\nParse time: laminas %.1fs, mime %.1fs\n", $seconds['laminas'], $seconds['mime']);

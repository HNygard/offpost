<?php
// Record or show parse snapshots (EmailParseSnapshot) for EML files.
//
// A snapshot is everything Offpost reads from an EML through the mail parser:
// subject, X-Forwarded-For addresses, plain text and HTML-derived text, and the
// parse error if any. The fixture tests compare each
// tests/fixtures/email-parsing/*.eml against its recorded snapshot:
// - <name>.json: current parsing (laminas), EmailParseSnapshotTest
// - <name>.new.json: replacement parser (MimeEmailReader) where it deliberately
//   differs from <name>.json, MimeEmailReaderSnapshotTest
//
// Only record after reviewing that the result is intended. See
// docs/email-parsing.md.
//
// Usage:
//   php bin/email-parse-snapshot.php [--parser=mime] <file.eml> [...]   print snapshots
//   php bin/email-parse-snapshot.php --record <file.eml> [...]          write <name>.json
//   php bin/email-parse-snapshot.php --parser=mime --record <file.eml> [...]
//       write <name>.new.json where it differs from <name>.json, remove it where not

require_once __DIR__ . '/../class/Extraction/EmailParseSnapshot.php';

$parser = EmailParseSnapshot::PARSER_LAMINAS;
$record = false;
$files = [];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--record') {
        $record = true;
    }
    elseif (str_starts_with($arg, '--parser=')) {
        $parser = substr($arg, strlen('--parser='));
    }
    else {
        $files[] = $arg;
    }
}
if (!$files) {
    fwrite(STDERR, "Usage: php bin/email-parse-snapshot.php [--parser=mime] [--record] <file.eml> [...]\n");
    exit(1);
}

$toJson = fn(array $snapshot) => json_encode(
    $snapshot,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
) . "\n";

foreach ($files as $file) {
    if (!str_ends_with($file, '.eml') || !is_file($file)) {
        fwrite(STDERR, "Not an .eml file: $file\n");
        exit(1);
    }
    $snapshot = EmailParseSnapshot::fromEml(file_get_contents($file), $parser);
    if (!$record) {
        echo "== $file\n" . $toJson($snapshot);
        continue;
    }

    $currentFile = preg_replace('/\.eml$/', '.json', $file);
    if ($parser === EmailParseSnapshot::PARSER_LAMINAS) {
        file_put_contents($currentFile, $toJson($snapshot));
        echo "Recorded $currentFile\n";
        continue;
    }

    $newFile = preg_replace('/\.eml$/', '.new.json', $file);
    if (!is_file($currentFile)) {
        fwrite(STDERR, "Record the current parser first: $currentFile is missing\n");
        exit(1);
    }
    if (json_decode(file_get_contents($currentFile), true) == $snapshot) {
        if (is_file($newFile)) {
            unlink($newFile);
            echo "Removed $newFile (same as current parser)\n";
        }
        continue;
    }
    file_put_contents($newFile, $toJson($snapshot));
    echo "Recorded $newFile\n";
}

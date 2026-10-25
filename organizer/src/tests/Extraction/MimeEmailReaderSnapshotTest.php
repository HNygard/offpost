<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../class/Extraction/EmailParseSnapshot.php';
require_once __DIR__ . '/EmailParseSnapshotTest.php';

/**
 * The replacement parser (MimeEmailReader, zbateson/mail-mime-parser) must give
 * the same snapshot as the current laminas parsing for every fixture, except
 * where a reviewed difference is recorded in <fixture>.new.json.
 *
 * Each .new.json is a deliberate behaviour change, listed with its reason in
 * docs/email-parsing.md. See EmailParseSnapshotTest for the laminas side.
 */
class MimeEmailReaderSnapshotTest extends TestCase {

    #[DataProvider('fixtureFiles')]
    public function testFixtureSnapshotMatchesCurrentParserOrReviewedDifference(string $emlFile): void {
        // :: Setup
        $newFile = preg_replace('/\.eml$/', '.new.json', $emlFile);
        $expectedFile = file_exists($newFile) ? $newFile : preg_replace('/\.eml$/', '.json', $emlFile);
        $expected = json_decode(file_get_contents($expectedFile), true);

        // :: Act
        $actual = EmailParseSnapshot::fromEml(file_get_contents($emlFile), EmailParseSnapshot::PARSER_MIME);

        // :: Assert
        $this->assertEquals($expected, $actual, 'MimeEmailReader result differs for ' . basename($emlFile) . ' (expected from ' . basename($expectedFile) . ')');
    }

    public static function fixtureFiles(): array {
        return EmailParseSnapshotTest::fixtureFiles();
    }

    public function testEveryReviewedDifferenceReallyDiffersFromCurrentParser(): void {
        // A .new.json equal to the .json is a stale leftover and hides nothing - remove it
        // :: Setup
        $stale = [];

        // :: Act
        foreach (glob(__DIR__ . '/../fixtures/email-parsing/*.new.json') as $newFile) {
            $oldFile = str_replace('.new.json', '.json', $newFile);
            if (json_decode(file_get_contents($newFile), true) == json_decode(file_get_contents($oldFile), true)) {
                $stale[] = basename($newFile);
            }
        }

        // :: Assert
        $this->assertEquals([], $stale, json_encode($stale, JSON_PRETTY_PRINT));
    }
}

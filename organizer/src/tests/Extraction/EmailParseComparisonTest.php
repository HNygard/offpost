<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../class/Extraction/EmailParseComparison.php';

class EmailParseComparisonTest extends TestCase {

    private function snapshot(array $override = []): array {
        return array_merge([
            'subject' => 'Innsyn i postliste',
            'x_forwarded_for' => ['a@offpost.no'],
            'plain_text' => 'Hei',
            'html' => "Hei\n\n",
            'parse_error' => null,
        ], $override);
    }

    public function testIdenticalSnapshotsAreEqualInEveryField(): void {
        // :: Act
        $result = EmailParseComparison::compare($this->snapshot(), $this->snapshot());

        // :: Assert
        $this->assertEquals([
            'subject' => EmailParseComparison::EQUAL,
            'x_forwarded_for' => EmailParseComparison::EQUAL,
            'plain_text' => EmailParseComparison::EQUAL,
            'html' => EmailParseComparison::EQUAL,
        ], $result);
    }

    public function testDifferenceOnlyInWhitespaceIsWhitespaceOnly(): void {
        // :: Act
        $result = EmailParseComparison::compare(
            $this->snapshot(['html' => "Hei\n\n\n", 'plain_text' => "Hei  på\r\ndeg"]),
            $this->snapshot(['html' => "Hei\n\n", 'plain_text' => "Hei på\ndeg"])
        );

        // :: Assert
        $this->assertEquals(EmailParseComparison::WHITESPACE_ONLY, $result['html']);
        $this->assertEquals(EmailParseComparison::WHITESPACE_ONLY, $result['plain_text']);
    }

    public function testChangedText(): void {
        // :: Act
        $result = EmailParseComparison::compare(
            $this->snapshot(['subject' => 'Søknad']),
            $this->snapshot(['subject' => 'Søknad om innsyn'])
        );

        // :: Assert
        $this->assertEquals(EmailParseComparison::CHANGED, $result['subject']);
    }

    public function testOldParseErrorAndNewBodyIsFixedFailureForBodyFields(): void {
        // :: Act
        $result = EmailParseComparison::compare(
            $this->snapshot(['plain_text' => null, 'html' => null, 'parse_error' => 'Exception: Failed to parse email']),
            $this->snapshot()
        );

        // :: Assert
        $this->assertEquals(EmailParseComparison::OLD_FAILED_NEW_OK, $result['plain_text']);
        $this->assertEquals(EmailParseComparison::OLD_FAILED_NEW_OK, $result['html']);
    }

    public function testNewParseErrorIsRegressionEvenWhenOldFailedDifferently(): void {
        // :: Act
        $regression = EmailParseComparison::compare(
            $this->snapshot(),
            $this->snapshot(['plain_text' => null, 'html' => null, 'parse_error' => 'RuntimeException: boom'])
        );
        $bothFailed = EmailParseComparison::compare(
            $this->snapshot(['plain_text' => null, 'html' => null, 'parse_error' => 'Exception: a']),
            $this->snapshot(['plain_text' => null, 'html' => null, 'parse_error' => 'RuntimeException: b'])
        );

        // :: Assert
        $this->assertEquals(EmailParseComparison::NEW_FAILED, $regression['plain_text']);
        $this->assertEquals(EmailParseComparison::NEW_FAILED, $bothFailed['plain_text'], 'A new-parser failure is always shown, never hidden as "both failed"');
    }

    public function testSubjectErrorTextCountsAsFailure(): void {
        // :: Act
        $fixed = EmailParseComparison::compare(
            $this->snapshot(['subject' => "Error getting subject - Failed to parse email due to problematic header on line 2\nHeader name: To"]),
            $this->snapshot(['subject' => 'Test'])
        );
        $regression = EmailParseComparison::compare(
            $this->snapshot(['subject' => 'Test']),
            $this->snapshot(['subject' => 'Error getting subject - boom'])
        );

        // :: Assert
        $this->assertEquals(EmailParseComparison::OLD_FAILED_NEW_OK, $fixed['subject']);
        $this->assertEquals(EmailParseComparison::NEW_FAILED, $regression['subject']);
    }

    public function testXForwardedForExceptionMarkerCountsAsFailure(): void {
        // :: Act
        $result = EmailParseComparison::compare(
            $this->snapshot(['x_forwarded_for' => ['exception-during-getting-x-forwarded-for (call to undefined method)']]),
            $this->snapshot(['x_forwarded_for' => ['a@offpost.no', 'b@offpost.no']])
        );

        // :: Assert
        $this->assertEquals(EmailParseComparison::OLD_FAILED_NEW_OK, $result['x_forwarded_for']);
    }

    public function testXForwardedForOrderMatters(): void {
        // Thread matching does not depend on order, but a change is still a change to review
        // :: Act
        $result = EmailParseComparison::compare(
            $this->snapshot(['x_forwarded_for' => ['a@offpost.no', 'b@offpost.no']]),
            $this->snapshot(['x_forwarded_for' => ['b@offpost.no', 'a@offpost.no']])
        );

        // :: Assert
        $this->assertEquals(EmailParseComparison::CHANGED, $result['x_forwarded_for']);
    }

    public function testInvalidUtf8MarkerComparesByValue(): void {
        // :: Act
        $same = EmailParseComparison::compare(
            $this->snapshot(['subject' => ['invalid_utf8_base64' => 'U/hrbmFk']]),
            $this->snapshot(['subject' => ['invalid_utf8_base64' => 'U/hrbmFk']])
        );
        $changed = EmailParseComparison::compare(
            $this->snapshot(['subject' => ['invalid_utf8_base64' => 'U/hrbmFk']]),
            $this->snapshot(['subject' => 'Søknad'])
        );

        // :: Assert
        $this->assertEquals(EmailParseComparison::EQUAL, $same['subject']);
        $this->assertEquals(EmailParseComparison::CHANGED, $changed['subject']);
    }

    public function testSummaryCountsAndKeepsFirstIdsPerFieldAndCategory(): void {
        // :: Setup
        $summary = new EmailParseComparison(2);

        // :: Act
        $summary->add('e1', ['subject' => EmailParseComparison::EQUAL, 'html' => EmailParseComparison::CHANGED]);
        $summary->add('e2', ['subject' => EmailParseComparison::CHANGED, 'html' => EmailParseComparison::CHANGED]);
        $summary->add('e3', ['subject' => EmailParseComparison::CHANGED, 'html' => EmailParseComparison::CHANGED]);

        // :: Assert
        $this->assertEquals([
            'subject' => [EmailParseComparison::EQUAL => 1, EmailParseComparison::CHANGED => 2],
            'html' => [EmailParseComparison::CHANGED => 3],
        ], $summary->counts());
        $this->assertEquals([
            'subject' => [EmailParseComparison::CHANGED => ['e2', 'e3']],
            'html' => [EmailParseComparison::CHANGED => ['e1', 'e2']],
        ], $summary->exampleIds(), 'EQUAL is not interesting and keeps no ids; at most 2 ids per bucket');
    }

    public function testReportListsRegressionsFirst(): void {
        // :: Setup
        $summary = new EmailParseComparison(5);
        $summary->add('e1', ['subject' => EmailParseComparison::CHANGED, 'plain_text' => EmailParseComparison::NEW_FAILED]);
        $summary->add('e2', ['subject' => EmailParseComparison::EQUAL, 'plain_text' => EmailParseComparison::OLD_FAILED_NEW_OK]);

        // :: Act
        $report = $summary->report(2);

        // :: Assert
        $this->assertEquals(
            "Compared 2 emails\n"
            . "\n"
            . "REGRESSIONS (new parser failed):\n"
            . "  plain_text: 1  e1\n"
            . "\n"
            . "field            equal  whitespace_only  changed  old_failed_new_ok  new_failed\n"
            . "subject              1                0        1                  0           0\n"
            . "plain_text           0                0        0                  1           1\n"
            . "\n"
            . "Example email ids (inspect with --show=<id>):\n"
            . "  subject changed: e1\n"
            . "  plain_text old_failed_new_ok: e2\n"
            . "  plain_text new_failed: e1\n",
            $report
        );
    }
}

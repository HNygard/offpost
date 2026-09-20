<?php
// organizer/src/tests/PostlistePeriodTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../class/PostlistePeriod.php';

class PostlistePeriodTest extends TestCase {
    /**
     * @dataProvider validPeriods
     */
    public function testValidPeriodParsesToInclusiveDateRange(string $period, string $expectedFrom, string $expectedTo): void {
        // :: Act
        $parsed = PostlistePeriod::tryParse($period);

        // :: Assert
        $this->assertNotNull($parsed, "Period '$period' should parse");
        $this->assertTrue(PostlistePeriod::isValid($period));
        $this->assertEquals($expectedFrom, $parsed->from->format('Y-m-d'), "from-date for '$period'");
        $this->assertEquals($expectedTo, $parsed->to->format('Y-m-d'), "to-date for '$period'");
        $this->assertEquals($period, $parsed->period);
    }

    public static function validPeriods(): array {
        return [
            'iso week' => ['2026-W38', '2026-09-14', '2026-09-20'],
            'iso week 1 starting in previous year' => ['2021-W01', '2021-01-04', '2021-01-10'],
            'iso week 53 in a 53-week year' => ['2020-W53', '2020-12-28', '2021-01-03'],
            'month' => ['2026-09', '2026-09-01', '2026-09-30'],
            'february leap year' => ['2024-02', '2024-02-01', '2024-02-29'],
            'year' => ['2025', '2025-01-01', '2025-12-31'],
            'month range' => ['2025-01--2025-06', '2025-01-01', '2025-06-30'],
            'month range single month' => ['2025-03--2025-03', '2025-03-01', '2025-03-31'],
            'year range' => ['2011--2021', '2011-01-01', '2021-12-31'],
        ];
    }

    /**
     * @dataProvider malformedPeriods
     */
    public function testMalformedPeriodIsRejected(string $period): void {
        $this->assertFalse(PostlistePeriod::isValid($period), "Period '$period' must be rejected");
        $this->assertNull(PostlistePeriod::tryParse($period));
    }

    public static function malformedPeriods(): array {
        return [
            'empty' => [''],
            'legacy single-dash year range' => ['2011-2021'],
            'week 00' => ['2026-W00'],
            'week 54' => ['2026-W54'],
            'week 53 in a 52-week year' => ['2025-W53'],
            'month 13' => ['2026-13'],
            'month 00' => ['2026-00'],
            'month range reversed' => ['2025-06--2025-01'],
            'year range reversed' => ['2021--2011'],
            'day precision' => ['2026-09-14'],
            'trailing text' => ['2026-09x'],
            'leading text' => ['uke 2026-W38'],
            'lowercase week' => ['2026-w38'],
            'two digit year' => ['26-09'],
        ];
    }

    public function testFromLabelsPicksFirstWellFormedPostlisteLabel(): void {
        // :: Setup
        $labels = ['postliste', 'postliste:2011-2021', 'postliste:2026-W38', 'postliste:2025'];

        // :: Act
        $parsed = PostlistePeriod::fromLabels($labels);

        // :: Assert
        $this->assertNotNull($parsed);
        $this->assertEquals('2026-W38', $parsed->period);
        $this->assertEquals('14.09.2026', $parsed->fromFormatted());
        $this->assertEquals('20.09.2026', $parsed->toFormatted());
    }

    public function testFromLabelsReturnsNullWithoutPeriodLabel(): void {
        $this->assertNull(PostlistePeriod::fromLabels(['postliste', 'postliste-politidistrikt']));
        $this->assertNull(PostlistePeriod::fromLabels([]));
    }
}

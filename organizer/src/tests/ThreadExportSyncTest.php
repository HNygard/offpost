<?php
// organizer/src/tests/ThreadExportSyncTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../class/ThreadExportSync.php';

class ThreadExportSyncTest extends TestCase {
    private function listed(string $id, string $fingerprint): array {
        return ['id' => $id, 'fingerprint' => $fingerprint];
    }

    public function testNewThreadIsFetched(): void {
        // :: Setup
        $listedThreads = [$this->listed('a', 'fp-a')];
        $localIndex = [];

        // :: Act
        $plan = ThreadExportSync::planFetch($listedThreads, $localIndex, false, null);

        // :: Assert
        $this->assertEquals(['a'], $plan['toFetch']);
        $this->assertEquals(0, $plan['unchanged']);
    }

    public function testChangedFingerprintIsFetched(): void {
        // :: Setup
        $listedThreads = [$this->listed('a', 'fp-a-new')];
        $localIndex = ['a' => 'fp-a-old'];

        // :: Act
        $plan = ThreadExportSync::planFetch($listedThreads, $localIndex, false, null);

        // :: Assert
        $this->assertEquals(['a'], $plan['toFetch']);
        $this->assertEquals(0, $plan['unchanged']);
    }

    public function testUnchangedFingerprintIsSkipped(): void {
        // :: Setup
        $listedThreads = [$this->listed('a', 'fp-a')];
        $localIndex = ['a' => 'fp-a'];

        // :: Act
        $plan = ThreadExportSync::planFetch($listedThreads, $localIndex, false, null);

        // :: Assert
        $this->assertEquals([], $plan['toFetch']);
        $this->assertEquals(1, $plan['unchanged']);
    }

    public function testFullRefetchesEvenUnchangedThreads(): void {
        // :: Setup
        $listedThreads = [$this->listed('a', 'fp-a'), $this->listed('b', 'fp-b')];
        $localIndex = ['a' => 'fp-a', 'b' => 'fp-b-old'];

        // :: Act
        $plan = ThreadExportSync::planFetch($listedThreads, $localIndex, true, null);

        // :: Assert
        $this->assertEquals(['a', 'b'], $plan['toFetch']);
        $this->assertEquals(0, $plan['unchanged']);
    }

    public function testLimitCapsFetchListPreservingOrder(): void {
        // :: Setup
        $listedThreads = [
            $this->listed('a', 'fp-a'),
            $this->listed('b', 'fp-b'),
            $this->listed('c', 'fp-c'),
        ];
        $localIndex = [];

        // :: Act
        $plan = ThreadExportSync::planFetch($listedThreads, $localIndex, false, 2);

        // :: Assert
        $this->assertEquals(['a', 'b'], $plan['toFetch']);
        $this->assertEquals(0, $plan['unchanged']);
    }

    public function testLimitDoesNotCountUnchangedThreadsAgainstItself(): void {
        // :: Setup
        // "a" is unchanged (skipped for that reason, not the limit), so the
        // limit of 1 should still let "b" and "c" compete for the one slot,
        // in list order.
        $listedThreads = [
            $this->listed('a', 'fp-a'),
            $this->listed('b', 'fp-b'),
            $this->listed('c', 'fp-c'),
        ];
        $localIndex = ['a' => 'fp-a'];

        // :: Act
        $plan = ThreadExportSync::planFetch($listedThreads, $localIndex, false, 1);

        // :: Assert
        $this->assertEquals(['b'], $plan['toFetch']);
        $this->assertEquals(1, $plan['unchanged']);
    }

    public function testEmptyListedThreadsFetchesNothing(): void {
        // :: Setup
        $listedThreads = [];
        $localIndex = ['a' => 'fp-a'];

        // :: Act
        $plan = ThreadExportSync::planFetch($listedThreads, $localIndex, false, null);

        // :: Assert
        $this->assertEquals([], $plan['toFetch']);
        $this->assertEquals(0, $plan['unchanged']);
    }

    public function testSummaryLine(): void {
        // :: Setup
        // (nothing to build - summaryLine is a pure formatter)

        // :: Act
        $line = ThreadExportSync::summaryLine(3, 5, 1);

        // :: Assert
        $this->assertEquals('fetched 3, unchanged 5, failed 1', $line);
    }

    public function testSummaryLineWithZeros(): void {
        // :: Act
        $line = ThreadExportSync::summaryLine(0, 0, 0);

        // :: Assert
        $this->assertEquals('fetched 0, unchanged 0, failed 0', $line);
    }

    public function testIsSafeBaseUrl(): void {
        // :: Setup
        $urls = [
            'https://offpost.no',
            'HTTPS://offpost.no/',
            'http://localhost:25081',
            'http://127.0.0.1:25081',
            'http://offpost.no',
            'ftp://offpost.no',
            'offpost.no',
            '',
        ];

        // :: Act
        $results = [];
        foreach ($urls as $url) {
            $results[$url] = ThreadExportSync::isSafeBaseUrl($url);
        }

        // :: Assert
        $this->assertEquals([
            'https://offpost.no' => true,
            'HTTPS://offpost.no/' => true,
            'http://localhost:25081' => true,
            'http://127.0.0.1:25081' => true,
            'http://offpost.no' => false,
            'ftp://offpost.no' => false,
            'offpost.no' => false,
            '' => false,
        ], $results);
    }
}

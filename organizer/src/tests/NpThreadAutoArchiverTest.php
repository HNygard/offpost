<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../class/NpThreadAutoArchiver.php';

class NpThreadAutoArchiverTest extends TestCase {

    public function testReleasedIsArchived() {
        // :: Act
        $reason = NpThreadAutoArchiver::decide('STATUS_OK',
            ['REQUEST_RECEIPT', 'INFORMATION_RELEASE'], false);

        // :: Assert
        $this->assertNull($reason);
    }

    public function testMoreTimeThenReleaseIsArchived() {
        // :: Act
        $reason = NpThreadAutoArchiver::decide('STATUS_OK',
            ['ASKING_FOR_MORE_TIME', 'INFORMATION_RELEASE', 'REQUEST_RECEIPT'], false);

        // :: Assert
        $this->assertNull($reason);
    }

    public function testReleaseWithoutReceiptIsArchivedWithoutWaiting() {
        // :: Act
        // No waiting period: finished is archived at once.
        $reason = NpThreadAutoArchiver::decide('STATUS_OK',
            ['INFORMATION_RELEASE'], false);

        // :: Assert
        $this->assertNull($reason);
    }

    public function testUnclassifiedEmailIsNotArchived() {
        // :: Act
        $reason = NpThreadAutoArchiver::decide('STATUS_OK',
            ['INFORMATION_RELEASE', 'unknown'], false);

        // :: Assert
        $this->assertEquals('incoming email classified unknown needs a human', $reason);
    }

    public function testRejectionIsNotArchived() {
        // :: Act
        $reason = NpThreadAutoArchiver::decide('STATUS_OK',
            ['INFORMATION_RELEASE', 'REQUEST_REJECTED'], false);

        // :: Assert
        $this->assertEquals('incoming email classified REQUEST_REJECTED needs a human', $reason);
    }

    public function testMoreTimeAfterReleaseIsNotArchived() {
        // :: Act
        $reason = NpThreadAutoArchiver::decide('STATUS_OK',
            ['INFORMATION_RELEASE', 'ASKING_FOR_MORE_TIME'], false);

        // :: Assert
        $this->assertEquals('no information release as the latest answer', $reason);
    }

    public function testOnlyReceiptIsNotArchived() {
        // :: Act
        $reason = NpThreadAutoArchiver::decide('STATUS_OK',
            ['REQUEST_RECEIPT'], false);

        // :: Assert
        $this->assertEquals('no information release as the latest answer', $reason);
    }

    public function testNoIncomingIsNotArchived() {
        // :: Act
        $reason = NpThreadAutoArchiver::decide('STATUS_OK', [], false);

        // :: Assert
        $this->assertEquals('no incoming email', $reason);
    }

    public function testErrorStatusIsNotArchived() {
        // :: Act
        $reason = NpThreadAutoArchiver::decide('ERROR_MULTIPLE_FOLDERS',
            ['INFORMATION_RELEASE'], false);

        // :: Assert
        $this->assertEquals('status ERROR_MULTIPLE_FOLDERS', $reason);
    }

    public function testSendingInFlightIsNotArchived() {
        // :: Act
        $reason = NpThreadAutoArchiver::decide('STATUS_OK',
            ['INFORMATION_RELEASE'], true);

        // :: Assert
        $this->assertEquals('sending in flight', $reason);
    }
}

<?php

use PHPUnit\Framework\TestCase;
use App\Enums\ThreadEmailStatusType;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../class/ThreadEmail.php';

/**
 * Ingest stamps every email with 'Uklassifisert'. The classify form
 * pre-fills it, so it used to survive a real classification and make a
 * classified email still read as unclassified.
 */
class ThreadEmailNormalizeStatusTextTest extends TestCase {

    public function testPlaceholderIsClearedWhenTypeIsReal(): void {
        // :: Setup
        $placeholder = ThreadEmail::UNCLASSIFIED_STATUS_TEXT;

        // :: Act
        $result = ThreadEmail::normalizeStatusText(
            ThreadEmailStatusType::INFORMATION_RELEASE, $placeholder);

        // :: Assert
        $this->assertEquals('', $result);
    }

    public function testPlaceholderIsClearedWhenTypeIsRealAsRawString(): void {
        // :: Setup
        $placeholder = ThreadEmail::UNCLASSIFIED_STATUS_TEXT;

        // :: Act
        $result = ThreadEmail::normalizeStatusText('INFORMATION_RELEASE', $placeholder);

        // :: Assert
        $this->assertEquals('', $result);
    }

    public function testPlaceholderIsKeptWhenTypeIsUnknownEnum(): void {
        // :: Setup
        $placeholder = ThreadEmail::UNCLASSIFIED_STATUS_TEXT;

        // :: Act
        $result = ThreadEmail::normalizeStatusText(
            ThreadEmailStatusType::UNKNOWN, $placeholder);

        // :: Assert
        $this->assertEquals('Uklassifisert', $result);
    }

    public function testPlaceholderIsKeptWhenTypeIsUnknownString(): void {
        // :: Setup
        $placeholder = ThreadEmail::UNCLASSIFIED_STATUS_TEXT;

        // :: Act
        $result = ThreadEmail::normalizeStatusText('unknown', $placeholder);

        // :: Assert
        $this->assertEquals('Uklassifisert', $result);
    }

    public function testRealTextIsUntouched(): void {
        // :: Setup
        $text = 'Svar med vedlegg';

        // :: Act
        $result = ThreadEmail::normalizeStatusText(
            ThreadEmailStatusType::INFORMATION_RELEASE, $text);

        // :: Assert
        $this->assertEquals('Svar med vedlegg', $result);
    }

    public function testEmptyTextIsUntouched(): void {
        // :: Setup
        $text = '';

        // :: Act
        $result = ThreadEmail::normalizeStatusText(
            ThreadEmailStatusType::INFORMATION_RELEASE, $text);

        // :: Assert
        $this->assertEquals('', $result);
    }

    public function testNullTextBecomesEmptyString(): void {
        // :: Setup
        $text = null;

        // :: Act
        $result = ThreadEmail::normalizeStatusText(
            ThreadEmailStatusType::INFORMATION_RELEASE, $text);

        // :: Assert
        $this->assertEquals('', $result);
    }
}

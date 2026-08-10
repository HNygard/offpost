<?php

use PHPUnit\Framework\TestCase;
use App\Enums\ThreadEmailStatusType;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../class/ThreadUtils.php';
require_once __DIR__ . '/../class/ThreadEmailAttachment.php';
require_once __DIR__ . '/../class/ThreadEmail.php';

/**
 * thread-view and the front page used to print only the free-text status_text,
 * so the classification itself was never visible. renderClassification() is now
 * the single place that turns a status type + text pair into HTML.
 */
class ThreadUtilsRenderClassificationTest extends TestCase {

    public function testInformationReleaseWithText(): void {
        // :: Setup
        $statusType = ThreadEmailStatusType::INFORMATION_RELEASE;
        $statusText = 'Svar med vedlegg';

        // :: Act
        $result = renderClassification($statusType, $statusText);

        // :: Assert
        $this->assertEquals(
            '<span class="classification label label_information_release label_ok">'
            . 'From entity: Information Release</span>'
            . ' <span class="status-text">Svar med vedlegg</span>',
            $result
        );
    }

    public function testOurRequestWithoutText(): void {
        // :: Setup
        $statusType = ThreadEmailStatusType::OUR_REQUEST;

        // :: Act
        $result = renderClassification($statusType, '');

        // :: Assert
        $this->assertEquals(
            '<span class="classification label label_our_request">From us: Our Request</span>',
            $result
        );
    }

    public function testRequestReceipt(): void {
        // :: Setup
        $statusType = ThreadEmailStatusType::REQUEST_RECEIPT;

        // :: Act
        $result = renderClassification($statusType, null);

        // :: Assert
        $this->assertEquals(
            '<span class="classification label label_request_receipt">'
            . 'From entity: Receipt of Request</span>',
            $result
        );
    }

    public function testRequestRejected(): void {
        // :: Setup
        $statusType = ThreadEmailStatusType::REQUEST_REJECTED;

        // :: Act
        $result = renderClassification($statusType, 'Avslag');

        // :: Assert
        $this->assertEquals(
            '<span class="classification label label_request_rejected label_warn">'
            . 'From entity: Request Rejected</span>'
            . ' <span class="status-text">Avslag</span>',
            $result
        );
    }

    public function testEnumCaseAndRawStringRenderIdentically(): void {
        // :: Setup
        $statusText = 'Svar med vedlegg';

        // :: Act
        $fromCase = renderClassification(ThreadEmailStatusType::RESPONSE_TO_REQUEST, $statusText);
        $fromString = renderClassification('RESPONSE_TO_REQUEST', $statusText);

        // :: Assert
        $this->assertEquals($fromCase, $fromString);
        $this->assertEquals(
            '<span class="classification label label_response_to_request">'
            . 'From entity: Response to Request</span>'
            . ' <span class="status-text">Svar med vedlegg</span>',
            $fromCase
        );
    }

    public function testUnknownStatusType(): void {
        // :: Setup
        $statusType = ThreadEmailStatusType::UNKNOWN;

        // :: Act
        $result = renderClassification($statusType, 'Trenger oppfolging');

        // :: Assert
        $this->assertEquals(
            '<span class="classification label">Unknown</span>'
            . ' <span class="status-text">Trenger oppfolging</span>',
            $result
        );
    }

    public function testNullStatusTypeRendersAsUnknown(): void {
        // :: Setup
        $statusType = null;

        // :: Act
        $result = renderClassification($statusType, '');

        // :: Assert
        $this->assertEquals('<span class="classification label">Unknown</span>', $result);
    }

    public function testEmptyStatusTypeRendersAsUnknown(): void {
        // :: Setup
        $statusType = '';

        // :: Act
        $result = renderClassification($statusType, '');

        // :: Assert
        $this->assertEquals('<span class="classification label">Unknown</span>', $result);
    }

    public function testLegacyDisabledValueRendersVerbatim(): void {
        // :: Setup
        $statusType = 'disabled';

        // :: Act
        $result = renderClassification($statusType, 'Autosvar');

        // :: Assert
        $this->assertEquals(
            '<span class="classification label label_disabled">disabled</span>'
            . ' <span class="status-text">Autosvar</span>',
            $result
        );
    }

    public function testLegacyDangerValueRendersVerbatim(): void {
        // :: Setup
        $statusType = 'danger';

        // :: Act
        $result = renderClassification($statusType, 'Avslag');

        // :: Assert
        $this->assertEquals(
            '<span class="classification label label_warn">danger</span>'
            . ' <span class="status-text">Avslag</span>',
            $result
        );
    }

    public function testLegacyUppercaseUnknownRendersVerbatim(): void {
        // :: Setup
        $statusType = 'UNKNOWN';

        // :: Act
        $result = renderClassification($statusType, '');

        // :: Assert
        $this->assertEquals('<span class="classification label">UNKNOWN</span>', $result);
    }

    public function testPlaceholderStatusTextIsSuppressed(): void {
        // :: Setup
        $statusType = ThreadEmailStatusType::INFORMATION_RELEASE;
        $statusText = ThreadEmailAttachment::UNCLASSIFIED_STATUS_TEXT;

        // :: Act
        $result = renderClassification($statusType, $statusText);

        // :: Assert
        $this->assertEquals(
            '<span class="classification label label_information_release label_ok">'
            . 'From entity: Information Release</span>',
            $result
        );
    }

    public function testEmailPlaceholderStatusTextIsSuppressed(): void {
        // :: Setup
        $statusType = ThreadEmailStatusType::INFORMATION_RELEASE;
        $statusText = ThreadEmail::UNCLASSIFIED_STATUS_TEXT;

        // :: Act
        $result = renderClassification($statusType, $statusText);

        // :: Assert
        $this->assertEquals(
            '<span class="classification label label_information_release label_ok">'
            . 'From entity: Information Release</span>',
            $result
        );
    }

    public function testEmailPlaceholderStatusTextIsSuppressedEvenWhenUnknown(): void {
        // :: Setup
        $statusType = ThreadEmailStatusType::UNKNOWN;
        $statusText = ThreadEmail::UNCLASSIFIED_STATUS_TEXT;

        // :: Act
        $result = renderClassification($statusType, $statusText);

        // :: Assert
        $this->assertEquals(
            '<span class="classification label">Unknown</span>',
            $result
        );
    }

    public function testStatusTextEqualToLabelIsSuppressed(): void {
        // :: Setup
        $statusType = ThreadEmailStatusType::INFORMATION_RELEASE;
        $statusText = 'From entity: Information Release';

        // :: Act
        $result = renderClassification($statusType, $statusText);

        // :: Assert
        $this->assertEquals(
            '<span class="classification label label_information_release label_ok">'
            . 'From entity: Information Release</span>',
            $result
        );
    }

    public function testStatusTextIsEscaped(): void {
        // :: Setup
        $statusType = ThreadEmailStatusType::INFORMATION_RELEASE;
        $statusText = '<script>alert("x")</script> & more';

        // :: Act
        $result = renderClassification($statusType, $statusText);

        // :: Assert
        $this->assertEquals(
            '<span class="classification label label_information_release label_ok">'
            . 'From entity: Information Release</span>'
            . ' <span class="status-text">'
            . '&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; &amp; more'
            . '</span>',
            $result
        );
    }

    public function testEveryEnumCaseRenders(): void {
        // :: Setup
        $cases = ThreadEmailStatusType::cases();

        // :: Act
        $rendered = [];
        foreach ($cases as $case) {
            $rendered[$case->value] = renderClassification($case, '');
        }

        // :: Assert
        $this->assertCount(
            count($cases),
            $rendered,
            'Every enum case must render: ' . json_encode($rendered, JSON_PRETTY_PRINT)
        );
        foreach ($rendered as $value => $html) {
            $this->assertStringContainsString(
                htmlescape(ThreadEmailStatusType::from($value)->label()),
                $html,
                "status_type $value must show its label"
            );
        }
    }
}

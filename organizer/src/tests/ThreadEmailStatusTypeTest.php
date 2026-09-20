<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../class/Enums/ThreadEmailStatusType.php';

use App\Enums\ThreadEmailStatusType;

class ThreadEmailStatusTypeTest extends TestCase {
    public function testClarificationCases() {
        // :: Setup

        // :: Act & Assert
        $this->assertEquals('ASKING_FOR_CLARIFICATION', ThreadEmailStatusType::ASKING_FOR_CLARIFICATION->value);
        $this->assertEquals('From entity: Asking for Clarification', ThreadEmailStatusType::ASKING_FOR_CLARIFICATION->label());
        $this->assertEquals('CLARIFICATION_SENT', ThreadEmailStatusType::CLARIFICATION_SENT->value);
        $this->assertEquals('From us: Clarification Sent', ThreadEmailStatusType::CLARIFICATION_SENT->label());
    }

    public function testResponseToRequestCase() {
        // :: Setup

        // :: Act & Assert
        $this->assertEquals('RESPONSE_TO_REQUEST', ThreadEmailStatusType::RESPONSE_TO_REQUEST->value);
        $this->assertEquals('From entity: Response to Request', ThreadEmailStatusType::RESPONSE_TO_REQUEST->label());
    }

    public function testRequestReceiptCase() {
        // :: Setup
        $case = ThreadEmailStatusType::REQUEST_RECEIPT;

        // :: Act & Assert
        $this->assertEquals('REQUEST_RECEIPT', $case->value);
        $this->assertEquals('From entity: Receipt of Request', $case->label());
    }

    public function testRequestReceiptDescriptionNamesTheNorwegianTermAndIgnoreEffect() {
        // :: Setup
        $case = ThreadEmailStatusType::REQUEST_RECEIPT;

        // :: Act
        $description = $case->description();

        // :: Assert
        // The Norwegian wording is what the classifying user sees in the email
        $this->assertStringContainsString('Kvittering på mottatt innsynshenvendelse', $description);
        $this->assertStringContainsString('Ignore', $description);
        $this->assertStringContainsString('NP integration', $description);
    }

    public function testEveryCaseHasAGroupExceptUnknown() {
        // :: Setup
        $cases = ThreadEmailStatusType::cases();

        // :: Act & Assert
        foreach ($cases as $case) {
            if ($case === ThreadEmailStatusType::UNKNOWN) {
                $this->assertNull($case->group(), 'UNKNOWN should not be grouped by sender');
                continue;
            }
            $this->assertNotEmpty($case->group(), 'Missing group for ' . $case->value);
        }
    }

    public function testLabelIsPrefixedWithItsGroup() {
        // :: Setup
        $grouped = ThreadEmailStatusType::OUR_REQUEST;
        $ungrouped = ThreadEmailStatusType::UNKNOWN;

        // :: Act & Assert
        $this->assertEquals('From us: Our Request', $grouped->label());
        $this->assertEquals('Unknown', $ungrouped->label(), 'Ungrouped cases keep a bare label');
    }

    public function testCasesAreOrderedByGroup() {
        // :: Setup
        // cases() returns declaration order, and the classify dropdown iterates it
        // directly, so declaration order is what the user sees.
        $expected = [
            'OUR_REQUEST',
            'CLARIFICATION_SENT',
            'COPY_SENT',
            'REQUEST_RECEIPT',
            'ASKING_FOR_MORE_TIME',
            'ASKING_FOR_COPY',
            'ASKING_FOR_CLARIFICATION',
            'RESPONSE_TO_REQUEST',
            'REQUEST_REJECTED',
            'INFORMATION_RELEASE',
            'RESPONSE_UNREADABLE',
            'info',
            'error',
            'success',
            'unknown',
        ];

        // :: Act
        $actual = ThreadEmailStatusType::values();

        // :: Assert
        $this->assertEquals(
            $expected,
            $actual,
            'Dropdown order should group from-us, then from-entity, then legacy, then unknown. Got: '
                . json_encode($actual, JSON_PRETTY_PRINT)
        );
    }

    public function testResponseToRequestDescriptionNamesInnsynskravAndIgnoreEffect() {
        // :: Setup
        $case = ThreadEmailStatusType::RESPONSE_TO_REQUEST;

        // :: Act
        $description = $case->description();

        // :: Assert
        // The Norwegian term is what the classifying user sees in the email,
        // and the Ignore consequence must be stated even though this status
        // carries no Ignore recommendation.
        $this->assertStringContainsString('innsynskrav', $description);
        $this->assertStringContainsString('NP integration', $description);
    }

    public function testAllCasesHaveLabelAndDescription() {
        // :: Setup
        $cases = ThreadEmailStatusType::cases();

        // :: Act & Assert
        // label() and description() use match without a default arm, so a
        // missing case throws UnhandledMatchError and fails this test.
        foreach ($cases as $case) {
            $this->assertNotEmpty($case->label(), 'Missing label for ' . $case->value);
            $this->assertNotEmpty($case->description(), 'Missing description for ' . $case->value);
        }
    }

    public function testIgnoreGuidanceMentionsNpIntegration() {
        // :: Setup
        $case = ThreadEmailStatusType::ASKING_FOR_CLARIFICATION;

        // :: Act
        $description = $case->description();

        // :: Assert
        // The consequence of Ignore (exclusion from the NP integration) must be
        // stated so the classifying user understands what the flag does.
        $this->assertStringContainsString('NP integration', $description);
        $this->assertStringContainsString('Ignore', $description);
    }
}

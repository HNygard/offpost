<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../class/Enums/ThreadEmailStatusType.php';

use App\Enums\ThreadEmailStatusType;

class ThreadEmailStatusTypeTest extends TestCase {
    public function testClarificationCases() {
        // :: Setup

        // :: Act & Assert
        $this->assertEquals('ASKING_FOR_CLARIFICATION', ThreadEmailStatusType::ASKING_FOR_CLARIFICATION->value);
        $this->assertEquals('Asking for Clarification', ThreadEmailStatusType::ASKING_FOR_CLARIFICATION->label());
        $this->assertEquals('CLARIFICATION_SENT', ThreadEmailStatusType::CLARIFICATION_SENT->value);
        $this->assertEquals('Clarification Sent', ThreadEmailStatusType::CLARIFICATION_SENT->label());
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

<?php

use PHPUnit\Framework\TestCase;
use App\Enums\ThreadEmailStatusType;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../class/ThreadUtils.php';

/**
 * getLabelType() throws on values outside its switch. ThreadEmailStatusType::ERROR
 * is a real enum case that had no branch, so classifying an email as 'error'
 * crashed the page that rendered it.
 */
class ThreadUtilsGetLabelTypeTest extends TestCase {

    public function testEveryEnumCaseHasALabelType(): void {
        // :: Setup
        $cases = ThreadEmailStatusType::cases();

        // :: Act
        $results = [];
        foreach ($cases as $case) {
            $results[$case->value] = getLabelType('email', $case);
        }

        // :: Assert
        $this->assertCount(
            count($cases),
            $results,
            'Every enum case must map to a CSS class: ' . json_encode($results, JSON_PRETTY_PRINT)
        );
        foreach ($results as $value => $class) {
            $this->assertStringStartsWith('label', $class, "status_type $value");
        }
    }

    public function testErrorMapsToLabelError(): void {
        // :: Setup
        $statusType = ThreadEmailStatusType::ERROR;

        // :: Act
        $result = getLabelType('email', $statusType);

        // :: Assert
        $this->assertEquals('label label_error', $result);
    }
}

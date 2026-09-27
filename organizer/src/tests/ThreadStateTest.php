<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../class/ThreadState/ThreadState.php';

class ThreadStateTest extends TestCase {

    private function validBlob(): array {
        return [
            'schema_version' => 1,
            'request' => ['summary' => 'Valgprotokoll', 'law_basis' => 'offentleglova', 'sent_at' => '2023-09-12'],
            'items' => [
                [
                    'id' => '1',
                    'asked_for' => 'Valgprotokoll 2023',
                    'status' => 'PARTLY_RELEASED',
                    'denial_basis' => [
                        'refs' => ['offentleglova § 13'],
                        'text' => 'Unntatt av hensyn til personvern',
                        'issues' => ['INCOMPLETE_REFERENCE'],
                    ],
                    'released_in_email_ids' => ['email-1'],
                    'note' => '',
                ],
            ],
            'waiting_for' => 'NOBODY',
            'asks_to_us' => [],
            'case_numbers' => ['23/1234'],
            'dates' => [
                ['date' => '2023-10-01', 'what' => 'entity promised answer', 'email_id' => null],
            ],
            'complaints' => [
                ['status' => 'SENT', 'item_ids' => ['1'], 'sent_email_id' => 'email-2', 'decision_email_id' => null, 'outcome' => ''],
            ],
            'notes' => '',
            'extra' => [],
        ];
    }

    public function testValidBlobRoundTripsUnchanged(): void {
        // :: Setup
        $blob = $this->validBlob();

        // :: Act
        $state = ThreadState::fromArray($blob);

        // :: Assert
        $this->assertEquals($blob, $state->toArray(), json_encode($state->toArray(), JSON_PRETTY_PRINT));
    }

    public static function coreKeys(): array {
        return array_map(fn(string $key) => [$key], ThreadState::CORE_KEYS);
    }

    /** @dataProvider coreKeys */
    public function testMissingCoreKeyIsRejected(string $missingKey): void {
        // :: Setup
        $blob = $this->validBlob();
        unset($blob[$missingKey]);

        // :: Act & Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($missingKey);
        ThreadState::fromArray($blob);
    }

    public function testBadItemStatusIsRejected(): void {
        // :: Setup
        $blob = $this->validBlob();
        $blob['items'][0]['status'] = 'NOT_A_REAL_STATUS';

        // :: Act & Assert
        $this->expectException(InvalidArgumentException::class);
        ThreadState::fromArray($blob);
    }

    public function testBadWaitingForIsRejected(): void {
        // :: Setup
        $blob = $this->validBlob();
        $blob['waiting_for'] = 'NOT_A_REAL_VALUE';

        // :: Act & Assert
        $this->expectException(InvalidArgumentException::class);
        ThreadState::fromArray($blob);
    }

    public function testBadDenialBasisIssueIsRejected(): void {
        // :: Setup
        $blob = $this->validBlob();
        $blob['items'][0]['denial_basis']['issues'] = ['NOT_A_REAL_ISSUE'];

        // :: Act & Assert
        $this->expectException(InvalidArgumentException::class);
        ThreadState::fromArray($blob);
    }

    public function testBadComplaintStatusIsRejected(): void {
        // :: Setup
        $blob = $this->validBlob();
        $blob['complaints'][0]['status'] = 'NOT_A_REAL_STATUS';

        // :: Act & Assert
        $this->expectException(InvalidArgumentException::class);
        ThreadState::fromArray($blob);
    }

    public function testDuplicateItemIdsAreRejected(): void {
        // :: Setup
        $blob = $this->validBlob();
        $secondItem = $blob['items'][0];
        $secondItem['status'] = 'NOT_ANSWERED';
        $secondItem['denial_basis'] = null;
        $blob['items'][] = $secondItem; // same 'id' => '1' as the first item

        // :: Act & Assert
        $this->expectException(InvalidArgumentException::class);
        ThreadState::fromArray($blob);
    }

    public function testEmptyItemsListIsRejected(): void {
        // :: Setup
        $blob = $this->validBlob();
        $blob['items'] = [];

        // :: Act & Assert
        $this->expectException(InvalidArgumentException::class);
        ThreadState::fromArray($blob);
    }

    public function testDenialBasisNullIsAcceptedOnDenied(): void {
        // :: Setup
        $blob = $this->validBlob();
        $blob['items'][0]['status'] = 'DENIED';
        $blob['items'][0]['denial_basis'] = null;
        $blob['items'][0]['released_in_email_ids'] = [];

        // :: Act
        $state = ThreadState::fromArray($blob);

        // :: Assert
        $this->assertEquals($blob, $state->toArray(), json_encode($state->toArray(), JSON_PRETTY_PRINT));
    }
}

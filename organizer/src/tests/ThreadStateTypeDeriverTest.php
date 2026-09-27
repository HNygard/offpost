<?php

use PHPUnit\Framework\TestCase;
use App\Enums\ThreadStateType;

require_once __DIR__ . '/../class/ThreadState/ThreadState.php';
require_once __DIR__ . '/../class/ThreadState/ThreadStateTypeDeriver.php';

class ThreadStateTypeDeriverTest extends TestCase {

    /**
     * Builds a valid ThreadState with the given item statuses (each either a
     * status string, or ['status' => ..., 'released_in_email_ids' => [...]]),
     * a waiting_for value and complaint rounds. Everything not relevant to the
     * derivation rules is filled with fixed, minimal values.
     */
    private function state(array $items, string $waitingFor = 'NOBODY', array $complaints = []): ThreadState {
        $builtItems = [];
        foreach ($items as $index => $item) {
            if (is_string($item)) {
                $item = ['status' => $item];
            }
            $builtItems[] = [
                'id' => (string) ($index + 1),
                'asked_for' => 'Document ' . ($index + 1),
                'status' => $item['status'],
                'denial_basis' => null,
                'released_in_email_ids' => $item['released_in_email_ids'] ?? [],
                'note' => '',
            ];
        }

        return ThreadState::fromArray([
            'schema_version' => 1,
            'request' => ['summary' => '', 'law_basis' => 'offentleglova', 'sent_at' => null],
            'items' => $builtItems,
            'waiting_for' => $waitingFor,
            'asks_to_us' => [],
            'case_numbers' => [],
            'dates' => [],
            'complaints' => $complaints,
            'notes' => '',
            'extra' => [],
        ]);
    }

    private function complaintRound(string $status): array {
        return ['status' => $status, 'item_ids' => ['1'], 'sent_email_id' => null, 'decision_email_id' => null, 'outcome' => ''];
    }

    public static function openComplaintRoundStatuses(): array {
        return [
            ['SENT', ThreadStateType::COMPLAINT_SENT],
            ['FORWARDED', ThreadStateType::COMPLAINT_FORWARDED],
            ['OMBUD_SENT', ThreadStateType::OMBUD_COMPLAINT_SENT],
        ];
    }

    /** @dataProvider openComplaintRoundStatuses */
    public function testRule1OpenComplaintRoundGivesItsThreadStatus(string $roundStatus, ThreadStateType $expected): void {
        // :: Setup
        $state = $this->state(['BEING_EVALUATED'], 'ENTITY', [$this->complaintRound($roundStatus)]);

        // :: Act
        $result = ThreadStateTypeDeriver::derive($state);

        // :: Assert
        $this->assertEquals($expected, $result);
    }

    public function testRule2WaitingForUsGivesWaitingForUs(): void {
        // :: Setup
        $state = $this->state(['BEING_EVALUATED'], 'US');

        // :: Act
        $result = ThreadStateTypeDeriver::derive($state);

        // :: Assert
        $this->assertEquals(ThreadStateType::WAITING_FOR_US, $result);
    }

    public function testRule3AllItemsWithdrawnGivesClosed(): void {
        // :: Setup
        $state = $this->state(['WITHDRAWN', 'WITHDRAWN'], 'NOBODY');

        // :: Act
        $result = ThreadStateTypeDeriver::derive($state);

        // :: Assert
        $this->assertEquals(ThreadStateType::CLOSED, $result);
    }

    public function testRule4AllNonWithdrawnItemsNoDocumentsGivesNoDocuments(): void {
        // :: Setup
        $state = $this->state(['NO_DOCUMENTS', 'WITHDRAWN'], 'NOBODY');

        // :: Act
        $result = ThreadStateTypeDeriver::derive($state);

        // :: Assert
        $this->assertEquals(ThreadStateType::NO_DOCUMENTS, $result);
    }

    public function testRule5AllFinalAtLeastOneRefusedNoneReleasedGivesDenied(): void {
        // :: Setup
        $state = $this->state(['DENIED', 'WITHDRAWN'], 'NOBODY');

        // :: Act
        $result = ThreadStateTypeDeriver::derive($state);

        // :: Assert
        $this->assertEquals(ThreadStateType::DENIED, $result);
    }

    public function testRule6AllFinalRefusedAndReleasedGivesPartlyDeniedPartlyReleased(): void {
        // :: Setup
        $state = $this->state(['DENIED', 'RELEASED'], 'NOBODY');

        // :: Act
        $result = ThreadStateTypeDeriver::derive($state);

        // :: Assert
        $this->assertEquals(ThreadStateType::PARTLY_DENIED_PARTLY_RELEASED, $result);
    }

    public function testRule7AllFinalOtherwiseGivesAnswered(): void {
        // :: Setup
        $state = $this->state(['RELEASED', 'WITHDRAWN'], 'NOBODY');

        // :: Act
        $result = ThreadStateTypeDeriver::derive($state);

        // :: Assert
        $this->assertEquals(ThreadStateType::ANSWERED, $result);
    }

    public function testRule8OneFinalOneNotFinalGivesPartlyAnswered(): void {
        // :: Setup
        $state = $this->state(['RELEASED', 'BEING_EVALUATED'], 'NOBODY');

        // :: Act
        $result = ThreadStateTypeDeriver::derive($state);

        // :: Assert
        $this->assertEquals(ThreadStateType::PARTLY_ANSWERED, $result);
    }

    public function testRule8NonEmptyReleasedInEmailIdsWithoutFinalItemGivesPartlyAnswered(): void {
        // :: Setup
        // Neither item is final, but the first already has a release recorded
        // against it, which counts the same as being final for this rule.
        $state = $this->state([
            ['status' => 'NOT_ANSWERED', 'released_in_email_ids' => ['email-1']],
            ['status' => 'BEING_EVALUATED'],
        ], 'NOBODY');

        // :: Act
        $result = ThreadStateTypeDeriver::derive($state);

        // :: Assert
        $this->assertEquals(ThreadStateType::PARTLY_ANSWERED, $result);
    }

    public function testRule9OtherwiseGivesWaitingForEntity(): void {
        // :: Setup
        $state = $this->state(['BEING_EVALUATED', 'NOT_ANSWERED'], 'NOBODY');

        // :: Act
        $result = ThreadStateTypeDeriver::derive($state);

        // :: Assert
        $this->assertEquals(ThreadStateType::WAITING_FOR_ENTITY, $result);
    }

    public function testOpenComplaintBeatsWaitingForUsAndAllFinalItems(): void {
        // :: Setup
        $state = $this->state(['RELEASED'], 'US', [$this->complaintRound('SENT')]);

        // :: Act
        $result = ThreadStateTypeDeriver::derive($state);

        // :: Assert
        $this->assertEquals(ThreadStateType::COMPLAINT_SENT, $result);
    }

    public function testWaitingForUsBeatsAllFinalItemsWhenNoOpenComplaint(): void {
        // :: Setup
        $state = $this->state(['RELEASED'], 'US', [$this->complaintRound('DECIDED')]);

        // :: Act
        $result = ThreadStateTypeDeriver::derive($state);

        // :: Assert
        $this->assertEquals(ThreadStateType::WAITING_FOR_US, $result);
    }

    public function testLonePartlyReleasedItemGivesPartlyDeniedPartlyReleased(): void {
        // :: Setup
        $state = $this->state(['PARTLY_RELEASED'], 'NOBODY');

        // :: Act
        $result = ThreadStateTypeDeriver::derive($state);

        // :: Assert
        $this->assertEquals(ThreadStateType::PARTLY_DENIED_PARTLY_RELEASED, $result);
    }

    public function testDeniedAndNoDocumentsGivesDenied(): void {
        // :: Setup
        $state = $this->state(['DENIED', 'NO_DOCUMENTS'], 'NOBODY');

        // :: Act
        $result = ThreadStateTypeDeriver::derive($state);

        // :: Assert
        $this->assertEquals(ThreadStateType::DENIED, $result);
    }

    public function testDecidedComplaintRoundWithItemsBackInBeingEvaluatedGivesWaitingForEntity(): void {
        // :: Setup
        $state = $this->state(['BEING_EVALUATED'], 'ENTITY', [$this->complaintRound('DECIDED')]);

        // :: Act
        $result = ThreadStateTypeDeriver::derive($state);

        // :: Assert
        $this->assertEquals(ThreadStateType::WAITING_FOR_ENTITY, $result);
    }
}

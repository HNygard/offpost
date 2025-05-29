<?php

require_once __DIR__ . '/../class/ThreadEmailHistory.php';

class ThreadEmailHistoryTest extends PHPUnit\Framework\TestCase {
    private $history;

    protected function setUp(): void {
        $this->history = new ThreadEmailHistory();
    }

    public function testFormatActionForDisplay() {
        // :: Setup
        $actions = [
            [
                'action' => 'received',
                'details' => null,
                'expected' => 'Email received'
            ],
            [
                'action' => 'classified',
                'details' => json_encode([
                    'status_type' => \App\Enums\ThreadEmailStatusType::SUCCESS->value,
                    'status_text' => 'Approved'
                ]),
                'expected' => 'Classified as success: Approved'
            ],
            [
                'action' => 'auto_classified',
                'details' => json_encode([
                    'status_type' => \App\Enums\ThreadEmailStatusType::INFO->value,
                    'status_text' => 'Auto-response'
                ]),
                'expected' => 'Auto-classified as info: Auto-response'
            ],
            [
                'action' => 'sent',
                'details' => null,
                'expected' => 'Email sent'
            ],
            [
                'action' => 'ignored',
                'details' => json_encode(['ignored' => true]),
                'expected' => 'Email ignored'
            ],
            [
                'action' => 'ignored',
                'details' => json_encode(['ignored' => false]),
                'expected' => 'Email unignored'
            ]
        ];

        // :: Act & Assert
        foreach ($actions as $test) {
            $result = $this->history->formatActionForDisplay($test['action'], $test['details']);
            $this->assertEquals($test['expected'], $result, 'Failed formatting action: ' . $test['action']);
        }
    }

    public function testFormatHistoryEntry() {
        // :: Setup
        $entry = [
            'action' => 'classified',
            'details' => json_encode([
                'status_type' => \App\Enums\ThreadEmailStatusType::SUCCESS->value,
                'status_text' => 'Approved'
            ]),
            'user_id' => 'test_user',
            'created_at' => '2025-02-19 21:05:00'
        ];

        // :: Act
        $result = $this->history->formatHistoryEntry($entry);

        // :: Assert
        $this->assertEquals([
            'action' => 'Classified as success: Approved',
            'user' => 'test_user',
            'date' => '2025-02-19 21:05:00'
        ], $result, 'Failed to format history entry correctly');
    }

    public function testFormatHistoryEntryWithMissingData() {
        // :: Setup
        $invalidAction = 'unknown_action';
        $entry = [
            'action' => $invalidAction,
            'created_at' => null
        ];

        // :: Act & Assert
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unknown action: ' . $invalidAction);
        $this->history->formatHistoryEntry($entry);
    }
    
    public function testFormatActionForDisplayWithInvalidAction() {
        // :: Setup
        $invalidAction = 'unknown_action';

        // :: Act & Assert
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unknown action: ' . $invalidAction);
        $this->history->formatActionForDisplay($invalidAction, null);
    }
}

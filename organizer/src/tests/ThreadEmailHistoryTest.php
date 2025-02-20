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
                    'status_type' => 'success',
                    'status_text' => 'Approved'
                ]),
                'expected' => 'Classified as success: Approved'
            ],
            [
                'action' => 'auto_classified',
                'details' => json_encode([
                    'status_type' => 'info',
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
                'status_type' => 'success',
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
        $entry = [
            'action' => 'unknown_action'
        ];

        // :: Act
        $result = $this->history->formatHistoryEntry($entry);

        // :: Assert
        $this->assertEquals('Unknown action', $result['action'], 'Should handle unknown action');
        $this->assertNull($result['user'], 'Should handle missing user');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $result['date'], 
            'Should use current date for missing created_at');
    }
}

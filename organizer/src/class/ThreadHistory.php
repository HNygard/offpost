<?php

class ThreadHistory {
    public function logAction($threadId, $action, $userId, $details = null) {
        $sql = "INSERT INTO thread_history (thread_id, action, user_id, details) VALUES (?, ?, ?, ?)";
        $params = [$threadId, $action, $userId, $details ? json_encode($details) : null];

        return Database::execute($sql, $params);
    }

    public function getHistoryForThread($threadId) {
        $sql = "SELECT * FROM thread_history WHERE thread_id = ? ORDER BY created_at DESC";
        return Database::query($sql, [$threadId]);
    }

    public static function sendingStatusToString($status) {
        switch ($status) {
            case Thread::SENDING_STATUS_STAGING:
                return 'Staging';
            case Thread::SENDING_STATUS_READY_FOR_SENDING:
                return 'Ready for sending';
            case Thread::SENDING_STATUS_SENT:
                return 'Sent';
            case Thread::SENDING_STATUS_SENDING:
                return 'Sending';
            default:
                throw new Exception('Unknown sending status: ' . $status);
        }
    }

    public function formatActionForDisplay($action, $details) {
        switch ($action) {
            case 'created':
                return 'Created thread';
            case 'edited':
                if (!$details) {
                    return 'Edited thread';
                }
                $details = json_decode($details, true);
                if (isset($details['labels'])) {
                    return 'Updated labels: ' . implode(', ', $details['labels']);
                }
                if (isset($details['title'])) {
                    return 'Changed title to: ' . $details['title'];
                }
                return 'Edited thread';
            case 'archived':
                return 'Archived thread';
            case 'unarchived':
                return 'Unarchived thread';
            case 'made_public':
                return 'Made thread public';
            case 'made_private':
                return 'Made thread private';
            case 'sent':
                return 'Marked thread as sent';
            case 'unsent':
                return 'Marked thread as not sent';
            case 'user_added':
                $details = json_decode($details, true);
                return 'Added user: ' . ($details['user_id'] ?? 'Unknown user') .
                       ($details['is_owner'] ? ' as owner' : ' as viewer');
            case 'user_removed':
                $details = json_decode($details, true);
                return 'Removed user: ' . ($details['user_id'] ?? 'Unknown user');
            case 'status_changed':
                $details = json_decode($details, true);
                
                return 'Sending status changed'
                    . ' from [' . self::sendingStatusToString($details['from']) . ']'
                    . ' to [' . self::sendingStatusToString($details['to']) . ']';
            case 'thread_reply_created_and_queued_sending':
                $details = json_decode($details, true);
                $recipients = isset($details['recipients']) ? implode(', ', $details['
recipients']) : 'Unknown recipients';
                return 'Reply created and queued for sending'
                    . ' to [' . $recipients . ']'
                    . ' with subject [' . ($details['subject'] ?? 'No subject') . ']';
            case 'thread_reply_draft':
                $details = json_decode($details, true);
                $recipients = isset($details['recipients']) ? implode(', ', $details['recipients']) : 'Unknown recipients';
                return 'Reply draft saved'
                    . ' to [' . $recipients . ']'
                    . ' with subject [' . ($details['subject'] ?? 'No subject') . ']';
            default:
                throw new Exception('Unknown action: ' . $action);
        }
    }

    public function formatHistoryEntry($entry) {
        $action = $this->formatActionForDisplay($entry['action'] ?? '', $entry['details'] ?? null);
        $date = isset($entry['created_at']) ? new DateTime($entry['created_at']) : new DateTime();
        $formattedDate = $date->format('Y-m-d H:i:s');

        return [
            'action' => $action ?: 'Unknown action',
            'user' => $entry['user_id'] ?? 'Unknown user',
            'date' => $formattedDate
        ];
    }
}
